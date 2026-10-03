<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\RootPin;
use XcVm\Domain\Server\ServerRepository;

/**
 * ClusterRootCommand — run MAIN's signed root commands on this node (Phase 4).
 *
 * Root's crontab starts it every minute; it then watches the root inbox for
 * about a minute, once a second, so a root command runs within ~1 s of the
 * agent handing it over. Each command is checked against root's own pin of
 * the panel key (/etc/xc_vm/cluster, see RootPin), not against anything the
 * panel's user can write; root's high-water (root.seq) is raised before the
 * action runs, so a command never runs twice. The action itself runs through
 * the same code as the legacy signals (RootSignalsCronJob::executeAction).
 *
 * A command that carries an artefact grant (a pinned binary, a module's
 * archive) has its artefact copied from the agent's download into root's
 * own stage (/etc/xc_vm/cluster/stage/) and checked there for the grant's
 * size and SHA-256 before the action runs; a mismatch is refused and
 * audited, and the action never runs (ArtefactStage::stage). A command
 * refused before that (a replay: root.seq only goes up, so the agent hands
 * root commands over in seq order) has its download removed.
 *
 * Without a pin it does nothing: root actions then keep the signals table.
 *
 * Usage: `console.php cluster:root` (root)
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterRootCommand implements CommandInterface {
	public const WATCH_SECONDS = 58;

	public function getName(): string {
		return 'cluster:root';
	}

	public function getDescription(): string {
		return "Run MAIN's signed root commands (root)";
	}

	public function execute(array $rArgs): int {
		if ((posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}
		if (RootPin::read() === null || !is_dir(RootPin::inbox())) {
			return 0;
		}
		$rLock = fopen(RootPin::dir() . 'root.lock', 'c');
		if ($rLock === false || !flock($rLock, LOCK_EX | LOCK_NB)) {
			return 0; // the previous minute's run is still watching
		}
		cli_set_process_title('XC_VM[ClusterRoot]');
		ArtefactStage::pruneStage(time());
		$rUntil = time() + (in_array('--once', $rArgs, true) ? 0 : self::WATCH_SECONDS);
		do {
			self::drain([self::class, 'runAction'], time());
			RootSignalsCronJob::reloadAsked(); // once for the actions it ran
			if (time() >= $rUntil) {
				break;
			}
			sleep(1);
		} while (true);
		return 0;
	}

	/**
	 * Run one verified root action through the signals cron's code; what it
	 * prints is the command's result. On a node in mode 2 it reaches no
	 * database: its system log line goes to the agent (LogSink::syslog), and
	 * an update reports its status there too (NodeStateSink::status). An
	 * action that throws leaves no output buffer open.
	 *
	 * @param array<string, mixed> $rAction {action, …}
	 */
	public static function runAction(array $rAction): string {
		global $db;
		ob_start();
		try {
			(new RootSignalsCronJob())->executeAction($rAction, ServerRepository::getAll(), $db);
		} finally {
			$rOutput = (string) ob_get_clean();
		}
		return $rOutput;
	}

	/**
	 * One pass over the inbox, oldest seq first.
	 *
	 * @param callable(array<string, mixed>): string $rRun runs an action, returns its output
	 * @return list<array{seq: int, ok: bool, detail: string}> what was done
	 */
	public static function drain(callable $rRun, int $rNow): array {
		$rPin = RootPin::read();
		if ($rPin === null) {
			return [];
		}
		$rDone = [];
		$rFiles = glob(RootPin::inbox() . '*.json') ?: [];
		natsort($rFiles);
		foreach ($rFiles as $rFile) {
			$rSeq = (int) basename($rFile, '.json');
			$rDonePath = RootPin::inbox() . $rSeq . '.done';
			if ($rSeq <= 0 || is_link($rFile) || !is_file($rFile) || filesize($rFile) > RootPin::MAX_COMMAND) {
				@unlink($rFile);
				continue;
			}
			$rIn = json_decode((string) file_get_contents($rFile), true);
			@unlink($rFile);
			$rCmd = RootPin::verify($rPin, (string) ($rIn['doc'] ?? ''), (string) Enc::b64urlDecode((string) ($rIn['sig'] ?? '')), $rNow, RootPin::highWater());
			if (is_string($rCmd)) {
				// Its download, if it carried a grant, is spent too.
				ArtefactStage::spendRefused((string) ($rIn['doc'] ?? ''));
				RootPin::writeDone($rDonePath, (string) json_encode(['ok' => false, 'result' => 'refused by root: ' . $rCmd]));
				$rDone[] = ['seq' => $rSeq, 'ok' => false, 'detail' => $rCmd];
				continue;
			}
			// At most once: the high-water goes up before the action runs.
			if (!RootPin::raiseHighWater((int) $rCmd['seq'])) {
				continue;
			}
			// Its artefact, staged and checked before anything runs.
			$rStaged = isset($rCmd['args']['artefact']) ? ArtefactStage::stage($rCmd) : null;
			if (is_string($rStaged)) {
				RootPin::writeDone($rDonePath, (string) json_encode(['ok' => false, 'result' => 'refused by root: ' . $rStaged]));
				$rDone[] = ['seq' => (int) $rCmd['seq'], 'ok' => false, 'detail' => $rStaged];
				continue;
			}
			try {
				// The action as the signals row carried it: {action, …}.
				$rArgs = ['action' => (string) $rCmd['action']] + (array) ($rCmd['args'] ?? []);
				$rOutput = $rStaged === null ? $rRun($rArgs) : ArtefactStage::withStaged($rStaged, static fn() => $rRun($rArgs));
				$rOk = true;
			} catch (\Throwable $rE) {
				$rOutput = $rE->getMessage();
				$rOk = false;
			} finally {
				if (is_array($rStaged)) {
					ArtefactStage::discard($rStaged);
				}
			}
			RootPin::writeDone($rDonePath, (string) json_encode(['ok' => $rOk, 'result' => substr(trim($rOutput), 0, 4096)]));
			$rDone[] = ['seq' => (int) $rCmd['seq'], 'ok' => $rOk, 'detail' => (string) $rCmd['action']];
		}
		return $rDone;
	}
}
