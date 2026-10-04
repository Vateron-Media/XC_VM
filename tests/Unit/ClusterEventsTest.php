<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\NodeStateSink;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Logging\FileLogger;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\AgentUser;

/**
 * Phase 5, logs and stream state: on a node whose LOGS / STREAMS flow is on,
 * LogSink and StreamStateWriter spool redacted events for the agent instead
 * of writing MAIN's database; MAIN's EventIngest applies a node's batch in
 * order, at most once, and only to that node's own rows.
 */
final class ClusterEventsTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `pid` int, `stream_status` int, `current_source` text, `on_demand` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `streams_logs` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `action` varchar(500), `source` varchar(1024), `date` int)');
		$this->rDb->exec('CREATE TABLE `lines_logs` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `user_id` int, `client_status` varchar(255), `query_string` text, `user_agent` text, `ip` varchar(64), `extra_data` text, `date` int)');
		$this->rDb->query('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`, `stream_status`) VALUES (11, 100, 5, 0, 0), (12, 100, 6, 0, 0)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		NodeRegistry::startEnrolment(5, '11111111-1111-4111-a111-111111111111', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(5, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_LOGS | NodeRegistry::FLOW_STREAMS]);

		$this->rDir = sys_get_temp_dir() . '/xcvm-events-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		EventSpool::useDir($this->rDir . '/spool/');
	}

	protected function tearDown(): void {
		EventSpool::useDir(null);
		SettingsManager::set([]);
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		FileLogger::setLogFile(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function flows(int $rFlows, int $rAge = 0, int $rMode = 1): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => 'active']));
		touch($this->rDir . '/flows.json', time() - $rAge);
		NodeFlows::usePath($this->rDir . '/flows.json');
	}

	/** A node in mode 2 (on a load balancer's build), its agent's file $rAge seconds old. */
	private function modeTwo(int $rFlows, int $rAge = 0): void {
		$this->flows($rFlows, $rAge, 2);
		NodeRole::useMainBuild(false);
	}

	/** @return list<array<string, mixed>> the spooled events of a lane, in file order */
	private function spooled(string $rLane): array {
		$rFiles = glob($this->rDir . '/spool/' . $rLane . '/*.ndjson') ?: [];
		sort($rFiles);
		$rOut = [];
		foreach ($rFiles as $rFile) {
			foreach (array_filter(explode("\n", (string) file_get_contents($rFile))) as $rLine) {
				$rOut[] = json_decode($rLine, true);
			}
		}
		return $rOut;
	}

	private function rows(string $rSql): array {
		$this->rDb->query($rSql);
		return $this->rDb->get_rows();
	}

	private function row(string $rSql): array {
		return $this->rows($rSql)[0] ?? [];
	}

	private function val(string $rSql): mixed {
		$rRow = $this->row($rSql);
		return $rRow === [] ? null : reset($rRow);
	}

	private function node(): array {
		return NodeRegistry::byServer(5);
	}

	// ── Node side ────────────────────────────────────────────────────────

	public function testLogsGoToTheSpoolRedactedWhenTheFlowIsOn(): void {
		$this->flows(NodeFlows::LOGS);
		$this->assertTrue(LogSink::write('client', [['stream_id' => 1, 'user_id' => 2, 'query_string' => 'username=alice&password=s3cret', 'ip' => '1.2.3.4', 'date' => 7, 'bogus' => 'x']]));
		$this->assertSame(0, (int) $this->val('SELECT COUNT(*) FROM `lines_logs`'), 'MAIN\'s database is not written');
		$rEvents = $this->spooled('p1');
		$this->assertCount(1, $rEvents);
		$this->assertSame('log.client', $rEvents[0]['type']);
		$rRow = $rEvents[0]['d']['rows'][0];
		$this->assertSame('username=***&password=***', $rRow['query_string']);
		$this->assertArrayNotHasKey('bogus', $rRow, 'only the type\'s columns');
		$this->assertSame([], glob($this->rDir . '/spool/p1/.*.tmp') ?: [], 'no half-written file is left');
	}

	public function testLogsFallBackToSqlWhenTheFlowIsOffOrTheAgentStopped(): void {
		$this->flows(NodeFlows::STREAMS);
		LogSink::write('stream', [['stream_id' => 1, 'server_id' => 5, 'action' => 'start', 'source' => 'http://u:p@src/1', 'date' => 1]]);
		$this->flows(NodeFlows::LOGS, EventSpool::STALE_AFTER + 30);
		LogSink::write('stream', [['stream_id' => 1, 'server_id' => 5, 'action' => 'stop', 'source' => '', 'date' => 2]]);
		$this->assertSame(2, (int) $this->val('SELECT COUNT(*) FROM `streams_logs`'));
		$this->assertSame([], $this->spooled('p1'));
	}

	public function testStreamStateGoesToTheSpoolWhenTheFlowIsOn(): void {
		$this->flows(NodeFlows::STREAMS);
		$this->assertTrue(StreamStateWriter::update(100, 5, ['pid' => 42, 'current_source' => 'http://bob:pw@origin/live/bob/pw/1.ts']));
		$this->assertTrue(StreamStateWriter::updateRow(11, ['stream_status' => 1]));
		$this->assertSame(0, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'));
		$rEvents = $this->spooled('p0');
		$this->assertSame(['stream.state', 'stream.state'], array_column($rEvents, 'type'));
		$this->assertSame(['stream_id' => 100, 'server_id' => 5], array_intersect_key($rEvents[0]['d'], ['stream_id' => 1, 'server_id' => 1]));
		$this->assertStringNotContainsString('pw', $rEvents[0]['d']['fields']['current_source']);
		$this->assertSame(11, $rEvents[1]['d']['ssid']);
	}

	public function testStreamStateStaysSqlOnALegacyNode(): void {
		$this->flows(0);
		StreamStateWriter::update(100, 5, ['pid' => 42]);
		$this->assertSame(42, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'));
		$this->assertSame([], $this->spooled('p0'));
	}

	/**
	 * What root does on a node (a root action, a PHP-FPM restart) goes to
	 * MAIN's system log as a `log.syslog` event once LOGS is on. Otherwise
	 * the caller writes the row itself, as before, except in mode 2, which
	 * never writes MAIN's database: a line the spool refuses goes to the
	 * panel's error log, redacted, written as the agent's user.
	 */
	public function testRootsSystemLogLinesGoToTheSpoolOnceLogsIsOn(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->flows(NodeFlows::LOGS);
		$this->assertTrue(LogSink::syslog('REBOOT', 'System rebooted on request.', 1799999000));
		$this->assertSame([['log.syslog', ['rows' => [['server_id' => SERVER_ID, 'type' => 'REBOOT', 'error' => 'System rebooted on request.', 'username' => 'root', 'ip' => 'localhost', 'database' => null, 'date' => 1799999000]]]]], array_map(static fn($e) => [$e['type'], $e['d']], $this->spooled('p1')));

		$this->flows(NodeFlows::STREAMS);
		$this->assertFalse(LogSink::syslog('REBOOT', 'System rebooted on request.'), 'LOGS off: the caller writes the row');
		$this->flows(NodeFlows::LOGS, EventSpool::STALE_AFTER + 30);
		$this->assertFalse(LogSink::syslog('REBOOT', 'System rebooted on request.'), 'the agent stopped: the caller writes the row');
		NodeFlows::usePath(null);
		$this->assertFalse(LogSink::syslog('REBOOT', 'System rebooted on request.'), 'MAIN and mode 0: the row, as before');

		// Mode 2 never writes MAIN's database: with the agent stopped the
		// line goes to the panel's error log (the agent's directory is xc_vm's).
		AgentUser::own($this->rDir);
		FileLogger::setLogFile($this->rDir . '/logs/error_log.log');
		$this->modeTwo(255, EventSpool::STALE_AFTER + 30);
		$this->assertTrue(LogSink::syslog('UPDATE', 'Updating from http://u:p@host/x'));
		$this->assertCount(1, $this->spooled('p1'), 'nothing spooled');
		$rLogged = array_map(static fn(string $rLine): array => json_decode((string) base64_decode($rLine), true), file($this->rDir . '/logs/error_log.log', FILE_IGNORE_NEW_LINES) ?: []);
		$this->assertSame([['syslog', 'Not in MAIN\'s system log (mode 2, and the agent took no event): UPDATE: Updating from http://***@host/x']], array_map(static fn(array $rRow): array => [$rRow['type'], $rRow['message']], $rLogged));
		if (AgentUser::root()) {
			$this->assertSame(AgentUser::UID, fileowner($this->rDir . '/logs/error_log.log'), 'written as the agent\'s user, never as root');
		}
	}

	/**
	 * A node in mode 2 never writes its own servers row (set_governor,
	 * set_sysctl, certbot's `certbot_ssl`): false when the spool did not take
	 * the event, with the agent stopped or TELEMETRY off. Mode 1 writes it,
	 * as before.
	 */
	public function testANodeInModeTwoNeverWritesItsStateRow(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `governor` text)');
		$this->rDb->query('INSERT INTO `servers` (`id`, `governor`) VALUES (?, ?)', SERVER_ID, 'old');
		$this->modeTwo(255, EventSpool::STALE_AFTER + 30);
		$this->assertFalse(NodeStateSink::state(['governor' => 'new'], $this->rDb), 'the agent stopped');
		$this->modeTwo(255 & ~NodeFlows::TELEMETRY);
		$this->assertFalse(NodeStateSink::state(['governor' => 'new'], $this->rDb), 'TELEMETRY off');
		$this->assertSame([], $this->spooled('p0'));
		$this->assertSame([['governor' => 'old']], $this->rows('SELECT `governor` FROM `servers`'));

		$this->flows(NodeFlows::STREAMS);
		$this->assertTrue(NodeStateSink::state(['governor' => 'new'], $this->rDb), 'mode 1 without TELEMETRY: the row, as before');
		$this->assertSame([['governor' => 'new']], $this->rows('SELECT `governor` FROM `servers`'));
	}

	// ── MAIN side ────────────────────────────────────────────────────────

	public function testP0IsGapCheckedAndAppliedOnce(): void {
		$rState = static fn(int $rPid) => ['type' => 'stream.state', 'd' => ['stream_id' => 100, 'server_id' => 5, 'fields' => ['pid' => $rPid]]];
		$this->assertSame(['ok' => false, 'useq' => 0, 'expected_useq' => 1], EventIngest::ingest($this->node(), 'p0', 3, [$rState(1)]));

		$rOut = EventIngest::ingest($this->node(), 'p0', 1, [$rState(1), $rState(2)]);
		$this->assertSame([true, 2, 2, 0], [$rOut['ok'], $rOut['useq'], $rOut['applied'], $rOut['dropped']]);
		$this->assertSame(2, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'));

		// The same batch again (its reply was lost): nothing is applied twice.
		$this->rDb->query('UPDATE `streams_servers` SET `pid` = 9 WHERE `server_stream_id` = 11');
		$this->assertSame(0, EventIngest::ingest($this->node(), 'p0', 1, [$rState(1), $rState(2)])['applied']);
		$this->assertSame(9, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'));
		$this->assertSame(2, (int) $this->node()['useq_p0']);
	}

	/**
	 * Below the cursor, only a copy of a batch applied is a repeat: the newest
	 * however old, the others for three minutes. Anything else is another
	 * install numbering from where this node was (`backwards`); with no
	 * record (a fresh lock directory) it is a repeat, as before.
	 */
	public function testAP0BatchThatGoesBackIsNoRepeat(): void {
		$rPrevious = EventIngest::useLockDir($this->rDir . '/locks/');
		try {
			$rState = static fn(int $rPid) => ['type' => 'stream.state', 'd' => ['stream_id' => 100, 'server_id' => 5, 'fields' => ['pid' => $rPid]]];
			$rA = [$rState(1), $rState(2)];
			$rB = [$rState(3)];
			$this->assertSame(2, EventIngest::ingest($this->node(), 'p0', 1, $rA)['applied']);
			$this->assertSame(1, EventIngest::ingest($this->node(), 'p0', 3, $rB)['applied']);

			$this->assertSame(['ok' => false, 'useq' => 3, 'backwards' => true], EventIngest::ingest($this->node(), 'p0', 1, [$rState(7), $rState(8)]), 'other events under the same numbers');
			$this->assertSame(['ok' => false, 'useq' => 3, 'backwards' => true], EventIngest::ingest($this->node(), 'p0', 2, [$rState(2)]), 'part of a batch');
			$this->assertSame(['ok' => false, 'useq' => 3, 'backwards' => true], EventIngest::ingest($this->node(), 'p0', 3, [$rState(3), $rState(4)]), 'an overlap');
			$this->assertSame(3, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'), 'none of them applied');

			$this->assertTrue(EventIngest::ingest($this->node(), 'p0', 1, $rA)['ok'], 'a late copy of the batch before');
			ClusterClock::fix(1800000000000 + 181000);
			$this->assertTrue(EventIngest::ingest($this->node(), 'p0', 3, $rB)['ok'], 'the newest, however old: the node resends it until it hears back');
			$this->assertTrue(EventIngest::ingest($this->node(), 'p0', 1, $rA)['ok'], 'kept until the next batch');
			$this->assertSame(1, EventIngest::ingest($this->node(), 'p0', 4, [$rState(4)])['applied']);
			$this->assertSame(['ok' => false, 'useq' => 4, 'backwards' => true], EventIngest::ingest($this->node(), 'p0', 1, $rA), 'three minutes past, no copy can still be on its way');

			exec('rm -rf ' . escapeshellarg($this->rDir . '/locks/'));
			$this->assertSame(['ok' => true, 'useq' => 4, 'applied' => 0, 'dropped' => 0], EventIngest::ingest($this->node(), 'p0', 1, [$rState(7)]), 'no record: a repeat');
			$this->assertSame(4, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'));
		} finally {
			EventIngest::useLockDir($rPrevious);
		}
	}

	/**
	 * A `log.syslog` row is root's on the sending node: one of the types
	 * root writes (never `AUTH`, whose addresses cron:root_mysql blocks),
	 * as `root` from `localhost`, dated no later than MAIN's clock (the
	 * newest date is cron:root_mysql's watermark), redacted.
	 */
	public function testMainKeepsASystemLogLineAsRootsOnTheNode(): void {
		$this->rDb->exec('CREATE TABLE `mysql_syslog` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `type` varchar(50), `error` text, `username` varchar(64), `ip` varchar(64), `database` varchar(64), `date` int, `server_id` int DEFAULT 1)');
		$rLine = static fn(array $rRow): array => ['type' => 'log.syslog', 'd' => ['rows' => [$rRow]]];
		$rOut = EventIngest::ingest($this->node(), 'p1', 1, [
			$rLine(['server_id' => 99, 'type' => 'REBOOT', 'error' => 'System rebooted on request.', 'username' => 'root', 'ip' => 'localhost', 'database' => null, 'date' => 1799999000]),
			$rLine(['type' => 'RESTART', 'error' => 'XC_VM services restarted on request.', 'username' => 'admin', 'ip' => '203.0.113.7', 'database' => 'xc_vm', 'date' => 1900000000]),
			$rLine(['type' => 'AUTH', 'error' => 'Access denied for user', 'username' => 'x', 'ip' => '192.0.2.50', 'date' => 1799999000]),
			$rLine(['type' => 'UPDATE', 'error' => 'Updating from http://u:p@host/x', 'date' => 'soon']),
			$rLine(['type' => 'UPDATE', 'error' => ['nested']]),
		]);
		$this->assertSame([5, 3, 2], [$rOut['useq'], $rOut['applied'], $rOut['dropped']]);
		$rRows = array_map(static fn($r) => array_map(static fn($v) => is_string($v) && ctype_digit($v) ? (int) $v : $v, $r), $this->rows('SELECT `server_id`, `type`, `error`, `username`, `ip`, `database`, `date` FROM `mysql_syslog` ORDER BY `id`'));
		$this->assertSame([
			['server_id' => 5, 'type' => 'REBOOT', 'error' => 'System rebooted on request.', 'username' => 'root', 'ip' => 'localhost', 'database' => null, 'date' => 1799999000],
			['server_id' => 5, 'type' => 'RESTART', 'error' => 'XC_VM services restarted on request.', 'username' => 'root', 'ip' => 'localhost', 'database' => null, 'date' => 1800000000],
			['server_id' => 5, 'type' => 'UPDATE', 'error' => 'Updating from http://***@host/x', 'username' => 'root', 'ip' => 'localhost', 'database' => null, 'date' => 1800000000],
		], $rRows);

		// LOGS off: refused, as every log.* event.
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_STREAMS]);
		$this->assertSame(0, EventIngest::ingest($this->node(), 'p1', 6, [$rLine(['type' => 'STOP', 'error' => 'x', 'date' => 1])])['applied']);
	}

	public function testANodeWritesOnlyItsOwnRowsAndStateColumns(): void {
		$rOut = EventIngest::ingest($this->node(), 'p0', 1, [
			['type' => 'stream.state', 'd' => ['ssid' => 12, 'fields' => ['pid' => 66]]],                        // node 6's row
			['type' => 'stream.state', 'd' => ['stream_id' => 100, 'server_id' => 6, 'fields' => ['pid' => 66]]], // ditto
			['type' => 'stream.state', 'd' => ['stream_id' => 100, 'fields' => ['on_demand' => 1]]],              // desired state
			['type' => 'stream.state', 'd' => ['ssid' => 11, 'fields' => ['pid' => 7, 'on_demand' => 1]]],
			['type' => 'log.stream', 'd' => ['rows' => [['stream_id' => 1]]]],                                    // wrong lane
		]);
		$this->assertSame(5, $rOut['useq']);
		$this->assertSame(0, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 12'));
		$this->assertSame([7, 0], array_map('intval', array_values($this->row('SELECT `pid`, `on_demand` FROM `streams_servers` WHERE `server_stream_id` = 11'))));
		$this->assertSame(0, (int) $this->val('SELECT COUNT(*) FROM `streams_logs`'));
	}

	public function testP1SkipsWhatWasAppliedAndForcesTheSendersServerID(): void {
		$rLog = static fn(string $rAction) => ['type' => 'log.stream', 'd' => ['rows' => [['stream_id' => 1, 'server_id' => 99, 'action' => $rAction, 'source' => 'http://u:p@src/x', 'date' => 1]]]];
		EventIngest::ingest($this->node(), 'p1', 1, [$rLog('a'), $rLog('b')]);
		// Overlaps what was applied, after a gap (the node dropped 3..4): fine on P1.
		$rOut = EventIngest::ingest($this->node(), 'p1', 2, [$rLog('b'), $rLog('c')]);
		$this->assertSame([3, 1], [$rOut['useq'], $rOut['applied']]);
		$this->assertSame(7, EventIngest::ingest($this->node(), 'p1', 6, [$rLog('d'), ['type' => 'skip', 'd' => ['count' => 2]]])['useq']);
		$rRows = $this->rows('SELECT `action`, `server_id`, `source` FROM `streams_logs` ORDER BY `id`');
		$this->assertSame(['a', 'b', 'c', 'd'], array_column($rRows, 'action'));
		$this->assertSame([5], array_values(array_unique(array_map('intval', array_column($rRows, 'server_id')))));
		$this->assertSame('http://***@src/x', $rRows[0]['source']);
		$this->assertSame(1, (int) $this->val("SELECT COUNT(*) FROM `cluster_audit` WHERE `event` = 'events.skip'"));
	}

	public function testAFlowThatIsOffRefusesItsEvents(): void {
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_STREAMS]);
		$rOut = EventIngest::ingest($this->node(), 'p1', 1, [['type' => 'log.stream', 'd' => ['rows' => [['stream_id' => 1, 'action' => 'x']]]], ['type' => 'nope', 'd' => []]]);
		$this->assertSame([2, 0, 2], [$rOut['useq'], $rOut['applied'], $rOut['dropped']]);
		$this->assertSame(0, (int) $this->val('SELECT COUNT(*) FROM `streams_logs`'));
	}

	// ── security.block_ip ────────────────────────────────────────────────

	private function spoolBlock(string $rIP, string $rReason): bool {
		return (bool) (new \ReflectionMethod(BruteforceGuard::class, 'spoolBlock'))->invoke(null, $rIP, $rReason);
	}

	public function testABlockGoesToMainOnceTheConfigFlowIsOn(): void {
		$this->flows(NodeFlows::STREAMS);
		$this->assertFalse($this->spoolBlock('203.0.113.9', 'FLOOD ATTACK'), 'CONFIG off: the node writes it itself');
		$this->flows(NodeFlows::CONFIG, EventSpool::STALE_AFTER + 5);
		$this->assertFalse($this->spoolBlock('203.0.113.9', 'FLOOD ATTACK'), 'agent stopped');
		$this->flows(NodeFlows::CONFIG);
		$this->assertTrue($this->spoolBlock('203.0.113.9', 'BRUTEFORCE MAC ATTACK'));
		$this->assertSame([['security.block_ip', ['ip' => '203.0.113.9', 'reason' => 'BRUTEFORCE MAC ATTACK']]], array_map(static fn($e) => [$e['type'], $e['d']], $this->spooled('p0')));
	}

	public function testMainRecordsABlockButNeverOneOfTheClustersOwn(): void {
		$this->rDb->exec('CREATE TABLE `blocked_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(39) UNIQUE, `notes` text, `date` int)');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_ip` varchar(64), `private_ip` varchar(64), `whitelist_ips` text)');
		$this->rDb->exec("INSERT INTO `servers` VALUES (1, '198.51.100.1', '10.0.0.1', '[\"192.0.2.7\"]'), (5, '198.51.100.5', NULL, NULL)");
		SettingsManager::set(['allowed_ips_admin' => '192.0.2.50, 192.0.2.51']);
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_CONFIG]);
		$rBlock = static fn(string $rIP, string $rReason = 'FLOOD ATTACK') => ['type' => 'security.block_ip', 'd' => ['ip' => $rIP, 'reason' => $rReason]];
		$rOut = EventIngest::ingest($this->node(), 'p0', 1, [
			$rBlock('203.0.113.9'),
			$rBlock('203.0.113.9', 'BRUTEFORCE USER ATTACK'), // already blocked
			$rBlock('2001:db8::9', 'BRUTEFORCE MAC ATTACK'),
			$rBlock('198.51.100.1'), $rBlock('10.0.0.1'), $rBlock('192.0.2.7'), $rBlock('192.0.2.51'), $rBlock('127.0.0.1'),
			$rBlock('203.0.113.10', 'admin said so'),
			$rBlock('not-an-ip'),
		]);
		$this->assertSame([10, 3, 7], [$rOut['useq'], $rOut['applied'], $rOut['dropped']]);
		$this->assertSame([['ip' => '203.0.113.9', 'notes' => 'FLOOD ATTACK'], ['ip' => '2001:db8::9', 'notes' => 'BRUTEFORCE MAC ATTACK']], $this->rows('SELECT `ip`, `notes` FROM `blocked_ips` ORDER BY `id`'));
		$this->assertSame(5, (int) $this->val("SELECT COUNT(*) FROM `cluster_audit` WHERE `event` = 'security.block_ip_refused'"));

		// CONFIG off: MAIN takes no block from the node.
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_STREAMS]);
		$this->assertSame(0, EventIngest::ingest($this->node(), 'p0', 11, [$rBlock('203.0.113.11')])['applied']);
	}

	// ── node.state, node.inventory ───────────────────────────────────────

	public function testNodeStateAndInventoryGoToMainOnceTelemetryIsOn(): void {
		$this->flows(NodeFlows::TELEMETRY);
		$this->assertTrue(NodeStateSink::state(['certbot_ssl' => '{"a":1}', 'server_ip' => '6.6.6.6']));
		$this->assertTrue(NodeStateSink::inventory(['ping' => 12, 'whitelist_ips' => '["6.6.6.6"]', 'status' => 1]));
		$this->assertSame([['node.state', ['fields' => ['certbot_ssl' => '{"a":1}']]]], array_map(static fn($e) => [$e['type'], $e['d']], $this->spooled('p0')), 'granting columns never leave the node');
		$this->assertSame([['node.inventory', ['fields' => ['ping' => 12]]]], array_map(static fn($e) => [$e['type'], $e['d']], $this->spooled('p1')));

		$this->flows(NodeFlows::STREAMS);
		$this->assertFalse(NodeStateSink::inventory(['ping' => 12]), 'TELEMETRY off: the cron writes the row itself');
	}

	/**
	 * A node's own status, as its update reports it (NodeStateSink::status):
	 * 5 while it updates and 1 once it is back, only over one of these, so
	 * an install state MAIN set is never the node's to leave.
	 */
	public function testANodeReportsItsUpdateInItsOwnStatusOnly(): void {
		$this->rDb->exec("CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `status` int DEFAULT 1, `certbot_ssl` text)");
		$this->rDb->exec("INSERT INTO `servers` (`id`, `status`) VALUES (5, 1), (6, 1)");
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_TELEMETRY]);
		$rStatus = fn(): array => array_map('intval', array_column($this->rows('SELECT `status` FROM `servers` ORDER BY `id`'), 'status'));
		$rSend = fn(int $rSeq, array $rFields): array => EventIngest::ingest($this->node(), 'p0', $rSeq, [['type' => 'node.state', 'd' => ['fields' => $rFields]]]);

		$this->assertSame(1, $rSend(1, ['status' => 5])['applied']);
		$this->assertSame([5, 1], $rStatus(), 'updating: its own row only');
		$this->assertSame(1, $rSend(2, ['status' => 1, 'certbot_ssl' => '{"a":1}'])['applied']);
		$this->assertSame([1, 1], $rStatus(), 'back');
		$this->assertSame(1, $rSend(3, ['status' => 3])['dropped'], 'no other status');
		$this->assertSame(1, $rSend(4, ['status' => '5'])['dropped'], 'an int only');
		$this->rDb->exec('UPDATE `servers` SET `status` = 4 WHERE `id` = 5');
		$rSend(5, ['status' => 1]);
		$this->assertSame([4, 1], $rStatus(), 'an install state MAIN set stays');

		$this->flows(NodeFlows::TELEMETRY);
		$this->assertTrue(NodeStateSink::status(5));
		$this->assertFalse(NodeStateSink::status(3));
		$this->assertSame([['node.state', ['fields' => ['status' => 5]]]], array_map(static fn($e) => [$e['type'], $e['d']], $this->spooled('p0')));

		// The update reports through it, so a node in mode 2 can be updated.
		$rUpdate = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/UpdateCommand.php');
		$this->assertStringNotContainsString('SET `status`', $rUpdate);
		$this->assertSame(3, substr_count($rUpdate, 'NodeStateSink::status('));
	}

	public function testMainWritesOnlyTheNodesOwnRowAndItsColumns(): void {
		$this->rDb->exec("CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_ip` varchar(64), `status` int DEFAULT 1, `whitelist_ips` text, `certbot_ssl` text, `governor` text, `sysctl` text, `ping` int DEFAULT 0, `xc_vm_version` varchar(50), `interfaces` text, `time_offset` int DEFAULT 0)");
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_ip`) VALUES (5, '198.51.100.5'), (6, '198.51.100.6')");
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_TELEMETRY, 'clock_offset_ms' => -2600]);
		$rOut = EventIngest::ingest($this->node(), 'p0', 1, [
			['type' => 'node.state', 'd' => ['fields' => ['certbot_ssl' => '{"a":1}', 'governor' => '["x"]']]],
			['type' => 'node.state', 'd' => ['fields' => ['server_ip' => '6.6.6.6', 'status' => 3]]],  // not the node's to set
			['type' => 'node.state', 'd' => ['fields' => ['sysctl' => ['nested']]]],
			['type' => 'node.state', 'd' => ['fields' => ['sysctl' => str_repeat('x', NodeStateSink::MAX_VALUE + 1)]]],
			['type' => 'node.inventory', 'd' => ['fields' => ['ping' => 3]]],                           // wrong lane
		]);
		$this->assertSame([1, 4], [$rOut['applied'], $rOut['dropped']]);
		// The request's row may be the cluster bus's copy, whose clock offset
		// lags the heartbeat flush's (NodeAuthCache::LAGGING): MySQL's is used.
		$rOut = EventIngest::ingest(['clock_offset_ms' => 9000] + $this->node(), 'p1', 1, [['type' => 'node.inventory', 'd' => ['fields' => ['ping' => 3, 'xc_vm_version' => '2.1', 'whitelist_ips' => '["6.6.6.6"]']]]]);
		$this->assertSame(1, $rOut['applied']);
		$rRows = array_map(static fn($r) => array_map(static fn($v) => is_string($v) && ctype_digit(ltrim($v, '-')) ? (int) $v : $v, $r), $this->rows('SELECT * FROM `servers` ORDER BY `id`'));
		$rMine = ['id' => 5, 'server_ip' => '198.51.100.5', 'status' => 1, 'whitelist_ips' => null, 'certbot_ssl' => '{"a":1}', 'governor' => '["x"]', 'sysctl' => null, 'ping' => 3, 'xc_vm_version' => '2.1', 'interfaces' => null, 'time_offset' => -3];
		$rOther = ['id' => 6, 'server_ip' => '198.51.100.6', 'status' => 1, 'whitelist_ips' => null, 'certbot_ssl' => null, 'governor' => null, 'sysctl' => null, 'ping' => 0, 'xc_vm_version' => null, 'interfaces' => null, 'time_offset' => 0];
		$this->assertSame([$rMine, $rOther], $rRows);

		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_STREAMS]);
		$this->assertSame(0, EventIngest::ingest($this->node(), 'p1', 2, [['type' => 'node.inventory', 'd' => ['fields' => ['ping' => 9]]]])['applied']);
	}
}
