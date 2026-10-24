<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Error\ErrorResponder;
use XcVm\Core\Error\ErrorResponseException;

/**
 * Golden + behavioural coverage for the error responder. The HTML hashes freeze
 * the exact bytes the legacy generateError()/generate404() emitted (verified
 * byte-for-byte against Core/Error/ErrorHandler.php before it became a shim), so
 * the PR2 cutover cannot silently change the rendered page.
 */
final class ErrorResponderTest extends TestCase {
	private const DEBUG_HTML_LEN  = 1651;
	private const DEBUG_HTML_SHA  = 'e6ff18792b0e26027c23b013b8fd35874c34baa8260f20ecbe5c56c5a8c47d6e';
	private const NOT_FOUND_LEN   = 546;
	private const NOT_FOUND_SHA   = 'dd21fa922cb6133c73a795e7b42164baeddd4ae53597cbd3405346f8f3a2f871';

	protected function tearDown(): void {
		ErrorResponder::$throwInsteadOfExit = false;
	}

	public function testDebugHtmlGolden(): void {
		$html = ErrorResponder::renderDebug('BANNED', ErrorResponder::codes()['BANNED']);

		$this->assertSame(self::DEBUG_HTML_LEN, strlen($html));
		$this->assertSame(self::DEBUG_HTML_SHA, hash('sha256', $html));
		$this->assertStringContainsString('<h2>BANNED</h2>', $html);
		$this->assertStringContainsString('Line has been banned.', $html);
	}

	public function test404HtmlGolden(): void {
		$html = ErrorResponder::render404();

		$this->assertSame(self::NOT_FOUND_LEN, strlen($html));
		$this->assertSame(self::NOT_FOUND_SHA, hash('sha256', $html));
		$this->assertStringContainsString('404 Not Found', $html);
	}

	public function testCodesCatalogueSize(): void {
		$codes = ErrorResponder::codes();

		$this->assertCount(66, $codes);
		$this->assertSame('Domain name not recognised.', $codes['INVALID_HOST']);
		$this->assertArrayHasKey('CACHE_INCOMPLETE', $codes);
	}

	/**
	 * Debug on: the styled page, with production's status when the request
	 * ends (404, or the given code). A 200 page was read by a player as its
	 * playlist, which then stalled instead of reconnecting.
	 */
	public function testDebugOutcomeEmitsBodyWithProductionsStatus(): void {
		$outcome = ErrorResponder::respondError('BANNED', ['debug_show_errors' => true], true, null);

		$this->assertFalse($outcome->is404);
		$this->assertSame(404, $outcome->httpCode);
		$this->assertTrue($outcome->shouldExit);
		$this->assertNotSame('', $outcome->body);
		$this->assertSame(403, ErrorResponder::respondError('BANNED', ['debug_show_errors' => true], true, 403)->httpCode);
	}

	public function testDebugNoKillDoesNotExitButStillEmitsBody(): void {
		$outcome = ErrorResponder::respondError('BANNED', ['debug_show_errors' => true], false, null);

		$this->assertFalse($outcome->shouldExit);
		$this->assertNull($outcome->httpCode, 'the request goes on: its own status stands');
		$this->assertNotSame('', $outcome->body);
	}

	/** Production + kill + no explicit code: falls through to a bare 404. */
	public function testProductionKillNoCodeFallsTo404(): void {
		$outcome = ErrorResponder::respondError('BANNED', ['debug_show_errors' => false], true, null);

		$this->assertTrue($outcome->is404);
		$this->assertSame(404, $outcome->httpCode);
		$this->assertTrue($outcome->shouldExit);
		$this->assertSame(self::NOT_FOUND_SHA, hash('sha256', $outcome->body));
	}

	/** Production + kill + explicit code: just the status, no body. */
	public function testProductionKillWithCodeSetsStatusOnly(): void {
		$outcome = ErrorResponder::respondError('BANNED', ['debug_show_errors' => false], true, 403);

		$this->assertSame(403, $outcome->httpCode);
		$this->assertSame('', $outcome->body);
		$this->assertTrue($outcome->shouldExit);
	}

	/** A falsy explicit code (0) falls through to 404, matching the legacy !$rCode check. */
	public function testProductionKillWithZeroCodeFallsTo404(): void {
		$outcome = ErrorResponder::respondError('BANNED', ['debug_show_errors' => false], true, 0);

		$this->assertTrue($outcome->is404);
		$this->assertSame(404, $outcome->httpCode);
		$this->assertTrue($outcome->shouldExit);
	}

	/** Production + no kill: emit nothing at all. */
	public function testProductionNoKillEmitsNothing(): void {
		$outcome = ErrorResponder::respondError('BANNED', ['debug_show_errors' => false], false, null);

		$this->assertSame('', $outcome->body);
		$this->assertNull($outcome->httpCode);
		$this->assertFalse($outcome->shouldExit);
	}

	public function testMissingSettingsTreatedAsProduction(): void {
		$outcome = ErrorResponder::respondError('BANNED', [], true, null);

		$this->assertTrue($outcome->is404);
	}

