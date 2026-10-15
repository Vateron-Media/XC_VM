<?php

use PHPUnit\Framework\TestCase;

/**
 * Guards for the "Undefined array key N" + "Trying to access array offset on
 * null" warnings surfaced by the 2.7.x panel error log: unguarded reads of
 * $rServers[<id>][...] when the id (a server id, or SERVER_ID itself) is not in
 * the servers map, plus a VOD import reading ApiClient::listDir(...)['files']
 * that can be null. Source-assertion (these sites are in cron/stream/controller
 * paths without a unit harness); each assertion is red against the pre-fix code.
 */
final class PanelServerMapGuardsTest extends TestCase {

	private static function src(string $rRel): string {
		return (string) file_get_contents(MAIN_HOME . $rRel);
	}

	public function testGetPublicURLGuardsTheServerLookup(): void {
		$this->assertStringContainsString('if (!empty($rServers[$rServerID])) {', self::src('Domain/Server/ServerRepository.php'));
	}

	public function testMultiServerDeleteGuardsTheLookup(): void {
		$this->assertStringContainsString("!empty(\$rServers[\$rServerID]) && \$rServers[\$rServerID]['is_main'] == 0", self::src('Public/Controllers/Admin/Ajax/MultiAjaxController.php'));
	}

	public function testTimeOffsetReadsAreGuarded(): void {
		// Every stream endpoint reads $rServers[SERVER_ID]['time_offset']; when
		// SERVER_ID is absent from the map the whole class faults, so all guard it.
		$rFiles = [
			'Streaming/Lifecycle/ShutdownHandler.php',
			'Public/stream/vod.php',
			'Public/stream/timeshift.php',
			'Public/stream/segment.php',
			'Public/stream/rtmp.php',
			'Public/stream/thumb.php',
			'Public/stream/subtitle.php',
		];
		foreach ($rFiles as $rRel) {
			$rSrc = self::src($rRel);
			$this->assertStringContainsString("['time_offset'] ?? 0", $rSrc, $rRel . ' guards time_offset');
			// No bare intval($rServers[SERVER_ID]['time_offset']) left.
			$this->assertSame(0, preg_match_all('/intval\(\$rServers\[SERVER_ID\]\[\x27time_offset\x27\]\)/', $rSrc), $rRel . ' has no unguarded time_offset read');
		}
	}

	public function testIsMainReadsAreGuarded(): void {
		foreach (['Cli/Commands/WatchdogCommand.php', 'Cli/CronJobs/RootSignalsCronJob.php', 'Cli/CronJobs/UsersCronJob.php'] as $rRel) {
			$rSrc = self::src($rRel);
			$this->assertStringContainsString("['is_main'] ?? 0", $rSrc, $rRel . ' guards is_main');
			// Every $rServers[SERVER_ID]['is_main'] must be guarded: wrapped in empty()
			// or followed by ??. A bare read (neither) is the warning this fixes.
			$this->assertSame(0, preg_match_all('/(?<!empty\()\$rServers\[SERVER_ID\]\[\x27is_main\x27\](?!\s*\?\?)/', $rSrc), $rRel . ' has no unguarded is_main read');
		}
	}

	public function testRootSignalsGuardsLimitReads(): void {
		$rSrc = self::src('Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringContainsString("['limit_requests'] ?? 0", $rSrc);
		$this->assertStringContainsString("['limit_burst'] ?? 0", $rSrc);
	}

	public function testMovieImportGuardsPartsAndListDir(): void {
		$rSrc = self::src('Domain/Vod/MovieService.php');
		$this->assertStringContainsString('isset($rParts[1], $rParts[2]) && is_numeric($rParts[1])', $rSrc);
		$this->assertStringContainsString("['files'] ?? []) as \$rFile)", $rSrc);
		// No bare listDir(...)['files'] foreach left.
		$this->assertSame(0, preg_match_all('/\]\)\[\x27files\x27\] as \$rFile\)/', $rSrc), 'no unguarded listDir files foreach');
	}
}
