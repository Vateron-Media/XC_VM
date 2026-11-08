<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Server\ServerRepository;

final class StreamToolsServerListDb extends DatabaseHandler {
	/** @var list<array<string, mixed>> */
	private array $rows;

	public function __construct() {
		$rNow = time();
		$this->rows = [
			['id' => 1, 'server_type' => 0, 'status' => 1, 'last_check_ago' => $rNow, 'is_main' => 1, 'order' => 0, 'server_name' => 'MAIN', 'php_pids' => ''],
			['id' => 2, 'server_type' => 0, 'status' => 2, 'last_check_ago' => $rNow - 1000, 'is_main' => 0, 'order' => 1, 'server_name' => 'Offline LB', 'php_pids' => ''],
			['id' => 3, 'server_type' => 0, 'status' => 1, 'last_check_ago' => $rNow, 'is_main' => 0, 'order' => 2, 'server_name' => 'Online LB', 'php_pids' => ''],
		];
	}

	public function query($query, $buffered = false) {
		return true;
	}

	public function num_rows() {
		return count($this->rows);
	}

	public function get_rows(bool $use_id = false, string $column_as_id = '', bool $unique_row = true, string $sub_row_id = '') {
		return $this->rows;
	}
}

final class StreamToolsServerListTest extends TestCase {
	protected function setUp(): void {
		ServerRepository::setDb(new StreamToolsServerListDb());
	}

	protected function tearDown(): void {
		(new ReflectionProperty(ServerRepository::class, 'db'))->setValue(null, null);
	}

	public function testOfflineServerIsAvailableOnlyAsMoveSource(): void {
		$rServers = ServerRepository::getStreamingSimple(null);
		$rPermissions = null;
		$language = StreamToolsServerListLanguage::class;
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/stream_tools.php');
		$rView = str_replace("LayoutRenderer::renderFooter('admin');", '', $rView);

		ob_start();
		eval('?>' . $rView);
		$rHtml = (string) ob_get_clean();

		$rDom = new DOMDocument();
		@$rDom->loadHTML($rHtml);
		$rXpath = new DOMXPath($rDom);
		$this->assertSame(1, $rXpath->query('//select[@id="source_server"]/option[@value="2"]')->length, 'offline server is a move source');
		$this->assertSame(0, $rXpath->query('//select[@id="replacement_server"]/option[@value="2"]')->length, 'offline server is not a move destination');
	}
}

final class StreamToolsServerListLanguage {
	public static function get(string $key): string {
		return $key;
	}
}
