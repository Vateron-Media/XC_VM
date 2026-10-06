<?php

use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * The XMLTV files (epg_all and one per bouquet set the lines use): the
 * programmes of a source set are read once, in pages, for every file that uses
 * them; each .gz is one gzip member inflating to its .xml; a file is written
 * beside its name and renamed, so a failed write keeps the previous one and a
 * part left by a killed run is not reused.
 *
 * EPG_PATH is a constant, so each build runs in a child PHP.
 */
final class EpgXmltvBuildTest extends TestCase {
	private const CHILD = <<<'PHP'
<?php
require %BOOTSTRAP%;
$rIn = json_decode($argv[1], true);
define('EPG_PATH', $rIn['dir'] . 'epg/');
define('SERVER_ID', 1);
$rServers = [1 => ['server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443, 'is_main' => 1, 'server_type' => 0]];
\XcVm\Core\Config\SettingsManager::set(['server_name' => 'Panel & "Co"', 'live_streaming_pass' => 'x']);
$rInner = new class (TestDb::connect($rIn['schema'])) extends \XcVm\Core\Database\DatabaseHandler {
	public function __construct(\PDO $rPdo) {
		$this->dbh = $rPdo;
	}
};
$GLOBALS['db'] = $rLog = new \XcVm\Tests\Support\QueryLogDb($rInner);
ob_start();
(new \XcVm\Cli\CronJobs\EpgCronJob())->buildXmltv($rIn['now']);
ob_end_clean();
echo json_encode(['reads' => count(preg_grep('/FROM `epg_data`/', $rLog->rQueries))]);
PHP;

	private const NOW = 1800000000;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['bouquets', 'lines', 'streams', 'epg_data'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, 'b1', '[10,11]', '[]', '[]', '[]'), (2, 'b2', '[12,13]', '[]', '[]', '[]'), (3, 'b3', '[14]', '[]', '[]', '[]')");
		$this->rDb->exec("INSERT INTO `lines` (`username`, `password`, `bouquet`) VALUES ('a', 'p', '[1]'), ('b', 'p', '[\"2\",\"1\"]'), ('c', 'p', '[1,2,3]')");
		// 12 keeps an archive, so every set with it keeps the past; 11 and 13 share a channel id.
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_icon`, `channel_id`, `epg_id`, `tv_archive_duration`) VALUES (10, 1, 'News & <One>', 'http://logo.test/a.png', 'news.fr', 1, 0), (11, 1, 'Sport', '', 'sport.fr', 1, 0), (12, 1, 'Archive', NULL, 'arch.uk', 2, 3), (13, 1, 'Sport 2', '', 'sport.fr', 1, 0), (14, 1, 'Movies', '', 'film.de', 2, 0)");
		$this->rDir = sys_get_temp_dir() . '/xcvm-xmltv-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'epg', 0777, true);
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** Programmes, source by source (id order is index order): past and future, a key in both sources, NULL texts, entities. */
	private function programmes(int $rPerChannel = 5, int $rDescription = 20): void {
		$rRows = [];
		foreach ([1, 2] as $rSource) {
			foreach (['news.fr', 'sport.fr', 'arch.uk', 'film.de'] as $i => $rChannel) {
				for ($k = 0; $k < $rPerChannel; $k++) {
					$rStart = self::NOW + ($k - 2) * 3600 + $i * 60;
					$rRows[] = [$rSource, $rChannel, $rStart, $rStart + 1800, $k === 3 ? null : "T$rSource $rChannel $k & \"x\"", $k === 4 ? null : str_repeat('d', $rDescription)];
				}
			}
		}
		foreach (array_chunk($rRows, 500) as $rChunk) {
			$this->rDb->query('INSERT INTO `epg_data` (`epg_id`, `channel_id`, `start`, `end`, `lang`, `title`, `description`) VALUES ' . implode(', ', array_fill(0, count($rChunk), "(?, ?, ?, ?, 'en', ?, ?)")), ...array_merge(...$rChunk));
		}
	}

	private function build(): int {
		$rIn = ['schema' => $this->rDb->schema(), 'dir' => $this->rDir, 'now' => self::NOW];
		exec(implode(' ', array_map('escapeshellarg', [...xcvm_test_child_php(), $this->rDir . 'child.php', (string) json_encode($rIn)])) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		return json_decode(implode('', $rOut), true)['reads'];
	}

	/** @return list<string> the files left in EPG_PATH */
	private function files(): array {
		return array_values(array_diff(scandir($this->rDir . 'epg'), ['.', '..']));
	}

	private function assertEveryGzIsItsXml(): void {
		$rFiles = glob($this->rDir . 'epg/*.xml.gz');
		$this->assertCount(4, $rFiles);
		foreach ($rFiles as $rGz) {
			$rData = (string) file_get_contents($rGz);
			$rInflate = inflate_init(ZLIB_ENCODING_GZIP);
			$this->assertSame(file_get_contents(substr($rGz, 0, -3)), inflate_add($rInflate, $rData, ZLIB_FINISH), basename($rGz));
			$this->assertSame(ZLIB_STREAM_END, inflate_get_status($rInflate));
			$this->assertSame(strlen($rData), inflate_get_read_len($rInflate), 'one member, nothing after it');
			$this->assertSame(file_get_contents(substr($rGz, 0, -3)), implode('', gzfile($rGz)));
		}
	}

	public function testEachSourceSetIsReadOnceAndEveryFileIsWhole(): void {
		$this->programmes();

		// all, [1,2] and [1,2,3] share the sources 1 and 2 with the past; [1] has source 1, future only.
		$this->assertSame(2, $this->build());

		$this->assertEveryGzIsItsXml();
		$rAll = (string) file_get_contents($this->rDir . 'epg/epg_all.xml');
		$this->assertStringStartsWith('<?xml version="1.0" encoding="utf-8" ?><!DOCTYPE tv SYSTEM "xmltv.dtd">' . "\n" . '<tv generator-info-name="Panel &amp; &quot;Co&quot;">', $rAll);
		$this->assertStringEndsWith('</tv>', $rAll);
		$this->assertSame(20, substr_count($rAll, '<programme'), 'a channel and start in both sources once');
		$this->assertStringContainsString('<title>T1 news.fr 0 &amp; &quot;x&quot;</title>', $rAll);
		$rFuture = (string) file_get_contents($this->rDir . 'epg/epg_' . md5('1') . '.xml');
		$this->assertSame(12, substr_count($rFuture, '<programme'), 'every channel of source 1, the ended programmes left out');
		$rExpected = [];
		foreach (['all', md5('1'), md5('1_2'), md5('1_2_3')] as $rName) {
			array_push($rExpected, 'epg_' . $rName . '.xml', 'epg_' . $rName . '.xml.gz');
		}
		$rFiles = $this->files();
		sort($rExpected);
		sort($rFiles);
		$this->assertSame($rExpected, $rFiles, 'no part left behind');
	}

	public function testALargeSharedPartAndAPartLeftByAKilledRun(): void {
		$this->programmes(1300, 300);
		file_put_contents($this->rDir . 'epg/programmes_0.tmp', 'left by a killed run');
		file_put_contents($this->rDir . 'epg/programmes_0.tmp.deflate', random_bytes(4096));

		$this->build();

		$this->assertGreaterThan(1048576, filesize($this->rDir . 'epg/epg_all.xml'));
		$this->assertEveryGzIsItsXml();
	}

	public function testAFileThatCannotBeWrittenKeepsThePreviousOne(): void {
		$this->programmes();
		$this->build();
		$rPrevious = file_get_contents($this->rDir . 'epg/epg_all.xml');
		touch($this->rDir . 'epg/epg_all.xml', self::NOW - 86400);
		$this->rDb->exec("UPDATE `epg_data` SET `title` = 'changed'");
		mkdir($this->rDir . 'epg/epg_all.xml.tmp'); // something in the way

		$rStart = time();
		$this->build();

		clearstatcache();
		$this->assertSame($rPrevious, file_get_contents($this->rDir . 'epg/epg_all.xml'));
		$this->assertGreaterThanOrEqual($rStart, filemtime($this->rDir . 'epg/epg_all.xml'), 'newer than the run: its sweep of old files keeps it');
		$this->assertStringContainsString('changed', (string) file_get_contents($this->rDir . 'epg/epg_' . md5('1') . '.xml'), 'the other files are rebuilt');
	}
}
