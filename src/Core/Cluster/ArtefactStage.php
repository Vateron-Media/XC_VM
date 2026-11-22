<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Logging\FileLogger;

/**
 * Artefacts on the node (plan, section 7: the `artefact` op and "Root
 * artefacts"; section 8: custom off-air videos "via artefact"): files MAIN
 * hands a node through a grant, a `cmd`-signed command that names the
 * artefact's id, size, SHA-256 and expiry.
 *
 * ```text
 * grant  = args.artefact {id, name, size, sha256, mtime, exp}
 *          id: offair/<name> | module/<name>/<version> | agent/<arch>
 * agent  → config/cluster/artefacts/<cmd_id>        xc_vm, 0600: its download,
 *                                                   checked by the agent itself
 * off-air video (artefact.fetch, cluster:exec, xc_vm)
 *        → content/video/cluster/<name>             checked, then renamed in
 * root artefact (node.root carrying a grant, cluster:root, root)
 *        → /etc/xc_vm/cluster/stage/<cmd_id>        root:root 0700 / 0600: copied
 *                                                   and checked there, then the
 *                                                   action runs with that copy
 * ```
 *
 * Whatever the agent checked, the bytes a node uses are checked here against
 * the signed grant's size and SHA-256 as they are copied to where they are
 * used, and anything else is refused and audited (refuse()): a `log.syslog`
 * line of type ARTEFACT for MAIN's system log, and the refusal in the
 * command's ack, which MAIN audits too. Root reads the agent's download only
 * with the agent's rights (SettingsAudit::asAgentUser), never follows a link
 * planted there, and runs the action on its own copy, which nothing but root
 * can change. Everything a grant names is data: the id and name are checked
 * for shape, and no path comes from anywhere but the node's own directories.
 */
final class ArtefactStage {
	/** The largest chunk the `artefact` op serves (plan section 7: ≤ 4 MB). */
	public const MAX_CHUNK = 4194304;

	/** The command that grants an off-air video (run by cluster:exec). */
	public const TYPE_FETCH = 'artefact.fetch';

	/** A file name the node writes an artefact under: no path, no dot file. */
	public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/';

	/** A staged copy older than this is a crash's leftover (cluster:root prunes it). */
	public const STAGE_TTL = 3600;

	/** Bytes copied at a time. */
	private const BLOCK = 1048576;

	/** The most of an agent binary's `version` output kept, for its first line. */
	private const VERSION_OUTPUT = 65536;

	/** Beside the agent binary: the one an install replaced, which run.sh puts back (bin/xc_agent/run.sh). */
	public const PREV = '.prev';

	/** Beside the agent binary: `<installed at> <failed starts> [reach]` while the new one is on trial. */
	public const TRIAL = '.trial';

	/** An agent that says this on `version`'s features line writes config/cluster/reached (run.sh's reach check). */
	public const FEATURE_REACHED = 'reached';

	private static ?string $rDownloads = null;

	private static ?string $rVideos = null;

	private static ?string $rAgent = null;

	/**
	 * The root artefact the action cluster:root runs now was staged as, while
	 * it runs (withStaged()); null otherwise, and always on the signals path.
	 *
	 * @var array{path: string, grant: array<string, mixed>}|null
	 */
	private static ?array $rCurrent = null;

	/** Tests: other directories (and agent binary); null restores the defaults. */
	public static function useDirs(?string $rDownloads, ?string $rVideos, ?string $rAgent = null): void {
		self::$rDownloads = $rDownloads;
		self::$rVideos = $rVideos;
		self::$rAgent = $rAgent;
	}

	/** Where the agent writes what it downloaded, one file per command: `<cmd_id>`. */
	public static function downloads(): string {
		return self::$rDownloads ?? AgentPaths::file(AgentPaths::DIR . 'artefacts/');
	}

	/** Root's own stage, beside its pin of the panel key. */
	public static function stageDir(): string {
		return RootPin::dir() . 'stage/';
	}

	/** Closed xc_vm-owned stage used only after root has verified a module archive. */
	public static function moduleStageDir(): string {
		return dirname(rtrim(self::downloads(), '/')) . '/module-stage/';
	}

	/** Where the node keeps the off-air videos MAIN granted it. */
	public static function videoDir(): string {
		return self::$rVideos ?? ((defined('VIDEO_PATH') ? VIDEO_PATH : '/home/xc_vm/content/video/') . 'cluster/');
	}

