<?php

namespace XcVm\Core\Cluster;

/**
 * This load balancer's cluster mode and flow bits, as MAIN last sent them to
 * the node's agent (`config/cluster/flows.json`, written by xc_agent from its
 * authenticated heartbeat replies). A flow that is on moves that part of the
 * node's work to the agent, and the legacy PHP path for it stands down.
 *
 * No file (legacy node, agent not enrolled, or stopped by MAIN) means every
 * flow is off. MAIN itself never has flows. Lives in Core: it ships to LBs,
 * where the Domain\Cluster registry does not.
 */
final class NodeFlows {
	/** Flow bits (plan, section 12); NodeRegistry::FLOW_* on MAIN are these. */
	public const TELEMETRY = 1;
	public const COMMANDS = 2;
	public const LOGS = 4;
	public const STREAMS = 8;
	public const CONTENT = 16;
	public const CONFIG = 32;
	public const CONNECTIONS = 64;
	public const DATAPLANE = 128;

	/** The agent's file, relative to the config directory. */
	public const FILE = AgentPaths::DIR . 'flows.json';

	/** Every flow off: no file, or not one. */
	private const OFF = ['mode' => 0, 'flows' => 0, 'state' => '', 'features' => []];

	/** @var array{mode: int, flows: int, state: string, features: list<string>}|null */
	private static ?array $rCache = null;

	private static int $rReadAt = 0;

	private static ?string $rPath = null;

	private static bool $rMainCheck = true;

	/**
	 * The file's flows while read() asks NodeRole whether this is MAIN: that
	 * reads the servers, which on a node whose replica owns them asks here
	 * again (ReplicaApply::owns). The inner call gets what the file says.
	 *
	 * @var array{mode: int, flows: int, state: string, features: list<string>}|null
	 */
	private static ?array $rReading = null;

	/** Is a flow on for this node? */
	public static function on(int $rFlow): bool {
		$rNow = self::current();
		return $rNow['mode'] >= 1 && ($rNow['flows'] & $rFlow) === $rFlow && in_array($rNow['state'], ['active', 'quarantined'], true);
	}

	/**
	 * Does the node's agent do this (e.g. "fanout_events": it follows the
	 * fanout's monitor feed, so PHP's reconcile leaves that state to it)?
	 */
	public static function agentHas(string $rFeature): bool {
		return in_array($rFeature, self::current()['features'], true);
	}

	/** @return array{mode: int, flows: int, state: string, features: list<string>} */
	public static function current(): array {
		if (self::$rReading !== null) {
			return self::$rReading;
		}
		if (self::$rCache === null || time() - self::$rReadAt >= 5) {
			self::$rCache = self::read();
			self::$rReadAt = time();
		}
		return self::$rCache;
	}

	/**
	 * Tests: read another file, and forget what was read. $rMainCheck: ignore
	 * that file on MAIN too, as the agent's own file is.
	 */
	public static function usePath(?string $rPath, bool $rMainCheck = false): void {
		self::$rPath = $rPath;
		self::$rMainCheck = $rPath === null || $rMainCheck;
		self::$rCache = null;
	}

	/**
	 * What the agent's file says, without ruling out MAIN (that reads the
	 * servers) and without the 5 s reuse: for the boot, which asks before the
	 * settings, the servers or a database handle exist (ReplicaBoot), and for
	 * checks that must never reach a database (SettingsAudit). MAIN runs no
	 * agent, so it has no file, and everything is off without one.
	 *
	 * @return array{mode: int, flows: int, state: string, features: list<string>}
	 */
	public static function declared(): array {
		// fileEarly(): console.php asks (ReplicaBoot::forArgv) before its
		// boot defines CONFIG_PATH.
		return self::parse(self::$rPath ?? AgentPaths::fileEarly(self::FILE)) ?? self::OFF;
	}

	/** @return array{mode: int, flows: int, state: string, features: list<string>} */
	private static function read(): array {
		// The file first: no file is the common case (MAIN, legacy nodes), and
		// it needs no database to find out.
		$rFlows = self::parse(self::$rPath ?? AgentPaths::fileOrNull(self::FILE));
		if ($rFlows === null) {
			return self::OFF;
		}
		// Only MAIN's build can be MAIN, and a load balancer's build says so from its
		// files: the servers it would read come from MAIN's database once their cache
		// is 10 s old, which segment.php and the other lightweight paths do not open.
		if (self::$rMainCheck && NodeRole::mainBuild()) {
			self::$rReading = $rFlows;
			try {
				$rMain = NodeRole::isMain();
			} finally {
				self::$rReading = null;
			}
			if ($rMain) {
				return self::OFF;
			}
		}
		return $rFlows;
	}

	/**
	 * The agent's file, or null when there is none (or it is not one).
	 *
	 * @return array{mode: int, flows: int, state: string, features: list<string>}|null
	 */
	private static function parse(?string $rPath): ?array {
		$rDoc = $rPath === null ? null : json_decode((string) @file_get_contents($rPath), true);
		if (!is_array($rDoc)) {
			return null;
		}
		$rFeatures = is_array($rDoc['features'] ?? null) ? array_values(array_filter($rDoc['features'], 'is_string')) : [];
		return ['mode' => max(0, min(2, (int) ($rDoc['mode'] ?? 0))), 'flows' => (int) ($rDoc['flows'] ?? 0) & 255, 'state' => (string) ($rDoc['state'] ?? ''), 'features' => $rFeatures];
	}
}
