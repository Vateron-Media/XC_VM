<?php

declare(strict_types=1);

namespace XcVm\Tests\Unit;

use TestDb;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use XcVm\Domain\Epg\EpgMerge;
use XcVm\Domain\Epg\EPG;
use XcVm\Cli\CronJobs\EpgCronJob;
use XcVm\Tests\Support\InstallSchema;

final class EpgMergeTest extends TestCase {
	private function row(int $start, int $end, string $title): array {
		return ['start' => $start, 'end' => $end, 'title' => $title, 'lang' => 'de', 'description' => 'A & B'];
	}

	public function testPriorityFillsWholeGapsWithoutClippingOverlaps(): void {
		$rows = EpgMerge::programmes([
			[$this->row(100, 200, 'Primary'), $this->row(300, 400, 'Primary 2')],
			[$this->row(100, 200, 'Duplicate'), $this->row(190, 250, 'Partial'), $this->row(200, 300, 'Fallback'), $this->row(400, 500, 'Next'), $this->row(500, 490, 'Invalid'), $this->row(500, 600, ' ')]
		]);
		$this->assertSame(['Primary', 'Fallback', 'Primary 2', 'Next'], array_column($rows, 'title'));
		$this->assertSame('de', $rows[1]['lang']);
	}

	public function testDuplicateStartsKeepNewestCorrection(): void {
		$old = $this->row(100, 200, 'Old') + ['id' => 1];
		$new = $this->row(100, 220, 'Corrected') + ['id' => 2];
		$this->assertSame(['Corrected'], array_column(EpgMerge::programmes([[$old, $new]]), 'title'));
	}

	public function testConfigurationRejectsNestedSourcesAndOrdersInputs(): void {
		$sources = [1 => ['epg_file' => 'a', 'epg_name' => 'A'], 2 => ['epg_file' => 'b', 'epg_name' => 'B']];
		$form = ['merge_sources' => [1, 2], 'merge_order' => [1 => 9, 2 => 1]];
		$this->assertSame([2, 1], EpgMerge::fromForm($form, $sources, 3)['sources']);
		$sources[2]['merge_config'] = '{"sources":[4,5]}';
		$this->expectException(InvalidArgumentException::class);
		EpgMerge::fromForm($form, $sources, 3);
	}

	public function testDuplicateMappingIsRejected(): void {
		$sources = [1 => ['epg_file' => 'a', 'epg_name' => 'A'], 2 => ['epg_file' => 'b', 'epg_name' => 'B']];
		$alias = ['target' => 'news', 'source' => 2, 'channel' => 'different'];
		$this->expectException(InvalidArgumentException::class);
		EpgMerge::fromForm(['merge_sources' => [1, 2], 'merge_aliases' => [$alias, $alias]], $sources, 3);
	}

	public function testTruncatedXmlAndHtmlCannotReplaceAValidGuide(): void {
		$file = tempnam(sys_get_temp_dir(), 'epg-test-');
		try {
			foreach (['<tv><channel id="x"/></tv>' => true, '<tv><channel id="x"/>' => false, '<html>error</html>' => false, '' => false] as $xml => $expected) {
				file_put_contents($file, $xml);
				$this->assertSame($expected, EPG::validXml($file));
			}
		} finally {
			unlink($file);
		}
	}

