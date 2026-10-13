<?php

use PHPUnit\Framework\TestCase;

final class AdaptiveProxySelectionTest extends TestCase {
	/** Run the actual adaptive variant branch with capacity changing after one selection. */
	private function variant(bool $available): array {
		$source = file_get_contents(MAIN_HOME . 'Public/stream/auth.php');
		$start = strpos($source, '$rLegacyProxy =');
		$end = strpos($source, '$rURL = StreamRedirector::getStreamingURL', $start);
		$branch = substr($source, $start, $end - $start);
		$code = <<<'CODE'
namespace XcVm\Domain\Stream { class ConnectionTracker { public static function getProxies($origin) { return [9 => ['enabled' => 1]]; } } }
namespace XcVm\Streaming\Balancer { class ProxySelector {
    public static int $calls = 0;
    public static bool $available = true;
    public static function availableProxy(...$args) { return (++self::$calls === 1 && self::$available) ? 9 : null; }
} }
namespace {
CODE;
		$code .= 'require ' . var_export(MAIN_HOME . 'Core/Module/Contract/StreamRoutingProviderInterface.php', true) . ';';
		$code .= 'require ' . var_export(MAIN_HOME . 'Streaming/Routing/PlaybackRouter.php', true) . ';';
		$code .= '\XcVm\Streaming\Balancer\ProxySelector::$available = ' . ($available ? 'true' : 'false') . ';';
		$code .= '$rServers = [3 => ["enable_proxy" => 1]]; $rUserInfo = ["id" => 42, "is_restreamer" => 0, "con_isp_name" => ""]; $rSettings = ["restreamer_bypass_proxy" => 0]; $rCountryCode = "AT"; $variants = []; foreach ([1] as $unused) { $rAdaptiveInfo = ["redirect_id" => 3];';
		$code .= $branch . '$variants[] = $rAdaptiveInfo; } echo json_encode(["calls" => \XcVm\Streaming\Balancer\ProxySelector::$calls, "variants" => $variants]); }';
		$process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $error);
		return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	}

	public function testAvailableProxyIsSelectedOnlyOnce(): void {
		$result = $this->variant(true);
		$this->assertSame(1, $result['calls']);
		$this->assertSame([['redirect_id' => 9, 'originator_id' => 3]], $result['variants']);
	}

	public function testUnavailableLegacyVariantIsOmitted(): void {
		$result = $this->variant(false);
		$this->assertSame(1, $result['calls']);
		$this->assertSame([], $result['variants']);
	}
}
