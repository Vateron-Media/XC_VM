<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Process\ProcessRunner;

/**
 * The guided cutover of one load balancer to mode 1 with every flow on
 * (Cluster Nodes → Guided cutover, `console.php cluster:cutover <id>`): the
 * guide's Step 3 done for the operator, one flow at a time in its order,
 * through the page's own action (ClusterAdmin::act()) and its checks, each
 * flow watched for SOAK before the next. Never mode 2: that move stays the
 * operator's (Mode up).
 *
 * - Before a flow, the node must be active, heard (NodeHealth `ok`) and show
 *   no red badge (ClusterOverview::nodeBadges(): the P0 lane lagging, a clock
 *   far off); else the cutover stops there.
 * - Before CONNECTIONS, the node's viewers are loaded into its agent
 *   (NodeActions::seedConnections()): without them the agent's empty
 *   registry would disagree with MAIN's store, and the snapshot MAIN then
 *   asks for would drop every viewer the node has.
 * - A flow after which the node is not healthy is switched off again, and
 *   the cutover stops.
 * - DATAPLANE needs an agent that offers the relay; without one it is left
 *   off, with a note, as the install leaves it.
 * - A node still in mode 0 is moved to mode 1 at the end.
 *
 * Progress is a JSON file per node in the cache (state()), shown on the page.
 *
 * @package XC_VM_Domain_Cluster
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ClusterCutover {
	/** The flows in the guide's order (docs: main-lb-cluster.md, Step 3). */
	public const ORDER = ['telemetry', 'commands', 'logs', 'streams', 'content', 'config', 'connections', 'dataplane'];

	/** Seconds a flow is watched before the next one. */
	public const SOAK = 120;

	/** Seconds between two looks at the node. */
	public const POLL = 10;

	public static function stateFile(int $rServerID): string {
		return CACHE_TMP_PATH . 'cutover_' . $rServerID . '.json';
	}

	public static function cancelFile(int $rServerID): string {
		return CACHE_TMP_PATH . 'cutover_' . $rServerID . '.cancel';
	}

	/** @return array<string, mixed>|null the node's last cutover */
	public static function state(int $rServerID): ?array {
		$rState = json_decode((string) @file_get_contents(self::stateFile($rServerID)), true);
		return is_array($rState) ? $rState : null;
	}

	/** Is one going on for the node: its state says so and its process is alive. */
	public static function running(int $rServerID, ?array $rState = null): bool {
		$rState ??= self::state($rServerID);
		return in_array($rState['status'] ?? '', ['starting', 'running'], true) && (int) ($rState['pid'] ?? 0) > 0 && @posix_kill((int) $rState['pid'], 0);
	}

	/** Start one in the background (the page), as $rUserID. False while one runs for the node or when it could not start. */
	public static function start(int $rServerID, ?int $rUserID): bool {
		if (self::running($rServerID)) {
			return false;
		}
		@unlink(self::cancelFile($rServerID));
		self::save($rServerID, ['status' => 'starting', 'started' => time(), 'pid' => 0, 'steps' => [], 'note' => '']);
		return ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'cluster:cutover', (string) $rServerID, (string) ($rUserID ?? 0)]);
	}

	public static function cancel(int $rServerID): void {
		touch(self::cancelFile($rServerID));
	}

	/**
	 * Why the node is not fit for the next flow, or null.
	 *
	 * @param array<string, mixed>|null $rNode a ClusterAdmin::nodes() row
	 * @param list<array{tone: string, key: string, vars?: array<string, string>}> $rBadges
	 */
	public static function unhealthy(?array $rNode, array $rBadges): ?string {
		if ($rNode === null) {
			return 'it is no longer enrolled';
		}
		if ($rNode['state'] !== 'active') {
			return 'it is ' . $rNode['state'];
		}
		if (($rNode['health'] ?? '') !== 'ok') {
			return 'MAIN does not hear it (' . ($rNode['health'] ?? 'unknown') . ')';
		}
		foreach ($rBadges as $rBadge) {
			if ($rBadge['tone'] === 'danger') {
				return 'it shows ' . $rBadge['key'] . ($rBadge['vars'] ?? [] ? ' (' . implode(', ', $rBadge['vars']) . ')' : '');
			}
		}
		return null;
	}

	/**
	 * Run it: what the command does, its world passed in.
	 *
	 * @param callable(): ?array $rNode The node's row now (ClusterAdmin::nodes()).
	 * @param callable(string, array<string, mixed>): array{type: string, message: string} $rAct ClusterAdmin::act() for this node: an action and its extra input.
	 * @param callable(): ?string $rSeed Load the node's viewers into its agent: null when done, else why not.
	 * @param callable(array<string, mixed>): list<array{tone: string, key: string}> $rBadges ClusterOverview::nodeBadges().
	 * @param callable(int): void $rSleep
	 * @param callable(): int $rNow
	 * @return array<string, mixed> The cutover's last state.
	 */
	public static function run(int $rServerID, callable $rNode, callable $rAct, callable $rSeed, callable $rBadges, callable $rSleep, callable $rNow): array {
		$rState = ['status' => 'running', 'started' => $rNow(), 'pid' => getmypid(), 'steps' => [], 'note' => ''];
		$rRow = $rNode();
		if ($rRow === null || $rRow['state'] !== 'active') {
			return self::finish($rServerID, $rState, 'failed', 'The node is not enrolled and active.', $rNow());
		}
		if ((int) $rRow['mode'] === 2) {
			return self::finish($rServerID, $rState, 'done', 'The node is in mode 2 already: every flow is on.', $rNow());
		}
		foreach (self::ORDER as $rFlow) {
			$rOn = ((int) $rRow['flows'] & ClusterAdmin::FLOW_BITS[$rFlow]) !== 0;
			$rState['steps'][] = ['flow' => $rFlow, 'state' => $rOn ? 'on' : 'waiting', 'since' => null, 'note' => ''];
		}
		self::save($rServerID, $rState);
		foreach ($rState['steps'] as $i => $rStep) {
			if ($rStep['state'] === 'on') {
				continue;
			}
			$rFlow = $rStep['flow'];
			if (is_file(self::cancelFile($rServerID))) {
				return self::finish($rServerID, $rState, 'cancelled', '', $rNow());
			}
			if (($rWhy = self::look($rNode, $rBadges)) !== null) {
				return self::fail($rServerID, $rState, $i, 'Not switched on: ' . $rWhy . '.', $rNow());
			}
			if ($rFlow === 'dataplane' && empty(($rNode() ?? [])['relay'])) {
				self::step($rServerID, $rState, $i, 'skipped', $rNow(), 'Its agent does not offer the relay: switch the data plane on once it does.');
				continue;
			}
			if ($rFlow === 'connections') {
				self::step($rServerID, $rState, $i, 'seeding', $rNow());
				if (($rWhy = $rSeed()) !== null) {
					return self::fail($rServerID, $rState, $i, 'Its viewers could not be loaded into its agent: ' . $rWhy . '.', $rNow());
				}
			}
			self::step($rServerID, $rState, $i, 'switching', $rNow());
			$rDone = $rAct($rFlow . '_on', []);
			if ($rDone['type'] !== 'success') {
				return self::fail($rServerID, $rState, $i, 'Refused: ' . $rDone['message'] . '.', $rNow());
			}
			self::step($rServerID, $rState, $i, 'watching', $rNow());
			$rUntil = $rNow() + self::SOAK;
			while ($rNow() < $rUntil) {
				$rSleep(self::POLL);
				if (($rWhy = self::look($rNode, $rBadges)) !== null) {
					$rAct($rFlow . '_off', []);
					return self::fail($rServerID, $rState, $i, 'Switched off again: ' . $rWhy . '.', $rNow());
				}
			}
			self::step($rServerID, $rState, $i, 'on', $rNow());
		}
		$rNote = '';
		if ((int) ($rNode()['mode'] ?? 1) === 0) {
			$rUp = $rAct('mode_up', ['mode' => '0']);
			$rNote = $rUp['type'] === 'success' ? 'Moved to mode 1.' : 'Not moved to mode 1: ' . $rUp['message'] . '.';
		}
		return self::finish($rServerID, $rState, 'done', $rNote, $rNow());
	}

	/**
	 * The node now, and why it is unfit (unhealthy()): a new look each time.
	 *
	 * @phpstan-impure
	 */
	private static function look(callable $rNode, callable $rBadges): ?string {
		$rRow = $rNode();
		return self::unhealthy($rRow, $rRow === null ? [] : $rBadges($rRow));
	}

	/** @param array<string, mixed> $rState */
	private static function step(int $rServerID, array &$rState, int $i, string $rStep, int $rNow, string $rNote = ''): void {
		$rState['steps'][$i]['state'] = $rStep;
		$rState['steps'][$i]['since'] = $rNow;
		$rState['steps'][$i]['note'] = $rNote;
		self::save($rServerID, $rState);
	}

	/**
	 * @param array<string, mixed> $rState
	 * @return array<string, mixed>
	 */
	private static function fail(int $rServerID, array $rState, int $i, string $rNote, int $rNow): array {
		$rState['steps'][$i]['state'] = 'failed';
		$rState['steps'][$i]['note'] = $rNote;
		return self::finish($rServerID, $rState, 'failed', $rNote, $rNow);
	}

	/**
	 * @param array<string, mixed> $rState
	 * @return array<string, mixed>
	 */
	private static function finish(int $rServerID, array $rState, string $rStatus, string $rNote, int $rNow): array {
		$rState['status'] = $rStatus;
		$rState['note'] = $rNote;
		$rState['finished'] = $rNow;
		self::save($rServerID, $rState);
		@unlink(self::cancelFile($rServerID));
		return $rState;
	}

	/** @param array<string, mixed> $rState */
	private static function save(int $rServerID, array $rState): void {
		$rTemp = self::stateFile($rServerID) . '.tmp';
		if (file_put_contents($rTemp, (string) json_encode($rState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
			rename($rTemp, self::stateFile($rServerID));
		}
	}
}
