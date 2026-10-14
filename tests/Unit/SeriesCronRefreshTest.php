<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\SeriesCronJob;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * cron:series must actually refresh a created series channel's stream_source from
 * its current episodes — the behaviour that was dead code while the cron read
 * generatePlaylist() as ['success']/['sources']. Exercises the real loadCron()
 * (not just generatePlaylist), so a regression to the old wrapped shape fails here.
 */
final class SeriesCronRefreshTest extends TestCase {

	private TestDb $rDb;

	/** @var array<string,mixed> */
	private array $rSavedSettings = [];

	protected function setUp(): void {
		$this->rSavedSettings = SettingsManager::getAll();
		// BouquetService::scan() (the tail of loadCron) is a detached shell_exec that
		// needs PHP_BIN defined; it forks a throwaway process and touches no tables here.
		if (!defined('PHP_BIN')) {
			define('PHP_BIN', PHP_BINARY);
		}
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `settings` (`cc_time` INT DEFAULT 0);');
		$this->rDb->exec('INSERT INTO `settings` (`cc_time`) VALUES (0);');
		$this->rDb->exec('CREATE TABLE `streams` (`id` INT PRIMARY KEY AUTO_INCREMENT, `type` INT, `series_no` INT, `stream_display_name` TEXT, `stream_source` TEXT);');
		$this->rDb->exec('CREATE TABLE `streams_episodes` (`id` INT PRIMARY KEY AUTO_INCREMENT, `series_id` INT, `stream_id` INT, `season_num` INT, `episode_num` INT);');

		// loadCron() reads global $db; generatePlaylist() reads DatabaseFactory — same instance.
		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		// Force the cron past its "ran within the hour" guard (SettingsManager is a static array).
		SettingsManager::update('cc_time', 0);
		// Start from an empty listener set so dispatch() is a clean no-op.
		EventDispatcher::resetInstance();
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		EventDispatcher::resetInstance();
		SettingsManager::set($this->rSavedSettings);
		unset($GLOBALS['db']);
	}

	private function runCron(): void {
		$rJob = (new ReflectionClass(SeriesCronJob::class))->newInstanceWithoutConstructor();
		$rMethod = new ReflectionMethod(SeriesCronJob::class, 'loadCron');
		$rMethod->setAccessible(true);
		$rMethod->invoke($rJob);
	}

	private function storedSource(int $rStreamID): string {
		$this->rDb->query('SELECT `stream_source` FROM `streams` WHERE `id` = ?;', $rStreamID);
		return (string) $this->rDb->get_row()['stream_source'];
	}

	public function testCronWritesTheRegeneratedEpisodeList(): void {
		// A created series channel whose stored sources are stale (missing one episode).
		$this->rDb->query("INSERT INTO `streams` (`id`, `type`, `series_no`, `stream_display_name`, `stream_source`) VALUES (500, 3, 42, 'My Series', ?);", json_encode(['vod:10']));
		$this->rDb->query('INSERT INTO `streams_episodes` (`series_id`, `stream_id`, `season_num`, `episode_num`) VALUES (42, 10, 1, 1);');
		$this->rDb->query('INSERT INTO `streams_episodes` (`series_id`, `stream_id`, `season_num`, `episode_num`) VALUES (42, 11, 1, 2);');

		$this->runCron();

		$this->assertSame(['vod:10', 'vod:11'], json_decode($this->storedSource(500), true), 'the cron wrote the current episode list');
	}

	public function testCronClearsSourcesWhenEveryEpisodeIsGone(): void {
		// Stored sources reference episodes that no longer exist → must be cleared, not kept.
		$this->rDb->query("INSERT INTO `streams` (`id`, `type`, `series_no`, `stream_display_name`, `stream_source`) VALUES (501, 3, 77, 'Gone', ?);", json_encode(['vod:90', 'vod:91']));

		$this->runCron();

		$this->assertSame('[]', $this->storedSource(501), 'removed episodes are cleared, not retained');
	}
}
