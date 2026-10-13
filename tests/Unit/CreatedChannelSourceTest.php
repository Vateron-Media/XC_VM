<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Infrastructure\Database\DatabaseFactory;

final class CreatedChannelSourceTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `streams` (`id` INT PRIMARY KEY, `type` INT, `target_container` VARCHAR(16), `direct_source` INT DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`stream_id` INT, `server_id` INT, `stream_status` INT, `to_analyze` INT)');
		$this->rDb->query("INSERT INTO `streams` VALUES (7, 2, 'mp4', 0)");
		$this->rDb->query('INSERT INTO `streams_servers` VALUES (7, 1, 0, 0)');
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	private function resolve(string $e, int $s = 1): ?string {
		$m = new \ReflectionMethod(StreamProcess::class, 'resolveVodSource');
		$m->setAccessible(true);
		return $m->invoke(null, $e, $s);
	}

	public function testVodEntryResolvesToEncodedFile(): void {
		$this->assertSame(VOD_PATH . '7.mp4', $this->resolve('vod:7'));
	}

	public function testVodEntryMissingOnServerReturnsNull(): void {
		$this->assertNull($this->resolve('vod:7', 2));
	}

	public function testVodEntryBrokenReturnsNull(): void {
		$this->rDb->query('UPDATE `streams_servers` SET `stream_status` = 1 WHERE `stream_id` = 7');
		$this->assertNull($this->resolve('vod:7'));
	}

	public function testVodEntryStillAnalysingReturnsNull(): void {
		$this->rDb->query('UPDATE `streams_servers` SET `to_analyze` = 1 WHERE `stream_id` = 7');
		$this->assertNull($this->resolve('vod:7'));
	}
}
