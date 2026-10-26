<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamService;

/**
 * 2.7.1 panel error-log batch: probe/import/monitor paths that dereferenced a
 * missing key or a null, and a Ministra handshake handed a null MAC. parseM3U
 * is exercised directly; the controller/view/portal sites are pinned by source
 * (request/CLI/view paths without a unit harness). Each is red against pre-fix.
 */
final class AdminToolsErrorLogGuardsTest extends TestCase {

	private static function src(string $rRel): string {
		return (string) file_get_contents(MAIN_HOME . $rRel);
	}

	/** A trailing #EXTINF with no URL yields an entry with an empty path; parseM3U drops it. */
	public function testParseM3uDropsEntriesWithNoUrl(): void {
		$rM3u = "#EXTM3U\n#EXTINF:-1,Has URL\nhttp://example.com/one.ts\n#EXTINF:-1,No URL\n";
		$rEntries = StreamService::parseM3U($rM3u, false);
		$this->assertCount(1, $rEntries, 'the path-less entry is dropped');
		$rPaths = array_map(static fn ($rEntry) => $rEntry->getPath(), $rEntries);
		$this->assertSame(['http://example.com/one.ts'], $rPaths, 'every surviving entry has a readable path');
	}

	public function testStreamToolsGuardsCodecType(): void {
		$rSrc = self::src('Public/Controllers/Admin/Ajax/StreamToolsAjaxController.php');
		$this->assertStringContainsString("(\$rCodec['codec_type'] ?? '') == 'video'", $rSrc);
		$this->assertStringContainsString("(\$rCodec['codec_type'] ?? '') == 'audio'", $rSrc);
		$this->assertStringContainsString("\$rCodec['avg_frame_rate'] ?? '0/0'", $rSrc);
		// No bare codec_type comparison left.
		$this->assertSame(0, preg_match_all('/\$rCodec\[\x27codec_type\x27\] ==/', $rSrc), 'no unguarded codec_type read');
	}

	public function testReviewControllerGuardsForeachArguments(): void {
		$rSrc = self::src('Public/Controllers/Admin/ReviewController.php');
		$this->assertStringContainsString("(RequestManager::get('category_selection') ?? [])", $rSrc);
		$this->assertStringContainsString("true) ?? []) as \$rCategory", $rSrc);
	}

	public function testRtmpMonitorViewGuardsNullStats(): void {
		$rSrc = self::src('Public/Views/admin/rtmp_monitor.php');
		foreach (['pid', 'nginx_version', 'nginx_http_flv_version'] as $rKey) {
			$this->assertStringContainsString("\$rRTMPInfo['" . $rKey . "'] ?? ''", $rSrc, $rKey . ' guarded');
		}
		foreach (['uptime', 'bw_in', 'bw_out'] as $rKey) {
			$this->assertStringContainsString("\$rRTMPInfo['" . $rKey . "'] ?? 0", $rSrc, $rKey . ' guarded');
		}
	}

	public function testMinistraHandshakeTakesANullableMac(): void {
		$rSrc = self::src('Ministra/PortalHandler.php');
		$this->assertStringContainsString('public static function handleHandshake(?string $rMAC)', $rSrc);
		$this->assertStringContainsString("if (\$rMAC === null || \$rMAC === '') {", $rSrc);
	}
}
