<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Process\ProcessRunner;

/**
 * StartupCommand — startup command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StartupCommand implements CommandInterface {
	use DaemonTrait;

	/**
	 * sha256 of each server.key the archive has shipped. They are in git, so a
	 * node still using one shares its private key with every other such node
	 * (a load balancer's install never replaced it).
	 */
	public const PLACEHOLDER_KEYS = [
		'27b205d352c99672ff15f2cba96e1c8ea1d14ebba0488e0591551b683bd552ef',
		'54d14bfaef0399f8891de7714e8d18368f917c87bc897298247dd3d7f468bff5',
	];

	/** sha256 of the ssl.conf the archive shipped before SSL_CONF (SSLv3, TLS 1.1 and 1.2). */
	public const OLD_SSL_CONF = 'ab86bf8ac5ee9430ef4a6000467d2001a227fa764be71f9047b6869e131993b4';

	/** The archive's ssl.conf (bin/nginx/conf/): the self-signed pair, TLS 1.2 and 1.3 only. */
	public const SSL_CONF = "ssl_certificate server.crt; ssl_certificate_key server.key; ssl_protocols TLSv1.2 TLSv1.3;\n";

	public function getName(): string {
		return 'startup';
	}

	public function getDescription(): string {
		return 'System initialization: daemons.sh, crontab, cache';
	}

	public function execute(array $rArgs): int {
		$rFixCron = false;
		if (!empty($rArgs[0]) && intval($rArgs[0]) == 1) {
			$rFixCron = true;
		}

		global $db;

		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		error_reporting(32767);

		exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php status 1');

		// ── Восстановление daemons.sh при повреждении ────────
		if (filesize(MAIN_HOME . 'bin/daemons.sh') == 0) {
			echo "Daemons corrupted! Regenerating...\n";
			$rNewScript = '#! /bin/bash' . "\n";
			$rNewBalance = 'upstream php {' . "\n" . '    least_conn;' . "\n";
			$rTemplate = file_get_contents(MAIN_HOME . 'bin/php/etc/template');
			exec('rm -f ' . MAIN_HOME . 'bin/php/etc/*.conf');
			foreach (range(1, 4) as $i) {
				$rNewScript .= 'start-stop-daemon --start --quiet --pidfile ' . MAIN_HOME . 'bin/php/sockets/' . $i . '.pid --exec ' . MAIN_HOME . 'bin/php/sbin/php-fpm -- --daemonize --fpm-config ' . MAIN_HOME . 'bin/php/etc/' . $i . '.conf' . "\n";
				$rNewBalance .= '    server unix:' . MAIN_HOME . 'bin/php/sockets/' . $i . '.sock;' . "\n";
				file_put_contents(MAIN_HOME . 'bin/php/etc/' . $i . '.conf', str_replace('#PATH#', MAIN_HOME, str_replace('#ID#', (string) $i, $rTemplate)));
			}
			$rNewBalance .= '}';
			file_put_contents(MAIN_HOME . 'bin/daemons.sh', $rNewScript);
			exec('chmod 0771 ' . MAIN_HOME . 'bin/daemons.sh');
			exec('sudo chown xc_vm:xc_vm ' . MAIN_HOME . 'bin/daemons.sh');
			exec('sudo chown xc_vm:xc_vm ' . MAIN_HOME . 'bin/php/etc/*');
			file_put_contents(MAIN_HOME . 'bin/nginx/conf/balance.conf', $rNewBalance);
		}

		// ── Права на console.php (могут сброситься после обновления) ──
		$rConsolePath = MAIN_HOME . 'console.php';
		if (file_exists($rConsolePath) && !is_executable($rConsolePath)) {
			@chmod($rConsolePath, 0755);
		}

		// ── Права на bin/xc_fanout/run.sh (супервизор демона; режим теряется
		//    при mode-роняющем деплое → service не может его запустить → xc_fanout
		//    не поднимается → все потоки уходят в not-on-air). service запускает
		//    его через `bash`, это лишь второй пояс на случай прямого вызова. ──
		$rRunSh = MAIN_HOME . 'bin/xc_fanout/run.sh';
		if (file_exists($rRunSh) && !is_executable($rRunSh)) {
			@chmod($rRunSh, 0755);
		}

		// Core scripts/binaries lose their executable bit on a mode-dropping
		// deploy, and a missing php-fpm pool config leaves that worker down.
		$this->ensureExecutableScripts(['service', 'update', 'bin/redis/redis-server', 'bin/daemons.sh'], MAIN_HOME);
		$this->ensurePhpFpmPoolConfigs(MAIN_HOME);

		// nginx is already up (service starts it before this): reload it onto the new pair.
		if (self::hardenTls(MAIN_HOME . 'bin/nginx/conf/')) {
			$rNginx = [MAIN_HOME . 'bin/nginx/sbin/nginx', '-s', 'reload'];
			ProcessRunner::run(posix_geteuid() === 0 ? array_merge(['sudo', '-u', 'xc_vm'], $rNginx) : $rNginx, true);
		}

		// ── Установка crontab и запуск кэша ──────────────────
		if (posix_getpwuid(posix_geteuid())['name'] == 'root') {
			self::installRootCrontab();
			if (!$rFixCron) {
				exec('sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache 1', $rOutput);
				$this->generateCacheIfNeeded();
			}
		} else {
			if (!$rFixCron) {
				exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache 1');
				$this->generateCacheIfNeeded();
			}
		}

		echo "\n";
		return 0;
	}

	/**
	 * Restore the executable bit on core scripts that a mode-dropping deploy
	 * (git archive, some rsync flags) can strip.
	 *
	 * @param list<string> $rScripts Paths relative to $rBase.
	 */
	private function ensureExecutableScripts(array $rScripts, string $rBase): void {
		foreach ($rScripts as $rScript) {
			$rPath = $rBase . $rScript;
			if (file_exists($rPath) && !is_executable($rPath)) {
				@chmod($rPath, 0755);
			}
		}
	}

	/**
	 * In nginx's conf dir $rConf: a placeholder server.key (PLACEHOLDER_KEYS) is
	 * replaced by a self-signed pair of this node's own, and the archive's old
	 * ssl.conf by SSL_CONF. A key or an ssl.conf anyone else wrote (the installer,
	 * an operator, certbot) is left alone. True when either changed.
	 */
	public static function hardenTls(string $rConf): bool {
		$rChanged = false;
		if (in_array(@hash_file('sha256', $rConf . 'server.key'), self::PLACEHOLDER_KEYS, true)) {
			$rPair = self::selfSigned(gethostname() ?: 'xc_vm');
			// The certificate first: interrupted before the key, the placeholder
			// is still there and the next start makes the pair again.
			if ($rPair !== null && self::replace($rConf . 'server.crt', $rPair[0], 0644) && self::replace($rConf . 'server.key', $rPair[1], 0600)) {
				echo "Replaced the placeholder TLS key with this node's own.\n";
				$rChanged = true;
			}
		}
		if (@hash_file('sha256', $rConf . 'ssl.conf') === self::OLD_SSL_CONF && self::replace($rConf . 'ssl.conf', self::SSL_CONF, 0644)) {
			echo "ssl.conf: TLS 1.2 and 1.3 only.\n";
			$rChanged = true;
		}
		return $rChanged;
	}

	/**
	 * A new RSA-2048 key and a 10-year self-signed certificate for $rName, as the
	 * installer makes them: [certificate PEM, key PEM], or null.
	 */
	private static function selfSigned(string $rName): ?array {
		// A config of its own: the system's would add its sample subject (AU, Some-State, Internet Widgits).
		$rCnf = @tempnam(sys_get_temp_dir(), 'xcvm_tls_');
		if ($rCnf === false || @file_put_contents($rCnf, "[req]\ndistinguished_name = dn\n[dn]\n") === false) {
			return null;
		}
		try {
			$rOpts = ['config' => $rCnf, 'digest_alg' => 'sha256'];
			$rKey = openssl_pkey_new($rOpts + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			$rCsr = $rKey ? openssl_csr_new(['commonName' => $rName], $rKey, $rOpts) : false;
			$rCrt = $rCsr ? openssl_csr_sign($rCsr, null, $rKey, 3650, $rOpts, random_int(1, PHP_INT_MAX)) : false;
			return $rCrt && openssl_x509_export($rCrt, $rCrtPem) && openssl_pkey_export($rKey, $rKeyPem, null, $rOpts) ? [$rCrtPem, $rKeyPem] : null;
		} finally {
			@unlink($rCnf);
		}
	}

	/** Write $rPath whole (temp file + rename), owned by xc_vm when run as root. */
	private static function replace(string $rPath, string $rData, int $rMode): bool {
		$rTmp = @tempnam(dirname($rPath), '.tls_');
		if ($rTmp === false) {
			return false;
		}
		if (@file_put_contents($rTmp, $rData) !== strlen($rData) || !@chmod($rTmp, $rMode)
			|| (posix_geteuid() === 0 && posix_getpwnam('xc_vm') !== false && !@chown($rTmp, 'xc_vm')) || !@rename($rTmp, $rPath)
		) {
			@unlink($rTmp);
			return false;
		}
		return true;
	}

	/**
	 * Regenerate any missing php-fpm pool config (1..4.conf) from the template,
	 * so a worker whose config was lost comes back on the next boot.
	 */
	private function ensurePhpFpmPoolConfigs(string $rBase): void {
		$rTemplatePath = $rBase . 'bin/php/etc/template';
		if (!file_exists($rTemplatePath)) {
			return;
		}
		$rTemplate = (string) file_get_contents($rTemplatePath);
		foreach (range(1, 4) as $rID) {
			$rConf = $rBase . 'bin/php/etc/' . $rID . '.conf';
			if (file_exists($rConf)) {
				continue;
			}
			file_put_contents($rConf, str_replace('#PATH#', $rBase, str_replace('#ID#', (string) $rID, $rTemplate)));
			@chmod($rConf, 0644);
			if (posix_geteuid() === 0) {
				exec('sudo chown xc_vm:xc_vm ' . escapeshellarg($rConf) . ' 2>/dev/null');
			}
		}
	}

	/**
	 * Root's crontab: cron:root_signals, cluster:root (MAIN's signed root
	 * commands), cron:root_mysql and the module licences, plus whatever the
	 * modules ask for. Static and public because `status` installs the same
	 * list — two writers meant the second one deleted what the first added.
	 */
	public static function installRootCrontab(): void {
		$rCrons = [];
		$rCrons[] = '* * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:root_signals # XC_VM';
		// MAIN's signed root commands (cluster API, Phase 4); a no-op until the node's root pin exists.
		if (file_exists(MAIN_HOME . 'Cli/Commands/ClusterRootCommand.php')) {
			$rCrons[] = '* * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cluster:root # XC_VM';
		}
		if (file_exists(MAIN_HOME . 'Cli/CronJobs/RootMysqlCronJob.php')) {
			$rCrons[] = '* * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:root_mysql # XC_VM';
		}
		// Renew per-machine ionCube licenses for platform modules before they
		// expire (runs as xc_vm so the .lic is owned by the panel user). No-op
		// when no licensed modules are installed.
		if (file_exists(MAIN_HOME . 'Cli/CronJobs/ModuleLicensesCronJob.php')) {
			$rCrons[] = '17 3 * * * sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:module_licenses # XC_VM';
		}

		foreach ((new ModuleLoader())->loadAll()->collectCronEntries() as $rEntry) {
			$rCrons[] = $rEntry;
		}

		$rInstalled = [];
		exec('sudo crontab -l', $rInstalled);

		// Удаляем старые записи XC_VM: путь v1.x.x (crons/root_) и любые
		// строки с нашим маркером — включая старый '# \XC_VM' от прошлой
		// миграции — чтобы при апгрейде не появлялись дубликаты.
		$rOutput = [];
		foreach ($rInstalled as $rLine) {
			if (strpos($rLine, MAIN_HOME . 'crons/root_') !== false
				|| strpos($rLine, '# XC_VM') !== false
				|| strpos($rLine, '# \XC_VM') !== false
			) {
				continue;
			}
			$rOutput[] = $rLine;
		}

		foreach ($rCrons as $rCron) {
			if (!in_array($rCron, $rOutput)) {
				$rOutput[] = $rCron;
			}
		}
		// Written only when the list changed: every `startup` and `status` comes here.
		if ($rOutput === $rInstalled) {
			echo "Crontab already installed\n";
			return;
		}
		// The whole list in a file before root's crontab is touched. Not in
		// tmp/: that tmpfs can be full, and in the system's temporary
		// directory only root replaces a file of root's.
		$rText = implode("\n", $rOutput) . "\n";
		$rCronFile = tempnam(sys_get_temp_dir(), 'crontab');
		if ($rCronFile === false || @file_put_contents($rCronFile, $rText) !== strlen($rText)) {
			@unlink((string) $rCronFile);
			echo "Crontab not installed: its new list could not be written\n";
			return;
		}
		exec('sudo chattr -i /var/spool/cron/crontabs/root');
		// `crontab <file>` replaces the crontab in one step, and keeps the old
		// one when it refuses the new: no `crontab -r` before it. The one part of
		// the command that is not a literal is the name tempnam() gave, quoted.
		// nosemgrep: php.lang.security.exec-use.exec-use
		exec('sudo crontab ' . escapeshellarg($rCronFile), $rOut, $rCode);
		exec('sudo chattr +i /var/spool/cron/crontabs/root');
		@unlink($rCronFile);
		echo $rCode === 0 ? "Crontab installed\n" : "Crontab not installed: crontab refused its new list\n";
	}

	private function generateCacheIfNeeded(): void {
		if (!file_exists(CACHE_TMP_PATH . 'cache_complete')) {
			echo "Generating cache...\n";
			// Drop to xc_vm when running as root (service boot / installer):
			// cache files written by root cannot be refreshed later by the
			// xc_vm daemons and crons.
			$rAsXcVm = ((posix_getpwuid(posix_geteuid())['name'] ?? null) === 'root') ? ['sudo', '-u', 'xc_vm'] : [];
			// The heavy cache pass is MAIN's: the LB build strips CacheEngineCronJob,
			// and an LB never has cache_complete, so this ran every boot and answered
			// "Unknown command". No database is asked, because a mode 2 node has none.
			if (file_exists(MAIN_HOME . 'Cli/CronJobs/CacheEngineCronJob.php')) {
				ProcessRunner::start(array_merge($rAsXcVm, [PHP_BIN, MAIN_HOME . 'console.php', 'cron:cache_engine']));
			}
		}
	}
}