	/** The xc_agent binary run.sh starts, which `fanout_binary` replaces. */
	public static function agentBinary(): string {
		return self::$rAgent ?? ((defined('BIN_PATH') ? BIN_PATH : '/home/xc_vm/bin/') . 'xc_agent/xc_agent');
	}

	/**
	 * Is this an artefact id of MAIN's registry? `offair/<name>` for the
	 * off-air names the `cluster` section carries, `module/<name>/<version>`
	 * as ModuleManager names a custom module's archive. Shape only: MAIN
	 * resolves it, never a node.
	 */
	public static function validId(string $rId): bool {
		$rParts = explode('/', $rId);
		return match ($rParts[0]) {
			'offair' => count($rParts) === 2 && isset(ReplicaSections::OFF_AIR[$rParts[1]]),
			'module' => count($rParts) === 3 && preg_match('/^[a-z0-9][a-z0-9-]{0,63}\z/', $rParts[1]) === 1
				&& preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}\z/', $rParts[2]) === 1 && !str_contains($rParts[2], '..'),
			default => false,
		};
	}

	/**
	 * A command's artefact grant, checked for shape.
	 *
	 * @param array<string, mixed> $rCmd The signed command, decoded.
	 * @return array{id: string, name: string, size: int, sha256: string, exp: int, cmd_id: string}|string the grant, or why it is refused
	 */
	public static function grant(array $rCmd): array|string {
		$rGrant = $rCmd['args']['artefact'] ?? null;
		$rCmdID = $rCmd['cmd_id'] ?? null;
		if (!is_array($rGrant) || !is_string($rCmdID) || !preg_match('/^[0-9a-f]{32}\z/', $rCmdID)) {
			return 'no artefact grant';
		}
		$rId = $rGrant['id'] ?? null;
		$rName = $rGrant['name'] ?? null;
		$rSize = $rGrant['size'] ?? null;
		$rHash = $rGrant['sha256'] ?? null;
		if (!is_string($rId) || !self::validId($rId)) {
			return 'not an artefact MAIN serves';
		}
		if (!is_string($rName) || !preg_match(self::NAME_PATTERN, $rName)) {
			return 'not a file name';
		}
		if (!is_int($rSize) || $rSize < 1 || !is_string($rHash) || !preg_match('/^[0-9a-f]{64}\z/', $rHash) || !is_int($rGrant['exp'] ?? null)) {
			return 'a malformed grant';
		}
		return ['id' => $rId, 'name' => $rName, 'size' => $rSize, 'sha256' => $rHash, 'exp' => (int) $rGrant['exp'], 'cmd_id' => $rCmdID];
	}

	/**
	 * `artefact.fetch` (cluster:exec, as xc_vm): the off-air video the agent
	 * downloaded, placed as `<videoDir>/<name>` once its size and SHA-256
	 * are the grant's. The copy is written aside and renamed in, so a player
	 * never reads half a video, and a refused one leaves the video placed
	 * before where it was.
	 *
	 * @param array<string, mixed> $rCmd The signed command, decoded.
	 * @return array{placed: string, size: int, sha256: string}|string what was placed, or the refusal (audited)
	 */
	public static function placeOffAir(array $rCmd): array|string {
		$rGrant = self::grant($rCmd);
		if (is_string($rGrant)) {
			return self::refuse($rCmd, $rGrant);
		}
		if (!str_starts_with($rGrant['id'], 'offair/')) {
			return self::refuse($rCmd, 'not an off-air video');
		}
		$rWhy = 'the agent\'s directory is not the agent\'s';
		$rDone = SettingsAudit::asAgentUser(static function () use ($rGrant, &$rWhy): bool {
			$rIn = self::openDownload($rGrant, $rWhy);
			if ($rIn !== null) {
				$rWhy = self::placeVideo($rIn, $rGrant);
				fclose($rIn);
			}
			// Spent, placed or not (a link planted there goes, never what it names).
			@unlink(self::downloads() . $rGrant['cmd_id']);
			return $rWhy === null;
		}, self::agentDir());
		if (!$rDone) {
			return self::refuse($rCmd, (string) $rWhy);
		}
		return ['placed' => $rGrant['name'], 'size' => $rGrant['size'], 'sha256' => $rGrant['sha256']];
	}

	/**
	 * Copy an off-air video into the node's video directory, checked as it is
	 * written aside, then rename it in. Null once placed, else why not.
	 *
	 * @param resource $rIn
	 * @param array<string, mixed> $rGrant
	 */
	private static function placeVideo($rIn, array $rGrant): ?string {
		$rDir = self::videoDir();
		if (!is_dir($rDir) && !@mkdir($rDir, 0755, true) && !is_dir($rDir)) {
			return 'cannot create ' . $rDir;
		}
		$rTmp = $rDir . '.' . $rGrant['name'] . '.' . $rGrant['cmd_id'] . '.tmp';
		$rWhy = self::copyChecked($rIn, $rTmp, $rGrant, 0644);
		if ($rWhy === null && !@rename($rTmp, $rDir . $rGrant['name'])) {
			@unlink($rTmp);
			$rWhy = 'cannot place it';
		}
		return $rWhy;
	}

	/**
	 * A root command's artefact (cluster:root, as root): the agent's download,
	 * read with the agent's rights, copied into root's own stage and checked
	 * there. The agent's copy goes either way; the staged one stays for the
	 * action (withStaged()) and goes after it (discard()).
	 *
	 * @param array<string, mixed> $rCmd The verified command, decoded.
	 * @return array{path: string, grant: array<string, mixed>}|string the staged copy, or the refusal (audited)
	 */
	public static function stage(array $rCmd): array|string {
		$rGrant = self::grant($rCmd);
		if (is_string($rGrant)) {
			return self::refuse($rCmd, $rGrant);
		}
		$rStage = self::stageDir();
		if (!self::ownStage($rStage)) {
			self::spend($rGrant['cmd_id']);
			return self::refuse($rCmd, 'root\'s stage ' . $rStage . ' is not root\'s alone');
		}
		$rWhy = 'the agent\'s directory is not the agent\'s';
		$rIn = null;
		SettingsAudit::asAgentUser(static function () use ($rGrant, &$rIn, &$rWhy): bool {
			$rIn = self::openDownload($rGrant, $rWhy);
			return $rIn !== null;
		}, self::agentDir());
		if ($rIn === null) {
			self::spend($rGrant['cmd_id']);
			return self::refuse($rCmd, (string) $rWhy);
		}
		$rPath = $rStage . $rGrant['cmd_id'];
		$rWhy = self::copyChecked($rIn, $rPath, $rGrant, 0600);
		fclose($rIn);
		self::spend($rGrant['cmd_id']);
		if ($rWhy !== null) {
			return self::refuse($rCmd, $rWhy);
		}
		return ['path' => $rPath, 'grant' => $rGrant];
	}

	/**
	 * Run a root action with its staged artefact, which current() hands it
	 * while it runs.
	 *
	 * @param array{path: string, grant: array<string, mixed>} $rStaged
	 * @template T
	 * @param callable(): T $rRun
	 * @return T
	 */
	public static function withStaged(array $rStaged, callable $rRun): mixed {
		self::$rCurrent = $rStaged;
		try {
			return $rRun();
		} finally {
			self::$rCurrent = null;
		}
	}

	/**
	 * The staged artefact of the root action running now: set only by
	 * cluster:root, after its checks. A `signals` row, or a payload naming a
	 * path, never gets one.
	 *
	 * @return array{path: string, grant: array<string, mixed>}|null
	 */
	public static function current(): ?array {
		return self::$rCurrent;
	}

	/**
	 * Remove the agent's download of a command root refused before staging
	 * it (a replay, an expired command, a bad signature), so it does not stay
	 * for the agent's 25 hours: only `<cmd_id>` (32 hex) in the agent's own
	 * directory, as the agent's user, which the agent could remove itself.
	 */
	public static function spendRefused(string $rDoc): void {
		$rCmd = json_decode($rDoc, true);
		$rCmdID = is_array($rCmd) && is_array($rCmd['args']['artefact'] ?? null) ? ($rCmd['cmd_id'] ?? null) : null;
		if (is_string($rCmdID) && preg_match('/^[0-9a-f]{32}\z/', $rCmdID)) {
			self::spend($rCmdID);
		}
	}

	/** Remove a staged copy once its action ran. */
	public static function discard(array $rStaged): void {
		@unlink((string) $rStaged['path']);
	}

	/** Remove what a crash left staged. */
	public static function pruneStage(int $rNow): void {
		foreach (glob(self::stageDir() . '*') ?: [] as $rFile) {
			if (!is_link($rFile) && is_file($rFile) && (int) @filemtime($rFile) < $rNow - self::STAGE_TTL) {
				@unlink($rFile);
			}
		}
	}

	/**
	 * `fanout_binary` (root): install a checked agent binary where run.sh
	 * starts it, as the agent's user (the directory is xc_vm's), from root's
	 * copy of the release asset. It is checked again (size, SHA-256) as it is
	 * written aside, run once there (runs(): a binary that does not start on
	 * this node is never put where run.sh would restart it every 2 s), then
	 * renamed in. With $rTrial (an agent runs to judge it), the binary it
	 * replaces is kept as PREV and the new one put on TRIAL: run.sh restores
	 * PREV if the new one fails at start three times within its trial, or,
	 * for an agent that says it reports reaching MAIN (its `version`'s
	 * features line), if it has not reached MAIN in time. PREV is the last
	 * binary that proved itself: one replaced while still on trial never
	 * becomes it, unless there is no PREV at all.
	 *
	 * @param array{path: string, grant: array<string, mixed>} $rStaged
	 * @return string|null null once installed, else why not
	 */
	public static function installAgent(array $rStaged, string $rTarget, bool $rTrial = true): ?string {
		$rIn = @fopen((string) $rStaged['path'], 'rb');
		if ($rIn === false) {
			return 'the staged copy is gone';
		}
		$rDir = dirname($rTarget) . '/';
		$rTmp = $rDir . '.' . basename($rTarget) . '.new';
		$rWhy = 'the agent\'s directory is not the agent\'s';
		SettingsAudit::asAgentUser(static function () use ($rIn, $rTmp, $rStaged, &$rWhy): bool {
			$rWhy = self::copyChecked($rIn, $rTmp, $rStaged['grant'], 0755);
			return $rWhy === null;
		}, $rDir);
		fclose($rIn);
		$rVersion = $rWhy === null ? self::runs($rTmp, $rDir) : null;
		if ($rWhy === null && $rVersion === null) {
			$rWhy = 'it does not run on this node (' . php_uname('m') . ')';
		}
		$rReach = $rVersion !== null && self::says($rVersion, self::FEATURE_REACHED);
		$rPlaced = SettingsAudit::asAgentUser(static function () use ($rTmp, $rTarget, $rReach, $rTrial, &$rWhy): bool {
			// The binary it replaces stays beside it, and the new one is on trial:
			// run.sh puts the old one back if the new one keeps failing at start
			// (or, with `reach`, never reaches MAIN). One still on trial has not
			// proved itself, so the PREV before it stays.
			if ($rWhy === null && $rTrial && is_file($rTarget) && (!is_file($rTarget . self::TRIAL) || !is_file($rTarget . self::PREV))) {
				@unlink($rTarget . self::PREV);
				@link($rTarget, $rTarget . self::PREV) || @copy($rTarget, $rTarget . self::PREV);
				@chmod($rTarget . self::PREV, 0755);
			}
			if ($rWhy === null && @rename($rTmp, $rTarget)) {
				if ($rTrial) {
					@file_put_contents($rTarget . self::TRIAL, time() . ' 0' . ($rReach ? ' reach' : '') . "\n");
				} else {
					@unlink($rTarget . self::TRIAL);
				}
				return true;
			}
			@unlink($rTmp);
			return false;
		}, $rDir);
		return $rPlaced ? null : ($rWhy ?? 'cannot install it');
	}

	/**
	 * Copy a verified root-staged module archive into xc_vm's closed stage.
	 *
	 * @param array{path: string, grant: array<string, mixed>} $rStaged
	 * @return array{path: string, grant: array<string, mixed>}|string
	 */
	public static function handoffModuleArchive(array $rStaged): array|string {
		$rGrant = $rStaged['grant'];
		if (!is_string($rGrant['cmd_id'] ?? null) || preg_match('/^[0-9a-f]{32}\z/', $rGrant['cmd_id']) !== 1) {
			return 'the grant has no command id';
		}
		$rWhy = self::stagedArchive((string) $rStaged['path'], $rGrant);
		if ($rWhy !== null) {
			return $rWhy;
		}
		$rIn = @fopen((string) $rStaged['path'], 'rb');
		if ($rIn === false) {
			return 'the staged copy is gone';
		}
		$rDir = self::moduleStageDir();
		$rPath = $rDir . $rGrant['cmd_id'];
		$rWhy = 'cannot prepare xc_vm module stage';
		$rDone = SettingsAudit::asAgentUser(static function () use ($rDir, $rPath, $rGrant, $rIn, &$rWhy): bool {
			$rBase = rtrim($rDir, '/');
			if (is_link($rBase) || (!is_dir($rBase) && !@mkdir($rBase, 0700))) {
				return false;
			}
			@chmod($rBase, 0700);
			clearstatcache(true, $rBase);
			$rStat = @lstat($rBase);
			if ($rStat === false || ($rStat['mode'] & 0170000) !== 0040000 || $rStat['uid'] !== posix_geteuid() || ($rStat['mode'] & 0077) !== 0) {
				return false;
			}
			$rWhy = self::copyChecked($rIn, $rPath, $rGrant, 0600);
			return $rWhy === null;
		}, self::agentDir());
		fclose($rIn);
		return $rDone ? ['path' => $rPath, 'grant' => $rGrant] : $rWhy;
	}

	/** Remove the unprivileged module handoff after its synchronous installer exits. */
	public static function discardModuleArchive(array $rStaged): void {
		$rPath = (string) ($rStaged['path'] ?? '');
		$rCmdID = (string) ($rStaged['grant']['cmd_id'] ?? '');
		if ($rCmdID === '' || $rPath !== self::moduleStageDir() . $rCmdID) {
			return;
		}
		SettingsAudit::asAgentUser(static function () use ($rPath): bool {
			@unlink($rPath);
			return true;
		}, self::agentDir());
	}

	/** The installed agent's version (the first line of `xc_agent version`, run as its user), or null when it does not run. */
	public static function agentVersion(): ?string {
		$rVersion = self::runs(self::agentBinary(), dirname(self::agentBinary()) . '/');
		return $rVersion === null ? null : ltrim(trim(explode("\n", $rVersion, 2)[0]), 'vV');
	}

	/**
	 * Does this binary start here: `<binary> version` exits 0 with a line
	 * within 10 s, run as the owner of its directory (the agent's user; from
	 * root through sudo, never with root's rights). No shell: an argv list,
	 * stdin and stderr /dev/null, and at most VERSION_OUTPUT bytes of stdout
	 * kept (the rest is read and dropped, so the binary never blocks on a
	 * full pipe). Returns what it printed (its version first), null when it
	 * does not start.
	 */
	private static function runs(string $rBinary, string $rDir): ?string {
		$rArgv = ['timeout', '10', $rBinary, 'version'];
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			clearstatcache(true, rtrim($rDir, '/'));
			$rStat = @lstat(rtrim($rDir, '/'));
			if (!is_array($rStat) || $rStat['uid'] === 0) {
				return null;
			}
			$rArgv = array_merge(['sudo', '-n', '-u', '#' . $rStat['uid']], $rArgv);
		}
		// An argv list, no shell: sudo and timeout, the directory owner's uid, and the copy installAgent() wrote beside the agent binary (no payload names it).
		// nosemgrep: php.lang.security.exec-use.exec-use
		$rProc = @proc_open($rArgv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		if (!is_resource($rProc)) {
			return null;
		}
		$rOut = '';
		while (!feof($rPipes[1])) {
			$rChunk = fread($rPipes[1], 8192);
			if ($rChunk === false || $rChunk === '') {
				break;
			}
			$rOut .= substr($rChunk, 0, max(0, self::VERSION_OUTPUT - strlen($rOut)));
		}
		fclose($rPipes[1]);
		return proc_close($rProc) === 0 && trim(explode("\n", $rOut, 2)[0]) !== '' ? $rOut : null;
	}

	/** Does an agent's `version` output name $rFeature on its `features:` line? */
	private static function says(string $rVersion, string $rFeature): bool {
		return (bool) preg_match('/^features:(.*)$/m', $rVersion, $rM) && in_array($rFeature, preg_split('/[\s,]+/', trim($rM[1])) ?: [], true);
	}

	/**
	 * module:install's archive: a checked file in root's stage, or its checked
	 * handoff in xc_vm's closed stage, and only the grant's bytes.
	 *
	 * @param array<string, mixed>|null $rGrant The command's grant, as the payload carries it.
	 * @return string|null null when it may be installed, else why not
	 */
	public static function stagedArchive(string $rPath, ?array $rGrant): ?string {
		$rStages = array_filter([realpath(self::stageDir()), realpath(self::moduleStageDir())], 'is_string');
		$rReal = realpath($rPath);
		if ($rReal === false || !in_array(dirname($rReal), $rStages, true) || is_link($rPath) || !is_file($rReal)) {
			return 'the archive is not in a module stage';
		}
		$rUserStage = realpath(self::moduleStageDir());
		if ($rUserStage !== false && dirname($rReal) === $rUserStage && basename($rReal) !== ($rGrant['cmd_id'] ?? null)) {
			return 'the archive is not this command\'s handoff';
		}
		return self::matches($rReal, $rGrant['size'] ?? null, $rGrant['sha256'] ?? null) ? null : 'the archive is not the one MAIN granted';
	}

	/** Is $rPath the file of this size and SHA-256 (hex)? False for a size or hash that is not one. */
	public static function matches(string $rPath, mixed $rSize, mixed $rSha256): bool {
		clearstatcache(true, $rPath);
		return is_int($rSize) && is_string($rSha256) && @filesize($rPath) === $rSize && hash_equals($rSha256, (string) @hash_file('sha256', $rPath));
	}

	/**
	 * The off-air video to play for a token's `video_path` (MAIN's path):
	 * the node's own file there when it has one, as before; else the copy of
	 * that video MAIN granted it (placeOffAir()), by the path's file name;
	 * else the path, as before.
	 */
	public static function offAirVideo(string $rPath): string {
		if (!str_starts_with($rPath, '/') || is_file($rPath)) {
			return $rPath;
		}
		$rName = basename($rPath);
		if (!preg_match(self::NAME_PATTERN, $rName) || !str_ends_with($rPath, '/' . $rName)) {
			return $rPath;
		}
		$rLocal = self::videoDir() . $rName;
		return is_file($rLocal) ? $rLocal : $rPath;
	}

	/**
	 * Refuse a staged grant (ArtefactStage::current()'s, which carries its
	 * command's id) that its action cannot take, audited as refuse() does.
	 *
	 * @param array<string, mixed> $rGrant
	 */
	public static function refuseGrant(array $rGrant, string $rWhy): string {
		return self::refuse(['cmd_id' => $rGrant['cmd_id'] ?? null, 'args' => ['artefact' => $rGrant]], $rWhy);
	}

	/**
	 * Refuse an artefact, audited: a system log line for MAIN (type
	 * ARTEFACT: a `log.syslog` event with LOGS on, else, in mode 2, the
	 * panel's error log), or the panel's error log where MAIN would take a
	 * row the node wrote itself (never MAIN's database from here). Returns
	 * the refusal, for the command's result.
	 *
	 * @param array<string, mixed> $rCmd The command, decoded.
	 */
	public static function refuse(array $rCmd, string $rWhy): string {
		$rGrant = is_array($rCmd['args']['artefact'] ?? null) ? $rCmd['args']['artefact'] : [];
		$rId = is_string($rGrant['id'] ?? null) ? substr((string) preg_replace('/[^\x21-\x7e]/', '?', $rGrant['id']), 0, 128) : '?';
		$rName = is_string($rGrant['name'] ?? null) ? substr((string) preg_replace('/[^\x21-\x7e]/', '?', $rGrant['name']), 0, 128) : '?';
		$rCmdID = is_string($rCmd['cmd_id'] ?? null) ? substr((string) preg_replace('/[^0-9a-f]/', '', $rCmd['cmd_id']), 0, 32) : '';
		$rLine = 'Refused artefact ' . $rId . ' (' . $rName . ') for command ' . $rCmdID . ': ' . $rWhy;
		if (!LogSink::syslog('ARTEFACT', $rLine)) {
			$rKept = SettingsAudit::asAgentUser(static function () use ($rLine): bool {
				FileLogger::log('artefact', $rLine);
				return true;
			}, self::agentDir());
			if (!$rKept) {
				error_log('XC_VM ' . $rLine);
			}
		}
		return 'artefact refused: ' . $rId . ' (' . $rName . '): ' . $rWhy;
	}

	/** The agent's state directory (config/cluster/), whose owner is the agent's user. */
	private static function agentDir(): string {
		return dirname(rtrim(self::downloads(), '/'));
	}

	/**
	 * Open the agent's download of a grant: a regular file, not a link,
	 * opened with the caller's rights (the agent's, under asAgentUser).
	 *
	 * @param array<string, mixed> $rGrant
	 * @return resource|null
	 */
	private static function openDownload(array $rGrant, ?string &$rWhy) {
		$rPath = self::downloads() . $rGrant['cmd_id'];
		clearstatcache(true, $rPath);
		$rLink = @lstat($rPath);
		if ($rLink === false) {
			$rWhy = 'not downloaded';
			return null;
		}
		if (($rLink['mode'] & 0170000) !== 0100000) {
			$rWhy = 'the download is not a file';
			return null;
		}
		$rIn = @fopen($rPath, 'rb');
		if ($rIn === false) {
			$rWhy = 'the download cannot be read with the agent\'s rights';
			return null;
		}
		$rStat = fstat($rIn);
		if ($rStat === false || $rStat['ino'] !== $rLink['ino'] || $rStat['dev'] !== $rLink['dev']) {
			fclose($rIn);
			$rWhy = 'the download changed while it was opened';
			return null;
		}
		return $rIn;
	}

	/**
	 * Copy what $rIn holds into a new file at $rDest, created exclusively
	 * (whatever was planted there goes first), hashing as it goes and
	 * reading at most one byte past the grant's size. Null when the bytes
	 * are the grant's; otherwise why not, and nothing is left at $rDest.
	 *
	 * @param resource $rIn
	 * @param array<string, mixed> $rGrant
	 */
	private static function copyChecked($rIn, string $rDest, array $rGrant, int $rMode): ?string {
		if (is_link($rDest) || file_exists($rDest)) {
			@unlink($rDest);
		}
		$rOut = @fopen($rDest, 'x');
		if ($rOut === false) {
			return 'cannot write ' . basename($rDest);
		}
		@chmod($rDest, $rMode);
		$rHash = hash_init('sha256');
		$rSize = (int) $rGrant['size'];
		$rLeft = $rSize + 1;
		$rCopied = 0;
		$rWritten = true;
		while ($rLeft > 0 && !feof($rIn)) {
			$rBlock = fread($rIn, min(self::BLOCK, $rLeft));
			if ($rBlock === false) {
				break;
			}
			if ($rBlock === '') {
				continue;
			}
			$rLeft -= strlen($rBlock);
			$rCopied += strlen($rBlock);
			hash_update($rHash, $rBlock);
			$rWritten = $rWritten && fwrite($rOut, $rBlock) === strlen($rBlock);
		}
		$rWritten = fflush($rOut) && $rWritten;
		fclose($rOut);
		$rWhy = match (true) {
			$rCopied !== $rSize => 'size mismatch (' . ($rCopied > $rSize ? 'more than ' . $rSize : $rCopied) . ' bytes, the grant says ' . $rSize . ')',
			!hash_equals((string) $rGrant['sha256'], hash_final($rHash)) => 'sha256 mismatch',
			!$rWritten => 'cannot write ' . basename($rDest),
			default => null,
		};
		if ($rWhy !== null) {
			@unlink($rDest);
		}
		return $rWhy;
	}

	/** Remove the agent's download of a command, as the agent's user. */
	private static function spend(string $rCmdID): void {
		SettingsAudit::asAgentUser(static function () use ($rCmdID): bool {
			@unlink(self::downloads() . $rCmdID);
			return true;
		}, self::agentDir());
	}

	/**
	 * Root's stage: created 0700 when missing, closed to others when it is
	 * already this process's, and trusted only when it is a directory (not a
	 * link) owned by this process's user (root) that no one else can enter.
	 */
	private static function ownStage(string $rStage): bool {
		$rDir = rtrim($rStage, '/');
		if (!file_exists($rDir) && !is_link($rDir)) {
			@mkdir($rDir, 0700);
		}
		clearstatcache(true, $rDir);
		$rStat = @lstat($rDir);
		if ($rStat === false || ($rStat['mode'] & 0170000) !== 0040000 || (function_exists('posix_geteuid') && $rStat['uid'] !== posix_geteuid())) {
			return false;
		}
		if (($rStat['mode'] & 0077) !== 0) {
			@chmod($rDir, 0700);
			clearstatcache(true, $rDir);
			$rStat = @lstat($rDir);
		}
		return $rStat !== false && ($rStat['mode'] & 0170000) === 0040000 && ($rStat['mode'] & 0077) === 0;
	}
}
