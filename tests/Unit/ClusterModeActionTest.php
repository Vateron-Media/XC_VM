<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\DbAllowlist;
use XcVm\Domain\Cluster\NodeAudit;
use XcVm\Domain\Cluster\NodeHealth;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * The mode buttons of the Cluster Nodes page, as ClusterAdmin::act() takes
 * them. Mode up moves a node from 1 to 2 at once when it can run without
 * MAIN's database now: it was heard a moment ago and its last report, which
 * its heartbeat carries (NodeAudit), says it runs from its own copy. The
 * thousands of connects a node in mode 1 makes by design are not held against
 * it. MAIN notes when the node entered mode 2: the days before it may give up
 * MAIN's credentials are counted from there (DbCredentials::strip).
 */
final class ClusterModeActionTest extends TestCase {
	private const NOW_MS = 1800000000000;

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` text, `updated_at` int)');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `node_uuid` char(36), `state` varchar(16) NOT NULL DEFAULT 'active', `mode` int NOT NULL DEFAULT 1, `flows` int NOT NULL DEFAULT 0, `root_ready` int NOT NULL DEFAULT 1, `gen` int NOT NULL DEFAULT 1, `audit` text DEFAULT NULL, `last_seen_at` bigint DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)");
		$this->rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `flows`) VALUES (7, ?, ?)', '0f8fad5b-d9cb-469f-a165-70867728950e', ClusterAdmin::MODE2_FLOWS);
		DatabaseFactory::set($this->rDb);
		foreach ([NodeAudit::class, NodeRegistry::class, ClusterMeta::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		ClusterClock::fix(self::NOW_MS);
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	/**
	 * The node's heartbeat, $rAgoMs ago, with its audit as the agent sends
	 * audit.json: $rSqlConnects connects counted, and whether it runs from
	 * its own copy (null: a release that does not say).
	 */
	private function heartbeat(int $rAgoMs, int $rSqlConnects, ?bool $rStreamsLocal): void {
		$rAudit = ['settings_misses' => [], 'sql_connects' => $rSqlConnects, 'redis_connects' => 0, 'sites' => [], 'connects_since' => 1790000000];
		if ($rStreamsLocal !== null) {
			$rAudit['streams_local'] = $rStreamsLocal;
		}
		$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ? WHERE `server_id` = 7', ClusterClock::nowMs() - $rAgoMs);
		NodeAudit::record((array) NodeRegistry::byServer(7), $rAudit);
	}

	/** The page's button, pressed on a row drawn for the node's mode as it is ($rShown: another mode, or null for a form without it). */
	private function act(string $rAction, array $rSettings = [], int|string|null $rShown = 'as it is'): string {
		$rServers = [1 => ['is_main' => 1, 'server_type' => 0], 7 => ['is_main' => 0, 'server_type' => 0, 'server_name' => 'lb-7']];
		$rInput = ['cluster_action' => $rAction, 'server_id' => 7];
		if ($rShown !== null) {
			$rInput['mode'] = $rShown === 'as it is' ? (string) $this->mode() : (string) $rShown;
		}
		return ClusterAdmin::act(new FakeClusterCrypto(), $rInput, $rServers, 1, $rSettings, 3)['message'];
	}

	private function mode(): int {
		return (int) $this->rDb->pdo->query('SELECT `mode` FROM `cluster_nodes` WHERE `server_id` = 7')->fetchColumn();
	}

	/** @return list<string> */
	private function audit(): array {
		return $this->rDb->pdo->query("SELECT `detail` FROM `cluster_audit` WHERE `event` = 'node.mode' ORDER BY `id`")->fetchAll(PDO::FETCH_COLUMN);
	}

	public function testANodeInModeOneMovesAtOnceForAllItsConnects(): void {
		// As they are in mode 1: tens of thousands of connects, heard two seconds ago, running from its own copy.
		$this->heartbeat(2000, 80701, true);
		$this->assertSame('cluster_mode_done', $this->act('mode_up'));
		$this->assertSame(2, $this->mode());
		$this->assertSame(['{"mode":2,"was":1}'], $this->audit());
		$this->assertSame((string) intdiv(self::NOW_MS, 1000), ClusterMeta::get(ClusterAdmin::MODE2_AT . 7), 'when it entered mode 2, by MAIN\'s clock');
		// What it said, it said in mode 1: it says it again from mode 2.
		$this->assertNull(NodeAudit::streamsLocal(NodeAudit::reports()[7]));
		$this->assertSame(80701, NodeAudit::connectsOf(NodeAudit::reports()[7])['sql_connects'], 'the rest of its report stays');

		// And back, whatever its state: the record goes with it.
		$this->rDb->exec("UPDATE `cluster_nodes` SET `last_seen_at` = 0, `state` = 'quarantined'");
		$this->assertSame('cluster_mode_done', $this->act('mode_down'));
		$this->assertSame(1, $this->mode());
		$this->assertNull(ClusterMeta::get(ClusterAdmin::MODE2_AT . 7));
		$this->assertSame(['{"mode":2,"was":1}', '{"mode":1,"was":2}'], $this->audit());
	}

	public function testTheMoveWaitsForANodeThatCanRunThatWayNow(): void {
		$this->assertSame('cluster_mode_not_heard', $this->act('mode_up'), 'never heard');
		$this->heartbeat(11000, 0, true);
		$this->assertSame('cluster_mode_not_heard', $this->act('mode_up'), 'eleven seconds ago: its report may be that old');
		$this->heartbeat(2000, 0, null);
		$this->assertSame('cluster_mode_needs_streams', $this->act('mode_up'), 'a release that does not say');
		$this->heartbeat(2000, 0, false);
		$this->assertSame('cluster_mode_needs_streams', $this->act('mode_up'));
		// What is not a boolean is not the node's word.
		NodeAudit::record((array) NodeRegistry::byServer(7), ['settings_misses' => [], 'streams_local' => 'yes']);
		$this->assertSame('cluster_mode_needs_streams', $this->act('mode_up'));

		$this->heartbeat(2000, 0, true);
		$this->rDb->exec("UPDATE `cluster_nodes` SET `state` = 'quarantined'");
		$this->assertSame('cluster_mode_needs_active', $this->act('mode_up'));
		$this->rDb->exec("UPDATE `cluster_nodes` SET `state` = 'active'");

		$this->assertSame(1, $this->mode());
		$this->assertSame([], $this->audit(), 'a refused move leaves no entry');
		$this->assertNull(ClusterMeta::get(ClusterAdmin::MODE2_AT . 7));
		// The Redis connection handler asks nothing: a node in mode 2 never opens MAIN's Redis.
		$this->assertSame('cluster_mode_done', $this->act('mode_up', ['redis_handler' => 1]));
	}

	/**
	 * The step is relative, and the page is drawn as the POST's answer: a
	 * reload posts the form again. A form drawn for another mode moves
	 * nothing, so the warning before mode 2 cannot be passed by posting twice
	 * the form that took the node from 0 to 1, which asks nothing.
	 */
	public function testAFormDrawnForAnotherModeMovesNothing(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `mode` = 0');
		$this->heartbeat(1000, 0, true);
		$this->assertSame('cluster_mode_done', $this->act('mode_up', [], 0));
		$this->heartbeat(1000, 0, true);
		$this->assertSame('cluster_mode_moved', $this->act('mode_up', [], 0), 'the same form, posted again by a reload');
		$this->assertSame(1, $this->mode());
		$this->assertSame('cluster_mode_moved', $this->act('mode_up', [], null), 'nor a form that does not say: only the one that asks first moves a node to 2');
		$this->assertSame('cluster_mode_moved', $this->act('mode_down', [], 2), 'someone else moved it meanwhile');
		$this->assertSame(1, $this->mode());
		$this->assertSame(['{"mode":1,"was":0}'], $this->audit());

		$this->assertSame('cluster_mode_done', $this->act('mode_up', [], 1));
		$this->assertSame(2, $this->mode());
		// A form without the mode still moves a node down, or up to 1 (an older page, the API).
		$this->assertSame('cluster_mode_done', $this->act('mode_down', [], null));
		$this->assertSame(1, $this->mode());
	}

	public function testTheOtherMovesAskNoneOfIt(): void {
		// Never heard, no report, the Redis handler on: mode 0 and 1 keep MAIN's database.
		$this->assertSame('cluster_mode_done', $this->act('mode_down', ['redis_handler' => 1]));
		$this->assertSame('cluster_mode_unknown', $this->act('mode_down'), 'there is no mode below 0');
		$this->assertSame('cluster_mode_done', $this->act('mode_up', ['redis_handler' => 1]));
		$this->assertSame(1, $this->mode());
		$this->assertNull(ClusterMeta::get(ClusterAdmin::MODE2_AT . 7));
		$this->assertSame(['{"mode":0,"was":1}', '{"mode":1,"was":0}'], $this->audit());

		$this->heartbeat(1000, 3, true);
		$this->assertSame('cluster_mode_done', $this->act('mode_up'));
		$this->assertSame('cluster_mode_unknown', $this->act('mode_up'), 'nor one above 2');
		$this->assertSame('cluster_unknown_action', $this->act('mode_up_now'), 'one button moves a node up');
	}

	/** @return array{0: string, 1: string} the last `node.mode` entry's actor and detail */
	private function lastMove(): array {
		return $this->rDb->pdo->query("SELECT `actor`, `detail` FROM `cluster_audit` WHERE `event` = 'node.mode' ORDER BY `id` DESC LIMIT 1")->fetch(PDO::FETCH_NUM);
	}

	/** Opt-in (cron:cluster): a node in mode 2 that lost its own streams goes back to mode 1 once the minutes set have passed. */
	public function testANodeThatLostItsStreamsGoesBackToModeOneWhenTheSettingSaysSo(): void {
		$rOn = ['cluster_auto_mode_down_min' => 10];
		$this->heartbeat(2000, 0, true);
		$this->assertSame('cluster_mode_done', $this->act('mode_up'));
		$this->assertSame('1', ClusterMeta::get(ClusterAdmin::MODE2_GEN . 7), 'the gen the page moved it at');

		// Running from its own copy: nothing.
		$this->heartbeat(2000, 0, true);
		$this->assertSame([], ClusterAdmin::autoModeDown($rOn));
		// It stops: off by default; on, noted first, and moved once the minutes have passed.
		$this->heartbeat(2000, 0, false);
		$this->assertSame([], ClusterAdmin::autoModeDown([]));
		$this->assertNull(ClusterMeta::get(ClusterAdmin::STREAMS_LOST_AT . 7), 'off notes nothing');
		$this->assertSame([], ClusterAdmin::autoModeDown($rOn));
		$this->assertSame((string) intdiv(self::NOW_MS, 1000), ClusterMeta::get(ClusterAdmin::STREAMS_LOST_AT . 7));
		ClusterClock::fix(self::NOW_MS + 599000);
		$this->heartbeat(2000, 0, false);
		$this->assertSame([], ClusterAdmin::autoModeDown($rOn), 'not yet');
		$this->assertSame(2, $this->mode());

		// Back for a moment: the count starts again.
		$this->heartbeat(2000, 0, true);
		$this->assertSame([], ClusterAdmin::autoModeDown($rOn));
		$this->assertNull(ClusterMeta::get(ClusterAdmin::STREAMS_LOST_AT . 7));
		$this->heartbeat(2000, 0, false);
		ClusterAdmin::autoModeDown($rOn);
		ClusterClock::fix(self::NOW_MS + 599000 + 600000);
		$this->heartbeat(2000, 0, false);
		$this->assertSame([7], ClusterAdmin::autoModeDown($rOn));
		$this->assertSame(1, $this->mode());
		$this->assertSame(['auto', '{"mode":1,"was":2,"streams_lost_at":' . intdiv(self::NOW_MS + 599000, 1000) . '}'], $this->lastMove());
		foreach ([ClusterAdmin::MODE2_AT, ClusterAdmin::MODE2_GEN, ClusterAdmin::STREAMS_LOST_AT] as $rMeta) {
			$this->assertNull(ClusterMeta::get($rMeta . 7), $rMeta);
		}
		$this->assertSame([], ClusterAdmin::autoModeDown($rOn), 'once');
	}

	/** Only a node that can run in mode 1 goes back by itself; the page's button still takes any. */
	public function testOnlyANodeThatHoldsItsCredentialsAndIsHeardGoesBack(): void {
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `db_revoked_at` int DEFAULT NULL');
		$rOn = ['cluster_auto_mode_down_min' => 1];
		$this->heartbeat(2000, 0, true);
		$this->assertSame('cluster_mode_done', $this->act('mode_up'));
		$this->heartbeat(2000, 0, false);
		ClusterAdmin::autoModeDown($rOn);
		ClusterClock::fix(self::NOW_MS + 60000);
		$this->heartbeat(2000, 0, false);

		$rHolds = [
			're-enrolled since the move (a reinstall in mode 2 gave it no credentials)' => ['UPDATE `cluster_nodes` SET `gen` = 2', 'UPDATE `cluster_nodes` SET `gen` = 1'],
			'its grant revoked' => ['UPDATE `cluster_nodes` SET `db_revoked_at` = 1790000000', 'UPDATE `cluster_nodes` SET `db_revoked_at` = NULL'],
			'quarantined' => ["UPDATE `cluster_nodes` SET `state` = 'quarantined'", "UPDATE `cluster_nodes` SET `state` = 'active'"],
			'not heard' => ['UPDATE `cluster_nodes` SET `last_seen_at` = ' . (self::NOW_MS + 60000 - NodeHealth::SUSPECT_AFTER_MS - 1), 'UPDATE `cluster_nodes` SET `last_seen_at` = ' . (self::NOW_MS + 58000)],
			'MAIN locked down' => ["INSERT INTO `cluster_meta` VALUES ('" . DbAllowlist::LOCKDOWN_META . "', '1', 0)", "DELETE FROM `cluster_meta` WHERE `name` = '" . DbAllowlist::LOCKDOWN_META . "'"],
			'enrolled in mode 2, never moved there by the page' => ["DELETE FROM `cluster_meta` WHERE `name` = '" . ClusterAdmin::MODE2_GEN . "7'", "INSERT INTO `cluster_meta` VALUES ('" . ClusterAdmin::MODE2_GEN . "7', '1', 0)"],
		];
		foreach ($rHolds as $rWhy => [$rSet, $rUndo]) {
			$this->rDb->exec($rSet);
			$this->assertSame([], ClusterAdmin::autoModeDown($rOn), $rWhy);
			$this->assertSame(2, $this->mode(), $rWhy);
			$this->rDb->exec($rUndo);
		}
		$this->assertSame([7], ClusterAdmin::autoModeDown($rOn), 'none of them');
	}
}
