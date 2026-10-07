<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\Commands\FfmpegBuildsCommand;
use XcVm\Cli\Commands\UpdateCommand;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Cluster\BlocklistChanges;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Core\Cluster\NodeCorePin;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\NodeStateSink;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\RootCredentials;
use XcVm\Core\Cluster\RootPin;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\PhpFpmPools;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Util\Encryption;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\DbAllowlist;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Streaming\Fanout\FanoutMode;

/**
 * RootSignalsCronJob — root signals cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RootSignalsCronJob implements CommandInterface {
	use CronTrait;

	private $rSaveIPTables = false;

	private $AutoUpdateServerIP = true;

	/** The start of the streams ramdisk's fstab line (install, LbInstallFlow). */
	public const RAMDISK_MOUNT = 'tmpfs /home/xc_vm/content/streams';

	/**
	 * Tests: runs the commands of the artefact actions (module:install, the
	 * agent's restart), as argv lists, instead of run(); null restores it.
	 *
	 * @var (callable(list<string>): array{0: int, 1: string})|null
	 */
	private static $rRunner = null;

	public function getName(): string {
		return 'cron:root_signals';
	}

	public function getDescription(): string {
		return 'Cron: process signals, iptables, nginx, service management (root)';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsRoot()) {
			return 1;
		}

		set_time_limit(0);
		register_shutdown_function([$this, 'shutdown']);

		ProcessManager::exitIfCronLockHeld(ProcessManager::legacyCronLockPath(static::class, SettingsManager::get('live_streaming_pass')));
		$this->rIdentifier = ProcessManager::cronLockPath(static::class);
		ProcessManager::acquireCronLock($this->rIdentifier);

		// A title of its own: it used to take the xc_vm signals daemon's,
		// and killed that daemon every minute (and cron:servers, seeing this
		// run under the daemon's title, did not start it again).
		$pids = shell_exec("pgrep -f 'XC_VM\[RootSignals\]'");
		if (!empty($pids)) {
			shell_exec("sudo kill -9 $pids");
		}
		cli_set_process_title('XC_VM[RootSignals]');
		file_put_contents(CONFIG_PATH . 'signals.last', time());

		$this->loadCron();

		return 0;
	}

	private function blockip($rIP): bool {
		$isPrivate = false;

		if (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$isPrivate = filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
			$isPrivate = !$isPrivate;

			if (!$isPrivate) {
				$isPrivate = (strpos($rIP, '127.') === 0) || ($rIP === '0.0.0.0');
			}

			if (!$isPrivate) {
				exec('sudo iptables -I INPUT -s ' . escapeshellcmd($rIP) . ' -j DROP');
			}
		} elseif (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$isPrivate = (strpos($rIP, 'fc') === 0 ||
				strpos($rIP, 'fd') === 0 ||
				strpos($rIP, 'fe80') === 0 ||
				$rIP === '::1' ||
				strpos($rIP, '2001:db8') === 0);

			if (!$isPrivate) {
				exec('sudo ip6tables -I INPUT -s ' . escapeshellcmd($rIP) . ' -j DROP');
			}
		}
		if (!$isPrivate && $rIP) {
			touch(FLOOD_TMP_PATH . 'block_' . $rIP);
			return true;
		}

		if ($isPrivate) {
			error_log("Block attempt denied for private IP: " . $rIP);
			return false;
		}

		return false;
	}

	private function unblockip($rIP): void {
		if (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			exec('sudo iptables -D INPUT -s ' . escapeshellcmd($rIP) . ' -j DROP');
		} elseif (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			exec('sudo ip6tables -D INPUT -s ' . escapeshellcmd($rIP) . ' -j DROP');
		}
		if (file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
			unlink(FLOOD_TMP_PATH . 'block_' . $rIP);
		}
	}

	/** Flush the panel's blocks from iptables and the flood guard's block files (protected: a test records the call). */
	protected function flushIPs(): void {
		$this->unblockAll();
	}

	/**
	 * Remove every block blockip() added, and the flood guard's block files.
	 * The host's other rules, chains and policies are not the panel's and
	 * stay: an operator's DROP policy with its ACCEPT rules, ufw's and
	 * Docker's chains, the DB allowlist's. One commit per address family, so
	 * a long list is gone in seconds; rule by rule, as the minute's sync
	 * removes them, where the tool refuses the list.
	 */
	public function unblockAll(): void {
		foreach (['iptables', 'ip6tables'] as $rTool) {
			$rRules = [];
			if ($rTool === 'iptables') {
				exec('sudo iptables -S INPUT', $rRules);
			} else {
				exec('sudo ip6tables -S INPUT', $rRules);
			}
			$rIPs = self::ownBlocks($rRules);
			if ($rIPs && !$this->unblockTogether($rTool, $rIPs)) {
				foreach ($rIPs as $rIP) {
					$this->unblockip($rIP);
				}
			}
		}
		// Root runs this (this cron, cluster:root, `tools flush`): no file is another user's to keep.
		foreach (glob(FLOOD_TMP_PATH . 'block_*') ?: [] as $rFile) {
			@unlink($rFile);
		}
	}

	/**
	 * Remove these blocks in one commit: `--noflush` leaves every other rule
	 * and chain alone. False when the tool refuses the list, and then none
	 * of it was applied.
	 *
	 * @param list<string> $rIPs from ownBlocks()
	 */
	private function unblockTogether(string $rTool, array $rIPs): bool {
		$rPipe = $rTool === 'iptables' ? popen('sudo iptables-restore --noflush', 'w') : popen('sudo ip6tables-restore --noflush', 'w');
		if (!is_resource($rPipe)) {
			return false;
		}
		// A refusal closes the pipe early: the exit status says so.
		@fwrite($rPipe, "*filter\n-D INPUT -s " . implode(" -j DROP\n-D INPUT -s ", $rIPs) . " -j DROP\nCOMMIT\n");
		return pclose($rPipe) === 0;
	}

	/**
	 * The addresses blockip() blocked, from `iptables -S INPUT` (or
	 * ip6tables'): one per `-A INPUT -s <address> -j DROP` rule, so an address
	 * blocked twice is there twice. A rule with a network (any prefix but the
	 * family's single-address one: /32 of an IPv6 address is a network),
	 * another match or another target is not the panel's.
	 *
	 * @param list<string> $rRules
	 * @return list<string>
	 */
	public static function ownBlocks(array $rRules): array {
		$rIPs = [];
		foreach ($rRules as $rRule) {
			if (preg_match('#^-A INPUT -s (\S+?)(?:/(\d+))? -j DROP$#', trim($rRule), $rMatch) && filter_var($rMatch[1], FILTER_VALIDATE_IP) && in_array($rMatch[2] ?? '', ['', str_contains($rMatch[1], ':') ? '128' : '32'], true)) {
				$rIPs[] = $rMatch[1];
			}
		}
		return $rIPs;
	}

	protected function saveiptables(): void {
		exec('sudo iptables-save && sudo ip6tables-save');
	}

	private function getBlockedIPs(): array {
		$rReturn = [];
		// INPUT only: blockip() writes nowhere else, and the DB allowlist's own
		// chain ends in a DROP that must not read as a banned 0.0.0.0/0.
		exec('sudo iptables -nL INPUT --line-numbers -t filter', $rLines);
		foreach ($rLines as $rLine) {
			$rLine = explode(' ', preg_replace('!\\s+!', ' ', $rLine));
			if (isset($rLine[1], $rLine[4]) && $rLine[1] == 'DROP') {
				$rReturn[] = $rLine[4];
			}
		}
		$rLines = '';
		exec('sudo ip6tables -nL INPUT --line-numbers -t filter', $rLines);
		foreach ($rLines as $rLine) {
			$rLine = explode(' ', preg_replace('!\\s+!', ' ', $rLine));
			if (isset($rLine[1], $rLine[3]) && $rLine[1] == 'DROP') {
				$rReturn[] = $rLine[3];
			}
		}
		return $rReturn;
	}

	private function getServerIP(?string $interface = null): ?string {
		if ($interface === null) {
			$route = shell_exec('ip route show default 2>/dev/null');
			if ($route && preg_match('/dev\s+([^\s]+)/', $route, $m)) {
				$interface = $m[1];
			} else {
				return null;
			}
		}

		$output = shell_exec(
			'ip -j addr show ' . escapeshellarg($interface) . ' 2>/dev/null'
		);

		if (!$output) {
			return null;
		}

		$data = json_decode($output, true);
		return self::pickInterfaceIP($data[0]['addr_info'] ?? []);
	}

	/**
	 * The interface's first usable IPv4 address from `ip -j addr` addr_info.
	 * Loopback and other reserved ranges are skipped: on OpenVZ/Virtuozzo
	 * venet0 carries 127.0.0.1 first and the public address only on the
	 * venet0:0 alias, so taking the first inet entry rewrote server_ip to
	 * 127.0.0.1 every minute.
	 *
	 * @param list<array<string, mixed>> $rAddrInfo
	 */
	public static function pickInterfaceIP(array $rAddrInfo): ?string {
		foreach ($rAddrInfo as $rAddr) {
			$rIP = $rAddr['local'] ?? null;
			if (($rAddr['family'] ?? null) === 'inet' && filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE)) {
				return $rIP;
			}
		}
		return null;
	}

	/**
	 * The blocked addresses, distinct: MAIN's `blocked_ips`, or with the CONFIG
	 * flow on the replica's `blocked_ips` cache. Null when that cache is not
	 * there (yet), and on a node in mode 2 without CONFIG, which may not read
	 * MAIN's table: the sync then leaves iptables as it is.
	 *
	 * @return list<string>|null
	 */
	public static function blockedIPs(?object $rDb): ?array {
		if (NodeFlows::on(NodeFlows::CONFIG)) {
			$rCache = FileCache::getCache('blocked_ips');
			return is_array($rCache) ? array_values(array_unique(array_map('strval', $rCache))) : null;
		}
		if ($rDb === null || !self::readsMainDatabase() || !$rDb->query('SELECT `ip` FROM `blocked_ips`;')) {
			return null;
		}
		return array_map('strval', array_keys($rDb->get_rows(true, 'ip') ?: []));
	}

	/**
	 * MAIN's address on its interface changed (the automatic server_ip
	 * rewrite): store it, and announce it to the cluster nodes as an admin's
	 * edit is, keeping the old URL a while (ClusterEndpoint::
	 * recordMainChange()). Returns the row with the new address, so the
	 * caller cannot hand it an already updated row. Domain\Cluster is not in
	 * the LB build.
	 *
	 * @param array<string, mixed> $rServer MAIN's `servers` row before the change.
	 * @return array<string, mixed>
	 */
	public static function rewriteServerIP(object $rDb, int $rServerID, array $rServer, string $rServerIP): array {
		$rDb->query('UPDATE `servers` SET `server_ip` = ? WHERE `id` = ?;', $rServerIP, $rServerID);
		$rNew = ['server_ip' => $rServerIP] + $rServer;
		if (class_exists(ClusterEndpoint::class)) {
			ClusterEndpoint::recordMainChange($rServer, $rNew, SettingsManager::getAll(), 'system');
		}
		return $rNew;
	}

	/**
	 * /etc/fstab with the streams ramdisk mount switched on or off: the
	 * `tmpfs /home/xc_vm/content/streams …` line the installers write is
	 * commented out to disable it and uncommented to enable it. Every other
	 * line, and a line already in the wanted state, is left as it is.
	 */
	public static function ramdiskFstab(string $rFstab, bool $rEnable): string {
		$rOutput = [];
		foreach (explode("\n", $rFstab) as $rLine) {
			if ($rEnable && str_starts_with($rLine, '#' . self::RAMDISK_MOUNT)) {
				$rLine = ltrim($rLine, '#');
			} elseif (!$rEnable && str_starts_with($rLine, self::RAMDISK_MOUNT)) {
				$rLine = '#' . $rLine;
			}
			$rOutput[] = $rLine;
		}
		return implode("\n", $rOutput);
	}

	/**
	 * api_legacy.conf for this node, or null on MAIN. The legacy `/api` of a
	 * load balancer is served until nothing reads the node's files with
	 * `getFile` any more (DataPlane::legacyApiRetired): its own data plane
	 * on, and every server of the cluster, MAIN included, reading through
	 * `/xfile`. MAIN has no data-plane client yet, so this is 1 on every
	 * node today. MAIN's own `/api` keeps no toggle (ADR 0004, Phase 8): its
	 * nginx.conf neither includes the file nor guards `/api`, so the cron
	 * does not write it there — retiring MAIN's `/api` is a cluster-wide
	 * judgement, not a per-node flow.
	 */
	public static function apiLegacyConf(): ?string {
		if (NodeRole::isMain()) {
			return null;
		}
		return 'set $api_legacy ' . (DataPlane::legacyApiRetired() ? '0' : '1') . ';';
	}

	/**
	 * viewer_api.conf for this node, or null on MAIN: the viewer APIs
	 * (player_api, enigma2, xplugin, epg, playlist) answer 404 on every load
	 * balancer, whatever its mode. Their controllers need Domain/User, which
	 * the load balancer build strips as privileged (verify-lb-archive.sh), so
	 * routed there they ended in a fatal error; viewers' apps use MAIN's
	 * address for them (plan, D16). MAIN's nginx.conf has no such switch.
	 */
	public static function viewerApiConf(): ?string {
		if (NodeRole::isMain()) {
			return null;
		}
		return 'set $viewer_api 0;';
	}

	/** The nginx and nginx_rtmp reloads this pass's actions asked for (reloadAsked()). */
	private static bool $rReloadNginx = false;

	private static bool $rReloadRtmp = false;

	/**
	 * Apply the reloads this pass's actions asked for, once each. One save of
	 * a server's ports queues its HTTP, HTTPS and RTMP ports, and each used to
	 * reload nginx at once: reloads landed within the same second, and nginx
	 * aborted a worker (signal 6 in MAIN's error.log).
	 */
	public static function reloadAsked(): void {
		if (self::$rReloadRtmp) {
			self::run(['sudo', BIN_PATH . 'nginx_rtmp/sbin/nginx_rtmp', '-s', 'reload']);
		}
		if (self::$rReloadNginx) {
			self::run(['sudo', BIN_PATH . 'nginx/sbin/nginx', '-s', 'reload']);
		}
		self::$rReloadNginx = self::$rReloadRtmp = false;
	}

	/** Tests: run the artefact actions' argv lists through $rRunner (argv => [exit status, output]); null restores run(). */
	public static function useRunner(?callable $rRunner): void {
		self::$rRunner = $rRunner;
	}

	/**
	 * module:install's argv for an install_module payload, its payload one
	 * argument (base64 of the JSON). With the archive cluster:root staged
	 * and checked (ArtefactStage::current()), from that copy, with the
	 * checked grant (with its command's id) to check it again against; its
	 * exit status and output are the action's. Without one, the node pulls
	 * the archive the legacy way, and neither is. Either runs to its end
	 * before the action returns, as before (exec() read the old
	 * `… 2>&1 &` line's output until module:install closed it). A payload
	 * never names the archive itself (a `signals` row's `archive` and
	 * `artefact` are dropped).
	 *
	 * @param array<string, mixed> $rData {action: install_module, source, name, version, …}
	 * @param array{path: string, grant: array<string, mixed>}|null $rStaged
	 * @return list<string>
	 */
	public static function moduleInstallArgv(array $rData, ?array $rStaged): array {
		unset($rData['archive'], $rData['artefact']);
		if ($rStaged !== null) {
			$rData = ['archive' => $rStaged['path'], 'artefact' => $rStaged['grant']] + $rData;
		}
		return ['sudo', PHP_BIN, MAIN_HOME . 'console.php', 'module:install', base64_encode((string) json_encode($rData))];
	}

	/**
	 * Run a command from its argv list, with no shell in between: stdin
	 * /dev/null, stdout and stderr read as they come into one output (the
	 * old lines' `2>&1`) until both are closed, as exec() read its pipe, and
	 * returned as exec() returned it (lines without their trailing
	 * whitespace).
	 *
	 * @param list<string> $rArgv
	 * @return array{0: int, 1: string} [exit status, output]
	 */
	private static function run(array $rArgv): array {
		if (self::$rRunner !== null) {
			return (self::$rRunner)($rArgv);
		}
		// An argv list, no shell: sudo, the node's own PHP_BIN and console.php, module:install, then the payload as one base64 argument.
		// nosemgrep: php.lang.security.exec-use.exec-use
		$rProc = proc_open($rArgv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		if (!is_resource($rProc)) {
			return [127, 'cannot run ' . $rArgv[0]];
		}
		$rOutput = '';
		$rOpen = [1 => $rPipes[1], 2 => $rPipes[2]];
		while ($rOpen !== []) {
			$rReady = $rOpen;
			$rWrite = null;
			$rExcept = null;
			if (@stream_select($rReady, $rWrite, $rExcept, null) === false) {
				// The wait failed (a signal): the rest, one pipe after the other.
				foreach ($rOpen as $rPipe) {
					$rOutput .= (string) stream_get_contents($rPipe);
				}
				break;
			}
			foreach ($rReady as $rFd => $rPipe) {
				$rChunk = fread($rPipe, 8192);
				if ($rChunk === false || $rChunk === '') {
					fclose($rPipe);
					unset($rOpen[$rFd]);
					continue;
				}
				$rOutput .= $rChunk;
			}
		}
		foreach ($rOpen as $rPipe) {
			fclose($rPipe);
		}
		$rCode = proc_close($rProc);
		$rLines = explode("\n", $rOutput);
		if (end($rLines) === '') {
			array_pop($rLines);
		}
		return [$rCode, implode("\n", array_map(static fn(string $rLine): string => rtrim($rLine, " \t\n\r\v\f"), $rLines))];
	}

	/**
	 * Does MAIN send this node's root actions as signed `node.root` commands
	 * (the COMMANDS flow on, and root's own pin of the panel key in place)?
	 * MAIN then sends the blocklist flush that way too (NodeActions::send,
	 * ClusterRoute::root), and cluster:root runs it through executeAction()
	 * within a second; this cron no longer polls the signals table for it.
	 * A flush row queued before (or while MAIN lacked the pin) still runs,
	 * from the signals loop below.
	 */
	public static function rootCommandsFromMain(): bool {
		return NodeFlows::on(NodeFlows::COMMANDS) && RootPin::read() !== null;
	}

	/**
	 * Does this cron read MAIN's database: its `signals` table, the crontab
	 * table, and `blocked_ips` without CONFIG? Not on a node in mode 2
	 * (plan, section 10), whose connects are refused
	 * (NodeRole::refusesConnects): MAIN sends it root's actions as signed
	 * `node.root` commands, which cluster:root runs, and its replica says
	 * when its own row changed (replicaChecks()). MAIN, mode 0 and mode 1
	 * read it as before, `signals` rows MAIN queued before COMMANDS or
	 * root's pin included.
	 */
	public static function readsMainDatabase(): bool {
		return !NodeRole::refusesConnects();
	}

	/**
	 * The ramdisk, ports and services checks of a node that reads no
	 * `signals` row (mode 2). A `set_services`, `set_port` or `*_ramdisk` row
	 * made them run against the node's own row; MAIN sends those as
	 * `node.root` commands now, which cluster:root runs as they come. What
	 * tells the node that its row changed is its replica: the servers cache
	 * an apply built from the `servers` and `node` sections, whose ETags
	 * ReplicaApply::OWNED_CACHE records ($rTag, replicaServersTag()). Each
	 * time they change, and once after a reboot, the three checks run
	 * against that cache, as a row of each kind made them run, so a command
	 * that never arrived (it expired while the node was away) is made good.
	 * None while the replica does not own the servers cache.
	 *
	 * $rTag must be read before the servers the checks use (loadCron): an
	 * apply writes the cache, then its ETags, so those rows are at least as
	 * new as the ETags marked checked here, and ETags an apply records in
	 * between run the checks again the next minute.
	 *
	 * @return array{php: bool, services: bool, ports: bool, ramdisk: bool}
	 */
	private function replicaChecks(?string $rTag): array {
		$rCheck = ['php' => false, 'services' => false, 'ports' => false, 'ramdisk' => false];
		$rMarker = CRONS_TMP_PATH . 'replica_servers_checked';
		if ($rTag === null || (string) @file_get_contents($rMarker) === $rTag) {
			return $rCheck;
		}
		// Before the checks, as a signal row was deleted before it ran: at most once.
		@file_put_contents($rMarker, $rTag);
		return ['php' => false, 'services' => true, 'ports' => true, 'ramdisk' => true];
	}

	/**
	 * What the replica's servers cache was built from: its entry in
	 * ReplicaApply::OWNED_CACHE (`<servers ETag>/<node ETag>`). Null while
	 * the replica does not own that cache.
	 */
	private static function replicaServersTag(): ?string {
		$rOwned = ReplicaApply::owns(ReplicaSections::SERVERS) ? FileCache::getCache(ReplicaApply::OWNED_CACHE) : null;
		$rTag = is_array($rOwned) ? ($rOwned[ReplicaSections::SERVERS] ?? null) : null;
		return is_string($rTag) && $rTag !== '' ? $rTag : null;
	}

	private function loadCron(): void {
		global $db;
		$rReads = self::readsMainDatabase();
		// Before the servers the replica's checks use (replicaChecks()).
		$rServersTag = $rReads ? null : self::replicaServersTag();
		$rServers = ServerRepository::getAll(true);
		$rFlush = false;
		if ($rReads && !self::rootCommandsFromMain()) {
			$db->query("SELECT `signal_id` FROM `signals` WHERE `server_id` = ? AND `custom_data` = '{\"action\":\"flush\"}' AND `cache` = 0;", SERVER_ID);
			$rFlush = $db->num_rows() > 0;
		}
		if ($rFlush) {
			echo "Flushing IP's...";
			$this->flushIPs();
			$this->saveiptables();
			if (!LogSink::syslog('FLUSH', 'Flushed blocked IP\'s from iptables.')) {
				$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'FLUSH', 'Flushed blocked IP\\'s from iptables.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
			}
			$db->query("DELETE FROM `signals` WHERE `server_id` = ? AND `custom_data` = '{\"action\":\"flush\"}' AND `cache` = 0;", SERVER_ID);
		} else {
			// Auto-unban: on MAIN only, drop expired automatic IP bans (flood/
			// bruteforce) so the sync below removes them from iptables. Manual admin
			// bans (any other notes) are left permanent.
			$rUnbanSettings = SettingsManager::getAll();
			if (!empty($rServers[SERVER_ID]['is_main']) && !empty($rUnbanSettings['auto_unban_ip'])) {
				$rUnbanMul = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];
				$rUnbanUnit = (string) ($rUnbanSettings['ban_duration_unit'] ?? 'hours');
				$rUnbanSecs = max(1, intval($rUnbanSettings['ban_duration_value'] ?? 24)) * ($rUnbanMul[$rUnbanUnit] ?? 3600);
				$rUnbanWhere = "`date` < ? AND (UPPER(`notes`) LIKE '%ATTACK%' OR UPPER(`notes`) LIKE '%BRUTEFORCE%' OR UPPER(`notes`) LIKE '%FLOOD%')";
				$rUnbanBefore = time() - $rUnbanSecs;
				$db->query('SELECT `ip` FROM `blocked_ips` WHERE ' . $rUnbanWhere . ';', $rUnbanBefore);
				$rUnbanned = array_column($db->get_rows() ?: [], 'ip');
				if ($rUnbanned !== []) {
					$db->query('DELETE FROM `blocked_ips` WHERE ' . $rUnbanWhere . ';', $rUnbanBefore);
					BlocklistChanges::del('ip', $rUnbanned, $db);
				}
			}

			$rSyncMarker = CRONS_TMP_PATH . 'blocked_ips_sync_marker';
			// The addresses iptables must block: MAIN's table, or with the
			// CONFIG flow on the node replica's cache (cluster:apply). No cache
			// yet means nothing to sync, never "unblock everything".
			$rBlocked = self::blockedIPs($db);
			$rRunFullSync = $rBlocked !== null;
			$rCurrentIPCount = count($rBlocked ?? []);

			if ($rRunFullSync && file_exists($rSyncMarker)) {
				$rLastSyncData = json_decode(@file_get_contents($rSyncMarker), true);
				if (is_array($rLastSyncData) && isset($rLastSyncData['count'], $rLastSyncData['time'])) {
					if (intval($rLastSyncData['count']) == $rCurrentIPCount && (time() - intval($rLastSyncData['time'])) < 300) {
						$rRunFullSync = false;
					}
				}
			}

			if ($rRunFullSync) {
				$rActualBlocked = $this->getBlockedIPs();
				$rActualBlockedFlip = array_flip($rActualBlocked);
				$rBlockedFlip = array_flip($rBlocked);
				$rAdd = $rDel = [];
				foreach (array_count_values($rActualBlocked) as $rIP => $rCount) {
					if ($rCount > 1) {
						echo $rCount . "\n";
						foreach (range(1, $rCount - 1) as $i) {
							$rDel[] = $rIP;
						}
					}
				}
				foreach ($rBlocked as $rIP) {
					if (!isset($rActualBlockedFlip[$rIP])) {
						$rAdd[] = $rIP;
					}
				}
				foreach ($rActualBlocked as $rIP) {
					if (!isset($rBlockedFlip[$rIP])) {
						$rDel[] = $rIP;
					}
				}
				if (count($rDel) > 0) {
					$this->rSaveIPTables = true;
					foreach ($rDel as $rIP) {
						echo 'Unblock IP: ' . $rIP . "\n";
						$this->unblockip($rIP);
					}
				}
				if (count($rAdd) > 0) {
					$this->rSaveIPTables = true;
					foreach ($rAdd as $rIP) {
						echo 'Block IP: ' . $rIP . "\n";
						$this->blockip($rIP);
					}
				}
				if ($this->rSaveIPTables) {
					$this->saveiptables();
					$this->rSaveIPTables = false;
				}
				@file_put_contents($rSyncMarker, json_encode(['count' => $rCurrentIPCount, 'time' => time()]));
			}
		}
		$rReload = false;
		$rApiLegacyConf = self::apiLegacyConf();
		if ($rApiLegacyConf !== null && $rApiLegacyConf !== (trim(@file_get_contents(BIN_PATH . 'nginx/conf/api_legacy.conf')) ?: '')) {
			echo 'Updating the legacy /api toggle...' . "\n";
			file_put_contents(BIN_PATH . 'nginx/conf/api_legacy.conf', $rApiLegacyConf);
			$rReload = true;
		}
		$rViewerApiConf = self::viewerApiConf();
		if ($rViewerApiConf !== null && $rViewerApiConf !== (trim(@file_get_contents(BIN_PATH . 'nginx/conf/viewer_api.conf')) ?: '')) {
			echo 'Updating the viewer API toggle...' . "\n";
			file_put_contents(BIN_PATH . 'nginx/conf/viewer_api.conf', $rViewerApiConf);
			$rReload = true;
		}
		$rMinistraLegacyConf = 'set $ministra_legacy_redirect ' . (SettingsManager::get('mag_legacy_redirect') ? '1' : '0') . ';';
		$rCurrentMinistraLegacyConf = (trim(@file_get_contents(BIN_PATH . 'nginx/conf/ministra_legacy.conf')) ?: '');
		if ($rMinistraLegacyConf != $rCurrentMinistraLegacyConf) {
			echo 'Updating Ministra legacy /c toggle...' . "\n";
			file_put_contents(BIN_PATH . 'nginx/conf/ministra_legacy.conf', $rMinistraLegacyConf);
			$rReload = true;
		}
		$rAllowedIPs = ServerRepository::getAllowedIPs();
		$rXC_VMList = [];
		foreach ($rAllowedIPs as $rIP) {
			if (!empty($rIP) && filter_var($rIP, FILTER_VALIDATE_IP)) {
				$newEntry = 'set_real_ip_from ' . $rIP . ';';
				if (!in_array($newEntry, $rXC_VMList)) {
					$rXC_VMList[] = $newEntry;
				}
			}
		}
		$rXC_VMList = trim(implode("\n", array_unique($rXC_VMList)));
		$rCurrentList = (trim(file_get_contents(BIN_PATH . 'nginx/conf/realip_xc_vm.conf')) ?: '');
		if ($rXC_VMList != $rCurrentList) {
			echo 'Updating XC_VM IP List...' . "\n";
			file_put_contents(BIN_PATH . 'nginx/conf/realip_xc_vm.conf', $rXC_VMList);
			$rReload = true;
		}
		$rCurrentList = (trim(file_get_contents(BIN_PATH . 'nginx/conf/realip_cloudflare.conf')) ?: '');
		if (SettingsManager::get('cloudflare')) {
			if (empty($rCurrentList)) {
				echo 'Enabling Cloudflare...' . "\n";
				file_put_contents(BIN_PATH . 'nginx/conf/realip_cloudflare.conf', 'set_real_ip_from 103.21.244.0/22;' . "\n" . 'set_real_ip_from 103.22.200.0/22;' . "\n" . 'set_real_ip_from 103.31.4.0/22;' . "\n" . 'set_real_ip_from 104.16.0.0/13;' . "\n" . 'set_real_ip_from 104.24.0.0/14;' . "\n" . 'set_real_ip_from 108.162.192.0/18;' . "\n" . 'set_real_ip_from 131.0.72.0/22;' . "\n" . 'set_real_ip_from 141.101.64.0/18;' . "\n" . 'set_real_ip_from 162.158.0.0/15;' . "\n" . 'set_real_ip_from 172.64.0.0/13;' . "\n" . 'set_real_ip_from 173.245.48.0/20;' . "\n" . 'set_real_ip_from 188.114.96.0/20;' . "\n" . 'set_real_ip_from 190.93.240.0/20;' . "\n" . 'set_real_ip_from 197.234.240.0/22;' . "\n" . 'set_real_ip_from 198.41.128.0/17;' . "\n" . 'set_real_ip_from 2400:cb00::/32;' . "\n" . 'set_real_ip_from 2606:4700::/32;' . "\n" . 'set_real_ip_from 2803:f800::/32;' . "\n" . 'set_real_ip_from 2405:b500::/32;' . "\n" . 'set_real_ip_from 2405:8100::/32;' . "\n" . 'set_real_ip_from 2c0f:f248::/32;' . "\n" . 'set_real_ip_from 2a06:98c0::/29;');
				$rReload = true;
			}
		} else {
			if (!empty($rCurrentList)) {
				echo 'Disabling Cloudflare...' . "\n";
				file_put_contents(BIN_PATH . 'nginx/conf/realip_cloudflare.conf', '');
				$rReload = true;
			}
		}
		if ($rServers[SERVER_ID]['is_main']) {
			$rCurrentStatus = stripos((trim(file_get_contents(BIN_PATH . 'nginx/conf/gzip.conf')) ?: 'gzip off'), 'gzip on') !== false;
			if ($rServers[SERVER_ID]['enable_gzip']) {
				if (!$rCurrentStatus) {
					echo 'Enabling GZIP...' . "\n";
					file_put_contents(BIN_PATH . 'nginx/conf/gzip.conf', 'gzip on;' . "\n" . 'gzip_min_length 1000;' . "\n" . 'gzip_buffers 4 32k;' . "\n" . 'gzip_proxied any;' . "\n" . 'gzip_types application/json application/xml;' . "\n" . 'gzip_vary on;' . "\n" . 'gzip_disable "MSIE [1-6].(?!.*SV1)";');
					$rReload = true;
				}
			} else {
				if ($rCurrentStatus) {
					echo 'Disabling GZIP...' . "\n";
					file_put_contents(BIN_PATH . 'nginx/conf/gzip.conf', 'gzip off;');
					$rReload = true;
				}
			}

			$rServerIP = $this->getServerIP(($rServers[SERVER_ID]['network_interface'] == 'auto' ? null : $rServers[SERVER_ID]['network_interface']));
			if ($rServerIP && $rServerIP != $rServers[SERVER_ID]['server_ip'] && $this->AutoUpdateServerIP) {
				echo 'Updating server IP from ' . $rServers[SERVER_ID]['server_ip'] . ' to ' . $rServerIP . '...' . "\n";
				$rServers[SERVER_ID] = self::rewriteServerIP($db, SERVER_ID, $rServers[SERVER_ID], $rServerIP);
			}

			if (empty(SettingsManager::get('live_streaming_pass'))) {
				$db->query('UPDATE `settings` SET `live_streaming_pass` = ?', Encryption::randomString(40));
			}
		}

		// xc_fanout keepalive supervisor — ensure run.sh itself is alive. It is
		// the daemon's Restart=always loop (respawns the daemon within ~2s of any
		// exit, SIGKILL included), launched once by `service boot`. If IT dies
		// (OOM, a stray kill) the daemon is left unsupervised and never comes back
		// after its next exit — nothing else re-launches the loop (fanout_binary
		// only pkills the daemon and relies on it; StartupCommand only fixes its
		// mode). Re-launch it here every minute when absent — a cheap pgrep,
		// idempotent regardless (run.sh holds an flock single-instance guard, so
		// any racing duplicate supervisor exits at once), run as xc_vm to
		// match `service boot`. Closes the "supervisor died → fanout stays down"
		// gap. Runs on every node (main + LB), like the daemon self-heal below.
		//
		// Fanout switched off (settings.fanout_enabled = 0, FanoutMode): write the
		// flag the shell scripts check, stop the supervisor and the daemon, and
		// skip the keepalive and the binary self-heal below. Switched back on:
		// the flag goes and the keepalive starts the supervisor again.
		$rFanoutEnabled = FanoutMode::enabled();
		if (FanoutMode::applyToNode($rFanoutEnabled)) {
			echo 'xc_fanout ' . ($rFanoutEnabled ? 'enabled' : 'disabled: daemon stopped') . "\n";
		}
		// Literal commands (no string building): the supervisors live at the fixed
		// deploy path, as `service` and run.sh themselves assume.
		if ($rFanoutEnabled && is_file('/home/xc_vm/bin/xc_fanout/run.sh') && trim((string) shell_exec('pgrep -u xc_vm -f /home/xc_vm/bin/xc_fanout/run.sh 2>/dev/null')) === '') {
			shell_exec('sudo -u xc_vm bash /home/xc_vm/bin/xc_fanout/run.sh >/dev/null 2>&1 &');
		}

		// Cluster agent keepalive, on nodes enrolled in the cluster API and on MAIN
		// with its data-plane client on (main.json): the same supervisor pattern
		// as xc_fanout. Not after MAIN stopped the node (run.sh writes `stopped`
		// when the agent exits 3); re-enrolment clears it.
		if ((is_file('/home/xc_vm/config/cluster/main.json') || (is_file('/home/xc_vm/config/cluster/agent.json') && !file_exists('/home/xc_vm/bin/xc_agent/stopped'))) && is_file('/home/xc_vm/bin/xc_agent/run.sh')
			&& trim((string) shell_exec('pgrep -u xc_vm -f /home/xc_vm/bin/xc_agent/run.sh 2>/dev/null')) === ''
		) {
			shell_exec('sudo -u xc_vm bash /home/xc_vm/bin/xc_agent/run.sh >/dev/null 2>&1 &');
		}

		// xc_fanout daemon and xc_agent binaries — keep them installed and
		// current (ADR 0003, Phase G; ADR 0004). Every node takes both from their
		// GitHub release itself, whatever its cluster mode: MAIN never hands
		// them out. Nothing else pulls them: not the installer, not
		// UpdateCommand, so a fresh node/LB would never get the daemon and an
		// updated panel would keep an old one. fanout_binary is idempotent
		// (downloads only on a version mismatch, and only when GitHub is
		// reachable), so it is safe to poll. Throttle the check to ~hourly via a
		// stamp, but the first pass (stamp absent) runs immediately so a fresh
		// install/LB gets them within a minute; the running process is respawned
		// by fanout_binary on an actual upgrade. Root context (this cron) is
		// required — it installs into bin/ and chowns. With fanout off, the
		// agent alone.
		$rFanoutStamp = CRONS_TMP_PATH . 'fanout_binary_check';
		// Ten minutes while the daemon is wanted and not there yet (fanout just switched on, or a download that failed).
		$rFanoutWait = $rFanoutEnabled && !is_executable(MAIN_HOME . 'bin/xc_fanout/xc_fanout') ? 600 : 3600;
		if (!file_exists($rFanoutStamp) || time() - intval(@file_get_contents($rFanoutStamp) ?: 0) > $rFanoutWait) {
			file_put_contents($rFanoutStamp, time());
			ProcessRunner::start($rFanoutEnabled ? [PHP_BIN, MAIN_HOME . 'console.php', 'fanout_binary'] : [PHP_BIN, MAIN_HOME . 'console.php', 'fanout_binary', 'agent']);
		}

		// xcvm_core PHP extension — same self-heal rationale as the daemon above.
		// The extension is mirrored into the binaries repo tree decoupled from the
		// heavy runtime bundle, so nothing else keeps it current: a fresh LB (or a
		// node on an older extension) would never converge on its own. xcvm_core is
		// idempotent (version-compared, downloads only on a mismatch) and installs
		// with a load-test + rollback, so it is safe to poll ~hourly; the first
		// pass runs immediately. This is what delivers config_set_redis to LB nodes,
		// without which StatusCommand::configureRedisLb cannot point Redis at main.
		$rCoreStamp = CRONS_TMP_PATH . 'xcvm_core_check';
		if (!file_exists($rCoreStamp) || time() - intval(@file_get_contents($rCoreStamp) ?: 0) > 3600) {
			file_put_contents($rCoreStamp, time());
			ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'xcvm_core']);
		}

		// yt-dlp — same self-heal rationale. It is a static bundled binary that
		// resolves media URLs (StreamUtils) and nothing else keeps it current, so
		// it goes stale between panel releases and breaks extraction. The `ytdlp`
		// command is idempotent (version-compared against the upstream release,
		// downloads only on a mismatch, SHA-verified + run-tested before an atomic
		// swap), so it is safe to poll. Daily is enough (yt-dlp releases ~weekly);
		// the first pass (stamp absent) runs immediately. Runs on every node that
		// has the binary (main + LB).
		$rYtDlpStamp = CRONS_TMP_PATH . 'ytdlp_check';
		if (!file_exists($rYtDlpStamp) || time() - intval(@file_get_contents($rYtDlpStamp) ?: 0) > 86400) {
			file_put_contents($rYtDlpStamp, time());
			ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'ytdlp']);
		}

		// ffmpeg — the builds for this distribution from XC_VM_FFMPEG (`ffmpeg`,
		// FfmpegBuildsCommand): same self-heal rationale. Idempotent (an unchanged
		// release is not fetched again) and each build is run-tested before it
		// replaces the installed one, so it is safe to poll. Daily is enough (rare
		// releases); the first pass runs immediately. As xc_vm, which owns
		// bin/ffmpeg_bin/: nothing of it needs root. A run that did not finish
		// (one this cron's own service restart below killed, GitHub unreachable,
		// a build that failed) leaves its index older than the stamp, and is
		// tried again after an hour.
		$rFfmpegStamp = CRONS_TMP_PATH . 'ffmpeg_check';
		$rFfmpegLast = intval(@file_get_contents($rFfmpegStamp) ?: 0);
		if (time() - $rFfmpegLast > ((int) @filemtime(FfmpegBuildsCommand::indexPath()) >= $rFfmpegLast ? 86400 : 3600)) {
			file_put_contents($rFfmpegStamp, time());
			ProcessRunner::start(['sudo', '-u', 'xc_vm', PHP_BIN, MAIN_HOME . 'console.php', 'ffmpeg']);
		}

		if ($rServers[SERVER_ID]['limit_requests'] > 0) {
			$rLimitConf = 'limit_req_zone global zone=two:10m rate=' . intval($rServers[SERVER_ID]['limit_requests']) . 'r/s;';
		} else {
			$rLimitConf = '';
		}
		$rCurrentConf = (trim(file_get_contents(BIN_PATH . 'nginx/conf/limit.conf')) ?: '');
		if ($rLimitConf != $rCurrentConf) {
			echo 'Updating rate limit...' . "\n";
			file_put_contents(BIN_PATH . 'nginx/conf/limit.conf', $rLimitConf);
			$rReload = true;
		}
		if ($rServers[SERVER_ID]['limit_requests'] > 0) {
			$rLimitConf = 'limit_req zone=two burst=' . intval($rServers[SERVER_ID]['limit_burst']) . ';';
		} else {
			$rLimitConf = '';
		}
		$rCurrentConf = (trim(file_get_contents(BIN_PATH . 'nginx/conf/limit_queue.conf')) ?: '');
		if ($rLimitConf != $rCurrentConf) {
			echo 'Updating rate limit queue...' . "\n";
			file_put_contents(BIN_PATH . 'nginx/conf/limit_queue.conf', $rLimitConf);
			$rReload = true;
		}
		if ($rReload) {
			self::$rReloadNginx = true; // once, after this pass's actions (reloadAsked())
		}
		if (SettingsManager::get('restart_php_fpm')) {
			$rPHP = count(glob(BIN_PATH . 'php/sockets/*.pid') ?: []);
			$rNginx = 0;
			foreach (glob('/proc/*/cmdline') ?: [] as $rCmdFile) {
				$rRaw = @file_get_contents($rCmdFile);
				if ($rRaw && strpos(str_replace("\0", ' ', $rRaw), 'nginx: master') !== false) {
					$rNginx++;
				}
			}
			if ($rNginx > 0) {
				if ($rPHP == 0) {
					echo 'PHP-FPM ERROR - Restarting...';
					if (!LogSink::syslog('PHP-FPM', 'Restarted PHP-FPM instances due to a suspected crash.')) {
						$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'PHP-FPM', 'Restarted PHP-FPM instances due to a suspected crash.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
					}
					shell_exec('sudo systemctl stop xc_vm');
					shell_exec('sudo systemctl start xc_vm');
					exit();
				}
			}
			$rCurlMarker = CRONS_TMP_PATH . 'fpm_curl_check';
			if (!file_exists($rCurlMarker) || (time() - filemtime($rCurlMarker)) >= 300) {
				@touch($rCurlMarker);
				$rHandle = curl_init('http://127.0.0.1:' . $rServers[SERVER_ID]['http_broadcast_port'] . '/init');
				curl_setopt($rHandle, CURLOPT_RETURNTRANSFER, true);
				curl_exec($rHandle);
				$rCode = curl_getinfo($rHandle, CURLINFO_HTTP_CODE);
				if (!in_array($rCode, [500, 502])) {
					curl_close($rHandle);
				} else {
					echo $rCode . ' ERROR - Restarting...';
					if (!LogSink::syslog('PHP-FPM', 'Restarted services due to ' . $rCode . ' error.')) {
						$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'PHP-FPM', 'Restarted services due to " . $rCode . " error.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
					}
					shell_exec('sudo systemctl stop xc_vm');
					shell_exec('sudo systemctl start xc_vm');
					exit();
				}
			}
		}
		// A node in mode 2 reads no `signals` row: its replica says when to
		// check its ramdisk, ports and services.
		if (!$rReads || $db->query("SELECT `signal_id`, `custom_data` FROM `signals` WHERE `server_id` = ? AND `custom_data` <> '' AND `cache` = 0 ORDER BY signal_id ASC;", SERVER_ID)) {
			$rRows = $rReads ? $db->get_rows() : [];
			$rCheck = $rReads ? ['php' => false, 'services' => false, 'ports' => false, 'ramdisk' => false] : $this->replicaChecks($rServersTag);
			foreach ($rRows as $rRow) {
				$rData = json_decode($rRow['custom_data'], true);
				switch ($rData['action'] ?? '') {
					case 'disable_ramdisk':
					case 'enable_ramdisk':
						$rCheck['ramdisk'] = true;
						break;
					case 'set_services':
						$rCheck['services'] = true;
						break;
					case 'set_port':
						$rCheck['ports'] = true;
						break;
				}
			}
			if ($rCheck['services']) {
				$rCurServices = 0;
				$rStartScript = explode("\n", file_get_contents(MAIN_HOME . 'bin/daemons.sh'));
				foreach ($rStartScript as $rLine) {
					if (explode(' ', $rLine)[0] == 'start-stop-daemon') {
						$rCurServices++;
					}
				}
				if ($rServers[SERVER_ID]['total_services'] != $rCurServices) {
					array_unshift($rRows, ['custom_data' => json_encode(['action' => 'set_services', 'count' => $rServers[SERVER_ID]['total_services'], 'reload' => true])]);
				}
			}
			if ($rCheck['ports']) {
				$rListen = $rPorts = ['http' => [], 'https' => []];
				foreach (array_merge([intval($rServers[SERVER_ID]['http_broadcast_port'])], explode(',', $rServers[SERVER_ID]['http_ports_add'])) as $rPort) {
					if (is_numeric($rPort) && $rPort > 0 && $rPort <= 65535) {
						$rListen['http'][] = 'listen ' . intval($rPort) . ';';
						$rPorts['http'][] = intval($rPort);
					}
				}
				foreach (array_merge([intval($rServers[SERVER_ID]['https_broadcast_port'])], explode(',', $rServers[SERVER_ID]['https_ports_add'])) as $rPort) {
					if (is_numeric($rPort) && $rPort > 0 && $rPort <= 65535) {
						$rListen['https'][] = 'listen ' . intval($rPort) . ' ssl;';
						$rPorts['https'][] = intval($rPort);
					}
				}
				if (trim(implode(' ', $rListen['http'])) != trim(file_get_contents(MAIN_HOME . 'bin/nginx/conf/ports/http.conf'))) {
					array_unshift($rRows, ['custom_data' => json_encode(['action' => 'set_port', 'type' => 0, 'ports' => $rPorts['http'], 'reload' => true])]);
				}
				if (trim(implode(' ', $rListen['https'])) != trim(file_get_contents(MAIN_HOME . 'bin/nginx/conf/ports/https.conf'))) {
					array_unshift($rRows, ['custom_data' => json_encode(['action' => 'set_port', 'type' => 1, 'ports' => $rPorts['https'], 'reload' => true])]);
				}
				if ('listen ' . intval($rServers[SERVER_ID]['rtmp_port']) . ';' != trim(file_get_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/port.conf'))) {
					array_unshift($rRows, ['custom_data' => json_encode(['action' => 'set_port', 'type' => 2, 'ports' => [intval($rServers[SERVER_ID]['rtmp_port'])], 'reload' => true])]);
				}
			}
			if ($rCheck['ramdisk']) {
				$rMounted = false;
				exec('df -h', $rLines);
				array_shift($rLines);
				foreach ($rLines as $rLine) {
					$rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rLine)));
					if (implode(' ', array_slice($rSplit, 5, count($rSplit) - 5)) == rtrim(STREAMS_PATH, '/')) {
						$rMounted = true;
						break;
					}
				}
				if ($rServers[SERVER_ID]['use_disk']) {
					if ($rMounted) {
						array_unshift($rRows, ['custom_data' => json_encode(['action' => 'disable_ramdisk'])]);
					}
				} else {
					if (!$rMounted) {
						array_unshift($rRows, ['custom_data' => json_encode(['action' => 'enable_ramdisk'])]);
					}
				}
			}
			// The crontab's jobs: MAIN's table, or the node replica's once it owns
			// them (null: leave the crontab as it is; so in mode 2 without them).
			$rCrontab = file_exists(TMP_PATH . 'crontab') ? ReplicaApply::crontabText($rReads ? $db : null) : null;
			if ($rCrontab !== null) {
				echo 'Checking crontab...' . "\n";
				exec('crontab -u xc_vm -l', $rCrons);
				$rCurrentCron = trim(implode("\n", $rCrons));
				$rActualCron = trim($rCrontab);
				if ($rCurrentCron != $rActualCron) {
					echo 'Updating Crons...' . "\n";
					unlink(TMP_PATH . 'crontab');
				} else {
					echo "Crons valid.\n";
				}
			}
			if (file_exists(CONFIG_PATH . 'sysctl.on')) {
				if (strtoupper(substr(explode("\n", file_get_contents('/etc/sysctl.conf'))[0], 0, 7)) != '# XC_VM') {
					echo 'Sysctl missing! Writing it.' . "\n";
					exec('sudo modprobe ip_conntrack');
					file_put_contents('/etc/sysctl.conf', implode(PHP_EOL, ['# XC_VM', '', 'net.core.somaxconn = 655350', 'net.ipv4.route.flush=1', 'net.ipv4.tcp_no_metrics_save=1', 'net.ipv4.tcp_moderate_rcvbuf = 1', 'fs.file-max = 6815744', 'fs.aio-max-nr = 6815744', 'fs.nr_open = 6815744', 'net.ipv4.ip_local_port_range = 1024 65000', 'net.ipv4.tcp_sack = 1', 'net.ipv4.tcp_rmem = 10000000 10000000 10000000', 'net.ipv4.tcp_wmem = 10000000 10000000 10000000', 'net.ipv4.tcp_mem = 10000000 10000000 10000000', 'net.core.rmem_max = 524287', 'net.core.wmem_max = 524287', 'net.core.rmem_default = 524287', 'net.core.wmem_default = 524287', 'net.core.optmem_max = 524287', 'net.core.netdev_max_backlog = 300000', 'net.ipv4.tcp_max_syn_backlog = 300000', 'net.netfilter.nf_conntrack_max=1215196608', 'net.ipv4.tcp_window_scaling = 1', 'vm.max_map_count = 655300', 'net.ipv4.tcp_max_tw_buckets = 50000', 'net.ipv6.conf.all.disable_ipv6 = 1', 'net.ipv6.conf.default.disable_ipv6 = 1', 'net.ipv6.conf.lo.disable_ipv6 = 1', 'kernel.shmmax=134217728', 'kernel.shmall=134217728', 'vm.overcommit_memory = 1', 'net.ipv4.tcp_tw_reuse=1']));
					exec('sudo sysctl -p > /dev/null');
				}
			}
			if (count($rRows) > 0) {
				foreach ($rRows as $rRow) {
					$rData = json_decode($rRow['custom_data'], true);
					if (!empty($rRow['signal_id'])) {
						$db->query('DELETE FROM `signals` WHERE `signal_id` = ?;', $rRow['signal_id']);
					}
					$this->executeAction(is_array($rData) ? $rData : [], $rServers, $db);
				}
			}
			self::reloadAsked();
			// Purges every node's signals, not just this one's: MAIN only.
			if (NodeRole::isMain()) {
				$db->query('DELETE FROM `signals` WHERE LENGTH(`custom_data`) > 0 AND UNIX_TIMESTAMP() - `time` >= 86400;');
				// Opt-in 3306/6379 allowlist: re-applied here after a flush or a
				// reboot, and removed once the setting is turned off.
				$rAllowlist = class_exists(DbAllowlist::class) ? (new DbAllowlist())->sync() : null;
				if ($rAllowlist !== null && array_diff($rAllowlist, ['ok', 'absent', 'skipped'])) {
					echo 'DB allowlist: IPv4 ' . $rAllowlist[4] . ', IPv6 ' . $rAllowlist[6] . "\n";
				}
			}
			$db->close_mysql();
		} else {
			exit();
		}
	}

	/**
	 * Run one root action: a `signals` row's custom_data here, or a verified
	 * `node.root` command handed over by cluster:root (Phase 4). Same code for
	 * both, so the two transports cannot drift apart.
	 *
	 * @param array<string, mixed> $rData {action, …}
	 * @param array<int, array<string, mixed>> $rServers
	 */
	public function executeAction(array $rData, array $rServers, object $db): void {
		switch ($rData['action'] ?? '') {
			case 'flush':
				// The blocklist flush; with the CONFIG flow on, the minute's sync
				// then follows the replica, which drops the flushed addresses at
				// the agent's next `config` pull.
				echo "Flushing IP's...\n";
				$this->flushIPs();
				$this->saveiptables();
				if (!LogSink::syslog('FLUSH', 'Flushed blocked IP\'s from iptables.')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'FLUSH', 'Flushed blocked IP\\'s from iptables.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
				}
				break;
			case 'reboot':
				echo 'Rebooting system...' . "\n";
				if (!LogSink::syslog('REBOOT', 'System rebooted on request.')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'REBOOT', 'System rebooted on request.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
				}
				$db->close_mysql();
				shell_exec('sudo reboot');
				break;
			case OpensslExtra::SIGNAL_ACTION:
				// Sent by server:sync-openssl-extra on MAIN. install() keeps the value it
				// replaces open for tokens minted just before; php-fpm reads the new one
				// on its next request. The MAIN's own value (hmac_keys, image names) never
				// changes this way.
				$rSet = OpensslExtra::applySignal($rData, !empty($rServers[SERVER_ID]['is_main']), CONFIG_PATH, time());
				if ($rSet === null) {
					break;
				}
				echo 'Setting OPENSSL_EXTRA...' . "\n";
				if ($rSet) {
					// This cron runs as root: hand the files to FPM's user directly,
					// with no shell in between.
					foreach (['openssl_extra', 'openssl_extra.prev'] as $rFile) {
						if (is_file(CONFIG_PATH . $rFile)) {
							@chown(CONFIG_PATH . $rFile, 'xc_vm');
							@chgrp(CONFIG_PATH . $rFile, 'xc_vm');
						}
					}
				}
				if (!LogSink::syslog('OPENSSL_EXTRA', $rSet ? 'OPENSSL_EXTRA set to the value sent by MAIN.' : 'Failed to write the OPENSSL_EXTRA sent by MAIN.')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'OPENSSL_EXTRA', ?, 'root', 'localhost', NULL, ?);", SERVER_ID, $rSet ? 'OPENSSL_EXTRA set to the value sent by MAIN.' : 'Failed to write the OPENSSL_EXTRA sent by MAIN.', time());
				}
				break;
			case 'rotate_redis':
				// MAIN rotated its Redis password (cluster:rotate-credentials):
				// config.enc follows, sealed from a signed command or read from
				// MAIN's settings on a legacy node. A refusal throws, and
				// cluster:root acks the command failed with its message.
				echo RootCredentials::rotateRedis($rData, $db) . "\n";
				break;
			case 'rotate_db':
				// MAIN rotated the panel's DB password (cluster:rotate-db-password):
				// the sealed password into config.enc through config_set_db.
				echo RootCredentials::rotateDb($rData) . "\n";
				break;
			case 'rotate_sign_key':
				// Only from a node.root command, verified under the key pinned now.
				echo RootPin::rotate($rData) . "\n";
				break;
			case 'restart_services':
				echo 'Restarting services...' . "\n";
				if (!LogSink::syslog('RESTART', 'XC_VM services restarted on request.')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'RESTART', 'XC_VM services restarted on request.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
				}
				shell_exec('sudo systemctl stop xc_vm');
				shell_exec('sudo systemctl start xc_vm');
				break;
			case 'stop_services':
				echo 'Stopping services...' . "\n";
				if (!LogSink::syslog('STOP', 'XC_VM services stopped on request.')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'STOP', 'XC_VM services stopped on request.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
				}
				shell_exec('sudo systemctl stop xc_vm');
				break;
			case 'reload_nginx':
				echo 'Reloading nginx...' . "\n";
				if (!LogSink::syslog('RELOAD', 'NGINX services reloaded on request.')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'RELOAD', 'NGINX services reloaded on request.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
				}
				self::$rReloadRtmp = self::$rReloadNginx = true;
				break;
			case 'disable_ramdisk':
				echo 'Disabling ramdisk...' . "\n";
				file_put_contents('/etc/fstab', self::ramdiskFstab((string) file_get_contents('/etc/fstab'), false));
				shell_exec('sudo umount -l ' . STREAMS_PATH);
				shell_exec('sudo chown -R xc_vm:xc_vm ' . STREAMS_PATH);
				break;
			case 'enable_ramdisk':
				echo 'Enabling ramdisk...' . "\n";
				file_put_contents('/etc/fstab', self::ramdiskFstab((string) file_get_contents('/etc/fstab'), true));
				shell_exec('sudo mount ' . STREAMS_PATH);
				shell_exec('sudo chown -R xc_vm:xc_vm ' . STREAMS_PATH);
				break;
			case 'certbot_generate':
				echo 'Generating certbot certificate.' . "\n";
				if (!LogSink::syslog('CERTBOT', 'Attempting to generate certbot certificate on request.')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'CERTBOT', 'Attempting to generate certbot certificate on request.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
				}
				shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php certbot "' . base64_encode(json_encode($rData)) . '" 2>&1 &');
				break;
			case 'update_binaries':
				echo 'Updating binaries...' . "\n";
				if (!LogSink::syslog('BINARIES', 'Updating XC_VM binaries from XC_VM server...')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'BINARIES', 'Updating XC_VM binaries from XC_VM server...', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
				}
				shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php binaries 2>&1 &');
				break;
			case 'install_module':
				echo 'Installing module distributed from MAIN...' . "\n";
				if (!LogSink::syslog('MODULE', 'Installing module distributed from MAIN...')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'MODULE', 'Installing module distributed from MAIN...', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
				}
				// Only cluster:root stages an archive (never a signals row).
				$rStaged = ArtefactStage::current();
				if ($rStaged !== null) {
					// The custom module's archive MAIN granted, staged by
					// cluster:root and checked there: only this module's, and
					// installed from that copy now, before the stage is emptied
					// (module:install checks it again against the grant). What
					// module:install refused or failed is the command's failure.
					$rWant = 'module/' . (string) ($rData['name'] ?? '') . '/' . (string) ($rData['version'] ?? '');
					if ($rStaged['grant']['id'] !== $rWant) {
						throw new \RuntimeException(ArtefactStage::refuseGrant($rStaged['grant'], 'not the archive of ' . $rWant));
					}
					[$rCode, $rOutput] = self::run(self::moduleInstallArgv($rData, $rStaged));
					if ($rCode !== 0) {
						throw new \RuntimeException(trim($rOutput) !== '' ? trim($rOutput) : 'module:install exited ' . $rCode);
					}
					echo $rOutput . "\n";
					break;
				}
				self::run(self::moduleInstallArgv($rData, null));
				break;
			case 'strip_db_credentials':
			case 'install_config':
				// Phase 9 (plan section 10, step 3): MAIN's credentials off this
				// node's config.enc, or a config MAIN packed for it installed. Only
				// a signed node.root reaches here (NodeActions::CLUSTER_ONLY); the
				// extension verifies and refuses on MAIN itself. A refusal throws,
				// so the command's ack says why.
				$rLine = NodeCredentials::run($rData);
				$rWhat = $rData['action'] === NodeCredentials::STRIP ? 'MAIN\'s DB credentials removed from config.enc.' : 'Node config installed from MAIN.';
				if (!LogSink::syslog('CONFIG', $rWhat)) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'CONFIG', ?, 'root', 'localhost', NULL, ?);", SERVER_ID, $rWhat, time());
				}
				echo $rWhat . "\n" . $rLine . "\n";
				break;
			case 'pin_core':
				// Phase 9: MAIN's panel key pinned in xcvm_core (core.pin), so the
				// extension judges this node's lease itself. Step 1 reports the
				// install_id MAIN packs the pin for; step 2 pins it, only to the key
				// root's own pin trusts. A signed node.root only (CLUSTER_ONLY).
				$rLine = NodeCorePin::run($rData, RootPin::read());
				if (array_key_exists('blob', $rData)) {
					$rWhat = 'MAIN\'s panel key pinned for the lease verdict.';
					if (!LogSink::syslog('CONFIG', $rWhat)) {
						$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'CONFIG', ?, 'root', 'localhost', NULL, ?);", SERVER_ID, $rWhat, time());
					}
				}
				echo $rLine . "\n";
				break;
			case 'delete_module':
				echo 'Deleting module removed on MAIN...' . "\n";
				if (!LogSink::syslog('MODULE', 'Deleting module removed on MAIN...')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'MODULE', 'Deleting module removed on MAIN...', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
				}
				shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php module:delete "' . base64_encode(json_encode($rData)) . '" 2>&1 &');
				break;
			case 'update':
				echo 'Updating...' . "\n";
				if (!LogSink::syslog('UPDATE', 'Updating XC_VM...')) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'UPDATE', 'Updating XC_VM...', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
				}
				// MAIN's release, when it names one (NodeActions::update).
				$rUpVersion = UpdateCommand::pinned($rData['version'] ?? null);
				ProcessRunner::start(array_merge(['sudo', PHP_BIN, MAIN_HOME . 'console.php', 'update', 'update'], $rUpVersion === null ? [] : [$rUpVersion]));
				break;
			case 'rollback':
				$rRbVersion = UpdateCommand::pinned($rData['version'] ?? null);
				if ($rRbVersion !== null) {
					echo 'Rolling back to ' . $rRbVersion . '...' . "\n";
					if (!LogSink::syslog('UPDATE', 'Rolling back XC_VM to ' . $rRbVersion . '...')) {
						$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'UPDATE', ?, 'root', 'localhost', NULL, ?);", SERVER_ID, 'Rolling back XC_VM to ' . $rRbVersion . '...', time());
					}
					ProcessRunner::start(['sudo', PHP_BIN, MAIN_HOME . 'console.php', 'update', 'rollback', $rRbVersion]);
				}
				break;
			case 'seed_connections':
				// MAIN's guided cutover, before it switches CONNECTIONS on
				// (ClusterCutover): this node's viewers from MAIN's store into its
				// agent, as xc_vm, so its first digest agrees with MAIN's and none
				// is dropped. Its line tells MAIN how it went (SEEDED / NOT_SEEDED).
				[$rSeedCode, $rSeedOut] = self::run(['sudo', '-u', 'xc_vm', PHP_BIN, MAIN_HOME . 'console.php', 'cluster:seed-connections']);
				$rSeedLines = preg_split('/\R/', trim($rSeedOut)) ?: [];
				$rSeedLine = ($rSeedCode === 0 ? NodeActions::SEEDED : NodeActions::NOT_SEEDED) . substr(trim((string) end($rSeedLines)), 0, 200);
				if (!LogSink::syslog('CLUSTER', $rSeedLine)) {
					$db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'CLUSTER', ?, 'root', 'localhost', NULL, ?);", SERVER_ID, $rSeedLine, time());
				}
				echo $rSeedLine . "\n";
				break;
			case 'set_services':
				echo 'Setting PHP Services' . "\n";
				$rServices = intval($rData['count']);
				if ($rData['reload']) {
					shell_exec('sudo systemctl stop xc_vm');
				}
				shell_exec('sudo rm ' . MAIN_HOME . 'bin/php/etc/*.conf');
				// The pool configs, then daemons.sh, then balance.conf.
				foreach (PhpFpmPools::files($rServices, (string) file_get_contents(MAIN_HOME . 'bin/php/etc/template'), MAIN_HOME) as $rPath => $rBody) {
					file_put_contents($rPath, $rBody);
				}
				shell_exec('sudo chown xc_vm:xc_vm ' . MAIN_HOME . 'bin/php/etc/*');
				if ($rData['reload']) {
					shell_exec('sudo systemctl start xc_vm');
				}
				break;
			case 'set_governor':
				$rNewGovernor = $rData['data'];
				if (!empty($rNewGovernor) && shell_exec('which cpufreq-info')) {
					$rGovernors = array_filter(explode(' ', trim(shell_exec('cpufreq-info -g'))));
					$rGovernor = explode(' ', trim(shell_exec('cpufreq-info -p')));
					if ($rGovernor[2] != $rNewGovernor && in_array($rNewGovernor, $rGovernors)) {
						shell_exec("sudo bash -c 'for ((i=0;i<\$(nproc);i++)); do cpufreq-set -c \$i -g " . $rNewGovernor . "; done'");
						sleep(2);
						$rGovernor = explode(' ', trim(shell_exec('cpufreq-info -p')));
						NodeStateSink::state(['governor' => json_encode($rGovernor)], $db);
					}
				}
				break;
			case 'set_sysctl':
				$rNewConfig = $rData['data'];
				if (!empty($rNewConfig)) {
					$rSysCtl = file_get_contents('/etc/sysctl.conf');
					if ($rSysCtl != $rNewConfig) {
						shell_exec('sudo modprobe ip_conntrack > /dev/null');
						file_put_contents('/etc/sysctl.conf', $rNewConfig);
						shell_exec('sudo sysctl -p > /dev/null');
						NodeStateSink::state(['sysctl' => $rNewConfig], $db);
					}
				}
				break;
			case 'set_port':
				echo 'Setting NGINX Port' . "\n";
				if (intval($rData['type']) == 0) {
					$rListen = [];
					foreach ($rData['ports'] as $rPort) {
						if (is_numeric($rPort) && $rPort >= 80 && $rPort <= 65535) {
							$rListen[] = 'listen ' . intval($rPort) . ';';
						}
					}
					file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/http.conf', implode(' ', $rListen));
					// MAIN: the cluster API's servers follow the ports (its old ports,
					// kept after a port change), rendered as xc_vm (ClusterNginxConfig).
					if (NodeRole::isMain() && class_exists(ClusterNginxConfig::class)) {
						shell_exec('sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cluster:nginx --no-reload');
					}
					file_put_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/live.conf', 'on_play http://127.0.0.1:' . intval($rData['ports'][0]) . '/stream/rtmp; on_publish http://127.0.0.1:' . intval($rData['ports'][0]) . '/stream/rtmp; on_play_done http://127.0.0.1:' . intval($rData['ports'][0]) . '/stream/rtmp;');
					if ($rData['reload']) {
						self::$rReloadNginx = true;
					}
				} elseif (intval($rData['type']) == 1) {
					$rListen = [];
					foreach ($rData['ports'] as $rPort) {
						if (is_numeric($rPort) && $rPort >= 80 && $rPort <= 65535) {
							$rListen[] = 'listen ' . intval($rPort) . ' ssl;';
						}
					}
					file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/https.conf', implode(' ', $rListen));
					if (NodeRole::isMain() && class_exists(ClusterNginxConfig::class)) {
						shell_exec('sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cluster:nginx --no-reload');
					}
					if ($rData['reload']) {
						self::$rReloadNginx = true;
					}
				} elseif (intval($rData['type']) == 2) {
					file_put_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/port.conf', 'listen ' . intval($rData['ports'][0]) . ';');
					if ($rData['reload']) {
						self::$rReloadRtmp = true;
					}
				}
				// no break
			default:
				break;
		}
	}

	public function shutdown(): void {
		global $db;
		if ($this->rSaveIPTables) {
			$this->saveiptables();
		}
		if (is_object($db)) {
			$db->close_mysql();
		}
		@unlink($this->rIdentifier);
	}
}
