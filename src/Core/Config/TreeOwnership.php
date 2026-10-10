<?php

namespace XcVm\Core\Config;

use XcVm\Core\Cluster\NodeRole;

/**
 * Who writes what under the install base (MAIN_HOME), in one place.
 *
 * The whole tree is handed to xc_vm: BLANKET_CHOWNS names every statement
 * that does. WRITABLE is what xc_vm has to write while the panel runs, and
 * ROOT_RUNS what root's crons, the service unit and the updater run or load
 * from the tree. `console.php root:paths` (RootPathsCommand) prints, for
 * each path root runs, whether xc_vm can replace it.
 *
 * On MAIN, what root runs is then taken back: ROOT_OWNED is root's, with
 * xc_vm's group still reading it (rootOwn(), which `status` runs as root at
 * every start, and so after every install and update). A load balancer's
 * tree stays xc_vm's for now. In Core, which ships to load balancers: the
 * check runs on a node of either kind.
 */
final class TreeOwnership {
	/** The user the panel's own processes run as. */
	public const USER = 'xc_vm';

	/**
	 * What xc_vm writes while the panel runs, from the base (a directory ends
	 * in `/` and stands for all that is under it): the data directories
	 * (ConstantsInitializer::paths()), what `service` gives xc_vm at every
	 * start, what an update keeps as the node's own, and the directories of
	 * the daemons that run as xc_vm. A first list, from the code: `root:paths`
	 * on a running node says where it meets what root runs.
	 */
	public const WRITABLE = [
		'backups/',
		'config/',
		'content/',
		'signals/',
		'storage/',
		'tmp/',
		// Module installs from the panel, and the licences cron:module_licenses renews.
		'Modules/',
		// certbot's keys, which only their owner reads.
		'bin/certbot/',
		'bin/cluster_bus/',
		// `startup` writes the pools again, as xc_vm on a new load balancer.
		'bin/daemons.sh',
		'bin/php/etc/',
		'bin/ffmpeg_bin/',
		// The proxy archive (cron:proxy) and the install logs.
		'bin/install/',
		'bin/maxmind/',
		// Not the two nginx binaries root runs (ROOT_RUNS): their configuration,
		// logs and temporary files.
		'bin/nginx/conf/',
		'bin/nginx/logs/',
		'bin/nginx/client_body_temp/',
		'bin/nginx/fastcgi_temp/',
		'bin/nginx/proxy_temp/',
		'bin/nginx/scgi_temp/',
		'bin/nginx/uwsgi_temp/',
		'bin/nginx_rtmp/conf/',
		'bin/nginx_rtmp/logs/',
		'bin/nginx_rtmp/client_body_temp/',
		'bin/nginx_rtmp/fastcgi_temp/',
		'bin/nginx_rtmp/proxy_temp/',
		'bin/nginx_rtmp/scgi_temp/',
		'bin/nginx_rtmp/uwsgi_temp/',
		'bin/php/sessions/',
		'bin/php/sockets/',
		'bin/php/var/',
		'bin/redis/',
		'bin/xc_agent/',
		'bin/xc_fanout/',
		// Translator writes the missing keys of a language.
		'Core/Localization/lang/',
	];

