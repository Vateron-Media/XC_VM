<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\FanoutBinaryCommand;

/**
 * 2.8.0: the LB binary updater prefers the merged xc_node release asset (which
 * dispatches by the name it is installed under) and falls back to the
 * standalone xc_fanout/xc_agent assets for a release that does not ship it.
 */
final class FanoutNodeAssetTest extends TestCase {
	public function testPrefersMergedNodeAssetWhenAvailable(): void {
		$this->assertSame('xc_node-linux-amd64', FanoutBinaryCommand::assetFor('fanout', 'amd64', true));
		$this->assertSame('xc_node-linux-amd64', FanoutBinaryCommand::assetFor('agent', 'amd64', true));
		$this->assertSame('xc_node-linux-arm64', FanoutBinaryCommand::assetFor('agent', 'arm64', true));
	}

	public function testFallsBackToStandaloneAssets(): void {
		$this->assertSame('xc_fanout-linux-amd64', FanoutBinaryCommand::assetFor('fanout', 'amd64', false));
		$this->assertSame('xc_agent-linux-amd64', FanoutBinaryCommand::assetFor('agent', 'amd64', false));
		$this->assertSame('xc_fanout-linux-arm64', FanoutBinaryCommand::assetFor('fanout', 'arm64', false));
	}
}
