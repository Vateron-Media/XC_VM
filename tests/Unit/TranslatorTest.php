<?php

use XcVm\Core\Localization\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Translator — INI-backed i18n with automatic backfill. Drives it against a
 * throwaway langs directory (en.ini + a partial ru.ini) to cover language
 * detection/switching, placeholder substitution, and the key behaviour it was
 * hardened for: a missing key is copied into the active language file (English
 * value, or the key itself as a visible placeholder) so translators can see
 * what still needs work.
 */
final class TranslatorTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/xcvm_i18n_' . uniqid('', true);
		mkdir($this->dir, 0755, true);
		file_put_contents($this->dir . '/en.ini', "greeting = \"Hello\"\nname = \"Name\"\nwelcome = \"Hi {n}\"\n");
		file_put_contents($this->dir . '/ru.ini', "greeting = \"Привет\"\n");
		unset($_COOKIE['lang']);
	}

	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		@rmdir($this->dir);
		unset($_COOKIE['lang']);
		// The panel's own strings again, for the tests that run after this one.
		Translator::init(MAIN_HOME . 'Core/Localization/lang');
	}

	public function testDefaultsToEnglishAndReadsValues(): void {
		Translator::init($this->dir);
		$this->assertSame('en', Translator::current());
		$this->assertSame('Hello', Translator::get('greeting'));
	}

	public function testDetectsLanguageFromCookie(): void {
		$_COOKIE['lang'] = 'ru';
		Translator::init($this->dir);
		$this->assertSame('ru', Translator::current());
		$this->assertSame('Привет', Translator::get('greeting'));
	}

	public function testUnknownCookieLanguageFallsBackToEnglish(): void {
		$_COOKIE['lang'] = 'zz';
		Translator::init($this->dir);
		$this->assertSame('en', Translator::current());
	}

	public function testAvailableListsEveryIniFile(): void {
		Translator::init($this->dir);
		$available = Translator::available();
		sort($available);
		$this->assertSame(['en', 'ru'], $available);
	}

	public function testGetAppliesReplacements(): void {
		Translator::init($this->dir);
		$this->assertSame('Hi Bob', Translator::get('welcome', ['{n}' => 'Bob']));
	}

	public function testSetLanguageSwitchesOnlyToKnownLanguages(): void {
		Translator::init($this->dir);
		$this->assertFalse(Translator::setLanguage('zz'));
		$this->assertTrue(Translator::setLanguage('ru'));
		$this->assertSame('ru', Translator::current());
	}

	public function testMissingKeyBackfillsEnglishValueIntoActiveLanguage(): void {
		$_COOKIE['lang'] = 'ru';
		Translator::init($this->dir);

		// 'name' exists in en.ini but not ru.ini → returns the English source
		$this->assertSame('Name', Translator::get('name'));

		// ...and is appended to ru.ini so the gap is visible to translators.
		$ru = file_get_contents($this->dir . '/ru.ini');
		$this->assertStringContainsString('name = "Name"', $ru);
	}

	public function testUnknownKeyBackfillsItselfAsPlaceholder(): void {
		Translator::init($this->dir);

		$this->assertSame('totally_new_key', Translator::get('totally_new_key'));

		$en = file_get_contents($this->dir . '/en.ini');
		$this->assertStringContainsString('totally_new_key = "totally_new_key"', $en);
	}
}
