<?php

use PHPUnit\Framework\TestCase;

/**
 * Stream-link tokens go through Encryption::mintToken / readToken, which seal
 * them when secure_stream_tokens is on. Calling the legacy encrypt()/decrypt()
 * directly for a token quietly brings back the format that can be read and
 * forged through padding errors, so only the uses that are not stream links —
 * deterministic encryption of stored data — may do that.
 */
final class StreamTokenCallSitesTest extends TestCase {
	/** Stored data that must stay deterministic: HMAC keys are looked up by ciphertext, image cache names are reversed by the self-heal. */
	private const LEGACY_ALLOWED = array(
		'Core/Auth/AuthService.php',
		'Core/Util/Encryption.php',
		'Core/Util/ImageUtils.php',
		'Cli/Commands/ToolsCommand.php',
	);

	public function testOnlyStoredDataUsesTheLegacyCipherDirectly(): void {
		$rRoot = dirname(__DIR__, 2) . '/src/';
		$rOffenders = array();
		$rIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rRoot, FilesystemIterator::SKIP_DOTS));
		foreach ($rIterator as $rFile) {
			$rPath = $rFile->getPathname();
			if (substr($rPath, -4) !== '.php' || strpos($rPath, '/vendor/') !== false) {
				continue;
			}
			$rRelative = substr($rPath, strlen($rRoot));
			if (in_array($rRelative, self::LEGACY_ALLOWED, true)) {
				continue;
			}
			if (preg_match('/Encryption::(encrypt|decrypt)\s*\(/', (string) file_get_contents($rPath))) {
				$rOffenders[] = $rRelative;
			}
		}
		$this->assertSame(array(), $rOffenders, 'use Encryption::mintToken / readToken for stream-link tokens');
	}
}
