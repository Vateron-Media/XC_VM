<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * StreamRepository::logSince(): the stream log past a cursor, for the
 * panel's start / stop / failure toasts. Nothing is passed over: a row that
 * commits after a higher id comes back through its hole, a cleared log is
 * read from its start, a burst past the page stays for the next call, and a
 * database that does not answer moves no cursor.
 */
final class StreamLogSinceTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['streams_logs', 'streams', 'servers'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		DatabaseFactory::set($this->rDb);
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_name`) VALUES (2, 'LB 2');");
		$this->rDb->exec("INSERT INTO `streams` (`id`, `stream_display_name`) VALUES (5, 'News & Weather'), (6, 'Sport');");
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	private function log(int $rStreamID, string $rAction, int $rServerID = 2, ?int $rID = null): void {
		$this->rDb->exec('INSERT INTO `streams_logs` (' . ($rID !== null ? '`id`, ' : '') . "`stream_id`, `server_id`, `action`, `date`) VALUES (" . ($rID !== null ? $rID . ', ' : '') . "{$rStreamID}, {$rServerID}, '{$rAction}', 1);");
	}

	private static function ids(array $rLog): array {
		return array_column($rLog['events'], 'id');
	}

	public function testLatestAlertsReturnOnlyTwoNewestEventsAcrossGapsAndServers(): void {
		$this->assertSame([], StreamRepository::latestLogEvents());
		$this->log(5, 'STREAM_START', 2, 1);
		$this->log(6, 'STREAM_STOP', 2, 10);
		$this->log(99, 'NEW_KIND', 7, 40);
		$rEvents = StreamRepository::latestLogEvents(2);
		$this->assertSame([40, 10], array_column($rEvents, 'id'));
		$this->assertSame('#99', $rEvents[0]['stream']);
		$this->assertSame('', $rEvents[0]['server']);
		$this->assertSame('NEW_KIND', $rEvents[0]['label']);
		$this->assertSame('Sport', $rEvents[1]['stream']);
		$this->assertSame('LB 2', $rEvents[1]['server']);
		$this->assertSame(1, $rEvents[1]['time']);
		$this->log(5, 'STREAM_FAILED', 2, 41);
		$this->assertSame([41, 40], array_column(StreamRepository::latestLogEvents(2), 'id'));
		$this->rDb->exec('DELETE FROM `streams_logs`;');
		$this->assertSame([], StreamRepository::latestLogEvents());
	}

	public function testSummaryCountsAllEventsEvenWhenOnlyFourRowsAreShown(): void {
		$this->rDb->exec('INSERT INTO `streams_logs` (`stream_id`, `server_id`, `action`, `date`) VALUES ' . implode(',', array_fill(0, 500, "(5, 2, 'STREAM_FAILED', 100)")) . ';');
		$this->rDb->exec("INSERT INTO `streams_logs` (`stream_id`, `server_id`, `action`, `date`) VALUES (6, 2, 'STREAM_START', 110), (6, 2, 'STREAM_FAILED', 1);");
		$this->assertCount(4, StreamRepository::latestLogEvents());
		$rSummary = StreamRepository::logEventSummary(60);
		$this->assertSame(['STREAM_FAILED' => 500, 'STREAM_START' => 1], array_column($rSummary, 'count', 'action'));
		$this->assertNotEmpty($rSummary[0]['label']);
		$this->assertSame([], StreamRepository::logEventSummary(111));
	}

	public function testLatestEventsSortByEventTimeWhenBatchesArriveLate(): void {
		$this->log(5, 'STREAM_START', 2, 1);
		$this->log(6, 'STREAM_STOP', 2, 2);
		$this->log(5, 'STREAM_FAILED', 2, 3);
		$this->rDb->exec('UPDATE `streams_logs` SET `date` = 100 WHERE `id` = 3;');
		$this->rDb->exec('UPDATE `streams_logs` SET `date` = 200 WHERE `id` IN (1, 2);');
		$this->assertSame([2, 1], array_column(StreamRepository::latestLogEvents(2), 'id'));
	}

	public function testSummaryExcludesFutureEvents(): void {
		$this->rDb->exec("INSERT INTO `streams_logs` (`stream_id`, `server_id`, `action`, `date`) VALUES (5, 2, 'STREAM_FAILED', 40), (5, 2, 'STREAM_FAILED', 100), (5, 2, 'STREAM_FAILED', 101);");
		$this->assertSame([2], array_column(StreamRepository::logEventSummary(40, 100), 'count'));
	}

	public function testDisabledLoggingOrAlertsReturnsNoStoredEvents(): void {
		$this->log(5, 'STREAM_FAILED');
		$rBefore = \XcVm\Core\Config\SettingsManager::getAll();
		try {
			foreach ([['save_restart_logs' => 0], ['save_restart_logs' => 1, 'dashboard_alerts' => 0]] as $rSettings) {
				\XcVm\Core\Config\SettingsManager::set($rSettings);
				$rFeed = \XcVm\Public\Controllers\Admin\Ajax\StatsAjaxController::latestAlertsPayload();
				$this->assertSame([], $rFeed['events']);
				$this->assertSame([], $rFeed['summary']);
			}
		} finally {
			\XcVm\Core\Config\SettingsManager::set($rBefore);
		}
	}

	public function testLatestAlertCountDefaultsToFourAndIsBounded(): void {
		for ($i = 1; $i <= 55; $i++) {
			$this->log(5, 'STREAM_START');
		}
		$this->assertSame([55, 54, 53, 52], array_column(StreamRepository::latestLogEvents(), 'id'));
		$this->assertCount(1, StreamRepository::latestLogEvents(1));
		$this->assertCount(10, StreamRepository::latestLogEvents(10));
		$this->assertCount(1, StreamRepository::latestLogEvents(-5));
		$this->assertCount(55, StreamRepository::latestLogEvents(9999));
		$this->assertCount(20, StreamRepository::latestLogEvents(20));
	}

	public function testEachEntryOnceOldestFirstWithLabelsAndNames(): void {
		$this->log(5, 'STREAM_START');
		$this->assertSame(['last' => 1, 'events' => [], 'holes' => []], StreamRepository::logSince(-1), 'a first look only says where the log stands');

		$this->log(5, 'STREAM_STOP');
		$this->log(6, 'STREAM_START_FAIL');
		$this->log(99, 'NEW_KIND', 7); // a stream and a server deleted since, an action with no label
		$rLog = StreamRepository::logSince(1);
		$this->assertSame(4, $rLog['last']);
		$this->assertSame([
			['id' => 2, 'stream_id' => 5, 'action' => 'STREAM_STOP', 'label' => 'Stream Stopped', 'stream' => 'News & Weather', 'server' => 'LB 2'],
			['id' => 3, 'stream_id' => 6, 'action' => 'STREAM_START_FAIL', 'label' => 'Stream Start Failed', 'stream' => 'Sport', 'server' => 'LB 2'],
			['id' => 4, 'stream_id' => 99, 'action' => 'NEW_KIND', 'label' => 'NEW_KIND', 'stream' => '#99', 'server' => ''],
		], $rLog['events'], 'raw names (the toast sets text, not HTML)');
		$this->assertSame([], $rLog['holes']);
		$this->assertSame([], StreamRepository::logSince(4)['events'], 'each entry once');
	}

	/** A row whose insert commits after a higher id's is a hole now, and shown once when it appears. */
	public function testARowCommittedLateComesBackThroughItsHole(): void {
		$this->log(5, 'STREAM_START', 2, 1);
		$this->log(6, 'STREAM_START', 2, 3); // id 2 not visible yet
		$rLog = StreamRepository::logSince(0);
		$this->assertSame([1, 3], self::ids($rLog));
		$this->assertSame([2], $rLog['holes']);

		$this->log(5, 'STREAM_STOP', 2, 2); // id 2 commits now
		$rLog = StreamRepository::logSince(3, [2]);
		$this->assertSame([2], self::ids($rLog), 'shown once, though below the cursor');
		$this->assertSame([], $rLog['holes']);
		$this->assertSame([], StreamRepository::logSince(3)['events'], 'and not again');
	}

	/** A cursor past where the log stands: it was emptied since and its ids start over. */
	public function testAClearedLogIsReadFromItsStart(): void {
		$this->log(5, 'STREAM_START');
		$this->log(6, 'STREAM_START');
		$this->assertSame([1, 2], self::ids(StreamRepository::logSince(40)));
	}

	/** More than a page at once: `last` stops at the last one returned, and the rest come next. */
	public function testABurstPastThePageStaysForTheNextCall(): void {
		$this->rDb->exec('INSERT INTO `streams_logs` (`stream_id`, `server_id`, `action`, `date`) VALUES ' . implode(',', array_fill(0, 520, "(5, 2, 'STREAM_RESTART', 1)")) . ';');
		$rFirst = StreamRepository::logSince(0);
		$this->assertCount(500, $rFirst['events']);
		$this->assertSame(500, $rFirst['last']);
		$rNext = StreamRepository::logSince($rFirst['last']);
		$this->assertSame(range(501, 520), self::ids($rNext));
		$this->assertSame(520, $rNext['last']);
	}

	/** A database that does not answer: null, so the endpoint gives no `last` and the tab keeps its cursor. */
	public function testNoAnswerMovesNoCursor(): void {
		DatabaseFactory::set(new class extends \XcVm\Core\Database\DatabaseHandler {
			public function __construct() {
			}

			public function query(string $query, mixed $buffered = false) {
				return false;
			}
		});
		$this->assertNull(StreamRepository::logSince(3));
		$this->assertNull(StreamRepository::latestLogEvents());
		$this->assertNull(StreamRepository::logEventSummary(0));
	}
}
