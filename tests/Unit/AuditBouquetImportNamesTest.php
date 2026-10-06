<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamService;

/**
 * An M3U import with "add source as backup" gives a channel the panel already
 * has by name its new source, instead of a second stream. Two names are the
 * same channel when their letters, digits and spaces are, whatever the case,
 * in any script: a name in Cyrillic, Arabic or Chinese is matched by its own
 * letters like a Latin one, and a name with none is matched to nothing.
 *
 * The import answers through the admin globals and the uploaded file, so each
 * one runs in a child PHP against its own empty database.
 */
final class AuditBouquetImportNamesTest extends TestCase {
	private string $rFile;

	protected function setUp(): void {
		$this->rFile = sys_get_temp_dir() . '/xcvm-import-names-' . bin2hex(random_bytes(4)) . '.m3u';
	}

	protected function tearDown(): void {
		@unlink($this->rFile);
	}

	/**
	 * Import the channels $rImported (name => source) as backups on a panel
	 * holding the live streams $rExisting (id => [name, source]).
	 *
	 * @param array<int, array{0: string, 1: string}> $rExisting
	 * @param array<string, string>                   $rImported
	 * @return array<string, list<string>> The sources of every stream after it, by "id name".
	 */
	private function import(array $rExisting, array $rImported): array {
		$rLines = ['#EXTM3U'];
		foreach ($rImported as $rName => $rSource) {
			array_push($rLines, '#EXTINF:-1,' . $rName, $rSource);
		}
		file_put_contents($this->rFile, implode("\n", $rLines) . "\n");

		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'error_reporting(E_ERROR | E_PARSE);'
			. 'foreach (["STATUS_FAILURE", "STATUS_SUCCESS", "STATUS_INVALID_FILE", "STATUS_INVALID_INPUT", "STATUS_NO_SOURCES"] as $rValue => $rName) {'
			. ' define($rName, $rValue);'
			. '}'
			. '$db = new TestDb();'
			. 'foreach (["streams", "streams_options"] as $rTable) {'
			. ' $db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));'
			. '}'
			. 'foreach (' . var_export($rExisting, true) . ' as $rID => [$rName, $rSource]) {'
			. ' $db->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (?, 1, ?, ?);", $rID, $rName, json_encode([$rSource]));'
			. '}'
			. '\XcVm\Infrastructure\Database\DatabaseFactory::set($db);'
			. '$rUserInfo = ["id" => 1, "member_group_id" => 1];'
			. '$rPermissions = ["is_admin" => 1, "advanced" => []];'
			. '$rSettings = ["download_images" => 0];'
			. '\XcVm\Core\Config\SettingsManager::set($rSettings);'
			. '$_FILES["m3u_file"] = ["name" => "channels.m3u", "tmp_name" => ' . var_export($this->rFile, true) . '];'
			. '$rReturn = \XcVm\Domain\Stream\StreamService::process(["add_source_as_backup" => "on", "server_tree_data" => "[]"]);'
			. '$rStreams = [];'
			. '$db->query("SELECT `id`, `stream_display_name`, `stream_source` FROM `streams` ORDER BY `id`;");'
			. 'foreach ($db->get_rows() as $rRow) {'
			. ' $rStreams[$rRow["id"] . " " . $rRow["stream_display_name"]] = json_decode($rRow["stream_source"], true);'
			. '}'
			. 'echo json_encode(["status" => $rReturn["status"], "streams" => $rStreams]);';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rAnswer = json_decode($rOut, true);
		$this->assertIsArray($rAnswer, $rOut . $rErr);
		$this->assertSame(1, $rAnswer['status'], $rOut);

		return $rAnswer['streams'];
	}

	public function testAChannelIsMatchedByItsOwnNameInAnyScript(): void {
		$rStreams = $this->import(
			[1 => ['Первый канал', 'http://panel.example/first'], 2 => ['Матч ТВ', 'http://panel.example/match'], 3 => ['Россия 1', 'http://panel.example/russia'], 4 => ['قناة الجزيرة', 'http://panel.example/jazeera']],
			['ПЕРВЫЙ КАНАЛ' => 'http://provider.example/first', 'Россия Культура' => 'http://provider.example/culture', 'Звезда 1' => 'http://provider.example/star', 'قناة العربية' => 'http://provider.example/arabiya']
		);

		$this->assertSame([
			'1 Первый канал' => ['http://panel.example/first', 'http://provider.example/first'],
			'2 Матч ТВ' => ['http://panel.example/match'],
			'3 Россия 1' => ['http://panel.example/russia'],
			'4 قناة الجزيرة' => ['http://panel.example/jazeera'],
			'5 Россия Культура' => ['http://provider.example/culture'],
			'6 Звезда 1' => ['http://provider.example/star'],
			'7 قناة العربية' => ['http://provider.example/arabiya'],
		], $rStreams);
	}

	public function testALatinNameIsMatchedWhateverItsCaseAndPunctuation(): void {
		$rStreams = $this->import(
			[1 => ['BBC One (HD)', 'http://panel.example/one'], 2 => ['BBC Two', 'http://panel.example/two']],
			['bbc one HD' => 'http://provider.example/one', 'BBC Three' => 'http://provider.example/three']
		);

		$this->assertSame([
			'1 BBC One (HD)' => ['http://panel.example/one', 'http://provider.example/one'],
			'2 BBC Two' => ['http://panel.example/two'],
			'3 BBC Three' => ['http://provider.example/three'],
		], $rStreams);
	}

	public function testANameWithNoLetterOrDigitIsMatchedToNothing(): void {
		$rStreams = $this->import(
			[1 => ['***** *****', 'http://panel.example/divider']],
			['----- -----' => 'http://provider.example/divider']
		);

		$this->assertSame([
			'1 ***** *****' => ['http://panel.example/divider'],
			'2 ----- -----' => ['http://provider.example/divider'],
		], $rStreams);
	}

	/** Every ASCII name with a letter or digit keeps the key it was matched by before other scripts were. */
	public function testALatinNameKeepsItsKey(): void {
		$rKey = new ReflectionMethod(StreamService::class, 'nameKey');
		$rAscii = implode('', array_map('chr', range(32, 126)));

		foreach (['BBC One (HD)', 'CNN -', ' CNN', 'Sky Sports F1 | UK', 'RTL Zwei +1', $rAscii, strrev($rAscii)] as $rName) {
			$this->assertSame(preg_replace('/[^A-Za-z0-9 ]/', '', strtolower($rName)), $rKey->invoke(null, $rName), $rName);
		}
	}
}
