<?php

declare(strict_types=1);

namespace XcVm\Tests\Unit;

use InvalidArgumentException;
use PDOException;
use PDO;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use TestDb;
use XcVm\Cli\CronJobs\EpgCronJob;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Epg\EpgBindingSelection;
use XcVm\Domain\Epg\EpgMerge;
use XcVm\Domain\Epg\EpgConfiguration;
use XcVm\Domain\Epg\CreatedChannelEpg;
use XcVm\Domain\Epg\EpgSelectionGuide;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Stream\StreamService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EpgReviewRegressionsTest extends TestCase {
	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		foreach (['epg', 'epg_data', 'epg_channels', 'streams', 'streams_options', 'streams_servers', 'bouquets', 'servers', 'streams_categories'] as $table) {
			$this->db->exec(InstallSchema::table($table));
		}
		DatabaseFactory::set($this->db);
		foreach ([1 => ['news', 'other'], 2 => ['alternate']] as $id => $channels) {
			$metadata = [];
			foreach ($channels as $channel) {
				$metadata[$channel] = ['display_name' => $channel, 'langs' => ['de', 'en']];
			}
			$this->db->query('INSERT INTO `epg` (`id`,`epg_name`,`epg_file`,`days_keep`,`data`) VALUES(?,?,?,14,?);', $id, 'Input ' . $id, 'http://127.0.0.1:1/missing-' . $id, json_encode($metadata));
		}
		$config = ['sources' => [1, 2], 'aliases' => [['target' => 'news', 'source' => 2, 'channel' => 'alternate']]];
		$this->db->query('INSERT INTO `epg` (`id`,`epg_name`,`epg_file`,`days_keep`,`merge_config`) VALUES(3,?,?,0,?);', 'Merged', '', json_encode($config));
	}

	protected function tearDown(): void {
		ProcessRunner::useRunner(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
	}

	private function sources(): array {
		$this->db->query('SELECT * FROM `epg`;');
		return array_column($this->db->get_raw_rows(), null, 'id');
	}

	private function programme(int $source, string $channel, string $title, int $start, int $end): void {
		$this->db->query('INSERT INTO `epg_data` (`epg_id`,`channel_id`,`start`,`end`,`title`,`lang`) VALUES(?,?,?,?,?,?);', $source, $channel, $start, $end, $title, 'en');
	}

	private function titles(int $source): array {
		$this->db->query('SELECT `title` FROM `epg_data` WHERE `epg_id` = ? ORDER BY `start`;', $source);
		return array_column($this->db->get_raw_rows(), 'title');
	}

	private function streams(int $guide = 3): array {
		return [['id' => 7, 'epg_id' => $guide, 'channel_id' => EpgMerge::channelId($guide, 'news')]];
	}

	public function testSuccessfulInputsRemoveObsoleteListingsAndChangedMappingsRejectFallback(): void {
		$now = time();
		$this->programme(1, 'news', 'Primary', $now, $now + 3600);
		$this->programme(2, 'alternate', 'Backup', $now + 3600, $now + 7200);
		$sources = $this->sources();
		EpgMerge::rebuild($sources[3], $sources, $this->streams());
		$this->assertSame(['Primary', 'Backup'], $this->titles(3));
		$this->db->query('DELETE FROM `epg_data` WHERE `epg_id` = 2;');
		$sources = $this->sources();
		EpgMerge::rebuild($sources[3], $sources, $this->streams());
		$this->assertSame(['Primary'], $this->titles(3));
		$config = ['sources' => [2, 1], 'aliases' => [['target' => 'news', 'source' => 1, 'channel' => 'other']]];
		$this->db->query('UPDATE `epg` SET `merge_config` = ? WHERE `id` = 3;', json_encode($config));
		$sources = $this->sources();
		EpgMerge::rebuild($sources[3], $sources, $this->streams(), [1, 2]);
		$this->assertSame([], $this->titles(3), 'An outage cannot resurrect listings from an older mapping.');
	}

	public function testSelectionLanguageAndLongHistorySurviveMaterialization(): void {
		$group = EpgSelectionGuide::group([1, 2]);
		$picks = EpgBindingSelection::validate('[{"source":1,"channel":"news","lang":"en"}]', $this->sources());
		$streams = [['id' => 7, 'epg_id' => $group, 'channel_id' => EpgMerge::channelId($group, EpgSelectionGuide::target(7)), 'stream_display_name' => 'News', 'epg_bindings' => json_encode($picks)]];
		$sources = EpgSelectionGuide::resolve($this->sources(), $streams);
		$this->assertSame('en', EpgMerge::dependencies($sources, $streams)[1]['news']['epg_lang']);
		$this->assertSame(14, $sources[$group]['days_keep']);
		$this->programme(1, 'news', 'Ten days ago', time() - 10 * 86400, time() - 10 * 86400 + 3600);
		EpgMerge::rebuild($sources[$group], $sources, $streams);
		$this->assertSame(['Ten days ago'], $this->titles($group));
		// Zero retention means that input history must not be cut to one day.
		$sources = $this->sources();
		EpgMerge::rebuild($sources[3], $sources, $this->streams());
		$this->assertSame(['Ten days ago'], $this->titles(3));
	}

	public function testUnusedGroupsDoNotBlockSourceDeletionOrLegacyAssignment(): void {
		$group = EpgSelectionGuide::group([1, 2]);
		$this->db->query('UPDATE `epg` SET `data` = ? WHERE `id` = ?;', '{"internal-only":{"display_name":"Internal","langs":["en"]}}', $group);
		$this->assertNull(EpgService::findByName('internal-only'));
		$this->db->query('DELETE FROM `epg` WHERE `id` = 3;');
		$this->assertTrue(EpgService::deleteEpgById(1));
		$this->db->query('SELECT `id` FROM `epg` WHERE `id` = ?;', $group);
		$this->assertSame([], $this->db->get_raw_rows());
	}

	public function testMissingAliasIsRejectedBeforeSaving(): void {
		$this->expectException(InvalidArgumentException::class);
		EpgMerge::fromForm(['merge_sources' => [1, 2], 'merge_aliases' => [['target' => 'news', 'source' => 2, 'channel' => 'typo']]], $this->sources(), 3);
	}

	public function testFailedDownloadsStillExpireHistoryAndOneBrokenMergeDoesNotStopOthers(): void {
		define('TMP_PATH', sys_get_temp_dir() . '/epg-review-' . getmypid() . '/');
		mkdir(TMP_PATH);
		$this->db->query('INSERT INTO `epg` (`id`,`epg_name`,`epg_file`,`days_keep`) VALUES(5,?,?,1);', CreatedChannelEpg::SOURCE_NAME, '');
		$this->programme(5, 'news', 'Created-channel archive', time() - 10 * 86400, time() - 10 * 86400 + 3600);
		$this->programme(1, 'news', 'Expired', time() - 20 * 86400, time() - 20 * 86400 + 3600);
		$this->programme(1, 'news', 'Current', time(), time() + 3600);
		$this->programme(3, EpgMerge::channelId(3, 'news'), 'Old valid snapshot', time(), time() + 3600);
		$sources = $this->sources();
		$this->db->query('INSERT INTO `epg` (`id`,`epg_name`,`epg_file`,`merge_config`) VALUES(4,?,?,?);', 'Other merge', '', $sources[3]['merge_config']);
		foreach ([3, 4] as $id) {
			$this->db->query('INSERT INTO `streams` (`id`,`type`,`epg_id`,`channel_id`) VALUES(?,1,?,?);', $id, $id, EpgMerge::channelId($id, 'news'));
		}
		$this->db->exec("CREATE TRIGGER fail_one_merge BEFORE INSERT ON epg_data FOR EACH ROW BEGIN IF NEW.epg_id = 3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'one merge failed'; END IF; END;");
		ob_start();
		try {
			(new EpgCronJob())->importSources();
		} finally {
			ob_end_clean();
			rmdir(TMP_PATH);
		}
		$this->assertSame(['Current'], $this->titles(1));
		$this->assertSame(['Old valid snapshot'], $this->titles(3));
		$this->assertSame(['Current'], $this->titles(4));
		$this->assertSame(['Created-channel archive'], $this->titles(5));
	}

	public function testARejectedChannelUpdateRollsBackTheEntireStreamWrite(): void {
		foreach (ConstantsInitializer::statuses() as $name => $value) {
			defined($name) || define($name, $value);
		}
		$saved = [];
		foreach (['db', 'rUserInfo', 'rPermissions', 'rSettings', 'rServers'] as $key) {
			$saved[$key] = $GLOBALS[$key] ?? null;
		}
		$GLOBALS['db'] = $this->db;
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		$GLOBALS['rSettings'] = ['download_images' => 0];
		$GLOBALS['rServers'] = [];
		SettingsManager::set(['enable_cache' => 0, 'download_images' => 0]);
		ProcessRunner::useRunner(static fn(): int => 0);
		$this->db->query('INSERT INTO `streams` (`id`,`type`,`stream_display_name`,`stream_source`,`category_id`,`epg_id`,`channel_id`) VALUES(7,1,?,?,?,1,?);', 'News', '["http://example.invalid/live.ts"]', '[]', 'news');
		$this->db->exec("CREATE TRIGGER fail_selection BEFORE UPDATE ON streams FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'selection update failed';");
		try {
			try {
				StreamService::process(['edit' => 7, 'stream_source' => ['http://example.invalid/changed.ts'], 'server_tree_data' => '[]', 'category_id' => [], 'bouquets' => [], 'epg_pick_enabled' => 1, 'epg_picks' => '[{"source":1,"channel":"news"}]']);
				$this->fail('Injected update failure was ignored.');
			} catch (PDOException $error) {
				$this->assertStringContainsString('selection update failed', $error->getMessage());
			}
			$this->db->query('SELECT `epg_id`,`channel_id`,`stream_source` FROM `streams` WHERE `id` = 7;');
			$this->assertSame(['epg_id' => 1, 'channel_id' => 'news', 'stream_source' => '["http://example.invalid/live.ts"]'], $this->db->get_raw_row());
		} finally {
			foreach ($saved as $key => $value) {
				$GLOBALS[$key] = $value;
			}
		}
	}

	public function testConcurrentSelectionsReuseTheSameInternalGuide(): void {
		$children = [];
		foreach ([[1, 2], [2, 1]] as $ids) {
			$code = 'require $argv[1]; \\XcVm\\Infrastructure\\Database\\DatabaseFactory::set(new \\TestDb($argv[2])); echo \\XcVm\\Domain\\Epg\\EpgSelectionGuide::group(json_decode($argv[3],true));';
			$process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__) . '/bootstrap.php', $this->db->schema(), json_encode($ids)], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
			$this->assertIsResource($process);
			$children[] = [$process, $pipes];
		}
		$ids = [];
		foreach ($children as [$process, $pipes]) {
			$ids[] = (int) stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$this->assertSame(0, proc_close($process), $error);
		}
		$this->assertGreaterThan(3, $ids[0]);
		$this->assertSame($ids[0], $ids[1]);
	}
	public function testLookupMigrationIsRepeatableAndTheCompositeIndexIsUsed(): void {
		$this->db->exec(file_get_contents(MAIN_HOME . 'migrations/database/down/105_add_epg_merge_lookup.sql'));
		$this->db->exec(InstallSchema::migration('105_add_epg_merge_lookup'));
		$this->db->exec(InstallSchema::migration('105_add_epg_merge_lookup'));
		$now = time();
		for ($batch = 0; $batch < 20; $batch++) {
			$values = [];
			for ($row = 0; $row < 500; $row++) {
				$i = $batch * 500 + $row;
				array_push($values, 10 + intdiv($i, 1000), 'channel-' . ($i % 100), $now + $i * 60, $now + $i * 60 + 3600, 'Fixture');
			}
			$this->db->query('INSERT INTO `epg_data` (`epg_id`,`channel_id`,`start`,`end`,`title`) VALUES ' . implode(',', array_fill(0, 500, '(?,?,?,?,?)')) . ';', ...$values);
		}
		$this->db->query('ANALYZE TABLE `epg_data`;');
		$pdo = TestDb::connect($this->db->schema());
		$query = $pdo->prepare('EXPLAIN SELECT * FROM `epg_data` WHERE `epg_id` = 10 AND `channel_id` = ? AND `end` > ? ORDER BY `start`, `id` DESC;');
		$query->execute(['channel-0', $now]);
		$plan = $query->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('epg_merge_lookup', $plan['key']);
		$this->assertLessThan(50, (int) $plan['rows']);
	}

	public function testMergeSaveAndSourceDeletionCannotPassEachOthersValidation(): void {
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		$GLOBALS['db'] = $this->db;
		foreach (ConstantsInitializer::statuses() as $name => $value) {
			defined($name) || define($name, $value);
		}
		$this->db->query('DELETE FROM `epg` WHERE `id` = 3;');
		$ready = tempnam(sys_get_temp_dir(), 'epg-delete-');
		unlink($ready);
		$process = null;
		$pipes = [];
		try {
			EpgConfiguration::locked(function () use ($ready, &$process, &$pipes): void {
				$code = 'require $argv[1]; \\XcVm\\Infrastructure\\Database\\DatabaseFactory::set(new \\TestDb($argv[2])); file_put_contents($argv[3], "ready"); echo json_encode(\\XcVm\\Domain\\Epg\\EpgService::deleteEpgById(1));';
				$process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__) . '/bootstrap.php', $this->db->schema(), $ready], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
				$this->assertIsResource($process);
				$until = microtime(true) + 5;
				while (!is_file($ready) && microtime(true) < $until) {
					usleep(1000);
				}
				$this->assertFileExists($ready);
				$this->assertTrue(proc_get_status($process)['running']);
				$result = EpgService::process(['epg_name' => 'Concurrent merge', 'merge_enabled' => 1, 'merge_sources' => [1, 2]]);
				$this->assertSame(STATUS_SUCCESS, $result['status']);
			});
			$this->assertSame('false', stream_get_contents($pipes[1]), 'Delete revalidates after the merge has committed.');
			$error = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$this->assertSame(0, proc_close($process), $error);
			$process = null;
		} finally {
			if (is_resource($process)) {
				proc_terminate($process);
				proc_close($process);
			}
			@unlink($ready);
		}
		$this->assertArrayHasKey(1, $this->sources());
	}

}
