<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Stream\StreamProcess;

/**
 * SourceProbeCommand — the fanout supervisor's `probe_cmd` for a module
 * source-driver source: exit 0 when the driver reports it reachable. It lets
 * the supervisor return to a higher-priority driver source, which it never
 * does blindly (a source without a probe command is skipped).
 *
 * Usage: console.php source:probe <stream_id> <base64 source URL>
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class SourceProbeCommand implements CommandInterface {
	public function getName(): string {
		return 'source:probe';
	}

	public function getDescription(): string {
		return 'Exit 0 when a module source driver reports a stream source reachable';
	}

	public function execute(array $rArgs): int {
		$rArg = (string) ($rArgs[1] ?? '');
		$rSource = preg_match('/^@(0|[1-9][0-9]*)$/', $rArg)
			? StreamProcess::lbDriverSource(intval($rArgs[0] ?? 0), (int) substr($rArg, 1))
			: base64_decode($rArg, true);
		return StreamProcess::driverProbe(intval($rArgs[0] ?? 0), (string) $rSource) ? 0 : 1;
	}
}
