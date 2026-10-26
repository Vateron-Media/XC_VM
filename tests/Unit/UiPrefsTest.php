<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\User\UiPrefs;

/**
 * The whitelist shared by the admin and reseller customizer endpoints: unknown
 * keys and invalid values must never reach users.ui_prefs.
 */
final class UiPrefsTest extends TestCase {
	public function testKeepsValidWhitelistedValues(): void {
		$rIn = ['theme' => 'dark', 'color' => '#7367f0', 'semiDark' => 'true', 'lang' => 'en'];

		$this->assertSame(
			['theme' => 'dark', 'semiDark' => true, 'color' => '#7367f0', 'lang' => 'en'],
			UiPrefs::sanitize($rIn)
		);
	}

	public function testUnsetThemeIsSeededFromTheProfile(): void {
		[$rPrefs, $rBsTheme] = UiPrefs::pageState(['theme' => 1, 'ui_prefs' => null], false);

		$this->assertSame('dark', $rPrefs['theme']);
		$this->assertSame('dark', $rBsTheme);
		$this->assertFalse($rPrefs['rtl']);
	}

	public function testSavedThemeWinsOverTheProfile(): void {
		[$rPrefs, $rBsTheme] = UiPrefs::pageState(['theme' => 1, 'ui_prefs' => '{"theme":"light"}'], true);

		$this->assertSame('light', $rPrefs['theme']);
		$this->assertSame('light', $rBsTheme);
		$this->assertTrue($rPrefs['rtl']);
	}

	public function testDropsUnknownKeysAndInvalidValues(): void {
		$rIn = ['theme' => 'neon', 'color' => 'red', 'skin' => 1, 'evil' => '<script>', 'rtl' => 'maybe'];

		$this->assertSame([], UiPrefs::sanitize($rIn));
	}
}
