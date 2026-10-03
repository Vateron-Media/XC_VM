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

	public function testEmitIsSilentForNonTerminatingOutcome(): void {
		ErrorResponder::$throwInsteadOfExit = true;
		$outcome = ErrorResponder::respondError('BANNED', ['debug_show_errors' => false], false, null);

		ob_start();
		ErrorResponder::emit($outcome);
		$output = ob_get_clean();

		$this->assertSame('', $output);
	}
}
