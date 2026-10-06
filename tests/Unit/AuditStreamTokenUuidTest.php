<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\Encryption;

/**
 * The connection id a viewer token carries names files under the server's own
 * directories (the connection marker, the speed file). The stream scripts
 * open their token in one place, StreamAuthMiddleware::decryptToken(), and it
 * hands on only an id of the form the panel mints: 32 hex characters. A token
 * in the legacy format is not authenticated, so its fields are not taken on
 * trust.
 */
final class AuditStreamTokenUuidTest extends TestCase {
	private const SECRET = 'test-live-pass';
	private const MINTED = '0123456789abcdef0123456789abcdef';

	/** What a child PHP answers when it opens $rToken: 'OPENED <uuid as JSON>', or the refusal's page. */
	private function opened(string $rToken, bool $rSealedOnly): string {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'if (!defined("SERVER_ID")) { define("SERVER_ID", 1); }'
			. '$rSettings = ["live_streaming_pass" => ' . var_export(self::SECRET, true) . ', "secure_stream_tokens" => ' . ($rSealedOnly ? 1 : 0) . ', "client_logs_save" => 0, "debug_show_errors" => 1];'
			. '$GLOBALS["rSettings"] = $rSettings;'
			. '$rData = \XcVm\Streaming\Auth\StreamAuthMiddleware::decryptToken($argv[1], $rSettings, [1 => ["time_offset" => 0]], "10.0.0.1");'
			. 'echo "OPENED " . json_encode($rData["uuid"] ?? null);';
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', '-r', $rCode, '--', $rToken], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);
		proc_close($rProc);
		return $rOut;
	}

	private function token(array $rData, bool $rSealed): string {
		return (string) Encryption::mintToken(json_encode($rData), self::SECRET, OPENSSL_EXTRA, $rSealed);
	}

	/** @return array<string, array{0: mixed}> */
	public static function idsThePanelDoesNotMint(): array {
		return [
			'a path' => ['../../config/x'],
			'a name with a slash' => ['aaaa/bbbb'],
			'too short' => ['0123456789abcdef'],
			'upper case' => ['0123456789ABCDEF0123456789ABCDEF'],
			'a trailing newline' => [self::MINTED . "\n"],
			'not a string' => [['a']],
			'empty' => [''],
		];
	}

	#[DataProvider('idsThePanelDoesNotMint')]
	public function testATokenWhoseConnectionIdIsNotOfTheMintedFormIsRefused(mixed $rUuid): void {
		foreach ([true, false] as $rSealed) {
			$rOut = $this->opened($this->token(['stream_id' => 5, 'uuid' => $rUuid], $rSealed), $rSealed);
			$this->assertStringNotContainsString('OPENED', $rOut, $rSealed ? 'sealed' : 'legacy');
		}
	}

	public function testATokenWithTheMintedConnectionIdOpens(): void {
		foreach ([true, false] as $rSealed) {
			$this->assertSame('OPENED "' . self::MINTED . '"', $this->opened($this->token(['stream_id' => 5, 'uuid' => self::MINTED], $rSealed), $rSealed), $rSealed ? 'sealed' : 'legacy');
		}
	}

	public function testATokenThatCarriesNoConnectionIdOpens(): void {
		$this->assertSame('OPENED null', $this->opened($this->token(['stream_id' => 5, 'expires' => time() + 60], true), true));
	}
}
