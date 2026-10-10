<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Updates\ReleaseAsset;
use XcVm\Core\Updates\UpdateChannels;

/**
 * FanoutBinaryCommand — install/update the xc_fanout daemon and the xc_agent
 * cluster agent from their release (ADR 0003, ADR 0004).
 *
 * Both live in their own repo (GIT_REPO_FANOUT) and ship per-arch static
 * binaries as GitHub **release assets** (not committed to the tree), from the
 * same tag and the same SHA256SUMS. Every node, MAIN included and whatever its
 * cluster mode, takes them from there itself: MAIN never hands them out. This
 * command is the panel-side installer/updater, modelled on the binaries/maxmind
 * updaters; cron:root_signals runs it hourly.
 *
 * The daemon's installed version is tracked in a sidecar file
 * (`xc_fanout.version`) rather than derived from the binary, so a locally-built
 * or custom-signed test build is not force-overwritten just because its
 * self-reported version differs from the latest GitHub release — pin it by
 * writing the file. Independently, the binary is probed with `xc_fanout
 * -version`: a binary that does not answer is treated as missing/corrupt and
 * reinstalled regardless of the recorded version.
 *
 * The agent's version is what `xc_agent version` prints. A new one is put on
 * trial where an agent runs to judge it (ArtefactStage::installAgent): run.sh
 * puts the previous binary back if the new one keeps failing. The version
 * tried is recorded beside it (`xc_agent.tried`), so a release that did not
 * stay on this node is not fetched again every hour, only a newer one.
 *
 * When an update is due it downloads the arch-matched asset, verifies its
 * SHA-256, installs it atomically and restarts the process (its keepalive
 * respawns it with the new binary). The release channel is the per-repository
 * FANOUT channel ({@see UpdateChannels}).
 *
 * Usage: `console.php fanout_binary [fanout|agent] [force]` (neither: both;
 * `force` reinstalls the same version and retries one that did not stay).
 * An install hands a node MAIN keeps credential-free MAIN's channel and
 * canary as options (MAIN_OPTIONS), with `--replica` to boot without MAIN's
 * database: no replica holds them before its enrolment.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FanoutBinaryCommand implements CommandInterface {
	/** Sidecar file (next to the binary) recording the installed version. */
	private const VERSION_FILE = 'xc_fanout.version';

	/** Beside the agent: the last version this command installed or tried to. */
	public const AGENT_TRIED = 'xc_agent.tried';

	/** MAIN's settings an install passes on the command line (mainOptions()): option => setting. */
	public const MAIN_OPTIONS = ['--channel' => 'update_channel_fanout', '--canary' => 'lb_binary_canary_server', '--pin' => 'lb_release_pin'];

	public function getName(): string {
		return 'fanout_binary';
	}

	public function getDescription(): string {
		return 'Install/update the xc_fanout daemon and the xc_agent binary from their release';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}
		$rMain = self::mainSettings($rArgs);
		if ($rMain !== []) {
			SettingsManager::set($rMain + SettingsManager::getAll());
		}
		$rForce = in_array('force', $rArgs, true);
		$rTools = array_values(array_intersect(['fanout', 'agent'], $rArgs)) ?: ['fanout', 'agent'];

		$rMachine = trim(php_uname('m'));
		$rArch = ReleaseAsset::arch($rMachine);
		if ($rArch === null) {
			echo "Unsupported architecture: {$rMachine}\n";
			return 1;
		}

		try {
			$rGit = new GitHubReleases(GIT_OWNER, GIT_REPO_FANOUT, UpdateChannels::fanout());
			$rGit->setTimeout(20);
			// `force` means "recheck everything from scratch": drop the 30-minute
			// releases cache so a forced reinstall resolves against a fresh GitHub
			// response instead of a stale one that could pin an old "latest".
			if ($rForce) {
				$rGit->clearCache();
			}
			$rReleases = $rGit->getReleases();
		} catch (\Exception $e) {
			echo 'Failed to check xc_fanout releases: ' . $e->getMessage() . "\n";
			return 1;
		}
		if (empty($rReleases[0])) {
			echo "Failed to resolve the latest xc_fanout release.\n";
			return 1;
		}
		// The fleet canary (ReleaseCanary on MAIN): what this server may take.
		$rSettings = SettingsManager::getAll();
		$rTag = self::releaseFor($rReleases, $rSettings, (int) SERVER_ID);
		if ($rTag === null) {
			echo "Held for the fleet's canary: no release is pinned yet.\n";
			return 0;
		}
		$rHeld = self::held($rSettings, (int) SERVER_ID);
		$rTag = trim($rTag);
		$rLatest = ltrim($rTag, 'vV');
		$rBase = ReleaseAsset::baseUrl(GIT_OWNER, GIT_REPO_FANOUT, $rTag);

		$rOk = true;
		foreach ($rTools as $rTool) {
			$rOk = ($rTool === 'fanout' ? $this->fanout($rBase, $rArch, $rLatest, $rForce, $rHeld) : self::agent($rBase, $rArch, $rLatest, $rForce, $rHeld)) && $rOk;
		}
		return $rOk ? 0 : 1;
	}

	/**
	 * The options that hand MAIN's $rSettings to a node's `fanout_binary`.
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function mainOptions(array $rSettings): string {
		$rOut = '';
		foreach (self::MAIN_OPTIONS as $rOption => $rKey) {
			$rOut .= ' ' . escapeshellarg($rOption . '=' . (string) ($rSettings[$rKey] ?? '')); // lb-settings: update_channel_fanout, lb_binary_canary_server, lb_release_pin
		}
		return $rOut;
	}

	/**
	 * MAIN's settings in $rArgs (mainOptions()), which override the node's.
	 *
	 * @param list<string> $rArgs
	 * @return array<string, string>
	 */
	public static function mainSettings(array $rArgs): array {
		$rOut = [];
		foreach ($rArgs as $rArg) {
			[$rOption, $rValue] = explode('=', (string) $rArg, 2) + [1 => null];
			if ($rValue !== null && isset(self::MAIN_OPTIONS[$rOption])) {
				$rOut[self::MAIN_OPTIONS[$rOption]] = $rValue;
			}
		}
		return $rOut;
	}

	/** Is this server held to MAIN's pin (a canary is set, and it is not this server)? */
	private static function held(array $rSettings, int $rServerID): bool {
		$rCanary = (int) ($rSettings['lb_binary_canary_server'] ?? 0);
		return $rCanary > 0 && $rCanary !== $rServerID;
	}

	/**
	 * The release this server installs: the newest, unless MAIN holds the
	 * fleet to what its canary has run long enough (`lb_binary_canary_server`,
	 * `lb_release_pin`: ReleaseCanary). Then the newest at or below the pin,
	 * and none while no release is pinned. The canary takes the newest.
	 *
	 * @param list<string> $rTags Release tags, newest first.
	 * @param array<string, mixed> $rSettings
	 * @return string|null The tag, or null: nothing to install now.
	 */
	public static function releaseFor(array $rTags, array $rSettings, int $rServerID): ?string {
		if (!self::held($rSettings, $rServerID)) {
			return $rTags[0] ?? null;
		}
		$rPin = ltrim(trim((string) ($rSettings['lb_release_pin'] ?? '')), 'vV');
		if ($rPin === '') {
			return null;
		}
		foreach ($rTags as $rTag) {
			if (version_compare(ltrim(trim((string) $rTag), 'vV'), $rPin, '<=')) {
				return (string) $rTag;
			}
		}
		return null;
	}

	/** The daemon at $rLatest, from the release at $rBase. */
	private function fanout(string $rBase, string $rArch, string $rLatest, bool $rForce, bool $rHeld = false): bool {
		$rDir = BIN_PATH . 'xc_fanout/';
		$rBinary = $rDir . 'xc_fanout';
		$rVerFile = $rDir . self::VERSION_FILE;

		// Integrity probe: the binary must answer `-version`. A binary that does
		// not respond is missing or corrupted ("bit-rotted") and must be
		// reinstalled regardless of the recorded version.
		$rReported = $this->binaryVersion($rBinary);
		$rHealthy = $rReported !== null;

		// Installed version is tracked in a sidecar file, not derived from the
		// binary, so a locally-built/signed test build is not force-overwritten
		// just because its self-reported version differs from the latest release.
		// Seed the file from the running binary on first run after upgrade
		// (migration) so an already up-to-date host is not needlessly reinstalled.
		$rInstalled = $this->readVersionFile($rVerFile);
		if ($rInstalled === null && $rHealthy) {
			$this->writeVersionFile($rVerFile, $rReported);
			$rInstalled = $rReported;
		}

		if (!$rForce && $rHealthy && $rInstalled !== null && $rInstalled === $rLatest) {
			echo "xc_fanout is up to date ({$rInstalled}).\n";
			return true;
		}
		if ($rHeld && $rHealthy && $rInstalled !== null && version_compare($rInstalled, $rLatest, '>')) {
			echo "xc_fanout {$rInstalled} is past the fleet's pin ({$rLatest}): kept.\n";
			return true;
		}
		$rReason = !$rHealthy
			? 'binary not responding (missing/corrupt)'
			: 'installed=' . ($rInstalled ?? 'none') . ', latest=' . $rLatest;
		echo 'xc_fanout: ' . $rReason . " → updating\n";

		$rAsset = 'xc_fanout-linux-' . $rArch;

		if (!is_dir($rDir) && !@mkdir($rDir, 0755, true)) {
			echo "Failed to create {$rDir}\n";
			return false;
		}
		$rTmp = $rDir . '.xc_fanout.new';

		if (!ReleaseAsset::download($rBase . $rAsset, $rTmp)) {
			echo "Failed to download {$rAsset}\n";
			@unlink($rTmp);
			return false;
		}

		$rExpected = ReleaseAsset::expectedSha256($rBase . 'SHA256SUMS', $rAsset);
		if ($rExpected === null) {
			echo "Failed to fetch SHA256SUMS\n";
			@unlink($rTmp);
			return false;
		}
		if (!hash_equals($rExpected, hash_file('sha256', $rTmp))) {
			echo "Checksum mismatch for {$rAsset} — aborting\n";
			@unlink($rTmp);
			return false;
		}

		$rFailed = self::install($rTmp, $rLatest);
		if ($rFailed !== null) {
			echo $rFailed . " — aborting\n";
			return false;
		}
		echo "xc_fanout {$rLatest} installed.\n";
		return true;
	}

	/**
	 * The agent at $rLatest, from the release at $rBase, put where run.sh
	 * starts it (as the agent's user, ArtefactStage::installAgent). On trial
	 * only where an agent runs to judge it: a trial nobody runs would be
	 * judged at the node's enrolment, long past, and roll the binary back.
	 */
	public static function agent(string $rBase, string $rArch, string $rLatest, bool $rForce, bool $rHeld = false): bool {
		$rTarget = ArtefactStage::agentBinary();
		$rDir = dirname($rTarget) . '/';
		$rInstalled = ArtefactStage::agentVersion();
		if (!$rForce && $rInstalled === $rLatest) {
			echo "xc_agent is up to date ({$rInstalled}).\n";
			return true;
		}
		if ($rHeld && $rInstalled !== null && version_compare($rInstalled, $rLatest, '>')) {
			echo "xc_agent {$rInstalled} is past the fleet's pin ({$rLatest}): kept.\n";
			return true;
		}
		if (!$rForce && ltrim(trim((string) @file_get_contents($rDir . self::AGENT_TRIED)), 'vV') === $rLatest) {
			echo "xc_agent {$rLatest} did not stay on this node: kept on " . ($rInstalled ?? 'none') . " until a newer release (force retries it).\n";
			return true;
		}
		echo 'xc_agent: installed=' . ($rInstalled ?? 'none') . ', latest=' . $rLatest . " → updating\n";
		if (!is_dir($rDir) && (!@mkdir($rDir, 0755, true) || !@chown($rDir, 'xc_vm') || !@chgrp($rDir, 'xc_vm'))) {
			echo "Failed to create {$rDir}\n";
			return false;
		}
		$rAsset = 'xc_agent-linux-' . $rArch;
		// Root's own temp file: installAgent copies it in as the agent's user.
		$rTmp = (string) tempnam(sys_get_temp_dir(), 'xc_agent');
		try {
			if (!ReleaseAsset::download($rBase . $rAsset, $rTmp)) {
				echo "Failed to download {$rAsset}\n";
				return false;
			}
			$rExpected = ReleaseAsset::expectedSha256($rBase . 'SHA256SUMS', $rAsset);
			if ($rExpected === null || !hash_equals($rExpected, (string) hash_file('sha256', $rTmp))) {
				echo "Checksum mismatch or missing for {$rAsset} — aborting\n";
				return false;
			}
			SettingsAudit::asAgentUser(static fn(): bool => @file_put_contents($rDir . self::AGENT_TRIED, $rLatest . "\n") !== false, $rDir);
			$rRunning = trim((string) shell_exec('pgrep -u xc_vm -x xc_agent 2>/dev/null')) !== '';
			$rWhy = ArtefactStage::installAgent(['path' => $rTmp, 'grant' => ['size' => (int) filesize($rTmp), 'sha256' => $rExpected]], $rTarget, $rRunning);
		} finally {
			@unlink($rTmp);
		}
		if ($rWhy !== null) {
			echo "xc_agent {$rLatest} not installed: {$rWhy}\n";
			return false;
		}
		// run.sh restarts it with the new binary.
		shell_exec('pkill -u xc_vm -x xc_agent 2>/dev/null');
		echo "xc_agent {$rLatest} installed.\n";
		return true;
	}

	/**
	 * Install $rTmp, a verified xc_fanout binary of version $rVersion in
	 * bin/xc_fanout/: it must run here (`-version`), then it replaces the
	 * binary atomically, the version is recorded beside it, and the daemon
	 * restarts. $rTmp is gone either way. Null once installed, else why not.
	 */
	public static function install(string $rTmp, string $rVersion): ?string {
		$rDir = BIN_PATH . 'xc_fanout/';
		@chmod($rTmp, 0755);
		if (trim((string) shell_exec(escapeshellarg($rTmp) . ' -version 2>/dev/null')) === '') {
			@unlink($rTmp);
			return 'the binary does not run on this host';
		}
		if (!@rename($rTmp, $rDir . 'xc_fanout')) { // atomic replace
			@unlink($rTmp);
			return 'cannot install ' . $rDir . 'xc_fanout';
		}
		@chown($rDir . 'xc_fanout', 'xc_vm');
		@chgrp($rDir . 'xc_fanout', 'xc_vm');
		// Record the installed version in the sidecar file so subsequent runs
		// compare this file against GitHub (not the binary's self-report).
		if (@file_put_contents($rDir . self::VERSION_FILE, ltrim($rVersion, 'vV') . "\n") !== false) {
			@chown($rDir . self::VERSION_FILE, 'xc_vm');
			@chgrp($rDir . self::VERSION_FILE, 'xc_vm');
		}
		// Restart: kill ONLY the daemon process (match the exact process NAME, not
		// the cmdline) so the service keepalive loop — whose bash cmdline contains
		// the same binary path — survives and respawns it with the new binary.
		// Harmless no-op if it isn't running yet.
		shell_exec("pkill -u xc_vm -x xc_fanout 2>/dev/null");
		return null;
	}

	/** The version recorded beside the installed binary (the node reports it), or null. */
	public static function installedVersion(): ?string {
		$rVal = trim((string) @file_get_contents(BIN_PATH . 'xc_fanout/' . self::VERSION_FILE));
		return $rVal !== '' ? ltrim($rVal, 'vV') : null;
	}

	/**
	 * Version the binary reports via `-version`, or null when it is absent or
	 * does not respond (missing/corrupt). Doubles as the integrity probe.
	 */
	private function binaryVersion(string $rBinary): ?string {
		if (!is_file($rBinary) || !is_executable($rBinary)) {
			return null;
		}
		$rOut = trim((string) shell_exec(escapeshellarg($rBinary) . ' -version 2>/dev/null'));
		return $rOut !== '' ? ltrim($rOut, 'vV') : null;
	}

	/** Recorded installed version from the sidecar file, or null if absent/empty. */
	private function readVersionFile(string $rFile): ?string {
		if (!is_file($rFile)) {
			return null;
		}
		$rVal = trim((string) @file_get_contents($rFile));
		return $rVal !== '' ? ltrim($rVal, 'vV') : null;
	}

	/** Persist the installed version to the sidecar file (best-effort). */
	private function writeVersionFile(string $rFile, string $rVersion): void {
		if (@file_put_contents($rFile, ltrim($rVersion, 'vV') . "\n") !== false) {
			@chown($rFile, 'xc_vm');
			@chgrp($rFile, 'xc_vm');
		}
	}
}
