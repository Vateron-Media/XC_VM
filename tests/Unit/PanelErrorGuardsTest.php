<?php

use PHPUnit\Framework\TestCase;

/**
 * Guards for unguarded array access surfaced by the panel error log
 * (2026-10-14, client 2.6.7 / 2.6.6-p6). Each site reads a key that is not
 * always present; the fix is a null-coalescing default at the trust boundary.
 * Behavioural coverage would need request/globals/ffmpeg harnesses these three
 * code paths pull in, so the regression is pinned at the source.
 */
final class PanelErrorGuardsTest extends TestCase {

	private function src(string $rRel): string {
		return (string) file_get_contents(MAIN_HOME . $rRel);
	}

	/** StreamToolsAjaxController::checkStream — a stream with no streams_options rows has no argument id 1/17. */
	public function testCheckStreamGuardsStreamOptionAccess(): void {
		$rSrc = $this->src('Public/Controllers/Admin/Ajax/StreamToolsAjaxController.php');
		$this->assertStringContainsString("\$rStreamOptions[1]['value'] ?? ''", $rSrc, 'user-agent option guarded');
		$this->assertStringContainsString("\$rStreamOptions[17]['value'] ?? ''", $rSrc, 'cookie option guarded');
		$this->assertStringContainsString("\$rStream['stream_source'] ?? ''", $rSrc, 'stream_source decode guarded');
	}

	/** ServerRepository::getPublicURL — a server row in $rServers may carry no 'domains'; array_map(null) threw. */
	public function testGetPublicUrlGuardsDomainUrls(): void {
		$rSrc = $this->src('Domain/Server/ServerRepository.php');
		$this->assertStringContainsString("['domains']['urls'] ?? []", $rSrc, 'domains.urls defaults to empty, not null');
	}

	/** ApiClient::asyncRequest — an online node with no api_url cannot be broadcast to; skip it, do not read a missing key. */
	public function testAsyncRequestGuardsApiUrl(): void {
		$rSrc = $this->src('Core/Http/ApiClient.php');
		$this->assertStringContainsString("!empty(\$rServer['api_url'])", $rSrc, 'api_url presence checked before use');
	}
}
