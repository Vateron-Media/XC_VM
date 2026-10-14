<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Deep links arrive with selected HTML before plugins and the first table request. */
final class AdminDeepLinkFiltersTest extends TestCase {
	private function render(string $view, array $request, string $scope = 'admin'): DOMXPath {
		$code = <<<'CODE'
namespace XcVm\Core\Auth { class Authorization { public static function check(...$args) { return true; } } }
namespace XcVm\Core\Util { class LayoutRenderer { public static function renderFooter(...$args) {} } }
namespace XcVm\Domain\Server { class ServerRepository {
    public static function getAll() { return [['id' => 3, 'server_name' => 'Server 3']]; }
    public static function getStreamingSimple(...$args) { return self::getAll(); }
} }
namespace XcVm\Domain\Stream { class CategoryService { public static function getAllByType(...$args) { return []; } } }
namespace { class FilterTestLanguage { public static function get($key) { return $key; } }
CODE;
		$code .= 'require ' . var_export(MAIN_HOME . 'Core/Http/RequestManager.php', true) . ';';
		$code .= '\XcVm\Core\Http\RequestManager::set(' . var_export($request, true) . ');';
		$code .= '$language = FilterTestLanguage::class; $rPermissions = ["reseller_client_connection_logs" => 1]; $rUserInfo = ["id" => 5]; $rSettings = ["default_entries" => 25]; require ' . var_export(MAIN_HOME . 'Public/Views/' . $scope . '/' . $view . '.php', true) . '; }';
		$process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$html = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $error);
		$this->assertSame('', $error);
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$document->loadHTML($html);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return new DOMXPath($document);
	}

	public static function statusLinks(): array {
		return ['online dashboard tile' => [1], 'offline dashboard tile' => [2]];
	}

	#[DataProvider('statusLinks')]
	public function testDashboardStatusIsSelectedInTheInitialPage(int $status): void {
		$xpath = $this->render('streams', ['filter' => (string) $status]);
		$this->assertSame((string) $status, $xpath->evaluate('string(//select[@id="filter-status"]/option[@selected]/@value)'));
	}

	public static function serverLinks(): array {
		return [
			'connections from manage servers' => ['live_connections', 'server_id'],
			'old bookmarked connection link' => ['live_connections', 'server'],
			'server detail stream tile' => ['streams', 'server_id'],
			'old bookmarked stream link' => ['streams', 'server'],
		];
	}

	#[DataProvider('serverLinks')]
	public function testServerIsSelectedTogetherWithTheStatus(string $view, string $parameter): void {
		$xpath = $this->render($view, [$parameter => '3', 'filter' => '2']);
		$this->assertSame('3', $xpath->evaluate('string(//select[@id="filter-server"]/option[@selected]/@value)'));
		if ($view === 'streams') {
			$this->assertSame('2', $xpath->evaluate('string(//select[@id="filter-status"]/option[@selected]/@value)'));
		}
	}

	public function testUnknownFiltersLeaveTheDefaultSelected(): void {
		$xpath = $this->render('streams', ['filter' => '999', 'server_id' => '999']);
		$this->assertSame(0, $xpath->query('//select[@id="filter-status" or @id="filter-server"]/option[@selected]')->length);
	}

	public static function connectionLinks(): array {
		return [
			'admin media connection counters' => ['admin', ['stream_id' => '42', 'server_id' => '3'], 'filter-stream', '42'],
			'admin line counter' => ['admin', ['user_id' => '7'], 'filter-user', '7'],
			'admin old stream bookmark' => ['admin', ['stream' => '42'], 'filter-stream', '42'],
			'reseller media connection counters' => ['reseller', ['stream_id' => '42'], 'filter-stream', '42'],
			'reseller line counter' => ['reseller', ['user_id' => '7'], 'filter-line', '7'],
			'reseller old stream bookmark' => ['reseller', ['stream' => '42'], 'filter-stream', '42'],
			'reseller old line bookmark' => ['reseller', ['line' => '7'], 'filter-line', '7'],
		];
	}

	#[DataProvider('connectionLinks')]
	public function testConnectionCountersSelectTheirTargetBeforeTableInitialisation(string $scope, array $request, string $filter, string $id): void {
		$xpath = $this->render('live_connections', $request, $scope);
		$this->assertSame($id, $xpath->evaluate('string(//select[@id="' . $filter . '"]/option[@selected]/@value)'));
		if (isset($request['server_id'])) {
			$this->assertSame('3', $xpath->evaluate('string(//select[@id="filter-server"]/option[@selected]/@value)'));
		}
	}
}
