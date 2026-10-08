<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cache\FileCache;

/**
 * Booting from the node replica instead of MAIN's database (plan, section
 * 10, step 2; Core\Bootstrap\Stage\ReplicaStage). Lives in Core: it ships to
 * LBs, where Domain\Cluster does not.
 *
 * - A node in mode 2 (api), and a node in mode 1 (hybrid) with the CONFIG
 *   flow on, boots its CLI processes and web API endpoints from the caches
 *   its replica built (ReplicaApply), once an apply has built the settings
 *   and servers caches since the last reboot, and its streaming entry points
 *   take a lazy database handle then (now()). Until then it falls back to
 *   the boot through MAIN's database: counted in mode 1, as before this
 *   boot existed, and refused in mode 2, whose process ends at its boot
 *   (fails closed).
 * - `cluster:apply` always boots from the replica, whatever the mode: its
 *   work is to build those caches, at boot too (`service` runs it with
 *   `--from-disk` before the daemons), when MAIN may be unreachable. So does
 *   any command given `--replica` (OPTION): an install runs `fanout_binary`
 *   so on a node MAIN keeps credential-free, before its enrolment brings a
 *   replica (LbInstallFlow::provisionCluster).
 * - Nodes in mode 0, in mode 1 without CONFIG, and MAIN, boot as before.
 *
 * Once a process has booted from the replica (start()), its settings and
 * servers come from the replica's caches: SettingsRepository and
 * ServerRepository answer them however old. Any other query opens MAIN's
 * database, lazily, on first use: that is the connect ConnectAudit counts,
 * with its site, and on a node in mode 2 refuses (plan, section 10, step 1).
 * What the replica does not own, a mode 1 process reads from MAIN's
 * database the same lazy way (hybrid()): the settings and servers once an
 * apply handed them back, the crontab's jobs. `cluster:apply` and a node
 * whose connects are refused (mode 2) never do: they get the caches however
 * old, or nothing.
 */
final class ReplicaBoot {
	/** Commands that boot from the replica in every mode. */
	public const COMMANDS = ['cluster:apply'];

	/** The console option that boots any command from the replica in every mode. */
	public const OPTION = '--replica';

	/** The boot option: boot from the replica even before an apply built its caches (cluster:apply). */
	public const ALWAYS = 'always';

	/** The boot option: boot from the replica once an apply built its caches, else as before (mode 1 with CONFIG, mode 2). */
	public const WHEN_READY = 'ready';

	/** The states in which MAIN counts a node as active, so its mode applies. */
	private const ACTIVE_STATES = ['active', 'quarantined'];

	private static bool $rActive = false;

	private static bool $rHybrid = false;

	/**
	 * Does this node boot from its replica? Mode 2 (apiMode()), and mode 1
	 * with the CONFIG flow on, as the agent's flows.json says, for a node
	 * MAIN counts as active or quarantined. Read from the file alone, before
	 * the settings, the servers or a database handle exist: MAIN runs no
	 * agent, so it has no such file. Mode 1 needs CONFIG, since only then
	 * does the replica own the settings and servers: with CONFIG off they
	 * are MAIN's database's, whatever an earlier apply left.
	 */
	public static function wanted(): bool {
		$rFlows = NodeFlows::declared();
		if (!in_array($rFlows['state'], self::ACTIVE_STATES, true)) {
			return false;
		}
		return $rFlows['mode'] === 2 || ($rFlows['mode'] === 1 && ($rFlows['flows'] & NodeFlows::CONFIG) === NodeFlows::CONFIG);
	}

	/**
	 * Is this node in mode 2 (api), as the agent's flows.json says, in a
	 * state MAIN counts as active? Its connects to MAIN are refused
	 * (NodeRole::refusesConnects), whatever its flows.
	 */
	public static function apiMode(): bool {
		$rFlows = NodeFlows::declared();
		return $rFlows['mode'] === 2 && in_array($rFlows['state'], self::ACTIVE_STATES, true);
	}

	/**
	 * Has an apply built the settings and servers caches the replica owns
	 * since the last reboot (`replica_owned`, beside the caches in tmp/)?
	 */
	public static function ready(): bool {
		return ReplicaApply::built(ReplicaSections::SETTINGS) && ReplicaApply::built(ReplicaSections::SERVERS);
	}

	/**
	 * The boot option for a console command line: ALWAYS for the commands
	 * that boot from the replica in every mode, and for any given OPTION,
	 * else null (the node's mode decides).
	 *
	 * @param list<string> $rArgv
	 */
	public static function forArgv(array $rArgv): ?string {
		return in_array($rArgv[1] ?? null, self::COMMANDS, true) || in_array(self::OPTION, array_slice($rArgv, 2), true) ? self::ALWAYS : null;
	}

	/**
	 * Does an entry point that chooses no boot stage (the streaming
	 * endpoints) boot from the replica now: this node wants to (wanted())
	 * and an apply built the caches since the reboot (ready())?
	 */
	public static function now(): bool {
		return self::wanted() && self::ready();
	}

	/**
	 * This process booted from the replica (ReplicaStage), with the boot
	 * option it was given (ALWAYS or WHEN_READY).
	 */
	public static function start(string $rWhen = self::ALWAYS): void {
		self::$rActive = true;
		self::$rHybrid = $rWhen === self::WHEN_READY;
	}

	/** Did this process boot from the replica? Its settings and servers then come from the replica's caches (in mode 1 while it owns them: hybrid()). */
	public static function active(): bool {
		return self::$rActive;
	}

	/**
	 * May this process, booted from the replica, still read from MAIN's
	 * database what the replica does not answer: the settings, the servers
	 * and the crontab's jobs while it does not own them (CONFIG went off, a
	 * section was refused or never stored)? Where its connects are not
	 * refused (mode 1; mode 2 on MAIN's build, which never refuses): lazily,
	 * on first use, counted by ConnectAudit at its site, as mode 1 read them
	 * before it booted from the replica. Never `cluster:apply` (ALWAYS: its
	 * apply needs no database), nor where they are refused
	 * (NodeRole::refusesConnects, mode 2). The refusal is asked at each call,
	 * as each connect asks it: a daemon that booted in mode 1 keeps the caches
	 * however old once the node is switched to mode 2.
	 */
	public static function hybrid(): bool {
		return self::$rHybrid && !NodeRole::refusesConnects();
	}

	/** Tests: forget the boot. */
	public static function reset(): void {
		self::$rActive = false;
		self::$rHybrid = false;
	}

	/**
	 * A cache as the node holds it, however old, or [] without one: what a
	 * process booted from the replica reads where it would have read MAIN's
	 * database (the bouquets and categories, which no section carries yet).
	 *
	 * @return array<mixed>
	 */
	public static function cached(string $rKey): array {
		$rCache = FileCache::getCache($rKey);
		return is_array($rCache) ? $rCache : [];
	}
}