	public function testMigrationIsRepeatableAndReversible(): void {
		$db = new TestDb();
		\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
		$db->exec(InstallSchema::table('epg'));
		$down = file_get_contents(MAIN_HOME . 'migrations/database/down/103_add_epg_merge.sql');
		$db->exec($down);
		$db->exec(InstallSchema::migration('103_add_epg_merge'));
		$db->exec(InstallSchema::migration('103_add_epg_merge'));
		$db->query('SHOW COLUMNS FROM `epg`');
		$this->assertContains('merge_config', array_column($db->get_raw_rows(), 'Field'));
		$db->exec($down);
		$db->query('SHOW COLUMNS FROM `epg`');
		$this->assertNotContains('merge_config', array_column($db->get_raw_rows(), 'Field'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testAdminCreatesValidatedMergeAndCannotDeleteItsInputs(): void {
		$db = new TestDb();
		\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
		foreach (['epg', 'epg_channels', 'epg_data', 'streams'] as $table) {
			$db->exec(InstallSchema::table($table));
		}
		foreach (\XcVm\Core\Config\ConstantsInitializer::statuses() as $name => $value) {
			defined($name) || define($name, $value);
		}
		\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
		$GLOBALS['db'] = $db;
		\XcVm\Domain\Epg\EpgService::setDb($db);
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		$db->query("INSERT INTO `epg` (`id`,`epg_name`,`epg_file`) VALUES (1,'Primary','a.xml'),(2,'Backup','b.xml')");
		$form = ['epg_name' => 'Combined', 'merge_enabled' => 1, 'merge_sources' => [1, 2], 'merge_order' => [1 => 1, 2 => 2]];
		$result = \XcVm\Domain\Epg\EpgService::process($form);
		$this->assertSame(STATUS_SUCCESS, $result['status']);
		$id = (int) $result['data']['insert_id'];
		$db->query('SELECT * FROM `epg` WHERE `id` = ?', $id);
		$row = $db->get_raw_row();
		$this->assertSame([1, 2], EpgMerge::config($row)['sources']);
		$this->assertSame('', $row['epg_file']);
		$this->assertFalse(\XcVm\Domain\Epg\EpgService::deleteEpgById(1));
		$form['merge_sources'] = [1, $id];
		$this->assertSame(STATUS_FAILURE, \XcVm\Domain\Epg\EpgService::process($form)['status']);
		$db->query('INSERT INTO `streams` (`id`,`type`,`epg_id`,`channel_id`) VALUES(1,1,?,?)', $id, EpgMerge::channelId($id, 'news'));
		$this->assertSame(STATUS_FAILURE, \XcVm\Domain\Epg\EpgService::process(['edit' => $id, 'merge_enabled' => 0, 'epg_file' => 'new.xml'])['status']);
		// Persisted JSON cannot be injected through a legacy POST bypassing form validation.
		$this->assertSame(STATUS_SUCCESS, \XcVm\Domain\Epg\EpgService::process(['edit' => $id, 'merge_config' => '{"sources":[99]}', 'epg_name' => 'Renamed'])['status']);
		$db->query('SELECT * FROM `epg` WHERE `id` = ?', $id);
		$this->assertSame([1, 2], EpgMerge::config($db->get_raw_row())['sources']);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testAliasDependenciesMaterializeAndSurviveSourceOutage(): void {
		define('TMP_PATH', sys_get_temp_dir() . '/xcvm-epg-' . getmypid() . '/');
		mkdir(TMP_PATH);
		$db = new TestDb();
		\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
		foreach (['epg', 'epg_channels', 'epg_data', 'streams'] as $table) {
			$db->exec(InstallSchema::table($table));
		}
		$metadata = ['news' => ['display_name' => 'News & More', 'langs' => ['de']]];
		$other = ['alt.news' => ['display_name' => 'Alternative', 'langs' => ['de']]];
		$db->query('INSERT INTO `epg` (`id`,`epg_name`,`epg_file`,`data`) VALUES (1, ?, ?, ?), (2, ?, ?, ?)', 'Primary', 'http://127.0.0.1:1/unavailable', json_encode($metadata), 'Backup', 'http://127.0.0.1:1/backup', json_encode($other));
		$config = ['sources' => [1, 2], 'aliases' => [['target' => 'news', 'source' => 2, 'channel' => 'alt.news']]];
		$db->query('INSERT INTO `epg` (`id`,`epg_name`,`epg_file`,`merge_config`) VALUES (3, ?, ?, ?)', 'Merged', '', json_encode($config));
		$key = EpgMerge::channelId(3, 'news');
		$this->assertLessThanOrEqual(50, strlen($key));
		$this->assertNotSame($key, EpgMerge::channelId(4, 'news'));
		$db->query('INSERT INTO `streams` (`id`,`type`,`epg_id`,`channel_id`) VALUES (1,1,3,?)', $key);
		$now = time();
		foreach ([[1, 'news', $now, $now + 3600, 'Primary'], [2, 'alt.news', $now, $now + 3600, 'Duplicate'], [2, 'alt.news', $now + 3600, $now + 7200, 'Backup']] as $row) {
			$db->query('INSERT INTO `epg_data` (`epg_id`,`channel_id`,`start`,`end`,`title`,`lang`,`description`) VALUES (?,?,?,?,?, ?, ?)', ...array_merge($row, ['de', 'A & B']));
		}
		$db->query('SELECT * FROM `epg`');
		$sources = array_column($db->get_raw_rows(), null, 'id');
		$streams = [['epg_id' => 3, 'channel_id' => $key]];
		$dependencies = EpgMerge::dependencies($sources, $streams);
		$this->assertArrayHasKey('alt.news', $dependencies[2]);
		EpgMerge::rebuild($sources[3], $sources, $streams);
		$db->query('SELECT * FROM `epg_data` WHERE `epg_id` = 3 ORDER BY `start`');
		$before = $db->get_raw_rows();
		$this->assertSame(['Primary', 'Backup'], array_column($before, 'title'));
		$this->assertSame('A & B', $before[0]['description']);
		$db->exec("CREATE TRIGGER fail_merge BEFORE INSERT ON epg_data FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'");
		try {
			EpgMerge::rebuild($sources[3], $sources, $streams);
			$this->fail('A failed insert must abort the merge.');
		} catch (PDOException $e) {
			$this->assertStringContainsString('injected failure', $e->getMessage());
		} finally {
			$db->exec('DROP TRIGGER fail_merge');
		}
		$db->query('SELECT * FROM `epg_data` WHERE `epg_id` = 3 ORDER BY `start`');
		$this->assertSame($before, $db->get_raw_rows(), 'Failed replacement rolled back the previous guide.');
		// Failed downloads must not clear input metadata or programmes, even with days_keep=0.
		$db->query('UPDATE `epg` SET `days_keep` = 0 WHERE `id` IN (1,2)');
		$previous = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = $db;
		try {
			ob_start();
			(new EpgCronJob())->importSources(3);
		} finally {
			ob_end_clean();
			$GLOBALS['db'] = $previous;
		}
		$db->query('SELECT `title` FROM `epg_data` WHERE `epg_id` = 3 ORDER BY `start`');
		$this->assertSame(['Primary', 'Backup'], array_column($db->get_raw_rows(), 'title'));
		$db->query('SELECT * FROM `epg` WHERE `id` = 3');
		$status = json_decode($db->get_raw_row()['merge_status'], true);
		$this->assertSame([1, 2], $status['failed_sources']);
		$this->assertGreaterThan(8, $status['channels'][$key]['percent']);
		// Complete input loss still keeps the successful merged snapshot as last fallback.
		$db->query('DELETE FROM `epg_data` WHERE `epg_id` IN (1,2)');
		EpgMerge::rebuild($sources[3], $sources, $streams, [1, 2]);
		$db->query('SELECT `title` FROM `epg_data` WHERE `epg_id` = 3 ORDER BY `start`');
		$this->assertSame(['Primary', 'Backup'], array_column($db->get_raw_rows(), 'title'));
		$db->query('UPDATE `epg` SET `data` = ? WHERE `id` IN (1,2)', '{}');
		$db->query('SELECT * FROM `epg`');
		$sources = array_column($db->get_raw_rows(), null, 'id');
		EpgMerge::rebuild($sources[3], $sources, $streams, [1, 2]);
		$db->query('SELECT `title` FROM `epg_data` WHERE `epg_id` = 3 ORDER BY `start`');
		$this->assertSame(['Primary', 'Backup'], array_column($db->get_raw_rows(), 'title'), 'A channel omitted by both input catalogs retains its merged snapshot.');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDownloadsImportUnusedInputsForMergeAndKeepDataAfterMalformedResponse(): void {
		$dir = sys_get_temp_dir() . '/xcvm-epg-http-' . getmypid() . '/';
		mkdir($dir);
		define('TMP_PATH', $dir);
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$process = proc_open([PHP_BINARY, '-S', $address, '-t', $dir], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
		$db = new TestDb();
		\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
		$previous = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = $db;
		EPG::setDb($db);
		try {
			for ($attempt = 0; $attempt < 100; $attempt++) {
				$ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
				if ($ready) {
					fclose($ready);
					break;
				}
				usleep(20000);
			}
			foreach (['epg', 'epg_channels', 'epg_data', 'streams'] as $table) {
				$db->exec(InstallSchema::table($table));
			}
			$now = time();
			foreach ([1 => 'news', 2 => 'alternate'] as $id => $channel) {
				$start = gmdate('YmdHis O', $now + ($id - 1) * 3600);
				$end = gmdate('YmdHis O', $now + $id * 3600);
				$xml = '<tv><channel id="' . $channel . '"><display-name>News</display-name></channel><programme channel="' . $channel . '" start="' . $start . '" stop="' . $end . '"><title lang="de">Programme ' . $id . '</title><desc lang="de">A &amp; B</desc></programme></tv>';
				if ($id === 1) {
					$primaryXml = $xml;
				}
				file_put_contents($dir . $id . '.xml.gz', gzencode($xml));
				$db->query('INSERT INTO `epg` (`id`,`epg_name`,`epg_file`,`days_keep`) VALUES(?,?,?,0)', $id, 'Source ' . $id, 'http://' . $address . '/' . $id . '.xml.gz?token=test');
			}
			$config = ['sources' => [1, 2], 'aliases' => [['target' => 'news', 'source' => 2, 'channel' => 'alternate']]];
			$db->query('INSERT INTO `epg` (`id`,`epg_name`,`epg_file`,`merge_config`) VALUES(3,?,?,?)', 'Merged', '', json_encode($config));
			$cron = new EpgCronJob();
			ob_start();
			$cron->importSources(3);
			ob_end_clean();
			$key = EpgMerge::channelId(3, 'news');
			$db->query('SELECT * FROM `epg_channels` WHERE `epg_id` = 3 AND `channel_id` = ?', $key);
			$this->assertSame('News', $db->get_raw_row()['name']);
			$db->query('INSERT INTO `streams` (`id`,`type`,`epg_id`,`channel_id`) VALUES(1,1,3,?)', $key);
			ob_start();
			$cron->importSources(3);
			ob_end_clean();
			$db->query('SELECT * FROM `epg_data` WHERE `epg_id` = 3 ORDER BY `start`');
			$rows = $db->get_raw_rows();
			$this->assertSame(['Programme 1', 'Programme 2'], array_column($rows, 'title'));
			$this->assertSame('A & B', $rows[0]['description']);
			file_put_contents($dir . '1.xml.gz', gzencode('<tv><channel'));
			ob_start();
			$cron->importSources(1);
			ob_end_clean();
			$db->query('SELECT * FROM `epg_data` WHERE `epg_id` = 1');
			$this->assertSame('Programme 1', $db->get_raw_row()['title']);
			$db->query('SELECT * FROM `epg_data` WHERE `epg_id` = 3 ORDER BY `start`');
			$this->assertSame(['Programme 1', 'Programme 2'], array_column($db->get_raw_rows(), 'title'));
			// The response belongs to the old URL; a concurrent admin edit must win.
			$script = '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . '; $db = new \\TestDb(' . var_export($db->schema(), true) . ');'
				. '$db->query("UPDATE epg SET epg_file = ? WHERE id = 1;", ' . var_export('http://' . $address . '/new-source.xml', true) . ');'
				. 'echo ' . var_export(gzencode(str_replace('Programme 1', 'Stale URL response', $primaryXml)), true) . ';';
			file_put_contents($dir . 'change.php', $script);
			$db->query('UPDATE `epg` SET `epg_file` = ? WHERE `id` = 1;', 'http://' . $address . '/change.php');
			ob_start();
			$cron->importSources(1);
			ob_end_clean();
			$db->query('SELECT `title` FROM `epg_data` WHERE `epg_id` = 1;');
			$this->assertSame(['Programme 1'], array_column($db->get_raw_rows(), 'title'));
			$db->query('SELECT `epg_file` FROM `epg` WHERE `id` = 1;');
			$this->assertSame('http://' . $address . '/new-source.xml', $db->get_col());
		} finally {
			$GLOBALS['db'] = $previous;
			proc_terminate($process);
			proc_close($process);
			foreach (glob($dir . '*') as $file) {
				unlink($file);
			}
			rmdir($dir);
		}
	}
}