	/**
	 * What root runs or loads from the tree, from the base, and what brings
	 * root to it: root's crontab (StartupCommand::installRootCrontab), the
	 * systemd unit, `service` and `update`. chain() adds what those name
	 * themselves.
	 */
	public const ROOT_RUNS = [
		'service' => 'the systemd unit starts it',
		'update' => 'the updater, started with sudo',
		'bin/php/bin/php' => "the PHP of root's crons, of `service` and of the updater",
		'bin/php/lib/' => 'its php.ini and its extensions',
		'console.php' => 'every command of root starts here',
		'bootstrap.php' => 'console.php loads it',
		'vendor/' => 'the autoloader and the libraries',
		'Cli/' => 'classes the autoloader loads',
		'Core/' => 'classes the autoloader loads',
		'Domain/' => 'classes the autoloader loads',
		'Infrastructure/' => 'classes the autoloader loads',
		'Ministra/' => 'classes the autoloader loads',
		'Public/' => 'classes the autoloader loads',
		'Streaming/' => 'classes the autoloader loads',
		'Modules/' => 'console.php loads every installed module',
		'config/modules.php' => 'the module loader requires it as PHP',
		'bin/nginx/sbin/nginx' => 'cron:root_signals reloads nginx with it, and the certbot cron runs it',
		'bin/nginx_rtmp/sbin/nginx_rtmp' => 'cron:root_signals reloads nginx_rtmp with it, and the certbot cron runs it',
		'bin/yt-dlp' => '`console.php ytdlp` runs it for its version',
		'tmp/.update.tar.gz' => 'the updater unpacks it over the tree, and post-update takes the installers from it',
		'bin/install/update_binaries.sh' => '`console.php binaries` runs it',
		'bin/install/install_xcvm_core.sh' => "a node being installed runs MAIN's copy",
		// Not bin/xc_agent/xc_agent: root starts it as the owner of its directory (ArtefactStage::runs()).
		'bin/xc_fanout/xc_fanout' => '`console.php fanout_binary` runs it for its version',
	];

	/**
	 * What is root's on MAIN, from the base, so that xc_vm cannot replace what
	 * root runs or loads: true for everything under a directory, false for the
	 * entry alone. The group stays xc_vm's, which reads all of it and writes
	 * none (no file of the tree is group-writable).
	 *
	 * The directories on the way are root's too, the base among them: the owner
	 * of a directory replaces what is in it, whoever owns the file. What xc_vm
	 * has to write in them is its own directory there (config/, tmp/, the
	 * working directories under bin/), which root's ownership of the parent
	 * leaves alone.
	 *
	 * Not here, and still xc_vm's to replace (`root:paths` names them): the
	 * modules and config/modules.php, which the panel installs and root's
	 * console loads; the two scripts of bin/install/; the update archive in
	 * tmp/; bin/xc_fanout/xc_fanout.
	 */
	public const ROOT_OWNED = [
		'' => false,
		'console.php' => false,
		'bootstrap.php' => false,
		'service' => false,
		'update' => false,
		'vendor' => true,
		'Cli' => true,
		'Core' => true,
		'Domain' => true,
		'Infrastructure' => true,
		'Ministra' => true,
		'Public' => true,
		'Streaming' => true,
		'bin' => false,
		'bin/php' => false,
		'bin/php/bin' => true,
		'bin/php/sbin' => true,
		'bin/php/lib' => true,
		'bin/nginx' => false,
		'bin/nginx/sbin' => true,
		'bin/nginx_rtmp' => false,
		'bin/nginx_rtmp/sbin' => true,
		'bin/yt-dlp' => false,
	];

	/**
	 * Every statement that gives the whole tree to xc_vm, by its file (from
	 * the repository's root) and as it is written there. The installer, the
	 * updater and update_binaries.sh are Python and shell, and the two SSH
	 * installs send their own words to the node, so each keeps its
	 * statement; post-update runs chownTree(). A change of the tree's owner
	 * has to reach all six.
	 */
	public const BLANKET_CHOWNS = [
		'install' => 'run_command("chown -R xc_vm:xc_vm /home/xc_vm")',
		'src/update' => 'os.system(\'sudo chown -R xc_vm:xc_vm "%s"\' % rBaseDir)',
		'src/bin/install/update_binaries.sh' => 'sudo chown xc_vm:xc_vm -R /home/xc_vm >/dev/null 2>&1',
		'src/Cli/Commands/LbInstallFlow.php' => 'call_user_func($rRunSSH, $rConn, \'sudo chown xc_vm:xc_vm -R /home/xc_vm >/dev/null 2>&1\');',
		'src/Cli/Commands/ServerInstallCommand.php' => 'call_user_func($rRunSSH, $rConn, \'sudo chown -R xc_vm:xc_vm \' . MAIN_HOME);',
		'src/Cli/Commands/UpdateCommand.php' => 'ProcessRunner::run(TreeOwnership::chownTree(MAIN_HOME));',
	];

