<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Epg\CreatedChannelEpg;
use XcVm\Infrastructure\Database\DatabaseFactory;

final class CreatedChannelEpgTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `epg` (`id` INT AUTO_INCREMENT PRIMARY KEY, `epg_name` VARCHAR(255), `epg_file` VARCHAR(300), `days_keep` INT)');
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	public function testReservedSourceIdFindsSentinelRow(): void {
		$this->rDb->query("INSERT INTO `epg` (`epg_name`, `epg_file`) VALUES ('Other', 'x.xml'), ('Created Channels', '')");
		$this->assertSame(2, CreatedChannelEpg::reservedSourceId());
	}

	public function testReservedSourceIdIsZeroWhenMissing(): void {
		$this->assertSame(0, CreatedChannelEpg::reservedSourceId());
	}

	public function testMigrationSeedIsIdempotent(): void {
		$sql = file_get_contents(__DIR__ . '/../../src/migrations/database/up/093_add_created_channels_epg_source.sql');
		$this->rDb->exec($sql);
		$this->rDb->exec($sql);
		$this->assertSame(1, CreatedChannelEpg::reservedSourceId());
		$this->rDb->query("SELECT COUNT(*) c FROM `epg`");
		$this->assertSame(1, (int) $this->rDb->get_row()['c']);
	}

	private const INFO = [
		['seconds' => 100, 'start' => 0, 'finish' => 100],
		['seconds' => 150, 'start' => 100, 'finish' => 250],
	];
	private const META = [
		['title' => 'Movie A', 'desc' => 'plot a'],
		['title' => 'Movie B', 'desc' => 'plot b'],
	];

	public function testProjectsOneCycleFromAnchor(): void {
		$p = CreatedChannelEpg::programmes(self::INFO, 1000, self::META, 1000, 1250);
		$this->assertSame(
			[[1000, 1100, 'Movie A', 'plot a'], [1100, 1250, 'Movie B', 'plot b']],
			array_map(fn($x) => [$x['start'], $x['end'], $x['title'], $x['description']], $p)
		);
	}

	public function testLoopsAcrossTheWindow(): void {
		$p = CreatedChannelEpg::programmes(self::INFO, 1000, self::META, 1000, 1350);
		$this->assertSame(['Movie A', 'Movie B', 'Movie A'], array_column($p, 'title'));
		$this->assertSame([1000, 1100, 1250], array_column($p, 'start'));
		$this->assertSame([1100, 1250, 1350], array_column($p, 'end'));
	}

	public function testIncludesProgrammeOverlappingWindowStart(): void {
		$p = CreatedChannelEpg::programmes(self::INFO, 1000, self::META, 1200, 1400);
		$this->assertSame([1100, 1250, 1350], array_column($p, 'start'));
	}

	public function testZeroDurationItemSkipped(): void {
		$info = [['seconds' => 0, 'start' => 0, 'finish' => 0], ['seconds' => 100, 'start' => 0, 'finish' => 100]];
		$meta = [['title' => 'Z', 'desc' => ''], ['title' => 'Real', 'desc' => '']];
		$p = CreatedChannelEpg::programmes($info, 1000, $meta, 1000, 1100);
		$this->assertSame(['Real'], array_column($p, 'title'));
	}

	public function testNoProgrammesWhenNotStarted(): void {
		$this->assertSame([], CreatedChannelEpg::programmes(self::INFO, 0, self::META, 1000, 1250));
		$this->assertSame([], CreatedChannelEpg::programmes([], 1000, [], 1000, 1250));
	}
}
