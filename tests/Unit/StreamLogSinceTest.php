<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * StreamRepository::logSince(): the stream log past an id, for the panel's
 * start / stop / failure toasts. A page with no id yet only learns where the
 * log stands (no backlog); later it gets each entry once, oldest first, with
 * its label and names, the newest ones only when there are more.
 */
final class StreamLogSinceTest extends TestCase {
	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	public function testEachEntryOnceOldestFirstWithLabelsAndNames(): void {
		$rDb = new TestDb();
		foreach (['streams_logs', 'streams', 'servers'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		DatabaseFactory::set($rDb);
		$rDb->exec("INSERT INTO `servers` (`id`, `server_name`) VALUES (2, 'LB 2');");
		$rDb->exec("INSERT INTO `streams` (`id`, `stream_display_name`) VALUES (5, 'News & Weather'), (6, 'Sport');");
		$rLog = static function (int $rStreamID, string $rAction, int $rServerID = 2) use ($rDb): void {
			$rDb->exec("INSERT INTO `streams_logs` (`stream_id`, `server_id`, `action`, `date`) VALUES ({$rStreamID}, {$rServerID}, '{$rAction}', 1);");
		};

		$rLog(5, 'STREAM_START');
		$rNow = StreamRepository::logSince(-1);
		$this->assertSame(['last' => 1, 'total' => 0, 'events' => []], $rNow, 'a first look only says where the log stands');

		$rLog(5, 'STREAM_STOP');
		$rLog(6, 'STREAM_START_FAIL');
		$rLog(99, 'NEW_KIND', 7); // a stream and a server deleted since, an action with no label
		$rSince = StreamRepository::logSince($rNow['last']);
		$this->assertSame(4, $rSince['last']);
		$this->assertSame(3, $rSince['total']);
		$this->assertSame([
			['id' => 2, 'stream_id' => 5, 'action' => 'STREAM_STOP', 'label' => 'Stream Stopped', 'stream' => 'News & Weather', 'server' => 'LB 2'],
			['id' => 3, 'stream_id' => 6, 'action' => 'STREAM_START_FAIL', 'label' => 'Stream Start Failed', 'stream' => 'Sport', 'server' => 'LB 2'],
			['id' => 4, 'stream_id' => 99, 'action' => 'NEW_KIND', 'label' => 'NEW_KIND', 'stream' => '#99', 'server' => ''],
		], $rSince['events'], 'raw names (the toast sets text, not HTML)');

		$this->assertSame([], StreamRepository::logSince(4)['events'], 'each entry once');
		$this->assertSame(['last' => 4, 'total' => 0, 'events' => []], StreamRepository::logSince(40), 'past the log (emptied since): start over from where it stands');

		$rNewest = StreamRepository::logSince(1, 2);
		$this->assertSame(3, $rNewest['total']);
		$this->assertSame([3, 4], array_column($rNewest['events'], 'id'), 'more than the limit: the newest, oldest first');
	}
}
