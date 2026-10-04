<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\StreamVersions;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamArgumentsChangedEvent;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Core\Events\Stream\StreamsDeletedEvent;
use XcVm\Core\Events\Stream\TranscodeProfileSavedEvent;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\RecordingFinalizer;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Stream\StreamRowMerge;
use XcVm\Domain\Stream\StreamService;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

if (!defined('STATUS_SUCCESS')) {
	define('STATUS_SUCCESS', 1);
}

/**
 * The R2 `streams` section's versions (cluster plan, section 9, "Change
 * detection"): MAIN's writers of a stream's desired configuration dispatch
 * an event, and StreamVersions stamps the stream anew in cluster_stream_ver
 * for every server that holds it now or held it before, each stream of a
 * bump with a version of its own. What nodes write back never bumps.
 */
final class StreamVersionsTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'recordings', 'profiles', 'streams_arguments', 'streams_options'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('034_create_cluster_changes'));
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` mediumtext, `updated_at` int NOT NULL)');
		DatabaseFactory::set($this->rDb);
		// The writers below read DatabaseFactory's handle, never one another test injected.
		foreach ([StreamRepository::class, StreamService::class, StreamConfigRepository::class, EpgService::class, RecordingFinalizer::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		EventDispatcher::resetInstance();
		SettingsManager::set([]);
	}

	protected function tearDown(): void {
		EventDispatcher::resetInstance();
		StreamStateWriter::useSink(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
	}

	/** Streams 10 and 11 on servers 2 and 3; 12 archived on 4 and thumbnailed on 5; a recording of 11 on 6. */
	private function catalogue(): void {
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_source`, `transcode_profile_id`, `tv_archive_server_id`, `vframes_server_id`, `epg_id`) VALUES (10, 1, '[]', 7, 0, 0, 3), (11, 1, '[]', 0, 0, 0, NULL), (12, 1, '[]', 0, 4, 5, NULL)");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (10, 2, NULL, 0), (10, 3, 2, 1), (11, 2, NULL, 0)');
		$this->rDb->exec("INSERT INTO `recordings` (`id`, `stream_id`, `source_id`, `title`, `start`, `end`, `archive`, `status`) VALUES (1, 11, 6, 'news', 100, 200, 0, 0)");
	}

	/** Every holder's row at version 0, as migration 047 seeds an install's. */
	private function seed(): void {
		foreach ([
			'SELECT `server_id`, `stream_id`, 0, 0 FROM `streams_servers`',
			'SELECT `tv_archive_server_id`, `id`, 0, 0 FROM `streams` WHERE `tv_archive_server_id` > 0',
			'SELECT `vframes_server_id`, `id`, 0, 0 FROM `streams` WHERE `vframes_server_id` > 0',
			'SELECT `source_id`, `stream_id`, 0, 0 FROM `recordings` WHERE `source_id` > 0',
		] as $rSelect) {
			$this->rDb->exec('REPLACE INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) ' . $rSelect);
		}
	}

	/** @return array<string, int> "server:stream" => ver */
	private function rows(): array {
		$this->rDb->query('SELECT `server_id`, `stream_id`, `ver` FROM `cluster_stream_ver` ORDER BY `server_id`, `stream_id`');
		$rOut = [];
		foreach ($this->rDb->get_rows() as $rRow) {
			$rOut[$rRow['server_id'] . ':' . $rRow['stream_id']] = (int) $rRow['ver'];
		}
		return $rOut;
	}

	public function testABumpStampsEveryServerThatHoldsTheStreamWithAVersionOfItsOwn(): void {
		$this->catalogue();
		$this->assertSame(1, StreamVersions::head(), 'before any change: 1, never 0');

		$this->assertSame(4, StreamVersions::bump([12, 10, '11', 10, 0, -3]));

		// Ids in order, one version each; the assignment, the archive and
		// thumbnail servers and the recording's node all hold the stream.
		$this->assertSame(['2:10' => 2, '2:11' => 3, '3:10' => 2, '4:12' => 4, '5:12' => 4, '6:11' => 3], $this->rows());
		$this->assertSame(4, StreamVersions::head());
		$this->assertSame(0, StreamVersions::floor());
		$this->assertSame(0, StreamVersions::bump([]), 'nothing to stamp');
		$this->assertSame(4, StreamVersions::head());
	}

	public function testAServerThatNoLongerHoldsTheStreamIsStampedToo(): void {
		$this->catalogue();
		StreamVersions::bump([10]);
		$this->rDb->exec('DELETE FROM `streams_servers` WHERE `server_id` = 3');
		$this->rDb->exec('DELETE FROM `streams` WHERE `id` = 10');

		$this->assertSame(3, StreamVersions::bump([10]));
		// Its row moves, so the node sees the change (a removal) past its cursor.
		$this->assertSame(['2:10' => 3, '3:10' => 3], $this->rows());
	}

	public function testResetRaisesTheFloorToANewVersion(): void {
		$this->catalogue();
		StreamVersions::bump([10, 11]);
		$this->assertSame(4, StreamVersions::reset());
		$this->assertSame([4, 4], [StreamVersions::head(), StreamVersions::floor()]);
		$this->assertSame(['2:10' => 2, '2:11' => 3, '3:10' => 2, '6:11' => 3], $this->rows(), 'no row is stamped');

		StreamVersions::raiseFloor(3);
		$this->assertSame(4, StreamVersions::floor(), 'never lowered');
		StreamVersions::raiseFloor(9);
		$this->assertSame(9, StreamVersions::floor());
	}

	public function testTheCounterIsKeptWhereTheMigrationStartedIt(): void {
		$this->catalogue();
		$this->rDb->exec("INSERT INTO `cluster_meta` VALUES ('stream_ver', '1', 0)");
		$this->assertSame(2, StreamVersions::bump([10]));
		$this->assertSame(4, StreamVersions::bump([11, 12]));
	}

	/**
	 * Run migration 047 one way, statement by statement as MigrationRunner
	 * does.
	 */
	private function migrate047(string $rWay): void {
		$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/' . $rWay . '/047_add_cluster_stream_ver_holders.sql');
		foreach (array_filter(array_map('trim', explode(';', (string) preg_replace('/^--.*$/m', '', $rSql)))) as $rStatement) {
			$this->rDb->exec($rStatement);
		}
	}

	private function maxVer(): int {
		$this->rDb->query('SELECT MAX(`ver`) AS `ver` FROM `cluster_stream_ver`');
		return (int) ($this->rDb->get_row()['ver'] ?? 0);
	}

	public function testARollbackThenAnUpgradeNeverStartsTheCounterBelowARow(): void {
		$this->catalogue();
		$this->migrate047('up');
		$this->assertSame(['2:10' => 0, '2:11' => 0, '3:10' => 0, '4:12' => 0, '5:12' => 0, '6:11' => 0], $this->rows(), 'every holder at version 0');
		$this->assertSame(1, StreamVersions::head());
		for ($i = 0; $i < 20; $i++) {
			StreamVersions::bump([10, 11, 12]);
		}
		$this->rDb->exec('DELETE FROM `streams_servers` WHERE `server_id` = 3');
		StreamVersions::bump([10]);
		StreamVersions::reset();
		StreamVersions::raiseFloor(30, 3);
		$this->assertSame(63, StreamVersions::head());

		// Rolled back: nothing of 047's is left, the version rows included.
		$this->migrate047('down');
		$this->assertSame([], $this->rows());
		$this->rDb->query("SELECT `name` FROM `cluster_meta` WHERE `name` LIKE 'stream%'");
		$this->assertSame([], $this->rDb->get_rows());

		// Upgraded again: seeded afresh, and a new change is past every row.
		$this->migrate047('up');
		$this->assertSame(['2:10' => 0, '2:11' => 0, '4:12' => 0, '5:12' => 0, '6:11' => 0], $this->rows());
		$this->assertSame(1, StreamVersions::head());
		$this->assertSame(2, StreamVersions::bump([11]));

		// Rows an older rollback left (it kept those above 0): the counter starts past them.
		$this->rDb->exec("REPLACE INTO `cluster_stream_ver` VALUES (7, 11, 52, 0)");
		$this->rDb->exec("DELETE FROM `cluster_meta` WHERE `name` = 'stream_ver'");
		$this->migrate047('up');
		$this->assertSame(52, StreamVersions::head());
		$this->assertSame(53, StreamVersions::bump([10]));
		$this->assertSame(53, $this->maxVer());
	}

	public function testAMissingCounterStartsPastEveryRow(): void {
		$this->catalogue();
		$this->rDb->exec('INSERT INTO `cluster_stream_ver` VALUES (2, 10, 40, 0)');
		$this->assertSame(41, StreamVersions::bump([11]));
		$this->assertSame(['2:10' => 40, '2:11' => 41, '6:11' => 41], $this->rows());
		$this->rDb->exec("DELETE FROM `cluster_meta` WHERE `name` = 'stream_ver'");
		$this->assertSame(42, StreamVersions::reset(), 'a reset\'s floor too');
	}

	public function testABumpRunsInATransactionThatAFailedStatementRollsBack(): void {
		$this->catalogue();
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		// Nothing refused: the bump stamps its rows, inside its own transaction.
		$rInside = [];
		$rLog->rBefore = function (string $rQuery) use (&$rInside): void {
			$rInside[] = $this->rDb->isInTransaction();
		};
		$this->assertSame(2, StreamVersions::bump([10]));
		$this->assertSame(['2:10' => 2, '3:10' => 2], $this->rows());
		$this->assertNotContains(false, $rInside, 'every statement of the bump');
		$this->assertFalse($this->rDb->isInTransaction(), 'committed');

		// More holders than a statement takes: the second REPLACE refused rolls back the first, and the counter.
		$rValues = [];
		for ($i = 1000; $i < 1000 + StreamVersions::CHUNK + 100; $i++) {
			$rValues[] = "($i, 1, '[]')";
		}
		$this->rDb->exec('INSERT INTO `streams` (`id`, `type`, `stream_source`) VALUES ' . implode(', ', $rValues));
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`) SELECT `id`, 7 FROM `streams` WHERE `id` >= 1000');
		$rReplaces = 0;
		$rLog->rBefore = function (string $rQuery) use ($rLog, &$rReplaces): void {
			if (str_starts_with($rQuery, 'REPLACE INTO `cluster_stream_ver`') && ++$rReplaces === 2) {
				$rLog->rRefuse = '/^REPLACE INTO `cluster_stream_ver`/';
			}
		};
		$rBefore = $this->rows();
		$this->assertSame(0, StreamVersions::bump(range(1000, 999 + StreamVersions::CHUNK + 100)));
		$this->assertSame(2, $rReplaces, 'the first statement ran');
		$this->assertSame($rBefore, $this->rows(), 'never a partial record');
		$this->assertSame(2, StreamVersions::head(), 'nor a version taken');
		$this->assertFalse($this->rDb->isInTransaction(), 'rolled back');
		$rLog->rBefore = null;
		$rLog->rRefuse = null;
		$this->assertSame(2 + StreamVersions::CHUNK + 100, StreamVersions::bump(range(1000, 999 + StreamVersions::CHUNK + 100)), 'the versions it did not take');
	}

	public function testAFailedStatementNeverThrowsAndStampsNothingPastIt(): void {
		$this->catalogue();
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^REPLACE INTO `cluster_stream_ver`/';
		DatabaseFactory::set($rLog);
		$this->assertSame(0, StreamVersions::bump([10]));
		$this->assertSame([], $this->rows());
		$this->assertSame(1, StreamVersions::head());
		$this->assertContains('REPLACE INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) VALUES (?, ?, ?, ?), (?, ?, ?, ?);', $rLog->rQueries, 'refused, not skipped');

		$rLog->rRefuse = '/FROM `recordings`/';
		$this->assertSame(0, StreamVersions::bump([10]), 'a holder that cannot be read: nothing is stamped, never a partial list');
		$this->assertSame([], $this->rows());
		$this->assertSame(1, StreamVersions::head(), 'the counter rolled back');

		// A reset whose floor cannot be written keeps the floor it had, and the counter.
		$rLog->rRefuse = null;
		$this->assertSame(2, StreamVersions::reset());
		$rLog->rRefuse = '/^INSERT INTO `cluster_meta`/';
		$this->assertSame(0, StreamVersions::reset());
		$this->assertSame([2, 2], [StreamVersions::head(), StreamVersions::floor()]);
		$rLog->rRefuse = '/cluster_meta/';
		$this->assertSame(0, StreamVersions::reset());
	}

	public function testEachEventStampsItsStreams(): void {
		$this->catalogue();
		$this->rDb->exec("INSERT INTO `streams_arguments` (`id`, `argument_key`, `argument_default_value`) VALUES (1, 'user_agent', 'VLC'), (2, 'proxy', NULL)");
		$this->rDb->exec("INSERT INTO `streams_options` (`stream_id`, `argument_id`, `value`) VALUES (11, 1, 'curl'), (12, 2, 'http://p')");
		EventDispatcher::subscribe(StreamVersions::class);

		EventDispatcher::dispatch(new StreamsChangedEvent([11]));
		$this->assertSame(['2:11' => 2, '6:11' => 2], $this->rows());
		EventDispatcher::dispatch(new StreamsDeletedEvent([12]));
		$this->assertSame(3, $this->rows()['4:12']);
		EventDispatcher::dispatch(new TranscodeProfileSavedEvent(7));
		$this->assertSame([4, 4], [$this->rows()['2:10'], $this->rows()['3:10']], 'the streams that transcode with it');
		EventDispatcher::dispatch(new StreamArgumentsChangedEvent(['user_agent']));
		$this->assertSame(5, $this->rows()['2:11'], 'the streams with an option of it');
		$this->assertSame(3, $this->rows()['4:12']);
		EventDispatcher::dispatch(StreamsChangedEvent::all());
		$this->assertSame([6, 6], [StreamVersions::head(), StreamVersions::floor()]);
	}

	public function testMainsWritersDispatchTheirChange(): void {
		$this->catalogue();
		$this->rDb->exec("INSERT INTO `profiles` (`profile_id`, `profile_name`, `profile_options`) VALUES (7, 'hd', '{}')");
		$this->rDb->exec('CREATE TABLE `watch_folders` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `transcode_profile_id` int)');
		$this->rDb->exec('CREATE TABLE `epg` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `epg_name` text)');
		$this->rDb->exec('CREATE TABLE `epg_channels` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `epg_id` int)');
		$this->rDb->exec("INSERT INTO `epg` VALUES (3, 'guide')");
		$this->seed();
		EventDispatcher::subscribe(StreamVersions::class);

		// Taken off a server: that server's row moves too (seeded, as every
		// holder's is), so its node sees the removal.
		StreamRepository::deleteStreamsByServer([10], 3);
		$this->assertSame(['2:10' => 2, '2:11' => 0, '3:10' => 2, '4:12' => 0, '5:12' => 0, '6:11' => 0], $this->rows());
		// Moved to another server: both nodes follow.
		StreamService::move(['content_type' => 0, 'source_server' => 2, 'replacement_server' => 8]);
		$this->assertSame(['2:10' => 3, '2:11' => 4, '3:10' => 3, '4:12' => 0, '5:12' => 0, '6:11' => 4, '8:10' => 3, '8:11' => 4], $this->rows(), 'every past holder too: a removal it already applied costs it nothing');
		// A profile deleted: its streams stop transcoding with it.
		$this->assertTrue(StreamConfigRepository::deleteProfile(7));
		$this->assertSame(5, $this->rows()['8:10']);
		// An EPG source deleted: its streams lose their guide.
		$this->assertTrue(EpgService::deleteEpgById(3));
		$this->assertSame(6, $this->rows()['8:10']);
		// A node finished a recording: it holds the VOD now.
		$this->rDb->exec('UPDATE `recordings` SET `created_id` = 20 WHERE `id` = 1');
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_source`) VALUES (20, 2, '[]')");
		$this->assertTrue(RecordingFinalizer::finish(1, 6));
		$this->assertSame([8, 7], [$this->rows()['6:20'], $this->rows()['6:11']]);
	}

	public function testWhatNodesWriteBackNeverBumps(): void {
		$this->catalogue();
		EventDispatcher::subscribe(StreamVersions::class);
		StreamVersions::bump([10, 11, 12]);
		$rBefore = [$this->rows(), StreamVersions::head()];

		StreamRowMerge::mergeNode(2, 10, ['pid' => 100, 'stream_status' => 0, 'bitrate' => 5000, 'current_source' => 'http://a']);
		StreamStateWriter::useSink(null);
		StreamStateWriter::update(10, 2, ['monitor_pid' => 7, 'progress_info' => '{}']);
		ContentSink::recordingState(1, 1);
		ContentSink::workerPid(12, 'tv_archive', 55);
		ContentSink::movieProperties(10, ['duration' => '00:01:00']);

		$this->assertSame($rBefore, [$this->rows(), StreamVersions::head()]);
		// And none of the runtime columns is a field a record carries.
		$this->assertSame([], array_values(array_diff(StreamStateWriter::STATE_FIELDS, ReplicaSections::STREAM_SERVER_LOCAL)));
		$this->assertSame([], array_values(array_intersect(StreamStateWriter::STATE_FIELDS, array_keys(ReplicaSections::STREAM_SERVER_FIELDS))));
		$this->assertContains('tv_archive_pid', ReplicaSections::STREAM_LOCAL);
		$this->assertContains('vframes_pid', ReplicaSections::STREAM_LOCAL);
	}

	public function testAnImportSeedsEveryHolderSoARemovalReachesItsNode(): void {
		$this->catalogue();
		$this->rDb->exec('INSERT INTO `cluster_stream_ver` VALUES (2, 10, 7, 0)');
		$this->assertTrue(StreamVersions::seedHolders());
		$rSeeded = ['2:10' => 7, '2:11' => 0, '3:10' => 0, '4:12' => 0, '5:12' => 0, '6:11' => 0];
		$this->assertSame($rSeeded, $this->rows(), 'every holder without a row, at version 0; a row it has keeps its version');
		$this->assertTrue(StreamVersions::seedHolders());
		$this->assertSame($rSeeded, $this->rows(), 'again: nothing new');

		// Taken off server 3 (deleted, then dispatched): its row moves, so its node sees the removal.
		EventDispatcher::subscribe(StreamVersions::class);
		StreamRepository::deleteStreamsByServer([10], 3);
		$this->assertSame([8, 8], [$this->rows()['2:10'], $this->rows()['3:10']]);

		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^INSERT INTO `cluster_stream_ver`/';
		$this->assertFalse(StreamVersions::seedHolders($rLog));
	}

	public function testAMassEditDispatchesItsChange(): void {
		$this->catalogue();
		$this->rDb->exec('CREATE TABLE `bouquets` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `bouquet_order` int)');
		(new \ReflectionProperty(BouquetService::class, 'db'))->setValue(null, null);
		$this->seed();
		EventDispatcher::subscribe(StreamVersions::class);

		$this->assertSame(['status' => STATUS_SUCCESS], StreamService::massEdit(['streams' => json_encode([11, 10]), 'c_custom_sid' => 1, 'custom_sid' => '1:0:1']));
		$this->assertSame(['2:10' => 2, '2:11' => 3, '3:10' => 2, '4:12' => 0, '5:12' => 0, '6:11' => 3], $this->rows());
	}

	/**
	 * Every writer of a stream's configuration dispatches its change (or
	 * stamps it itself), function by function: a named function or method
	 * that writes one of the tables dispatches one of the four events (a
	 * `use` import is not a dispatch), and top-level code (views, scripts)
	 * dispatches after each such write before its next exit. A statement
	 * that sets only columns no record carries (ReplicaSections'
	 * STREAM_LOCAL and STREAM_SERVER_LOCAL) needs none. A new path fails
	 * here until it dispatches, or is listed with why not.
	 */
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testEveryWriterOfAStreamsConfigurationDispatchesIt(): void {
		$rSrc = rtrim(MAIN_HOME, '/');
		$rExempt = [
			// What the node reports: its recordings' status, its VOD analysis, workers' pids, its stream row.
			'Domain/Stream/ContentSink.php::recordingState', 'Domain/Stream/ContentSink.php::movieProperties', 'Domain/Stream/ContentSink.php::workerPid',
			'Domain/Cluster/EventIngest.php::recordingState', 'Domain/Cluster/EventIngest.php::vodAnalysis', 'Domain/Cluster/EventIngest.php::streamWorker',
			'Domain/Stream/StreamRowMerge.php::apply',
			// MAIN's catalogue metadata from TMDb and providers: the agent's section hashes pick it up.
			'Domain/Vod/TmdbCron.php::processMovie', 'Domain/Vod/TmdbCron.php::processEpisode', 'Cli/CronJobs/ProvidersCronJob.php::loadCron',
			// Private helpers of process() and massEdit(), which dispatch for the stream.
			'Domain/Stream/RadioService.php::saveStreamOptions', 'Domain/Stream/RadioService.php::syncServerTree', 'Domain/Stream/RadioService.php::planServerTreeForStream',
			// Assignments whose server or stream is gone: no node to tell, or a stream whose delete dispatched.
			'Public/Views/admin/post.php::DELETE FROM `streams_servers` WHERE (`server_id` NOT IN (SELECT `id` FROM `servers`)) OR (`stream_id` NOT IN (SELECT `id` FROM `streams`));',
			// An import rewrites the tables whole, then seeds every holder's row and resets every node (below).
			'Cli/migration_logic.php',
		];
		$rMissing = [];
		$rIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rSrc, FilesystemIterator::SKIP_DOTS));
		foreach ($rIt as $rFile) {
			$rPath = (string) $rFile;
			if (!str_ends_with($rPath, '.php') || str_contains($rPath, '/vendor/') || str_contains($rPath, '/bin/install/')) {
				continue;
			}
			$rRel = substr($rPath, strlen($rSrc) + 1);
			if (in_array($rRel, $rExempt, true)) {
				continue;
			}
			foreach (self::undispatchedWrites((string) file_get_contents($rPath)) as $rWhere) {
				if (!in_array($rRel . '::' . $rWhere, $rExempt, true)) {
					$rMissing[] = $rRel . '::' . $rWhere;
				}
			}
		}
		$this->assertSame([], $rMissing, 'writes a stream\'s configuration without stamping its R2 version');
		$this->assertStringContainsString("StreamVersions::seedHolders(\$db);\nStreamVersions::reset(\$db);\n", (string) file_get_contents($rSrc . '/Cli/migration_logic.php'));

		// The scan itself: a write whose function only imports the event, or dispatches in another function, is found.
		$rCode = "<?php\nuse X\\StreamsChangedEvent;\nclass A {\n\tpublic static function a(\$db) {\n\t\t\$db->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = ?;');\n\t}\n\tpublic static function b(\$db) {\n\t\t\$db->query('UPDATE `streams` SET `rating` = ? WHERE `id` = ?;');\n\t\tEventDispatcher::dispatch(new StreamsChangedEvent([1]));\n\t}\n\tpublic static function c(\$db) {\n\t\t\$db->query('UPDATE `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `stream_id` SET `pid` = IF(`pid`, `pid`, 1), `streams`.`order` = 1 WHERE 1;');\n\t\t\$db->query('UPDATE `streams` SET ' . \$rSet . ' WHERE `id` = ?;');\n\t}\n}\n\$db->query('DELETE FROM `recordings` WHERE `id` = ?;');\nif (\$x) {\n\tEventDispatcher::dispatch(new StreamsChangedEvent([1]));\n}\n\$db->query('UPDATE `profiles` SET `profile_name` = ?;');\nexit();\nEventDispatcher::dispatch(StreamsChangedEvent::all());\n";
		$this->assertSame(['a', 'c', 'UPDATE `profiles` SET `profile_name` = ?;'], self::undispatchedWrites($rCode));
	}

	/**
	 * The writes of a stream's configuration in a PHP file that no dispatch
	 * covers: a named function's name when it writes and never dispatches,
	 * and a top-level write's SQL when no dispatch follows it before the next
	 * exit (or the file's end).
	 *
	 * @return list<string>
	 */
	private static function undispatchedWrites(string $rCode): array {
		$rWrite = '/(INSERT INTO|REPLACE INTO|DELETE FROM|UPDATE|TRUNCATE)\s+`(streams|streams_servers|streams_options|streams_arguments|streams_types|profiles|recordings)`/';
		$rEvents = ['StreamsChangedEvent', 'StreamsDeletedEvent', 'TranscodeProfileSavedEvent', 'StreamArgumentsChangedEvent'];
		$rTokens = array_values(array_filter(token_get_all($rCode), static fn($rT): bool => !is_array($rT) || !in_array($rT[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
		$rText = static fn(int $rI): string => isset($rTokens[$rI]) ? (is_array($rTokens[$rI]) ? $rTokens[$rI][1] : $rTokens[$rI]) : '';
		$rFunctions = [];
		$rTop = [];
		$rDepth = 0;
		$rIn = null;
		$rInDepth = 0;
		$rPending = null;
		foreach ($rTokens as $rI => $rT) {
			$rId = is_array($rT) ? $rT[0] : null;
			$rTok = $rText($rI);
			if ($rId === T_FUNCTION && $rIn === null && is_array($rTokens[$rI + 1] ?? null) && $rTokens[$rI + 1][0] === T_STRING) {
				$rPending = $rTokens[$rI + 1][1];
			}
			if ($rTok === '{' || $rId === T_CURLY_OPEN || $rId === T_DOLLAR_OPEN_CURLY_BRACES) {
				$rDepth++;
				if ($rPending !== null) {
					[$rIn, $rInDepth, $rPending] = [$rPending, $rDepth, null];
					$rFunctions[$rIn] ??= ['writes' => false, 'dispatches' => false];
				}
				continue;
			}
			if ($rTok === '}') {
				if ($rIn !== null && $rDepth === $rInDepth) {
					$rIn = null;
				}
				$rDepth--;
				continue;
			}
			if ($rTok === ';') {
				$rPending = null;
			}
			$rName = in_array($rId, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) ? substr((string) strrchr('\\' . $rTok, '\\'), 1) : '';
			$rEvent = null;
			if (in_array($rId, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match($rWrite, $rTok) && !self::setsOnlyLocalColumns($rTok)) {
				$rEvent = trim($rTok, '\'"');
			} elseif (($rName !== '' && in_array($rName, $rEvents, true) && ($rText($rI - 1) === 'new' || ($rText($rI + 1) === '::' && $rText($rI + 2) === 'all'))) || ($rName === 'StreamVersions' && $rText($rI + 1) === '::' && $rText($rI + 2) !== 'class')) {
				$rEvent = true;
			} elseif ($rId === T_EXIT) {
				$rEvent = false;
			}
			if ($rEvent === null) {
				continue;
			}
			if ($rIn !== null) {
				$rFunctions[$rIn][is_string($rEvent) ? 'writes' : 'dispatches'] |= $rEvent !== false;
				continue;
			}
			$rTop[] = $rEvent;
		}
		$rOut = array_keys(array_filter($rFunctions, static fn(array $rF): bool => $rF['writes'] && !$rF['dispatches']));
		foreach ($rTop as $rK => $rEvent) {
			if (!is_string($rEvent)) {
				continue;
			}
			// The next dispatch or exit after it: a dispatch covers it.
			$rNext = null;
			foreach (array_slice($rTop, $rK + 1) as $rLater) {
				if (!is_string($rLater)) {
					$rNext = $rLater;
					break;
				}
			}
			if ($rNext !== true) {
				$rOut[] = $rEvent;
			}
		}
		return $rOut;
	}

	/** An UPDATE of `streams` or `streams_servers` whose every assignment is a local column. */
	private static function setsOnlyLocalColumns(string $rSql): bool {
		if (!preg_match('/^\W*UPDATE\s+`(streams|streams_servers)`.*?\sSET\s(.*?)\sWHERE\s/s', $rSql, $rM) || !preg_match_all('/(?:`(\w+)`\.)?`(\w+)`\s*=/', $rM[2], $rSet, PREG_SET_ORDER)) {
			return false;
		}
		foreach ($rSet as [, $rTable, $rColumn]) {
			$rLocal = ($rTable !== '' ? $rTable : $rM[1]) === 'streams' ? ReplicaSections::STREAM_LOCAL : ReplicaSections::STREAM_SERVER_LOCAL;
			if (!in_array($rColumn, $rLocal, true)) {
				return false;
			}
		}
		return true;
	}
}
