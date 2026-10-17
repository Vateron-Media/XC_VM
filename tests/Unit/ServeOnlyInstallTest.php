<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * Phase A (MAIN side): a load balancer installed with `server:install
 * --serve-only` is marked serve-only — its `servers.serve_only` is set, its
 * config.enc carries a `serve_only` marker (so the node's run.sh threads the
 * agent/fanout flags), and its held streams' versions are bumped so the next
 * delta re-emits their directives with a transcode Spec (B1/B2).
 *
 * The node side (skip nginx/php-fpm, launch xc_node) and the end-to-end run
 * need a real LB and are not covered here; the SSH-driven execute() flow is
 * checked by source assertion, as ApiModeInstallTest checks its threading.
 *
 * @covers \XcVm\Cli\Commands\LbInstallFlow
 */
final class ServeOnlyInstallTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
	}

	public function testConfigPackCarriesServeOnlyMarkerOnlyWhenServeOnly(): void {
		$rServers = [1 => ['server_ip' => '10.0.0.1'], 5 => []];

		$rOn = LbInstallFlow::configPackParams($rServers, 5, true);
		$this->assertSame(1, $rOn['serve_only'] ?? null, 'a serve-only install packs serve_only=1');
		$this->assertSame(1, $rOn['is_lb'] ?? null, 'it is still a load balancer');

		$rOff = LbInstallFlow::configPackParams($rServers, 5, false);
		$this->assertArrayNotHasKey('serve_only', $rOff, 'a legacy LB config is byte-identical (no marker)');
	}

	public function testServerInstallCommandWiresServeOnly(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString("\$rServeOnly = \$rType == 2 && !empty(\$rOptions['serve-only']);", $rSrc, 'the --serve-only option is parsed for an LB');
		$this->assertStringContainsString('`serve_only` = ?', $rSrc, 'the final success UPDATE sets serve_only');
		$this->assertStringContainsString('StreamVersions::bump(StreamRecords::held($rServerID', $rSrc, 'B2: a serve_only install re-stamps its held streams');
		$this->assertStringContainsString('$db, $rApiMode, $rServeOnly)', $rSrc, 'serve_only threads into provisionConfig');
	}

	public function testProvisionConfigThreadsServeOnlyToConfigPack(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringContainsString('self::configPackParams($rServers, $rServerID, $rServeOnly)', $rSrc, 'provisionConfig passes serve_only to configPackParams');
	}
}
