<?php

use PHPUnit\Framework\TestCase;

/**
 * An M3U import with "add source as backup" gives a channel the panel already
 * has its new source. An import is asked whether it is refused before
 * anything is written: one that is refused leaves every stream as it was,
 * and one that is accepted adds each backup source, in the playlist's order.
 *
 * The import answers through the admin globals and the uploaded file, so each
 * one runs in a child PHP against its own empty database.
 */
final class AuditStreamBuildImportOrderTest extends TestCase {
	private const STATUS_SUCCESS = 1;
	private const STATUS_INVALID_INPUT = 3;

	private string $rFile;

	protected function setUp(): void {
		$this->rFile = sys_get_temp_dir() . '/xcvm-import-order-' . bin2hex(random_bytes(4)) . '.m3u';
	}

	protected function tearDown(): void {
		@unlink($this->rFile);
	}

	/**
	 * Import the channels $rImported ([name, source] each) as backups, with
	 * the form's other fields $rPost, on a panel holding the live stream
	 * "BBC One". `acmedash://` sources are a module source driver's.
	 *
	 * @param list<array{0: string, 1: string}> $rImported
	 * @return array{status: int, streams: array<string, list<string>>} The answer, and the sources of every stream after it by "id name".
	 */
	private function import(array $rImported, array $rPost = []): array {
		$rLines = ['#EXTM3U'];
		foreach ($rImported as [$rName, $rSource]) {
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
			. '$db->query("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (1, 1, ?, ?);", "BBC One", json_encode(["http://panel.example/one"]));'
			. '\XcVm\Infrastructure\Database\DatabaseFactory::set($db);'
			. '\XcVm\Core\Module\SourceDriverRegistry::register(new TestSourceDriver(["acmedash"]));'
			. '$rUserInfo = ["id" => 1, "member_group_id" => 1];'
			. '$rPermissions = ["is_admin" => 1, "advanced" => []];'
			. '$rSettings = ["download_images" => 0];'
			. '\XcVm\Core\Config\SettingsManager::set($rSettings);'
			. '$_FILES["m3u_file"] = ["name" => "channels.m3u", "tmp_name" => ' . var_export($this->rFile, true) . '];'
			. '$rReturn = \XcVm\Domain\Stream\StreamService::process(' . var_export($rPost + ['add_source_as_backup' => 'on', 'server_tree_data' => '[]'], true) . ');'
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

		return $rAnswer;
	}

	public function testARefusedImportLeavesTheStreamsAsTheyWere(): void {
		// A driver's source cannot be a direct source: the import is refused for its last channel.
		$rAnswer = $this->import(
			[['BBC One', 'http://provider.example/one'], ['Driver Channel', 'acmedash://provider/channel']],
			['direct_source' => 'on']
		);

		$this->assertSame(self::STATUS_INVALID_INPUT, $rAnswer['status']);
		$this->assertSame(['1 BBC One' => ['http://panel.example/one']], $rAnswer['streams']);
	}

	public function testAnAcceptedImportAddsEveryBackupSourceInOrder(): void {
		$rAnswer = $this->import([['BBC One', 'http://provider.example/one'], ['BBC Two', 'http://provider.example/two'], ['bbc one', 'http://other.example/one']]);

		$this->assertSame(self::STATUS_SUCCESS, $rAnswer['status']);
		$this->assertSame([
			'1 BBC One' => ['http://panel.example/one', 'http://provider.example/one', 'http://other.example/one'],
			'2 BBC Two' => ['http://provider.example/two'],
		], $rAnswer['streams']);
	}
}
