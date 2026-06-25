<?php

use PHPUnit\Framework\TestCase;

/**
 * Autoloader invariants after the PSR-4 migration (plan: Фаза 0 + Финал).
 *
 * - The Composer PSR-4 autoloader is registered and resolves every XcVm\* class.
 * - The legacy XC_Autoloader directory scanner is RETIRED: its init() is a no-op
 *   that registers nothing, so it must not appear in the SPL stack.
 * - No igbinary class-map cache (tmp/cache/autoload_map) is ever written.
 */
final class AutoloadOrderTest extends TestCase {
	public function testComposerAutoloaderIsRegistered(): void {
		$hasComposer = false;
		foreach (spl_autoload_functions() as $fn) {
			if (is_array($fn) && is_object($fn[0])
				&& $fn[0] instanceof \Composer\Autoload\ClassLoader) {
				$hasComposer = true;
				break;
			}
		}
		$this->assertTrue($hasComposer, 'Composer ClassLoader must be registered.');
	}

	public function testComposerResolvesNamespacedClassFromEveryLayer(): void {
		$samples = [
			'XcVm\\Core\\Config\\SettingsManager',
			'XcVm\\Domain\\User\\UserRepository',
			'XcVm\\Infrastructure\\Redis\\RedisManager',
			'XcVm\\Streaming\\Codec\\FfmpegPaths',
			'XcVm\\Cli\\CommandRegistry',
			'XcVm\\Public\\Controllers\\Admin\\UserController',
		];
		foreach ($samples as $fqcn) {
			$this->assertTrue(
				class_exists($fqcn) || interface_exists($fqcn),
				"Composer must autoload {$fqcn}."
			);
		}
	}

	public function testLegacyScannerIsNotRegistered(): void {
		foreach (spl_autoload_functions() as $fn) {
			if (is_array($fn)) {
				$target = is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0];
				$this->assertNotSame(
					'XC_Autoloader',
					$target,
					'The retired XC_Autoloader scanner must not be registered.'
				);
			}
		}
		$this->addToAssertionCount(1);
	}

	public function testLegacyAutoloaderIsRemoved(): void {
		$this->assertFalse(
			class_exists('XC_Autoloader'),
			'The legacy XC_Autoloader class has been removed entirely.'
		);
		$this->assertFileDoesNotExist(
			MAIN_HOME . 'autoload.php',
			'src/autoload.php must be deleted.'
		);
	}

	public function testNoClassMapCacheIsWritten(): void {
		$cache = MAIN_HOME . 'tmp/cache/autoload_map';
		if (is_file($cache)) {
			@unlink($cache);
		}
		// Trigger an autoload; resolution is live (Composer) with no cache file.
		$this->assertTrue(class_exists('XcVm\\Core\\Logging\\Logger'));
		$this->assertFileDoesNotExist($cache, 'The igbinary class-map cache must be gone.');
	}
}