	/** In test mode a terminating outcome throws instead of exit()-ing. */
	public function testEmitThrowsInTestModeForTerminatingOutcome(): void {
		ErrorResponder::$throwInsteadOfExit = true;
		$outcome = ErrorResponder::respond404(true);

		$this->expectException(ErrorResponseException::class);
		ob_start();
		try {
			ErrorResponder::emit($outcome);
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * The status is set before the body: after a body that left the buffer the
	 * headers were sent, the status was lost, and PHP 8.3 printed a warning
	 * into the page.
	 */
	public function testEmitSetsTheStatusBeforeTheBodyLeaves(): void {
		$rCode = 'require ' . var_export(MAIN_HOME . 'vendor/autoload.php', true) . ';'
			. ' XcVm\Core\Error\ErrorResponder::emit(new XcVm\Core\Error\ErrorResponseException("X", 404, str_repeat("b", 10), false, false));'
			. ' echo "\nstatus ", var_export(http_response_code(), true);';
		exec(escapeshellarg(PHP_BINARY) . ' -n -d output_buffering=0 -d display_errors=1 -r ' . escapeshellarg($rCode) . ' 2>&1', $rOut);
		$rText = implode("\n", $rOut);
		$this->assertStringNotContainsString('Warning', $rText);
		$this->assertStringEndsWith('status 404', $rText);
	}

	public function testEmitIsSilentForNonTerminatingOutcome(): void {
		ErrorResponder::$throwInsteadOfExit = true;
		$outcome = ErrorResponder::respondError('BANNED', ['debug_show_errors' => false], false, null);

		ob_start();
		ErrorResponder::emit($outcome);
		$output = ob_get_clean();

		$this->assertSame('', $output);
	}

	/**
	 * Only a browser asking for a page gets an error page: a player, an API
	 * client and the panel's own Ajax key off the status code.
	 */
	public function testOnlyABrowserPageRequestWantsHtml(): void {
		$this->assertTrue(ErrorResponder::wantsHtml(['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']));
		$this->assertFalse(ErrorResponder::wantsHtml(['HTTP_ACCEPT' => 'text/html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), 'the panel\'s Ajax');
		$this->assertFalse(ErrorResponder::wantsHtml(['HTTP_ACCEPT' => 'application/json, text/javascript, */*; q=0.01']));
		$this->assertFalse(ErrorResponder::wantsHtml(['HTTP_ACCEPT' => '*/*']), 'curl, VLC, most players');
		$this->assertFalse(ErrorResponder::wantsHtml([]), 'no Accept header at all');
	}

	public function testAPanelAddressAnswersABrowserWithItsErrorPage(): void {
		$rBrowser = ['HTTP_ACCEPT' => 'text/html'];
		foreach ([403 => 'Access denied', 404 => 'Page not found', 405 => 'Method not allowed', 429 => 'Too many requests'] as $rStatus => $rTitle) {
			$rOutcome = ErrorResponder::respondPage($rStatus, 'plain', 'dashboard', $rBrowser);
			$this->assertSame($rStatus, $rOutcome->httpCode);
			$this->assertTrue($rOutcome->shouldExit);
			$this->assertStringStartsWith('<!DOCTYPE html>', $rOutcome->body);
			$this->assertStringContainsString('<title>' . $rStatus . ' ' . $rTitle . '</title>', $rOutcome->body);
			$this->assertStringContainsString('href="dashboard"', $rOutcome->body);
			// One file, nothing to fetch: it shows whatever state the panel is in.
			$this->assertStringNotContainsString('http', $rOutcome->body);
			$this->assertStringNotContainsString('<script', $rOutcome->body);
		}
		$this->assertStringNotContainsString('<a ', ErrorResponder::respondPage(429, '', '', $rBrowser)->body, 'no way back to offer');
		$this->assertStringContainsString('href="&quot;&gt;x"', ErrorResponder::renderPage(404, '">x'), 'the way back is escaped');
		$this->assertStringContainsString('404 Page not found', ErrorResponder::renderPage(500), 'a status with no page reads as 404');
	}

	public function testAnythingElseKeepsTheBodyItAlwaysHad(): void {
		foreach ([['HTTP_ACCEPT' => '*/*'], ['HTTP_ACCEPT' => 'text/html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], []] as $rServer) {
			$rOutcome = ErrorResponder::respondPage(403, 'Access denied', 'dashboard', $rServer);
			$this->assertSame([403, 'Access denied', true], [$rOutcome->httpCode, $rOutcome->body, $rOutcome->shouldExit]);
			$this->assertSame('', ErrorResponder::respondPage(429, '', '', $rServer)->body, 'a rate-limited player gets the bare status');
		}
	}

	public function testEmitSendsThePageAndStops(): void {
		ErrorResponder::$throwInsteadOfExit = true;
		ob_start();
		try {
			ErrorResponder::emit(ErrorResponder::respondPage(403, 'Access denied', 'dashboard', ['HTTP_ACCEPT' => '*/*']));
			$this->fail('emit() should have stopped');
		} catch (ErrorResponseException $rOutcome) {
			$this->assertSame(403, $rOutcome->httpCode);
		} finally {
			$rSent = (string) ob_get_clean();
		}
		$this->assertSame('Access denied', $rSent);
	}
}
