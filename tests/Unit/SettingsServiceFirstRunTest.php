<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Server\SettingsService;

/**
 * The first-run page sets the panel's language, timezone, name and message of
 * the day with the admin account. What it posts is not trusted: a language or
 * a timezone the panel does not have is left out, and so the stored value
 * stays.
 */
final class SettingsServiceFirstRunTest extends TestCase {
	protected function setUp(): void {
		unset($_COOKIE['lang']);
		Translator::init(MAIN_HOME . 'Core/Localization/lang');
	}

	public function testWhatThePageSendsIsWhatIsSet(): void {
		$this->assertSame(
			['language' => 'pt', 'default_timezone' => 'Europe/Lisbon', 'server_name' => 'My Panel', 'message_of_day' => 'Welcome'],
			SettingsService::firstRunValues(['language' => 'pt', 'default_timezone' => ' Europe/Lisbon ', 'server_name' => '  My Panel ', 'message_of_day' => 'Welcome', 'username' => 'administrator'])
		);
	}

	public function testALanguageOrTimezoneThePanelDoesNotHaveIsLeftOut(): void {
		$this->assertSame([], SettingsService::firstRunValues(['language' => '../../etc/passwd', 'default_timezone' => 'Mars/Olympus']));
		$this->assertSame([], SettingsService::firstRunValues(['language' => ['en'], 'default_timezone' => 7, 'server_name' => ['x'], 'message_of_day' => null]));
		$this->assertSame([], SettingsService::firstRunValues([]), 'a form of an older page: nothing changes');
	}

	public function testAnEmptyNameKeepsTheStoredOneAndAnEmptyMessageClearsIt(): void {
		$this->assertSame(['message_of_day' => ''], SettingsService::firstRunValues(['server_name' => '   ', 'message_of_day' => '']));
		$this->assertSame(255, mb_strlen(SettingsService::firstRunValues(['server_name' => str_repeat('é', 300)])['server_name']));
	}
}
