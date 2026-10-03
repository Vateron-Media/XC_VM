<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\LbDatabaseAccessException;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Database\MigrationRunner;
use XcVm\Core\Module\ModuleManager;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\ClusterPool;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Bootstrap\StreamingRequestBootstrap;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisConfigHardening;

/**
 * Проверка статуса, DB-миграции, конфигурация системы.
 *
 * Команда: status [first-run]
 * Требует: root
 *
 * Заменяет src/status. При первом запуске (аргумент "first-run")
 * пропускает вывод статуса и сразу выполняет миграции/конфигурацию.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StatusCommand implements CommandInterface {
	use DatabaseAware;

	public function getName(): string {
		return 'status';
	}

	public function getDescription(): string {
		return 'XC_VM status, DB migrations, system configuration';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}

		set_time_limit(0);

		$rFirstRun = isset($rArgs[0]);
		$rReload = false;

		StreamingRequestBootstrap::init('status');
		ini_set('display_startup_errors', 1);
		ini_set('display_errors', 1);
		error_reporting(E_ALL);

		if (!$rFirstRun) {
			echo "\nStatus\n------------------------------\n";
			if ($this->isRunning()) {
				echo "XC_VM is running.\n\n";
			} else {
				echo "XC_VM is not running.\n\n";
			}
		} else {
			echo "\n";
		}

		echo "Database\n------------------------------\n";

		// A node in cluster mode 2 (api) has no database of MAIN's: its agent
		// keeps the replica status reads from. Everything status does locally —
		// the permissions, nginx's config, root's crontab, the file limits — still
		// has to run, and returning here left a node without any of it.
		$rApi = NodeRole::refusesConnects();
		$db = null;
		if ($rApi) {
			echo "Cluster mode 2 (api): not this node's database, its agent keeps the replica.\n\n";
		} else {
			try {
				$rConnected = self::mainDatabaseAnswers();
			} catch (LbDatabaseAccessException $e) {
				echo $e->getMessage() . "\n\n";
				return 1;
			}
			if (!$rConnected) {
				echo "Couldn't connect to database. Please add them to config.ini.\n\n";
				return 1;
			}
			$db = self::db();
			echo "Connected successfully.\n\n";
		}
		$rServers = $this->getServers();

		if ($rServers[SERVER_ID]['is_main']) {
			MigrationRunner::run($db);

			// Provision bundled modules (create their tables via migrations) the
			// first time after a fresh install/update. Idempotent and safe to
			// re-run; failures are logged, not fatal.
			try {
				$rInstalled = (new ModuleManager(null, null, ServiceContainer::getInstance()))
					->syncBundledModules();
				if ($rInstalled !== []) {
					echo 'Installed bundled modules: ' . implode(', ', $rInstalled) . "\n";
				}
			} catch (\Throwable $e) {
				error_log('Bundled module sync failed: ' . $e->getMessage());
			}

			ProcessRunner::run(['sudo', 'chmod', '0775', MAIN_HOME . 'bin/install']);
		}

		$this->fixPermissions();
		$rReload = $this->fixNginxConfig($rReload);

		if ($rReload) {
			ProcessRunner::run(['sudo', '-u', 'xc_vm', MAIN_HOME . 'bin/nginx/sbin/nginx', '-s', 'reload']);
			exec('sudo service xc_vm restart');
		}

		StartupCommand::installRootCrontab();
		$this->configureFileLimits();
		$this->removeInitScript();

		if ($rServers[SERVER_ID]['is_main']) {
			$this->broadcastUpdateBinaries($rServers);
			$this->configureRedis();
			$this->ensureClusterNginx();
			$this->ensureClusterPools();
		} elseif (!$rApi) {
			// LB nodes run no local Redis (bin/redis is stripped from the LB build)
			// and never reach configureRedis, so the xcvm_core extension would keep
			// its default Redis target of 127.0.0.1 and every connection refuses —
			// live.php then fails with LINE_CREATE_FAIL under redis_handler. Point
			// the extension at the MAIN server's Redis (same host as MySQL) using the
			// shared password, without touching MAIN's own config.
			$this->configureRedisLb($rServers);
		}

		if (!$rFirstRun && $rServers[SERVER_ID]['is_main']) {
			$this->printStatusReport($rServers);
		}

		// In mode 2 the version goes to MAIN with the next inventory event
		// (cron:servers, NodeStateSink::INVENTORY), within the minute.
		if ($db !== null) {
			$db->query('UPDATE `servers` SET `xc_vm_version` = ? WHERE `id` = ?;', XC_VM_VERSION, SERVER_ID);
		}

		return 0;
	}

	/**
	 * MAIN: the cluster API's nginx config, rendered from the code and the
	 * settings (ClusterNginxConfig) at boot and after an update: the location
	 * the release ships, the cluster_api_port server and the old ports still
	 * kept. As xc_vm, never as root: root would follow a link xc_vm planted
	 * among the files. nginx reloads only while XC_VM runs.
	 */
	private function ensureClusterNginx(): void {
		if (!class_exists(ClusterNginxConfig::class)) {
			return;
		}
		ProcessRunner::passThrough(['sudo', '-u', 'xc_vm', PHP_BIN, MAIN_HOME . 'console.php', 'cluster:nginx', ...($this->isRunning() ? [] : ['--no-reload'])]);
	}

	/**
	 * MAIN: the cluster API's own FPM pools, created on the first boot after
	 * an upgrade and sized to the fleet (ClusterPool). Only while XC_VM runs:
	 * boot starts nginx and the panel pools before this. As xc_vm, never as
	 * root: root would follow a link xc_vm planted among the pools' files.
	 */
	private function ensureClusterPools(): void {
		if (!class_exists(ClusterPool::class) || !$this->isRunning()) {
			return;
		}
		ProcessRunner::passThrough(['sudo', '-u', 'xc_vm', PHP_BIN, MAIN_HOME . 'console.php', 'cluster:pools']);
		echo "\n";
	}

	/**
	 * Connect MAIN's database, as status always did, and ask it whether it
	 * answers: with a query, not the handle's `connected`. A process booted
	 * from the node replica (ReplicaBoot: mode 1 with the CONFIG flow on, or
	 * mode 2, once an apply built the caches) holds a lazy handle, which opens
	 * only at its first query. It opens here, so ConnectAudit counts status's
	 * connect at this site in mode 1, and refuses it here in mode 2.
	 *
	 * @throws LbDatabaseAccessException on a node in cluster mode 2 (api)
	 */
	public static function mainDatabaseAnswers(): bool {
		DatabaseFactory::connect();
		return (bool) self::db()->query('SELECT 1');
	}

	private function isRunning(): bool {
		$rNginx = 0;
		exec('ps -fp $(pgrep -u xc_vm 2>/dev/null) 2>/dev/null', $rOutput);

		foreach ($rOutput as $rProcess) {
			$rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rProcess)));
			if (isset($rSplit[8], $rSplit[9]) && $rSplit[8] === 'nginx:' && $rSplit[9] === 'master') {
				$rNginx++;
			}
		}

		return $rNginx > 0;
	}

	private function getServers(): array {
		// A node in mode 2 reads no database of MAIN's: the replica's servers,
		// as every reader there has them (status needs only its own row).
		if (NodeRole::refusesConnects()) {
			return ServerRepository::getAll(true);
		}
		$db = self::db();
		$db->query('SELECT * FROM `servers`');
		$rServers = [];
		$rOnlineStatus = [1];

		foreach ($db->get_rows() as $rRow) {
			if (empty($rRow['domain_name'])) {
				$rURL = escapeshellcmd($rRow['server_ip']);
			} else {
				$rURL = str_replace(['http://', '/', 'https://'], '', escapeshellcmd(explode(',', $rRow['domain_name'])[0]));
			}

			$rProtocol = ($rRow['enable_https'] == 1) ? 'https' : 'http';
			$rPort = ($rProtocol === 'http') ? intval($rRow['http_broadcast_port']) : intval($rRow['https_broadcast_port']);
			$rRow['site_url'] = $rProtocol . '://' . $rURL . ':' . $rPort . '/';
			$rLastCheckTime = ($rRow['server_type'] == 1) ? 180 : 90;
			$rRow['server_online'] = ($rRow['enabled'] && in_array($rRow['status'], $rOnlineStatus) && time() - $rRow['last_check_ago'] <= $rLastCheckTime) || SERVER_ID == $rRow['id'];
			$rServers[intval($rRow['id'])] = $rRow;
		}

		return $rServers;
	}

	private function fixPermissions(): void {
		$rSockets = glob(MAIN_HOME . 'bin/php/sockets/*') ?: []; // none while PHP-FPM is stopped (an update)
		if ($rSockets !== []) {
			ProcessRunner::run(['sudo', 'chmod', '0660', ...$rSockets], true);
		}
		ProcessRunner::run(['sudo', 'chmod', '0771', MAIN_HOME . 'bin/daemons.sh']);
		ProcessRunner::run(['sudo', 'chmod', '0775', MAIN_HOME . 'bin/certbot']);
		ProcessRunner::run(['sudo', 'chown', '-R', 'xc_vm:xc_vm', MAIN_HOME . 'config']);
		shell_exec("sudo echo 'net.ipv4.ip_unprivileged_port_start=0' > /etc/sysctl.d/50-allports-nonroot.conf && sudo sysctl --system 2> /dev/null");
	}

	private function fixNginxConfig(bool $rReload): bool {
		$rSSLConfig = file_get_contents(MAIN_HOME . 'bin/nginx/conf/ssl.conf');

		if (stripos($rSSLConfig, "\nssl_stapling on;\nssl_stapling_verify on;") !== false || stripos($rSSLConfig, "\r\nssl_stapling on;\r\nssl_stapling_verify on;") !== false) {
			file_put_contents(MAIN_HOME . 'bin/nginx/conf/ssl.conf', str_replace("\r\nssl_stapling on;\r\nssl_stapling_verify on;", '', str_replace("\nssl_stapling on;\nssl_stapling_verify on;", '', $rSSLConfig)));
			$rReload = true;
		}

		foreach (['http', 'https'] as $rType) {
			$rPortConfig = file_get_contents(MAIN_HOME . 'bin/nginx/conf/ports/' . $rType . '.conf');

			if (stripos($rPortConfig, ' reuseport') !== false) {
				file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/' . $rType . '.conf', str_replace(' reuseport', '', $rPortConfig));
				$rReload = true;
			}
		}

		return $rReload;
	}

	private function configureFileLimits(): void {
		// systemd 256+ (Debian 13) ships its defaults under /usr/lib/systemd and
		// leaves these absent: file() answered false, count() threw, and status
		// stopped there. They start with the section the keys belong to, as
		// systemd ignores an assignment outside one; a file without that header
		// (2.6.0 made one there) holds nothing systemd reads, and starts over.
		foreach (['system', 'user'] as $rName) {
			if (!preg_match('/^\[Manager\]/m', (string) @file_get_contents('/etc/systemd/' . $rName . '.conf'))) {
				@file_put_contents('/etc/systemd/' . $rName . '.conf', "[Manager]\n");
			}
		}
		$rFile = @file('/etc/systemd/system.conf') ?: [];
		$rHasHard = false;
		$rHasSoft = false;
		$counter = count($rFile);

		for ($i = 0; $i < $counter; $i++) {
			if (substr($rFile[$i], 0, 19) === 'DefaultLimitNOFILE=') {
				$rHasHard = true;
			}
			if (substr($rFile[$i], 0, 23) === 'DefaultLimitNOFILESoft=') {
				$rHasSoft = true;
			}
		}

		if (!$rHasHard || !$rHasSoft) {
			echo "Configuration\n------------------------------\n";
			echo "Increasing file limit... You need to reboot your system!\n\n";

			if (!$rHasHard) {
				shell_exec('sudo echo "' . "\n" . 'DefaultLimitNOFILE=1048576" >> "/etc/systemd/system.conf"');
				shell_exec('sudo echo "' . "\n" . 'DefaultLimitNOFILE=1048576" >> "/etc/systemd/user.conf"');
			}

			if (!$rHasSoft) {
				shell_exec('sudo echo "' . "\n" . 'DefaultLimitNOFILESoft=1048576" >> "/etc/systemd/system.conf"');
				shell_exec('sudo echo "' . "\n" . 'DefaultLimitNOFILESoft=1048576" >> "/etc/systemd/user.conf"');
			}
		}
	}

	private function removeInitScript(): void {
		if (file_exists('/etc/init.d/xc_vm')) {
			unlink('/etc/init.d/xc_vm');
			shell_exec('sudo systemctl daemon-reload');
		}
	}

	private function broadcastUpdateBinaries(array $rServers): void {
		$db = self::db();
		// Drop whatever an earlier run left queued, once — the same statement ran
		// per server before. Only MAIN is queued: `binaries` needs
		// bin/install/update_binaries.sh, which the LB build strips, so every LB
		// answered "Updater script not found" and wrote a syslog row for it. LB
		// runtimes are refreshed by the install flow, and fanout_binary/xcvm_core
		// keep themselves current on every node (UpdateCommand).
		$db->query('DELETE FROM `signals` WHERE `custom_data` = ?;', json_encode(['action' => 'update_binaries']));
		NodeActions::updateBinaries(SERVER_ID, $db);
	}

	private function configureRedis(): void {
		$db = self::db();
		// Run as root in a tree xc_vm owns: a link in the config's place would
		// have root read and rewrite whatever it points to.
		if (is_link(MAIN_HOME . 'bin/redis') || is_link(MAIN_HOME . 'bin/redis/redis.conf')) {
			echo "WARNING: bin/redis/redis.conf is a symlink, not configuring Redis\n";
			return;
		}
		$rConfig = file_get_contents(MAIN_HOME . 'bin/redis/redis.conf');
		$rWrite = false;
		if (stripos($rConfig, "\nsave 60 1000") === false) {
			$rWrite = true;
			$rConfig .= "\nsave 60 1000";
			$rConfig = str_replace('stop-writes-on-bgsave-error yes', 'stop-writes-on-bgsave-error no', $rConfig);
			echo "Turning Redis Snapshots On!\n\n";
		}
		if (stripos($rConfig, 'stop-writes-on-bgsave-error yes') !== false) {
			$rWrite = true;
			$rConfig = str_replace('stop-writes-on-bgsave-error yes', 'stop-writes-on-bgsave-error no', $rConfig);
			echo "Disabling failed write lock on Redis\n\n";
		}
		if (stripos($rConfig, "\nserver-threads") === false) {
			$rWrite = true;
			$rConfig .= "\nserver-threads 4\nserver-thread-affinity true";
			echo "Enabling multithreading on Redis\n\n";
		}
		[$rConfig, $rDisabled] = RedisConfigHardening::apply($rConfig);
		if ($rDisabled !== []) {
			$rWrite = true;
			echo 'Disabling unused Redis admin commands (' . implode(', ', $rDisabled) . "), effective on the next Redis restart\n\n";
		}
		$rPassword = trim(explode("\n", explode("\nrequirepass ", $rConfig)[1])[0]);
		if ($rPassword === '#PASSWORD#') {
			$rWrite = true;
			$rPassword = $this->generateString(512);
			$rConfig = str_replace('#PASSWORD#', $rPassword, $rConfig);
			echo "Generating a new Redis password\n\n";
		}
		if ($rWrite) {
			file_put_contents(MAIN_HOME . 'bin/redis/redis.conf', $rConfig);
		}
		$db->query('SELECT `redis_password` FROM `settings`;');
		if ($db->get_row()['redis_password'] !== $rPassword) {
			echo "Updating Redis password in database\n";
			$db->query('UPDATE `settings` SET `redis_password` = ?;', $rPassword);
		}
		// \XC_VM::redis_connect() reads its credentials from config.enc only —
		// without this sync a rotated requirepass leaves the stored auth stale
		// and every panel Redis connection fails with NOAUTH.
		if (method_exists('XC_VM', 'config_set_redis')) {
			if (!\XC_VM::config_set_redis('127.0.0.1', 6379, $rPassword)) {
				echo "WARNING: failed to sync the Redis password into config.enc\n";
			}
		}
	}

	/**
	 * Point this LB node's xcvm_core Redis config at the MAIN server's Redis.
	 *
	 * The MAIN server owns the shared Redis (its configureRedis generates the
	 * password and stores it in `settings`). An LB has no local Redis, so it must
	 * connect to MAIN's — at the same host it already uses for MySQL. We only READ
	 * the shared password here (never rotate it — that is MAIN's job) and write the
	 * host/password into the local config.enc so `\XC_VM::redis_connect()` reaches
	 * the right server instead of refusing on 127.0.0.1.
	 *
	 * @param array<int,array<string,mixed>> $rServers Servers keyed by id.
	 */
	private function configureRedisLb(array $rServers): void {
		$db = self::db();
		if (!method_exists('XC_VM', 'config_set_redis')) {
			return;
		}

		$rHost = self::mainRedisHost($rServers);
		if ($rHost === null) {
			echo "WARNING: could not resolve the main server's IP for Redis\n";
			return;
		}

		$db->query('SELECT `redis_password` FROM `settings`;');
		$rPassword = $db->get_row()['redis_password'] ?? '';
		if ($rPassword === '' || $rPassword === '#PASSWORD#') {
			echo "WARNING: no shared Redis password available yet\n";
			return;
		}

		if (!\XC_VM::config_set_redis($rHost, 6379, $rPassword)) {
			echo "WARNING: failed to point config.enc Redis at the main server ({$rHost})\n";
		} else {
			echo "Redis pointed at main server {$rHost}:6379\n";
		}
	}

	/**
	 * The address a load balancer reaches MAIN's Redis on: MAIN's `private_ip`
	 * when it has one, else its `server_ip` — the raw column, never a URL built
	 * from it (`private_url_ip` is `http://<ip>:<port>/`). Null when MAIN's row
	 * is missing or neither is an IP address or a plain host name.
	 *
	 * @param array<int, array<string, mixed>> $rServers `servers` rows
	 */
	public static function mainRedisHost(array $rServers): ?string {
		foreach ($rServers as $rServer) {
			if (empty($rServer['is_main'])) {
				continue;
			}
			foreach (['private_ip', 'server_ip'] as $rKey) {
				$rHost = trim((string) ($rServer[$rKey] ?? ''));
				if ($rHost !== '' && (filter_var($rHost, FILTER_VALIDATE_IP) !== false || preg_match('/^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$/', $rHost) === 1)) {
					return $rHost;
				}
			}
			return null;
		}
		return null;
	}

	private function printStatusReport(array $rServers): void {
		$db = self::db();
		global $rSettings;

		$db->query('UPDATE `servers` SET `is_main` = 0 WHERE `id` <> ?;', SERVER_ID);
		$db->query('UPDATE `servers` SET `is_main` = 1 WHERE `id` = ?;', SERVER_ID);
		$db->query('UPDATE `settings` SET `status_uuid` = ?;', md5(XC_VM_VERSION));

		if (stripos($rSettings['server_name'], 'xtream') !== false || stripos($rSettings['server_name'], 'zapx') !== false || stripos($rSettings['server_name'], 'streamcreed') !== false) {
			$db->query("UPDATE `settings` SET `server_name` = 'XC_VM';");
		}

		$db->query('SELECT * FROM `access_codes` WHERE `enabled` = 1 AND `type` = 0;');
		$rCodeCount = 0;
		echo "Access Codes\n------------------------------\n";

		foreach ($db->get_rows() as $rRow) {
			$rExists = file_exists(MAIN_HOME . 'bin/nginx/conf/codes/' . $rRow['code'] . '.conf');
			if ($rExists) {
				$rCodeCount++;
			}
			echo $rServers[SERVER_ID]['site_url'] . $rRow['code'] . "/\n";
		}

		if ($rCodeCount === 0) {
			echo "No access codes available!\nGenerate a rescue code using tools\n\n";
		} else {
			echo "\n";
		}

		$db->query('SELECT COUNT(`id`) AS `count` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `status` = 1 AND `users_groups`.`is_admin` = 1;');
		if ($db->get_row()['count'] == 0) {
			echo "No administrator users are available on the system.\nGenerate a rescue user with tools\n\n";
		}

		echo "Servers\n------------------------------\n";
		$rOffline = 0;

		foreach ($rServers as $rServerID => $rServer) {
			if ($rServerID !== SERVER_ID) {
				if (!$rServer['server_online'] && $rServer['enabled'] && $rServer['status'] != 3 && $rServer['status'] != 5) {
					echo 'Server #' . $rServerID . ' - ' . $rServer['server_ip'] . ' - Down since: ' . ($rServer['last_check_ago'] ? date('Y-m-d H:i:s', $rServer['last_check_ago']) : 'Always') . "\n";
					$rOffline++;
				}
			}
		}

		if ($rOffline === 0) {
			echo "All servers are Online and reporting back to XC_VM!\n\n";
		} else {
			echo "\n";
		}

		echo 'Installed Version: ' . $rServers[SERVER_ID]['xc_vm_version'] . "\n------------------------------\n";
		$rMismatch = 0;

		foreach ($rServers as $rServerID => $rServer) {
			if ($rServerID !== SERVER_ID) {
				if ($rServer['server_type'] == 0 && $rServer['xc_vm_version'] && $rServer['xc_vm_version'] !== $rServers[SERVER_ID]['xc_vm_version']) {
					echo 'Server #' . $rServerID . ' - ' . $rServer['server_ip'] . ' - Running v' . $rServer['xc_vm_version'] . "\n";
					$rMismatch++;
				}
			}
		}

		if ($rMismatch === 0) {
			echo "All servers are up to date!\n\n";
		} else {
			echo "\n";
		}
	}

	private function generateString(int $rLength = 10): string {
		$rCharacters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789qwertyuiopasdfghjklzxcvbnm';
		$rString = '';
		$rMax = strlen($rCharacters) - 1;

		for ($i = 0; $i < $rLength; $i++) {
			$rString .= $rCharacters[random_int(0, $rMax)];
		}

		return $rString;
	}
}
