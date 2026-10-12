<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\EpgCronJob;
use XcVm\Domain\Epg\CreatedChannelEpg;
use XcVm\Domain\Epg\EpgService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

final class CreatedChannelEpgIntegrationTest extends TestCase {
	private TestDb $rDb;
	private int $rReserved;

	protected function setUp(): void {
		if (!defined('EPG_PATH')) {
			$rDir = sys_get_temp_dir() . '/xcvm_epg_' . getmypid() . '/';
			@mkdir($rDir, 0777, true);
			define('EPG_PATH', $rDir);
		}
		$this->rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'epg_data'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec('CREATE TABLE `epg` (`id` INT AUTO_INCREMENT PRIMARY KEY, `epg_name` VARCHAR(255), `epg_file` VARCHAR(300), `days_keep` INT)');
		$this->rDb->exec('CREATE TABLE `epg_channels` (`epg_id` INT, `channel_id` VARCHAR(255), `name` VARCHAR(255), `langs` TEXT)');
		DatabaseFactory::set($this->rDb);
		$GLOBALS['db'] = $this->rDb;

		$this->rDb->query("INSERT INTO `epg` (`epg_name`, `epg_file`) VALUES ('Created Channels', '')");
		$this->rReserved = CreatedChannelEpg::reservedSourceId();
		$this->rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `movie_properties`) VALUES (11, 2, 'va', ?), (12, 2, 'vb', ?)", json_encode(['name' => 'Movie A', 'plot' => 'plot a']), json_encode(['name' => 'Movie B']));
		$this->rDb->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`, `epg_id`, `channel_id`) VALUES (50, 3, 'CC', ?, ?, 'created_50')", json_encode(['vod:11', 'vod:12']), $this->rReserved);
		$rInfo = [['filename' => 'a.ts', 'seconds' => 100, 'start' => 0, 'finish' => 100], ['filename' => 'b.ts', 'seconds' => 150, 'start' => 100, 'finish' => 250]];
		$this->rDb->query('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `cc_info`, `stream_started`) VALUES (50, 1, ?, ?)', json_encode($rInfo), time() - 60);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		unset($GLOBALS['db']);
		@unlink(EPG_PATH . 'stream_50');
	}

	private function rows(): array {
		$this->rDb->query("SELECT `title`, `description`, `start` FROM `epg_data` WHERE `epg_id` = ? AND `channel_id` = 'created_50' ORDER BY `start`", $this->rReserved);
		return $this->rDb->get_rows();
	}

	public function testRegenerateWritesAndReplaces(): void {
		$n = CreatedChannelEpg::regenerate(50);
		$this->assertGreaterThan(0, $n);
		$rows = $this->rows();
		$this->assertCount($n, $rows);
		$this->assertSame('Movie A', $rows[0]['title']);
		$this->assertSame('plot a', $rows[0]['description']);
		$this->assertSame('Movie B', $rows[1]['title']);

		CreatedChannelEpg::regenerate(50);
		$this->assertCount($n, $this->rows());
	}

	public function testNoAnchorClearsStaleRows(): void {
		CreatedChannelEpg::regenerate(50);
		$this->rDb->query('UPDATE `streams_servers` SET `stream_started` = 0');
		$this->assertSame(0, CreatedChannelEpg::regenerate(50));
		$this->assertSame([], $this->rows());
	}

	public function testCacheBuildIncludesCreatedChannel(): void {
		CreatedChannelEpg::regenerate(50);
		(new EpgCronJob())->buildStreamCaches();
		$this->assertFileExists(EPG_PATH . 'stream_50');
		$rows = igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_50'));
		$this->assertSame('Movie A', $rows[0]['title']);
	}

	public function testReservedSourceCannotBeDeletedOrListed(): void {
		$this->assertFalse(EpgService::deleteEpgById($this->rReserved));
		$this->assertArrayNotHasKey($this->rReserved, EpgService::getAll());
		$this->rDb->query('SELECT COUNT(*) c FROM `streams` WHERE `epg_id` = ?', $this->rReserved);
		$this->assertSame(1, (int) $this->rDb->get_row()['c']);
	}
}
