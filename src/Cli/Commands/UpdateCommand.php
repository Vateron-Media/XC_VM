<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\NodeStateSink;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\TreeOwnership;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\MigrationRunner;
use XcVm\Core\Logging\UpdateLogger;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Updates\ReleaseArchiveInspector;
use XcVm\Core\Updates\UpdateChannels;
use XcVm\Core\Util\AtomicFile;
use XcVm\Domain\Server\ServerRepository;

/**
 * UpdateCommand — update command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UpdateCommand implements CommandInterface {
	public function getName(): string {
		return 'update';
	}

	public function getDescription(): string {
		return 'System update (update / post-update)';
	}

	/**
	 * The update/rollback flow shells out to `sudo` (the Python updater). In
	 * XC_VM's model the xc_vm user has NO sudoers entry, so that sudo only works
	 * when this process is already root — the sanctioned path is
	 * RootSignalsCronJob (which asserts root) launching `console.php update`.
	 * Run non-root, the nested sudo prompts for a password it can never read and
	 * the updater silently never starts. Fail fast here, before any state
	 * change, so a mistaken manual invocation cannot strand the server at
	 * status=5 (updating) with nothing left to reset it.
	 */
	private function assertRunAsRoot(): bool {
		$rUser = posix_getpwuid(posix_geteuid())['name'] ?? '?';
		if ($rUser !== 'root') {
			echo "ERROR: this must run as root (current user: {$rUser}).\n";
			echo "  Trigger the update from the panel, or run it via sudo/as root.\n";
			UpdateLogger::error('Aborted: must run as root, invoked as ' . $rUser);
			return false;
		}
		return true;
	}

	public function execute(array $rArgs): int {
		set_time_limit(0);

		if (empty($rArgs[0])) {
			return 0;
		}

		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		global $db;
		$gitRelease = UpdateChannels::mainReleases();
		$gitRelease->setTimeout(30);

		$rCommand = $rArgs[0];

		switch ($rCommand) {
			case 'update':
				UpdateLogger::reset();
				if (!$this->assertRunAsRoot()) {
					return 1;
				}
				$rIsMain = ServerRepository::getAll()[SERVER_ID]['is_main'];
				$rServerType = $rIsMain ? 'MAIN' : 'LB';
				echo "Checking for updates (server={$rServerType}, version=" . XC_VM_VERSION . ")...\n";
				UpdateLogger::info('Update started; server=' . $rServerType . ', current version=' . XC_VM_VERSION);

				// A load balancer MAIN names a release for installs exactly that
				// one (NodeActions::update), so it runs MAIN's, never one newer.
				// Run without one, it takes the release MAIN's row records.
				$rPinned = $rIsMain ? null : self::pinned($rArgs[1] ?? self::mainVersion());
				if (!$rIsMain && $rPinned === null) {
					echo "ERROR: MAIN's release is unknown: a load balancer installs MAIN's release only.\n";
					UpdateLogger::error('Aborted: MAIN\'s release is unknown');
					return 1;
				}
				if ($rPinned !== null && version_compare($rPinned, XC_VM_VERSION, '<=')) {
					echo "Already at MAIN's release {$rPinned} or newer (" . XC_VM_VERSION . ").\n";
					UpdateLogger::info('Already at MAIN\'s release ' . $rPinned . ' or newer, no action needed');
					return 0;
				}

				$rLatest = $rPinned ?? $gitRelease->getLatestVersion(XC_VM_VERSION);

				if ($rLatest === null) {
					echo "Already up to date.\n";
					UpdateLogger::info('Already up to date, no action needed');
					return 0;
				}

				echo ($rPinned === null ? 'New version available: ' : 'MAIN\'s release: ') . $rLatest . "\n";
				UpdateLogger::info(($rPinned === null ? 'New version found: ' : 'Updating to MAIN\'s release: ') . $rLatest);

				$UpdateData = $rIsMain ? $gitRelease->getUpdateFile('main', XC_VM_VERSION) : $gitRelease->getVersionFile('lb_update', $rPinned);

				if (!$UpdateData || empty($UpdateData['url'])) {
					echo "ERROR: Failed to get update file URL.\n";
					UpdateLogger::error('Failed to get update file URL');
					return 1;
				}

				if (empty($UpdateData['md5'])) {
					$rAssetName = $rIsMain ? 'xc_vm.tar.gz' : 'loadbalancer.tar.gz';
					$rHashUrl = $gitRelease->assetUrl($rLatest, 'hashes.md5');
					echo "WARNING: Could not fetch MD5 hash. Retrying...\n";
					echo "  Hash URL: {$rHashUrl}\n";
					UpdateLogger::info('MD5 hash fetch failed for ' . $rAssetName . ', retrying...');
					$UpdateData['md5'] = $gitRelease->getAssetHash($rLatest, $rAssetName);
					if (empty($UpdateData['md5'])) {
						echo "ERROR: Failed to get MD5 hash after retry.\n";
						echo "  Check if hashes.md5 exists in release {$rLatest} on GitHub.\n";
						UpdateLogger::error('Failed to get MD5 hash for version ' . $rLatest);
						return 1;
					}
				}

				echo "Downloading update...\n";
				UpdateLogger::info('Downloading update file...');
				$rOutputDir = TMP_PATH . '.update.tar.gz';
				$rDownloaded = $this->downloadFile($UpdateData['url'], $rOutputDir);

				if (!$rDownloaded) {
					echo "ERROR: Download failed.\n";
					UpdateLogger::error('Download failed from: ' . $UpdateData['url']);
					return 1;
				}

				$rFileMd5 = md5_file($rOutputDir);
				if ($rFileMd5 !== $UpdateData['md5']) {
					echo "ERROR: MD5 checksum mismatch.\n";
					UpdateLogger::error('MD5 mismatch: expected=' . $UpdateData['md5'] . ', got=' . $rFileMd5);
					@unlink($rOutputDir);
					return 1;
				}

				echo "Download OK, MD5 verified (" . filesize($rOutputDir) . " bytes).\n";
				UpdateLogger::info('Download OK, MD5 verified, size=' . filesize($rOutputDir) . ' bytes');

				// Pre-flight the launcher before flipping status: a missing
				// interpreter or updater script must not strand the server at
				// status=5 with nothing left running to reset it.
				$rUpdater = MAIN_HOME . 'update';
				if (!is_file($rUpdater) || !is_executable('/usr/bin/python3')) {
					echo "ERROR: updater not launchable (script or python3 missing).\n";
					UpdateLogger::error('Updater not launchable: script=' . $rUpdater . ', interpreter=/usr/bin/python3');
					@unlink($rOutputDir);
					return 1;
				}

				if ($rIsMain) {
					self::dumpBeforeUpdate($db, $rLatest);
				}

				// Through the agent where the node reports its row that way (mode 2 has no other).
				NodeStateSink::status(5, $db);
				UpdateLogger::info('Server status set to 5 (updating), launching system update...');

				echo "Launching system update...\n";
				$rLogFile = UpdateLogger::getLogFile();
				$rCmd = 'sudo /usr/bin/python3 ' . escapeshellarg($rUpdater) . ' '
					. escapeshellarg($rOutputDir) . ' '
					. escapeshellarg($UpdateData['md5'])
					. ' >> ' . escapeshellarg($rLogFile) . ' 2>&1 &';
				shell_exec($rCmd);
				exit(1);

			case 'rollback':
				UpdateLogger::reset();
				if (!$this->assertRunAsRoot()) {
					return 1;
				}
				$rTarget = isset($rArgs[1]) ? trim((string) $rArgs[1]) : '';

				if (!preg_match('/^\d+\.\d+\.\d+$/', $rTarget)) {
					echo "ERROR: invalid target version.\n";
					UpdateLogger::error('Rollback aborted: invalid target version "' . $rTarget . '"');
					return 1;
				}
				if (version_compare($rTarget, XC_VM_VERSION, '>=')) {
					echo "ERROR: target {$rTarget} is not older than current " . XC_VM_VERSION . ".\n";
					UpdateLogger::error('Rollback aborted: target ' . $rTarget . ' >= current ' . XC_VM_VERSION);
					return 1;
				}

				$rIsMain = ServerRepository::getAll()[SERVER_ID]['is_main'];
				$rServerType = $rIsMain ? 'MAIN' : 'LB';
				echo "Rolling back {$rServerType} from " . XC_VM_VERSION . " to {$rTarget}...\n";
				UpdateLogger::info('Rollback started; server=' . $rServerType . ', ' . XC_VM_VERSION . ' -> ' . $rTarget);

				// Safety net: dump the DB before applying the older tree (MAIN only —
				// the DB lives there). Migrations are forward-only, so a downgrade
				// cannot undo them; this backup is the recovery path if the older code
				// mishandles newer schema. Abort the rollback if the dump fails.
				if ($rIsMain) {
					echo "Backing up database...\n";
					UpdateLogger::info('Creating pre-rollback DB backup');
					$rBackupFile = BackupService::dumpFor($db, 'pre_rollback_' . XC_VM_VERSION . '_to_' . $rTarget);

					if ($rBackupFile === null) {
						echo "ERROR: DB backup failed, aborting rollback.\n";
						UpdateLogger::error('Pre-rollback DB backup failed (empty/missing), aborting');
						return 1;
					}
					UpdateLogger::info('Pre-rollback DB backup OK: ' . basename($rBackupFile) . ' (' . filesize($rBackupFile) . ' bytes)');
				}

				$UpdateData = $gitRelease->getVersionFile($rIsMain ? 'main' : 'lb_update', $rTarget);

				if (empty($UpdateData['url'])) {
					echo "ERROR: failed to resolve release asset for {$rTarget}.\n";
					UpdateLogger::error('Failed to resolve rollback asset URL for ' . $rTarget);
					return 1;
				}
				if (empty($UpdateData['md5'])) {
					echo "ERROR: could not fetch MD5 for {$rTarget} (missing hashes.md5?).\n";
					UpdateLogger::error('Missing MD5 for rollback version ' . $rTarget);
					return 1;
				}

				echo "Downloading {$rTarget}...\n";
				UpdateLogger::info('Downloading rollback archive...');
				$rOutputDir = TMP_PATH . '.update.tar.gz';

				if (!$this->downloadFile($UpdateData['url'], $rOutputDir)) {
					echo "ERROR: download failed.\n";
					UpdateLogger::error('Rollback download failed from: ' . $UpdateData['url']);
					return 1;
				}

				$rFileMd5 = md5_file($rOutputDir);
				if ($rFileMd5 !== $UpdateData['md5']) {
					echo "ERROR: MD5 checksum mismatch.\n";
					UpdateLogger::error('Rollback MD5 mismatch: expected=' . $UpdateData['md5'] . ', got=' . $rFileMd5);
					@unlink($rOutputDir);
					return 1;
				}

				echo "Download OK, MD5 verified (" . filesize($rOutputDir) . " bytes).\n";
				UpdateLogger::info('Rollback download OK, MD5 verified, size=' . filesize($rOutputDir) . ' bytes');

				// Reverse schema changes a target up to 2.5.3 doesn't carry (e.g. a
				// column a newer migration dropped), so the older code about to be
				// installed doesn't hit schema it doesn't expect. MAIN only — the DB
				// lives there. Abort on failure: the pre-rollback backup above is
				// still the recovery path.
				if ($rIsMain) {
					echo "Checking for schema changes to reverse...\n";
					try {
						$rTargetMigrations = self::rollbackMigrations($rOutputDir);
						$rMigrationResult = $rTargetMigrations === null ? null : MigrationRunner::rollback($db, $rTargetMigrations);
					} catch (\Throwable $e) {
						echo "ERROR: schema reversal failed: " . $e->getMessage() . "\n";
						UpdateLogger::error('Rollback aborted: schema reversal failed: ' . $e->getMessage());
						@unlink($rOutputDir);
						return 1;
					}
					if ($rMigrationResult === null) {
						echo "Schema left as it is: the target release applies its own migration files.\n";
						UpdateLogger::info('Rollback schema reversal: none, the schema is left as it is');
					} else {
						foreach ($rMigrationResult['reversed'] as $rName) {
							echo "  [DOWN] " . $rName . "\n";
						}
						foreach ($rMigrationResult['skipped'] as $rName) {
							echo "  [SKIP] " . $rName . " (no down migration; left applied)\n";
						}
						UpdateLogger::info('Rollback schema reversal: ' . count($rMigrationResult['reversed']) . ' reversed, ' . count($rMigrationResult['skipped']) . ' skipped');
					}
				}

				// Pre-flight the launcher before flipping status: a missing
				// interpreter or updater script must not strand the server at
				// status=5 with nothing left running to reset it.
				$rUpdater = MAIN_HOME . 'update';
				if (!is_file($rUpdater) || !is_executable('/usr/bin/python3')) {
					echo "ERROR: updater not launchable (script or python3 missing).\n";
					UpdateLogger::error('Updater not launchable: script=' . $rUpdater . ', interpreter=/usr/bin/python3');
					@unlink($rOutputDir);
					return 1;
				}

				NodeStateSink::status(5, $db);
				UpdateLogger::info('Server status set to 5 (updating), launching system rollback...');

				echo "Launching system rollback...\n";
				$rLogFile = UpdateLogger::getLogFile();
				$rCmd = 'sudo /usr/bin/python3 ' . escapeshellarg($rUpdater) . ' '
					. escapeshellarg($rOutputDir) . ' '
					. escapeshellarg($UpdateData['md5'])
					. ' >> ' . escapeshellarg($rLogFile) . ' 2>&1 &';
				shell_exec($rCmd);
				exit(1);

			case 'post-update':
				UpdateLogger::info('Post-update started');

				$rFailed = [];
				if (ServerRepository::getAll()[SERVER_ID]['is_main']) {
					UpdateLogger::info('Running database migrations...');
					$rFailed = MigrationRunner::run($db);
					foreach ($rFailed as $rFailure) {
						UpdateLogger::error('Database migration failed: ' . $rFailure);
					}
				}
				UpdateLogger::info('Running file cleanup...');
				MigrationRunner::runFileCleanup();
				// The updater that ran is the release before this one's, which left
				// bin/install out: MAIN kept installers too old for the load
				// balancers it installs (no install_xcvm_core.sh). Take them from
				// the archive this update came in.
				// ponytail: drop once every MAIN runs an updater that copies bin/install.
				$rArchive = TMP_PATH . '.update.tar.gz';
				if (ServerRepository::getAll()[SERVER_ID]['is_main'] && is_file($rArchive)) {
					ProcessRunner::run(['tar', '-xzf', $rArchive, '-C', MAIN_HOME, './bin/install/install_xcvm_core.sh', './bin/install/update_binaries.sh', './bin/install/database.sql'], true);
					ProcessRunner::run(['chown', '-R', 'xc_vm:xc_vm', MAIN_HOME . 'bin/install'], true);
				}
				// MAIN's copies of the binaries it used to hand its nodes: every node
				// takes them from GitHub itself now (ADR 0004, "Binaries from GitHub
				// on every node"), and nothing reads these.
				foreach (['xc_agent/cache', 'xcvm_core/cache'] as $rLeft) {
					if (is_dir(BIN_PATH . $rLeft) && !is_link(BIN_PATH . $rLeft)) {
						ProcessRunner::run(['rm', '-rf', '--', BIN_PATH . $rLeft], true);
					}
				}
				@rmdir(BIN_PATH . 'xcvm_core');

				if (ServerRepository::getAll()[SERVER_ID]['is_main'] && SettingsManager::get('auto_update_lbs')) {
					UpdateLogger::info('Broadcasting update signal to LB servers');
					// Every load balancer is listed, with when it was told (0: not
					// yet), until its row shows MAIN's release (cron:servers). The
					// first condition skips MAIN, the one row updating itself here.
					$rList = [];
					foreach (ServerRepository::getAll() as $rServer) {
						if ($rServer['is_main']) {
							continue;
						}
						$rTold = $rServer['enabled'] && $rServer['status'] == 1 && time() - $rServer['last_check_ago'] <= 180
							&& NodeActions::update(intval($rServer['id']), $db);
						if ((int) ($rServer['server_type'] ?? 0) !== 1) {
							$rList[intval($rServer['id'])] = $rTold ? time() : 0;
						}
					}
					AtomicFile::write(self::PENDING, (string) json_encode($rList, JSON_FORCE_OBJECT));
				}

				NodeStateSink::status(1, $db);
				if (!NodeStateSink::inventory(['xc_vm_version' => XC_VM_VERSION]) && !NodeRole::refusesConnects()) {
					$db->query('UPDATE `servers` SET `xc_vm_version` = ? WHERE `id` = ?;', XC_VM_VERSION, SERVER_ID);
				}
				if (ServerRepository::getAll()[SERVER_ID]['is_main']) {
					// One settings row for the cluster: an LB clearing it threw away
					// MAIN's pending update record.
					$db->query('UPDATE `settings` SET `update_data` = NULL;');
				}
				UpdateLogger::info('Server status set to 1 (online), version=' . XC_VM_VERSION);

				foreach (['http', 'https'] as $rType) {
					$rPortConfig = file_get_contents(MAIN_HOME . 'bin/nginx/conf/ports/' . $rType . '.conf');
					if (stripos($rPortConfig, ' reuseport') !== false) {
						file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/' . $rType . '.conf', str_replace(' reuseport', '', $rPortConfig));
					}
				}

				// An update keeps the installed access-code files: they are built
				// again from the codes, so what this release's template (or the
				// generator) changes reaches them. Never with no code to build, which
				// would leave only the no-code file.
				if (ServerRepository::getAll()[SERVER_ID]['is_main'] && array_filter(AuthRepository::getAllCodes(), static fn(array $rCode): bool => (bool) $rCode['enabled'])) {
					UpdateLogger::info('Rebuilding the access-code nginx files');
					AuthRepository::updateCodes();
				}

				ProcessRunner::run(TreeOwnership::chownTree(MAIN_HOME));
				exec('sudo systemctl daemon-reload');
				exec("sudo echo 'net.ipv4.ip_unprivileged_port_start=0' > /etc/sysctl.d/50-allports-nonroot.conf && sudo sysctl --system");
				exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php status');

				// Pull/refresh the xc_fanout daemon binary to match this panel
				// version (ADR 0003, Phase G) — nothing else does it. Idempotent
				// (downloads only on a version mismatch) + background + best-effort
				// so an unreachable GitHub never blocks the update; the RootSignals
				// hourly self-heal is the backstop. Runs on every node (LBs too).
				exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php fanout_binary >/dev/null 2>&1 &');

				// Pull/refresh the xcvm_core PHP extension to match the version
				// published in the binaries repo — decoupled from the heavy runtime
				// bundle, so an update alone would otherwise leave a stale extension.
				// Picks the OpenSSL-ABI-matched build, installs atomically with a
				// load-test + rollback, and reloads php-fpm. Idempotent + background
				// + best-effort; the RootSignals hourly self-heal is the backstop.
				// Runs on every node (LBs too) — this is what ships config_set_redis
				// to LB nodes so their Redis target can be pointed at the main server.
				exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php xcvm_core >/dev/null 2>&1 &');

				// The archive just put back its own yt-dlp, older than the one the daily
				// RootSignals check had installed; refresh it now, not up to a day later.
				exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php ytdlp >/dev/null 2>&1 &');

				// ipset for the blocklist's sets (RootSignalsCronJob::syncSets), on a server
				// installed before the installers took it. Background + best-effort: apt held
				// or unreachable leaves the blocks one rule each, and the next update tries again.
				// An argument list, no shell; post-update already runs as root.
				ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'ipset']);

				// Ensure GeoLite2 databases are present/refreshed after an update.
				// They are no longer shipped in the update archive, so fetch them
				// from the XC_VM_Update release. Best-effort: run in background so a
				// slow/failed download never blocks or fails the update.
				if (ServerRepository::getAll()[SERVER_ID]['is_main']) {
					UpdateLogger::info('Refreshing GeoLite2 databases (background)');
					exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:maxmind --force >/dev/null 2>&1 &');
				}

				if ($rFailed === []) {
					UpdateLogger::info('Post-update completed successfully');
				} else {
					// status above ran them again and marked the schema; say what came of it.
					$db->query('SELECT `status_uuid` FROM `settings`;');
					UpdateLogger::error('Post-update completed, but a database migration failed on the first attempt: ' . ((string) $db->get_col() === StatusCommand::schemaMark() ? 'it applied when status ran it again' : 'it still fails, run "sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php status" for the cause'));
				}
				break;
		}

		return 0;
	}

	/**
	 * A release version (x.y.z, or a nightly x.y.z-dev.N when MAIN runs the dev
	 * channel) as an update names it, or null for none or anything else.
	 */

	/**
	 * The migration list a rollback reverses to, from the target release's archive.
	 * Only a release up to 2.5.3 gets one: its runner reads migrations/*.sql, so what is
	 * reversed stays reversed. A later release runs migrations/database/up/, which the
	 * updater leaves in place (it copies and deletes nothing), so it would apply every
	 * reversed file again, with what the reversal dropped gone. null leaves the schema
	 * as it is, as for an archive that lists no migrations: an empty list must never
	 * mean "reverse everything".
	 *
	 * @return string[]|null
	 */
	public static function rollbackMigrations(string $rArchive): ?array {
		if (ReleaseArchiveInspector::listSubpathFiles($rArchive, 'migrations/database/up') !== []) {
			return null;
		}
		$rOld = array_values(array_filter(ReleaseArchiveInspector::listSubpathFiles($rArchive, 'migrations'), static fn(string $rName): bool => str_ends_with($rName, '.sql')));
		return $rOld === [] ? null : $rOld;
	}

	/**
	 * MAIN keeps a dump of the database from before the update's migrations, the only
	 * copy of what a migration drops. Best effort: an update is never stopped by it.
	 * One pre_update file is kept, the newest.
	 */
	private static function dumpBeforeUpdate(Database $db, string $rTarget): void {
		$rNeed = BackupService::estimateSize($db);
		$rFree = (float) @disk_free_space(MAIN_HOME . 'backups');
		if (!BackupService::roomForDump($rNeed, $rFree)) {
			echo "WARNING: no room for a database backup before the update, updating without one.\n";
			UpdateLogger::error('No room for a pre-update DB backup (about ' . $rNeed . ' bytes, needed twice over plus 512 MiB; ' . (int) $rFree . ' free): updating without one');
			return;
		}
		echo "Backing up database...\n";
		UpdateLogger::info('Creating pre-update DB backup');
		$rFile = BackupService::dumpFor($db, 'pre_update_' . XC_VM_VERSION . '_to_' . $rTarget);
		if ($rFile === null) {
			echo "WARNING: the database backup before the update failed, updating without one.\n";
			UpdateLogger::error('Pre-update DB backup failed: updating without one');
			return;
		}
		BackupService::keepOnly(MAIN_HOME . 'backups/', 'pre_update_', $rFile);
		UpdateLogger::info('Pre-update DB backup OK: ' . basename($rFile) . ' (' . filesize($rFile) . ' bytes)');
	}

	public static function pinned(mixed $rVersion): ?string {
		$rVersion = is_string($rVersion) ? trim($rVersion) : '';
		return preg_match('/^\d+\.\d+\.\d+$/', $rVersion) || GitHubReleases::isDevVersion($rVersion) ? $rVersion : null;
	}

	/**
	 * MAIN: the load balancers its last update has yet to see on its release,
	 * as a JSON map of server id to when it was told (0: not yet). cron:servers
	 * tells the ones that are back, and drops each once it is on MAIN's release.
	 */
	public const PENDING = CONFIG_PATH . 'lbs_to_update.json';

	/** Seconds a told update lives (the signals purge, a node.root command's TTL): after it, a listed one is told again. */
	public const TELL_AGAIN = 86400;

	/**
	 * Of the load balancers MAIN's update listed ($rPending, id => told at),
	 * those to tell now and those still listed. One is told when it is enabled,
	 * online and heard from in the last 180 s, and was not told in the last
	 * day: never, or its update was not queued, or it expired while the node
	 * was away. One that is gone, or on MAIN's release by now, is dropped.
	 * Only listed ones are ever told: a load balancer an admin rolled back
	 * reached MAIN's release first, and is on an older one on purpose.
	 *
	 * @param array<int, array<string, mixed>> $rServers by server id
	 * @param array<mixed> $rPending
	 * @return array{tell: list<int>, wait: array<int, int>}
	 */
	public static function lbsToTell(array $rServers, array $rPending, int $rNow): array {
		$rOut = ['tell' => [], 'wait' => []];
		foreach ($rPending as $rID => $rToldAt) {
			$rID = (int) $rID;
			$rServer = $rServers[$rID] ?? null;
			if ($rServer === null || !empty($rServer['is_main']) || (int) ($rServer['server_type'] ?? 0) === 1
				|| !version_compare((string) ($rServer['xc_vm_version'] ?? ''), XC_VM_VERSION, '<')
			) {
				continue;
			}
			$rBack = !empty($rServer['enabled']) && (int) ($rServer['status'] ?? 0) === 1 && $rNow - (int) ($rServer['last_check_ago'] ?? 0) <= 180;
			if ($rBack && $rNow - (int) $rToldAt >= self::TELL_AGAIN) {
				$rOut['tell'][] = $rID;
			}
			$rOut['wait'][$rID] = (int) $rToldAt;
		}
		return $rOut;
	}

	/** MAIN's release as its servers row records it, or null. */
	private static function mainVersion(): ?string {
		foreach (ServerRepository::getAll() as $rServer) {
			if (!empty($rServer['is_main'])) {
				return $rServer['xc_vm_version'] ?? null;
			}
		}
		return null;
	}

	private function downloadFile($url, $targetPath): bool {
		$rData = @fopen($url, 'rb');
		if (!$rData) {
			return false;
		}
		$rOutput = fopen($targetPath, 'wb');
		stream_copy_to_stream($rData, $rOutput);
		fclose($rData);
		fclose($rOutput);
		return true;
	}
}