	/**
	 * The command that gives the tree at $rBase to xc_vm, as an argv list.
	 *
	 * @return non-empty-list<string>
	 */
	public static function chownTree(string $rBase): array {
		return ['sudo', 'chown', '-R', self::USER . ':' . self::USER, $rBase];
	}

	/**
	 * The commands, as argv lists, that make what root runs root's (ROOT_OWNED)
	 * in the tree at $rBase, for root to run after the tree was handed to
	 * xc_vm: the entries alone, the directories with what is under them, then
	 * back to xc_vm what it writes inside one of those (an entry of WRITABLE,
	 * as the languages under Core/). Only what exists is named; links are
	 * changed themselves, never followed.
	 *
	 * @return list<non-empty-list<string>>
	 */
	public static function rootOwn(string $rBase): array {
		$rBase = rtrim($rBase, '/') . '/';
		$rAlone = $rTrees = $rBack = [];
		foreach (self::ROOT_OWNED as $rPath => $rTree) {
			$rFull = rtrim($rBase . $rPath, '/');
			if (!file_exists($rFull) && !is_link($rFull)) {
				continue;
			}
			if ($rTree) {
				$rTrees[] = $rFull;
				foreach (self::WRITABLE as $rEntry) {
					if (str_starts_with($rEntry, $rPath . '/') && file_exists($rBase . $rEntry)) {
						$rBack[] = rtrim($rBase . $rEntry, '/');
					}
				}
			} else {
				$rAlone[] = $rFull;
			}
		}
		$rOwner = 'root:' . self::USER;
		$rCommands = [];
		if ($rAlone !== []) {
			$rCommands[] = array_merge(['chown', '-h', $rOwner], $rAlone);
		}
		if ($rTrees !== []) {
			$rCommands[] = array_merge(['chown', '-hR', $rOwner], $rTrees);
		}
		if ($rBack !== []) {
			$rCommands[] = array_merge(['chown', '-hR', self::USER . ':' . self::USER], $rBack);
		}
		return $rCommands;
	}

	/**
	 * Who a file an updater has just put in place belongs to: root for what is
	 * root's on MAIN (rootOwn()), else xc_vm.
	 *
	 * @param string $rPath From the base, as ROOT_OWNED names it.
	 */
	public static function owner(string $rPath): string {
		if (!NodeRole::isMain()) {
			return self::USER;
		}
		foreach (self::ROOT_OWNED as $rEntry => $rTree) {
			if ($rPath === $rEntry || ($rTree && str_starts_with($rPath, $rEntry . '/') && self::writable($rPath) === null)) {
				return 'root';
			}
		}
		return self::USER;
	}

	/** The entry of WRITABLE that $rPath (from the base) is, or is under; else null. */
	public static function writable(string $rPath): ?string {
		foreach (self::WRITABLE as $rEntry) {
			if ($rPath === $rEntry || (str_ends_with($rEntry, '/') && str_starts_with($rPath . '/', $rEntry))) {
				return $rEntry;
			}
		}
		return null;
	}

