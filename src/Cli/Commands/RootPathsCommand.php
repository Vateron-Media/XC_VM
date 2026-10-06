<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\TreeOwnership;
use XcVm\Core\Process\ProcessRunner;

/**
 * RootPathsCommand — what root runs from the panel's tree, and whether the
 * xc_vm user could replace it. Root's crons, the systemd unit and the
 * updater run the panel's PHP, its classes and its scripts (TreeOwnership);
 * this prints each of those paths and each directory above it with its
 * owner, group and mode, and says where xc_vm can write. It reads root's
 * crontab, the unit and `service`, and changes nothing.
 *
 * Usage: `console.php root:paths` (root)
 *
 * @package XC_VM_CLI_Commands
 */
class RootPathsCommand implements CommandInterface {
	/** The unit the installer and the SSH install write. */
	public const UNIT = '/etc/systemd/system/xc_vm.service';

	public function getName(): string {
		return 'root:paths';
	}

	public function getDescription(): string {
		return 'List what root runs from the tree and whether xc_vm can replace it (root, changes nothing)';
	}

	public function execute(array $rArgs): int {
		if (posix_geteuid() !== 0) {
			echo "Please run as root!\n";
			return 1;
		}
		$rUser = posix_getpwnam(TreeOwnership::USER);
		if ($rUser === false) {
			echo 'This server has no ' . TreeOwnership::USER . " user. Exiting\n";
			return 1;
		}
		// Its groups, and root's own crontab: both only read.
		$rGids = array_map('intval', preg_split('/\s+/', ProcessRunner::capture(['id', '-G', TreeOwnership::USER])[1], -1, PREG_SPLIT_NO_EMPTY) ?: []);
		$rCrontab = ProcessRunner::capture(['crontab', '-l'])[1];
		echo self::report(MAIN_HOME, $rUser['uid'], $rGids ?: [$rUser['gid']], $rCrontab, (string) @file_get_contents(self::UNIT), (is_file(MAIN_HOME . 'service') ? (string) @file_get_contents(MAIN_HOME . 'service') : ''));
		return 0;
	}

	/**
	 * The report for the tree at $rBase and the user $rUid in the groups
	 * $rGids: each path of TreeOwnership::chain() with its trail, then
	 * whether the user can replace it.
	 *
	 * @param list<int> $rGids
	 */
	public static function report(string $rBase, int $rUid, array $rGids, string $rCrontab, string $rUnit, string $rService): string {
		$rUser = TreeOwnership::USER;
		$rChain = TreeOwnership::chain($rBase, $rCrontab, $rUnit, $rService);
		if ($rUnit !== '') {
			$rChain[self::UNIT][] = 'systemd reads it';
		}
		$rOut = "What root runs from {$rBase}, and whether {$rUser} (uid {$rUid}) can replace it. Nothing is changed.\n\n";
		$rOpen = 0;
		foreach ($rChain as $rPath => $rWho) {
			$rOut .= $rPath . '  (' . implode('; ', $rWho) . ")\n";
			// The path as it is named, and each path a link on the way leads to.
			$rReal = realpath($rPath);
			$rVia = null;
			foreach (TreeOwnership::hops($rPath) as $rWalk) {
				$rTrail = TreeOwnership::trail($rWalk, $rUid, $rGids);
				foreach ($rTrail as $rRow) {
					if ($rRow['mode'] !== null) {
						$rOut .= sprintf('  %-22s %s  %s', $rRow['owner'] . ':' . $rRow['group'], $rRow['mode'], $rRow['path']) . ($rRow['by'] !== null ? "  <- {$rUser} writes it ({$rRow['by']})" : '') . "\n";
					}
				}
				$rVia ??= TreeOwnership::through($rTrail);
			}
			$rInside = $rVia === null && $rReal !== false && is_dir($rReal) ? TreeOwnership::inside($rReal, $rUid, $rGids) : [];
			if (str_starts_with($rPath, $rBase) && ($rEntry = TreeOwnership::writable(substr($rPath, strlen($rBase)))) !== null) {
				$rOut .= "  {$rUser} has to write {$rEntry} (TreeOwnership::WRITABLE)\n";
			}
			if ($rVia !== null) {
				$rOut .= "  => {$rUser} can replace it, through {$rVia}\n\n";
			} elseif ($rInside !== []) {
				$rOut .= "  => {$rUser} can replace " . implode("\n  => {$rUser} can replace ", $rInside) . "\n\n";
			} else {
				$rOut .= ($rReal === false ? '  => not there' : "  => {$rUser} cannot replace it") . "\n\n";
			}
			$rOpen += $rVia !== null || $rInside !== [] ? 1 : 0;
		}
		return $rOut . $rOpen . ' of ' . count($rChain) . " paths root runs can be replaced by {$rUser}.\n";
	}
}
