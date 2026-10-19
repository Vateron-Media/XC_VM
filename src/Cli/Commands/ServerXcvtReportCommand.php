<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\XcvtTargets;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ServerXcvtReportCommand — the gate for release N+1 (xcvm_core's ADR-005,
 * Decision 10): which load balancers MAIN sends XCVT v2 configs to, and why
 * the others get v1.
 *
 * In scope: every enabled load balancer (`servers.server_type` 0, not MAIN).
 * Proxies (type 1, config.ini), MAIN and disabled servers are not. A node is
 * covered when it is a v2 target (XcvtTargets::ready: a v2 pin of this
 * panel's key and the node's own panel key); otherwise its reason is one of
 * `ext_old`, `no_pk`, `no_pin`, `stale_fp`, `ssh_unreachable`
 * (XcvtTargets::reason). An operator may waive a node (`--waive=<id>
 * --reason=<text>`, `--unwaive=<id>`), audit-logged: from N+1 a waived node
 * gets no config at all, never v1.
 *
 * The gate passes (exit 0) with no uncovered node; otherwise exit 1.
 *
 * Usage: `console.php server:xcvt-report [--waive=<id> --reason=<text>] [--unwaive=<id>]`. MAIN only.
 *
 * @package XC_VM_CLI_Commands
 */
class ServerXcvtReportCommand implements CommandInterface {
	use DatabaseAware;

	/** A waiver, per server: `xcvt_waive.<server_id>` = {reason, at}. */
	public const WAIVE = 'xcvt_waive.';

	public function getName(): string {
		return 'server:xcvt-report';
	}

	public function getDescription(): string {
		return 'Report which LBs get XCVT v2 configs (the gate for release N+1)';
	}

	public function execute(array $rArgs): int {
		if ((posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}
		$rOptions = [];
		foreach ($rArgs as $rArg) {
			if (is_string($rArg) && preg_match('/^--(waive|unwaive|reason)=(.*)$/s', $rArg, $rM)) {
				$rOptions[$rM[1]] = $rM[2];
			}
		}
		if (isset($rOptions['waive'])) {
			$rReason = trim($rOptions['reason'] ?? '');
			if ((int) $rOptions['waive'] <= 0 || $rReason === '') {
				echo "Usage: server:xcvt-report --waive=<serverID> --reason=<why it is waived>\n";
				return 1;
			}
			self::waive((int) $rOptions['waive'], $rReason);
		}
		if (isset($rOptions['unwaive'])) {
			self::unwaive((int) $rOptions['unwaive']);
		}
		[$rRows, $rPass] = self::report();
		foreach ($rRows as $rRow) {
			printf("%-6d %-24s %-10s %s\n", $rRow['id'], mb_substr($rRow['name'], 0, 24), $rRow['state'], $rRow['detail']);
		}
		$rCount = array_count_values(array_column($rRows, 'state'));
		echo 'N+1 gate: ' . ($rPass ? 'PASS' : 'FAIL') . ' (' . ($rCount['covered'] ?? 0) . ' covered, ' . ($rCount['uncovered'] ?? 0) . ' uncovered, ' . ($rCount['waived'] ?? 0) . " waived)\n";
		if (!$rPass) {
			echo "Cover a node with console.php server:xcvt-pin <serverID> --cred-file=... --expect-hostkey=..., update its xcvm_core (ext_old), or waive it.\n";
		}
		return $rPass ? 0 : 1;
	}

	/**
	 * Every load balancer in scope, and whether the gate passes.
	 *
	 * @return array{0: list<array{id: int, name: string, state: string, detail: string}>, 1: bool}
	 */
	public static function report(): array {
		self::db()->query('SELECT `id`, `server_name` FROM `servers` WHERE `server_type` = 0 AND `is_main` = 0 AND `enabled` = 1 ORDER BY `id` ASC;');
		$rRows = [];
		$rPass = true;
		foreach (self::db()->get_rows() ?: [] as $rServer) {
			$rID = (int) $rServer['id'];
			$rWhy = XcvtTargets::reason($rID);
			$rWaiver = self::waiver($rID);
			if ($rWhy === null) {
				$rRow = ['state' => 'covered', 'detail' => ''];
			} elseif ($rWaiver !== null) {
				$rRow = ['state' => 'waived', 'detail' => $rWhy . ' (waived: ' . $rWaiver . ')'];
			} else {
				$rRow = ['state' => 'uncovered', 'detail' => $rWhy];
				$rPass = false;
			}
			$rRows[] = ['id' => $rID, 'name' => (string) ($rServer['server_name'] ?? '')] + $rRow;
		}
		return [$rRows, $rPass];
	}

	public static function waive(int $rServerID, string $rReason): void {
		ClusterMeta::set(self::WAIVE . $rServerID, (string) json_encode(['reason' => $rReason, 'at' => ClusterClock::now()], JSON_UNESCAPED_SLASHES));
		ClusterAudit::log('node.xcvt_waived', $rServerID, ['reason' => $rReason], 'cli');
	}

	public static function unwaive(int $rServerID): void {
		ClusterMeta::delete(self::WAIVE . $rServerID);
		ClusterAudit::log('node.xcvt_unwaived', $rServerID, '', 'cli');
	}

	/** Why the node is waived, or null. */
	public static function waiver(int $rServerID): ?string {
		$rDoc = json_decode((string) ClusterMeta::get(self::WAIVE . $rServerID), true);
		return is_array($rDoc) && is_string($rDoc['reason'] ?? null) ? $rDoc['reason'] : null;
	}
}