	/**
	 * The absolute paths a command line starts as root: its program, when it
	 * is named by its path, and the paths among its arguments (the script an
	 * interpreter is handed). A line run through `sudo -u` is that user's,
	 * and a program found through PATH is not looked for.
	 *
	 * @return list<string>
	 */
	public static function rootRuns(string $rLine): array {
		// Without its redirections and what follows a `#`.
		$rLine = (string) preg_replace(['/\d*[<>]+&?\s*\S+/', '/(^|\s)#.*/'], '', $rLine);
		$rWords = preg_split('/\s+/', $rLine, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if (($rWords[0] ?? '') === 'sudo') {
			array_shift($rWords);
		}
		if (!str_starts_with($rWords[0] ?? '', '/')) {
			return [];
		}
		return array_values(preg_grep('#^/[\w./+-]+$#', $rWords) ?: []);
	}

	/**
	 * Every path root runs or loads: ROOT_RUNS under $rBase, and what the
	 * panel's lines of root's crontab ($rCrontab), the Exec lines of the
	 * systemd unit ($rUnit) and the lines of `service` ($rService) start as
	 * root.
	 *
	 * @return array<string, list<string>> path => what brings root to it
	 */
	public static function chain(string $rBase, string $rCrontab, string $rUnit, string $rService): array {
		$rChain = [];
		foreach (self::ROOT_RUNS as $rPath => $rWho) {
			$rChain[$rBase . $rPath][] = $rWho;
		}
		$rLines = [];
		foreach (explode("\n", $rCrontab) as $rLine) {
			// The panel's lines, as installRootCrontab() knows them, without their schedule.
			if (!str_starts_with(ltrim($rLine), '#') && (str_contains($rLine, '# XC_VM') || str_contains($rLine, $rBase))) {
				$rLines[] = ["named in root's crontab", (string) preg_replace('/^\s*(@\w+\s+|(\S+\s+){5})/', '', $rLine)];
			}
		}
		foreach (explode("\n", $rUnit) as $rLine) {
			if (preg_match('/^Exec\w+=[-@:+!]*(.*)/', $rLine, $rMatch)) {
				$rLines[] = ['named in the systemd unit', $rMatch[1]];
			}
		}
		$rHome = preg_match('/^SCRIPT=(\S+)/m', $rService, $rMatch) ? $rMatch[1] : rtrim($rBase, '/');
		foreach (explode("\n", $rService) as $rLine) {
			$rLines[] = ['named in `service`', str_replace('$SCRIPT', $rHome, $rLine)];
		}
		foreach ($rLines as [$rWho, $rLine]) {
			foreach (self::rootRuns($rLine) as $rPath) {
				if (!in_array($rWho, $rChain[$rPath] ?? [], true)) {
					$rChain[$rPath][] = $rWho;
				}
			}
		}
		return $rChain;
	}

	/**
	 * $rPath, and each path a symbolic link on the way to it leads to, the
	 * link put in its target's place, down to the last: realpath(), or the
	 * missing target of a dangling link. Each is a way to what root runs.
	 *
	 * @return list<string>
	 */
	public static function hops(string $rPath): array {
		$rHops = [$rPath = self::normal($rPath)];
		// ponytail: 40 links at most, as the kernel's own limit for one path.
		for ($rTurn = 0; $rTurn < 40; $rTurn++) {
			$rParts = array_values(array_filter(explode('/', $rPath), static fn(string $rPart): bool => $rPart !== ''));
			$rAt = '';
			foreach ($rParts as $i => $rPart) {
				$rAt .= '/' . $rPart;
				$rLink = @readlink($rAt);
				if ($rLink !== false) {
					$rRest = array_slice($rParts, $i + 1);
					$rPath = self::normal((str_starts_with($rLink, '/') ? $rLink : dirname($rAt) . '/' . $rLink) . '/' . implode('/', $rRest));
					$rHops[] = $rPath;
					continue 2;
				}
			}
			break;
		}
		return array_values(array_unique($rHops));
	}

	/** $rPath without `.`, `..` and doubled slashes, read as written. */
	private static function normal(string $rPath): string {
		$rParts = [];
		foreach (explode('/', $rPath) as $rPart) {
			if ($rPart === '..') {
				array_pop($rParts);
			} elseif ($rPart !== '' && $rPart !== '.') {
				$rParts[] = $rPart;
			}
		}
		return '/' . implode('/', $rParts);
	}

	/**
	 * How the user $rUid, in the groups $rGids, can write what $rStat is of:
	 * as its `owner` (who can give itself the right), through its `group` or
	 * as one of the `other`s; else null. The kernel's order: a member of the
	 * group has the group's rights, not the others'. Access lists are not read.
	 *
	 * @param array<int|string, int> $rStat
	 * @param list<int> $rGids
	 */
	private static function writes(array $rStat, int $rUid, array $rGids): ?string {
		if ($rStat['uid'] === $rUid) {
			return 'owner';
		}
		if (in_array($rStat['gid'], $rGids, true)) {
			return $rStat['mode'] & 0020 ? 'group' : null;
		}
		return $rStat['mode'] & 0002 ? 'other' : null;
	}

	/**
	 * $rPath and each directory above it, from `/` down: its owner, its group
	 * and its mode, and how the user can write it (writes()), or null. In a
	 * sticky directory the user replaces only what is its own, so one that is
	 * not the user's, above an entry that is not either, has null. What is
	 * not there has null throughout; a symbolic link has `link` for its mode
	 * and null: it is replaced through the directory it is in.
	 *
	 * @param list<int> $rGids
	 * @return list<array{path: string, owner: ?string, group: ?string, mode: ?string, by: ?string}>
	 */
	public static function trail(string $rPath, int $rUid, array $rGids): array {
		$rPaths = ['/'];
		$rAt = '';
		foreach (array_filter(explode('/', $rPath), static fn(string $rPart): bool => $rPart !== '') as $rPart) {
			$rPaths[] = $rAt .= '/' . $rPart;
		}
		$rRows = [];
		$rStats = [];
		foreach ($rPaths as $i => $rAt) {
			$rStat = $rStats[$i] = @lstat($rAt);
			if ($rStat === false) {
				$rRows[] = ['path' => $rAt, 'owner' => null, 'group' => null, 'mode' => null, 'by' => null];
				continue;
			}
			$rLink = ($rStat['mode'] & 0170000) === 0120000;
			$rRows[] = [
				'path' => $rAt,
				'owner' => posix_getpwuid($rStat['uid'])['name'] ?? (string) $rStat['uid'],
				'group' => posix_getgrgid($rStat['gid'])['name'] ?? (string) $rStat['gid'],
				'mode' => $rLink ? 'link' : sprintf('%04o', $rStat['mode'] & 07777),
				'by' => $rLink ? null : self::writes($rStat, $rUid, $rGids),
			];
		}
		foreach ($rRows as $i => $rRow) {
			$rStat = $rStats[$i];
			$rBelow = $rStats[$i + 1] ?? false;
			if ($rStat !== false && $rBelow !== false && $rRow['by'] !== null && $rRow['by'] !== 'owner' && ($rStat['mode'] & 01000) && $rBelow['uid'] !== $rUid) {
				$rRows[$i]['by'] = null;
			}
		}
		return $rRows;
	}

	/**
	 * The path of $rTrail (trail()) through which the user replaces its last
	 * one: the first it can write, a directory above or the path itself; null
	 * when it can write none.
	 *
	 * @param list<array{path: string, owner: ?string, group: ?string, mode: ?string, by: ?string}> $rTrail
	 */
	public static function through(array $rTrail): ?string {
		foreach ($rTrail as $rRow) {
			if ($rRow['by'] !== null) {
				return $rRow['path'];
			}
		}
		return null;
	}

	/**
	 * What the user can write under the directory $rDir, for a directory it
	 * cannot replace itself: each file, and each directory (ending in `/`,
	 * and not looked into: all of it is the user's). A symbolic link is named,
	 * with what it leads to, when the user can replace that.
	 *
	 * @param list<int> $rGids
	 * @return list<string>
	 */
	public static function inside(string $rDir, int $rUid, array $rGids): array {
		$rFound = [];
		foreach (@scandir($rDir) ?: [] as $rName) {
			$rPath = rtrim($rDir, '/') . '/' . $rName;
			$rStat = $rName === '.' || $rName === '..' ? false : @lstat($rPath);
			if ($rStat === false) {
				continue;
			}
			$rType = $rStat['mode'] & 0170000;
			if ($rType === 0120000) {
				// ponytail: a link is judged by where it leads and not walked; walk it if the tree starts linking directories.
				$rReal = realpath($rPath);
				if ($rReal !== false && self::through(self::trail($rReal, $rUid, $rGids)) !== null) {
					$rFound[] = $rPath . ' -> ' . $rReal;
				}
			} elseif (self::writes($rStat, $rUid, $rGids) !== null) {
				$rFound[] = $rPath . ($rType === 0040000 ? '/' : '');
			} elseif ($rType === 0040000) {
				array_push($rFound, ...self::inside($rPath, $rUid, $rGids));
			}
		}
		return $rFound;
	}
}
