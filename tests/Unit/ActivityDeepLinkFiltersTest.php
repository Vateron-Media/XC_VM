<?php

use PHPUnit\Framework\TestCase;

/** Activity links arrive with selected filters before the first table request. */
final class ActivityDeepLinkFiltersTest extends TestCase {
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

	public function testLinesPerIpActivityLinkIsFilteredBeforeTheFirstTableRequest(): void {
		$xpath = $this->render('line_activity', ['user_id' => '17', 'stream_id' => '24', 'server_id' => '3', 'range' => '2026-10-07 - 2026-10-14']);
		$this->assertSame('17', $xpath->evaluate('string(//select[@id="filter-user"]/option[@selected]/@value)'));
		$this->assertSame('24', $xpath->evaluate('string(//select[@id="filter-stream"]/option[@selected]/@value)'));
		$this->assertSame('3', $xpath->evaluate('string(//select[@id="filter-server"]/option[@selected]/@value)'));
		$this->assertSame('2026-10-07 - 2026-10-14', $xpath->evaluate('string(//input[@id="filter-range"]/@value)'));
	}

	public function testActivityBookmarksAcceptLegacyFilterNames(): void {
		$xpath = $this->render('line_activity', ['user' => '17', 'stream' => '24', 'server' => '3']);
		$this->assertSame('17', $xpath->evaluate('string(//select[@id="filter-user"]/option[@selected]/@value)'));
		$this->assertSame('24', $xpath->evaluate('string(//select[@id="filter-stream"]/option[@selected]/@value)'));
	}

}
