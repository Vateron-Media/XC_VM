<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Stream-link tokens go through Encryption::mintToken / readToken, which seal
 * them when secure_stream_tokens is on. Calling the legacy encrypt()/decrypt()
 * directly for a token quietly brings back the format that can be read and
 * forged through padding errors, so only the uses that are not stream links —
 * deterministic encryption of stored data — may do that.
 */
#[Group('skip-on-panel')]
final class StreamTokenCallSitesTest extends TestCase {
	/**
	 * Stored data that must stay deterministic: HMAC keys are looked up by
	 * ciphertext, image cache names are reversed by the self-heal, and
	 * cluster:rotate-stream-secret re-encrypts both under the new secret.
	 */
	private const LEGACY_ALLOWED = array(
		'Core/Auth/AuthService.php',
		'Domain/Cluster/StreamSecretRotation.php',
		'Core/Util/Encryption.php',
		'Core/Util/ImageUtils.php',
		'Cli/Commands/ToolsCommand.php',
	);

	public function testOnlyStoredDataUsesTheLegacyCipherDirectly(): void {
		// Source-wide guard: scans the repo's src/ tree. On a flat deployment
		// (/home/xc_vm) there is no such directory and the app root is mixed with
		// runtime/test files, so this static guard only runs in the repo layout.
		$rRoot = MAIN_HOME;
		if (!is_dir($rRoot)) {
			$this->markTestSkipped('source-tree guard runs only in the repo layout');
		}
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
