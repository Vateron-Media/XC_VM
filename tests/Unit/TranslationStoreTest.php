<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\PageAuthorization;
use XcVm\Core\Localization\TranslationStore;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Backup\RecoveryBundle;

/**
 * The translation manager (Settings > Translations): the Translator reads a
 * custom directory over the bundled one and writes only there, and
 * TranslationStore takes an uploaded or edited translation into it — a
 * language code as the only file name, the keys of en.ini only, every value
 * sanitised — and gives a language back as an .ini file that reads the same.
 *
 * Both directories are the test's own: neither the panel's language files nor
 * its config/ is written.
 */
final class TranslationStoreTest extends TestCase {
	private string $rDir;
	private string $rBundled;
	private string $rCustom;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm_translations_' . bin2hex(random_bytes(6));
		$this->rBundled = $this->rDir . '/bundled/';
		// Not made: the first write makes it.
		$this->rCustom = $this->rDir . '/custom/';
		mkdir($this->rBundled, 0755, true);
		// The values as the files have them: quotes, a backslash, `;` and `=` inside the outer quotes.
		file_put_contents($this->rBundled . 'en.ini', implode("\n", [
			'[Language]',
			'greeting = "Hello"',
			'name = "Name"',
			'welcome = "Hi {n}"',
			'link = "Go to <a href=\"./settings.php\">Settings</a>"',
			'tricky = "a "q" ; b = c \ d"',
			'cpu_% = "CPU (%)"',
		]) . "\n");
		file_put_contents($this->rBundled . 'ru.ini', implode("\n", [
			'[Language]',
			'greeting = "Привет"',
			'link = "Откройте <a href=\"./settings.php\">Настройки</a>"',
		]) . "\n");
		unset($_COOKIE['lang']);
		Translator::init($this->rBundled, $this->rCustom);
	}

	protected function tearDown(): void {
		foreach (array_merge(glob($this->rDir . '/*/{,.}[!.]*', GLOB_BRACE) ?: [], glob($this->rDir . '/*.ini') ?: []) as $rFile) {
			unlink($rFile);
		}
		@rmdir($this->rCustom);
		@rmdir($this->rBundled);
		@rmdir($this->rDir);
		unset($_COOKIE['lang']);
		// The panel's own strings again, and no custom directory, for the tests that run after this one.
		Translator::init(MAIN_HOME . 'Core/Localization/lang');
	}

	/** @return array<string, string> the bundled directory's files, by name */
	private function bundled(): array {
		$rFiles = [];
		foreach (glob($this->rBundled . '{,.}*', GLOB_BRACE) ?: [] as $rFile) {
			if (is_file($rFile)) {
				$rFiles[basename($rFile)] = (string) file_get_contents($rFile);
			}
		}
		return $rFiles;
	}

	private function custom(string $rCode): string {
		return (string) file_get_contents($this->rCustom . $rCode . '.ini');
	}

	private function writeCustom(string $rCode, string $rLines): void {
		is_dir($this->rCustom) || mkdir($this->rCustom, 0755, true);
		file_put_contents($this->rCustom . $rCode . '.ini', "[Language]\n" . $rLines . "\n");
	}

	// ── Translator: the custom directory over the bundled one ────────────────

	public function testACustomFileOverridesAndExtendsABundledLanguage(): void {
		$this->writeCustom('ru', "greeting = \"Здравствуйте\"\nname = \"Имя\"");
		$_COOKIE['lang'] = 'ru';
		Translator::init($this->rBundled, $this->rCustom);

		$this->assertSame('ru', Translator::current());
		$this->assertSame('Здравствуйте', Translator::get('greeting'), 'the custom value, over the bundled one');
		$this->assertSame('Имя', Translator::get('name'), 'a key the bundled file lacks');
		$this->assertSame('Откройте <a href=\"./settings.php\">Настройки</a>', Translator::get('link'), 'the bundled value, where there is no custom one');
		$this->assertSame('Hi Bob', Translator::get('welcome', ['{n}' => 'Bob']), 'English, where neither has the key');
	}

	public function testALanguageOnlyTheCustomDirectoryHasIsAvailable(): void {
		$this->writeCustom('tr', 'greeting = "Merhaba"');
		$_COOKIE['lang'] = 'tr';
		Translator::init($this->rBundled, $this->rCustom);

		$rAvailable = Translator::available();
		sort($rAvailable);
		$this->assertSame(['en', 'ru', 'tr'], $rAvailable, 'the union, each language once');
		$this->assertSame('tr', Translator::current());
		$this->assertSame('Merhaba', Translator::get('greeting'));
		$this->assertSame('Name', Translator::get('name'), 'a key it lacks is the English text');

		$this->writeCustom('ru', 'name = "Имя"');
		Translator::init($this->rBundled, $this->rCustom);
		$this->assertCount(3, Translator::available(), 'a language of both directories is one language');
	}

	public function testAMissingKeyWritesNoFile(): void {
		$rBefore = $this->bundled();
		$_COOKIE['lang'] = 'ru';
		Translator::init($this->rBundled, $this->rCustom);

		$rEnglish = Translator::englishTranslations();
		$this->assertSame('Name', Translator::get('name'), 'the English text');
		$this->assertSame('not_a_key_yet', Translator::get('not_a_key_yet'), 'a key en.ini lacks, as itself');
		$this->assertSame($rEnglish['tricky'], Translator::get('tricky'));

		$this->assertDirectoryDoesNotExist($this->rCustom, 'the custom directory holds what an administrator saved, nothing else');
		$this->assertSame($rBefore, $this->bundled(), 'no file of the bundled directory is written or made');
	}

	// ── The sanitiser ───────────────────────────────────────────────────────

	/** @return array<string, array{0: string, 1: string}> */
	public static function values(): array {
		return [
			'a script' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
			'an allowed tag with an attribute' => ['<b onclick="x()">bold</b>', '&lt;b onclick=&quot;x()&quot;&gt;bold</b>'],
			'an allowed tag with a style' => ['<i style=color:red>x</i>', '&lt;i style=color:red&gt;x</i>'],
			'an image' => ['<img src=x onerror=alert(1)>', '&lt;img src=x onerror=alert(1)&gt;'],
			'a link' => ['<a href="javascript:alert(1)">x</a>', '&lt;a href=&quot;javascript:alert(1)&quot;&gt;x&lt;/a&gt;'],
			'a tag in a tag' => ['<b<script>>x', '&lt;b&lt;script&gt;&gt;x'],
			'the bare tags' => ['<b>b</b> <i>i</i> <strong>s</strong> <em>e</em> <code>c</code><br>', '<b>b</b> <i>i</i> <strong>s</strong> <em>e</em> <code>c</code><br>'],
			'their other spellings' => ['<B>b</B><br/><br /><BR>', '<b>b</b><br><br><br>'],
			'placeholders' => ['Hi {name}, {count} of %s left', 'Hi {name}, {count} of %s left'],
			'an ampersand and entities' => ['Tom & Jerry &amp; co&nbsp;&mdash; &lt;b&gt;', 'Tom &amp; Jerry &amp; co&nbsp;&mdash; &lt;b&gt;'],
			'quotes' => ['say "hi"', 'say &quot;hi&quot;'],
			'an apostrophe' => ["l'utente';alert(1);//", 'l’utente’;alert(1);//'],
			'a backslash' => ['a\\b \\x3cscript\\x3e \\', 'a&#92;b &#92;x3cscript&#92;x3e &#92;'],
			'more lines' => ["one\r\ntwo\n\tthree\0", 'one two three'],
			'not UTF-8' => ["caf\xE9", 'caf' . "\u{FFFD}"],
			'other scripts' => ['مرحبا «мир» 你好', 'مرحبا «мир» 你好'],
		];
	}

	/** @dataProvider values */
	public function testAValueIsStoredSafeToPrintAsHtml(string $rValue, string $rStored): void {
		$this->assertSame($rStored, TranslationStore::sanitise($rValue));
		$this->assertSame($rStored, TranslationStore::sanitise($rStored), 'a stored value stays as it is');
		// Outside the bare tags, nothing is left that opens a tag, ends an attribute or a script string, or escapes in one.
		$this->assertDoesNotMatchRegularExpression('/[<>"\'\\\\\x00-\x1F]/', (string) preg_replace('~</?(b|i|strong|em|code)>|<br>~', '', $rStored));
	}

	// ── Upload ──────────────────────────────────────────────────────────────

	/** @return array<string, array{0: string}> */
	public static function badCodes(): array {
		return [
			'a path' => ['../../etc/passwd'], 'a path in a code' => ['ru/../en'], 'a file name' => ['ru.ini'], 'upper case' => ['EN'],
			'one letter' => ['e'], 'four letters' => ['engl'], 'a line feed' => ["ru\n"], 'a null byte' => ["ru\0"],
			'a long region' => ['pt-BRAZI'], 'no language' => ['-BR'], 'empty' => [''], 'a space' => ['r u'],
		];
	}

	/** @dataProvider badCodes */
	public function testALanguageCodeIsTheOnlyFileName(string $rCode): void {
		$this->assertFalse(TranslationStore::validCode($rCode));
		$this->assertSame(['result' => false, 'error' => 'code'], TranslationStore::import($rCode, "greeting = \"x\"\n"));
		$this->assertSame(['result' => false, 'error' => 'code'], TranslationStore::save($rCode, ['greeting' => 'x']));
		$this->assertNull(TranslationStore::export($rCode));
		$this->assertSame([], TranslationStore::values($rCode));
		$this->assertDirectoryDoesNotExist($this->rCustom, 'nothing is written anywhere');
	}

	public function testGoodCodes(): void {
		foreach (['tr', 'fil', 'pt-BR', 'zh-Hans', 'sr-latn', 'exyu'] as $rCode) {
			$this->assertTrue(TranslationStore::validCode($rCode), $rCode);
		}
	}

	public function testAnUploadKeepsOnlyTheKeysOfEnglishSanitised(): void {
		$rBefore = $this->bundled();
		$rText = "\xEF\xBB\xBF[Language]\n"
			. "greeting = \"Merhaba <b>dünya</b>\"\n"
			. "welcome = \"<script>alert(1)</script> {n}\"\n"
			. "name = \"Name\"\n"
			. "cpu_% = \"İşlemci (%)\"\n"
			. "not_in_english = \"x\"\n"
			. "list[] = \"an array\"\n";
		$this->assertSame(['result' => true, 'saved' => 3], TranslationStore::import('tr', $rText));

		$rStored = parse_ini_file($this->rCustom . 'tr.ini', false, INI_SCANNER_RAW);
		$this->assertSame(['greeting' => 'Merhaba <b>dünya</b>', 'welcome' => '&lt;script&gt;alert(1)&lt;/script&gt; {n}', 'cpu_%' => 'İşlemci (%)'], $rStored, 'in en.ini\'s order; the line still in English is no translation');
		$this->assertStringNotContainsString('not_in_english', $this->custom('tr'));
		$this->assertSame('0644', substr(sprintf('%o', fileperms($this->rCustom . 'tr.ini')), -4));
		$this->assertSame($rBefore, $this->bundled());
		$this->assertSame([], glob($this->rCustom . '{,.}*.tmp', GLOB_BRACE), 'no file written aside is left');

		// The panel shows it, and falls back to English for the rest.
		$_COOKIE['lang'] = 'tr';
		Translator::init($this->rBundled, $this->rCustom);
		$this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt; Bob', Translator::get('welcome', ['{n}' => 'Bob']));
		$this->assertSame('Name', Translator::get('name'));
		$this->assertSame(['en' => ['translated' => 6, 'bundled' => true, 'custom' => 0], 'ru' => ['translated' => 2, 'bundled' => true, 'custom' => 0], 'tr' => ['translated' => 3, 'bundled' => false, 'custom' => 3]], TranslationStore::languages());
	}

	public function testAnUploadIsRefusedWhenItIsTooLargeNoIniFileOrNoTranslation(): void {
		$this->assertSame(['result' => false, 'error' => 'size'], TranslationStore::import('tr', 'greeting = "' . str_repeat('x', TranslationStore::MAX_BYTES) . "\"\n"));
		$this->assertSame(['result' => false, 'error' => 'parse', 'line' => 3], TranslationStore::import('tr', "[Language]\ngreeting = \"Merhaba\"\n{{{ = \"x\"\n"));
		$this->assertSame(['result' => false, 'error' => 'empty'], TranslationStore::import('tr', "[Language]\nsomething_else = \"x\"\n"), 'no key of the panel');
		$this->assertSame(['result' => false, 'error' => 'empty'], TranslationStore::import('tr', (string) file_get_contents($this->rBundled . 'en.ini')), 'en.ini as it was downloaded is no language');
		$this->assertSame([], glob($this->rCustom . '*.ini') ?: []);
		$this->assertNotContains('tr', Translator::scanAvailableLanguages());
	}

	public function testAnUploadExpandsNothingOfThePanel(): void {
		// INI_SCANNER_RAW: a constant or ${variable} in a value stays the text it is.
		$this->assertSame(['result' => true, 'saved' => 2], TranslationStore::import('tr', "greeting = MAIN_HOME\nname = \"\${PATH} PHP_VERSION\"\n"));
		$this->assertSame(['greeting' => 'MAIN_HOME', 'name' => '${PATH} PHP_VERSION'], TranslationStore::values('tr'));
	}

	public function testABundledValueIsNotCopiedAndNotSanitised(): void {
		// A language downloaded and uploaded again, one line changed: only that line is custom.
		$rText = str_replace('Привет', 'Здравствуйте', (string) TranslationStore::export('ru'));
		$this->assertSame(['result' => true, 'saved' => 1], TranslationStore::import('ru', $rText));
		$this->assertSame(['greeting' => 'Здравствуйте'], parse_ini_file($this->rCustom . 'ru.ini', false, INI_SCANNER_RAW));
		$this->assertSame('Откройте <a href=\"./settings.php\">Настройки</a>', TranslationStore::values('ru')['link'], 'the panel\'s own link, as the bundled file has it');
		$this->assertSame(['translated' => 2, 'bundled' => true, 'custom' => 1], TranslationStore::languages()['ru']);

		// The same link in a language the panel does not ship is an upload's markup.
		TranslationStore::save('tr', ['link' => '<a href="./settings.php">Ayarlar</a>']);
		$this->assertSame('&lt;a href=&quot;./settings.php&quot;&gt;Ayarlar&lt;/a&gt;', TranslationStore::values('tr')['link']);
	}

	// ── The editor's save ───────────────────────────────────────────────────

	public function testAnEditIsMergedAndAnEmptyOrEnglishValueTakesItAway(): void {
		$this->assertSame(['result' => true, 'saved' => 2], TranslationStore::save('ru', ['name' => 'Имя', 'greeting' => 'Здравствуйте', 'nope' => 'x']));
		$this->assertSame(['result' => true, 'saved' => 1], TranslationStore::save('ru', ['welcome' => 'Привет, {n}']));
		$this->assertSame(['greeting' => 'Здравствуйте', 'name' => 'Имя', 'welcome' => 'Привет, {n}'], parse_ini_file($this->rCustom . 'ru.ini', false, INI_SCANNER_RAW), 'the earlier edits are kept');

		// Emptied, the English text typed again, the bundled value typed again: each is no custom translation.
		$this->assertSame(['result' => true, 'saved' => 0], TranslationStore::save('ru', ['name' => '', 'welcome' => 'Hi {n}', 'greeting' => 'Привет']));
		$this->assertFileDoesNotExist($this->rCustom . 'ru.ini', 'a language left with nothing custom loses its file');
		$this->assertSame(['greeting' => 'Привет', 'link' => 'Откройте <a href=\"./settings.php\">Настройки</a>'], TranslationStore::values('ru'));

		// English is edited the same way, and is always whole.
		$this->assertSame(['result' => true, 'saved' => 1], TranslationStore::save('en', ['greeting' => 'Welcome']));
		$this->assertSame('Welcome', TranslationStore::values('en')['greeting']);
		$this->assertCount(6, TranslationStore::values('en'));
	}

	public function testASaveCleansAFileEditedByHand(): void {
		// The English text again, a key en.ini lacks and markup, all written into the file by hand.
		$this->writeCustom('ru', "name = \"Name\"\nnot_a_key_yet = \"not_a_key_yet\"\nwelcome = \"<img src=x onerror=alert(1)> {n}\"");
		$this->assertSame(['result' => true, 'saved' => 1], TranslationStore::save('ru', ['greeting' => 'Здравствуйте']));
		$this->assertSame(['greeting' => 'Здравствуйте', 'welcome' => '&lt;img src=x onerror=alert(1)&gt; {n}'], parse_ini_file($this->rCustom . 'ru.ini', false, INI_SCANNER_RAW));
	}

	// ── Download, and download → upload ─────────────────────────────────────

	public function testADownloadReadsBackAsThePanelShowsTheLanguage(): void {
		$rEnglish = Translator::englishTranslations();
		$this->assertSame('a "q" ; b = c \ d', $rEnglish['tricky'], 'the fixture: quotes, `;`, `=` and a backslash');

		// English is the template: every key, each value as en.ini has it.
		$this->assertSame($rEnglish, parse_ini_string((string) TranslationStore::export('en'), false, INI_SCANNER_RAW));

		// A language has en.ini's keys in en.ini's order: its own value, or the English one.
		TranslationStore::save('ru', ['tricky' => 'я "к" ; б = в \ г']);
		$rRussian = parse_ini_string((string) TranslationStore::export('ru'), false, INI_SCANNER_RAW);
		$this->assertSame(array_keys($rEnglish), array_keys($rRussian));
		$this->assertSame('Привет', $rRussian['greeting']);
		$this->assertSame('Name', $rRussian['name']);
		$this->assertSame('Откройте <a href=\"./settings.php\">Настройки</a>', $rRussian['link']);
		$this->assertSame('я &quot;к&quot; ; б = в &#92; г', $rRussian['tricky']);

		$this->assertNull(TranslationStore::export('tr'), 'no such language');
	}

	public function testADownloadUploadedAgainGivesTheSameLanguage(): void {
		$rValues = [
			'greeting' => 'say "hi" & \'bye\'',
			'name' => 'C:\\path\\ ; a = b ; "c"',
			'welcome' => '= {n} ; \\',
			'link' => '<b>;</b>="x"<br/>',
			'tricky' => '"quoted"',
			'cpu_%' => '%; \\" =',
		];
		$this->assertSame(['result' => true, 'saved' => 6], TranslationStore::save('tr', $rValues));
		$rStored = TranslationStore::values('tr');
		$this->assertSame(array_map([TranslationStore::class, 'sanitise'], $rValues), $rStored, 'in en.ini\'s order, each value sanitised');
		$this->assertSame('C:&#92;path&#92; ; a = b ; &quot;c&quot;', $rStored['name']);

		// The file itself reads back the same...
		$this->assertSame($rStored, parse_ini_file($this->rCustom . 'tr.ini', false, INI_SCANNER_RAW));

		// ...and so does its download, uploaded as another language and as itself.
		$rText = (string) TranslationStore::export('tr');
		$this->assertSame(['result' => true, 'saved' => 6], TranslationStore::import('az', $rText));
		$this->assertSame($rStored, TranslationStore::values('az'));
		$this->assertSame(['result' => true, 'saved' => 6], TranslationStore::import('tr', $rText));
		$this->assertSame($rStored, TranslationStore::values('tr'));
		$this->assertSame($rText, TranslationStore::export('az'));
	}

	public function testThePanelsOwnLanguagesDownloadAsTheyAre(): void {
		// The real language files, read only; the custom directory is still the test's.
		$rReal = MAIN_HOME . 'Core/Localization/lang/';
		Translator::init($rReal, $this->rCustom);
		$rEnglish = parse_ini_file($rReal . 'en.ini', false, INI_SCANNER_RAW);

		$this->assertSame($rEnglish, parse_ini_string((string) TranslationStore::export('en'), false, INI_SCANNER_RAW), 'en.ini, every value as it is');
		foreach (array_keys(TranslationStore::languages()) as $rCode) {
			$rLanguage = parse_ini_file($rReal . $rCode . '.ini', false, INI_SCANNER_RAW);
			$rDownload = parse_ini_string((string) TranslationStore::export($rCode), false, INI_SCANNER_RAW);
			$this->assertSame(array_keys($rEnglish), array_keys($rDownload), $rCode);
			$this->assertSame([], array_diff_assoc(TranslationStore::values($rCode), $rLanguage), $rCode . ': a translation is the file\'s own value');
			if ($rCode !== 'en') {
				$this->assertEquals(TranslationStore::values($rCode), array_diff_assoc($rDownload, $rEnglish), $rCode . ': the rest is the English text');
			}
			// Uploaded as it was downloaded, it changes nothing.
			$this->assertSame(['result' => true, 'saved' => 0], TranslationStore::import($rCode, (string) TranslationStore::export($rCode)), $rCode);
		}
		$this->assertSame([], glob($this->rCustom . '*.ini') ?: []);
	}

	// ── The page, and the backup ────────────────────────────────────────────

	/** The page writes what every administrator reads: it takes the Settings page's permission. */
	public function testThePageNeedsTheSettingsPermission(): void {
		$rBefore = [$GLOBALS['db'] ?? null, $GLOBALS['rUserInfo'] ?? null, $GLOBALS['rPermissions'] ?? null];
		$GLOBALS['db'] = new stdClass();
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => 5];
		try {
			$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => ['manage_tickets']];
			$this->assertFalse(PageAuthorization::checkPermissions('translations', false));
			$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => ['settings']];
			$this->assertTrue(PageAuthorization::checkPermissions('translations', false));
			$GLOBALS['rPermissions'] = ['is_admin' => 0, 'advanced' => []];
			$this->assertFalse(PageAuthorization::checkPermissions('translations', false), 'no reseller');
		} finally {
			[$GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']] = $rBefore;
		}
		$rRoutes = (string) file_get_contents(MAIN_HOME . 'Public/routes/admin.php');
		foreach (['keys', 'save', 'upload'] as $rAction) {
			$this->assertStringContainsString("\$router->api('translation_{$rAction}', [TranslationAjaxController::class, '{$rAction}']);", $rRoutes);
		}
		// Each action asks for the permission itself, and the two that write for a POST.
		$rController = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/Ajax/TranslationAjaxController.php');
		$this->assertSame(3, substr_count($rController, '$this->guard('));
		$this->assertSame(2, substr_count($rController, '$this->guard(true);'));
		$this->assertStringContainsString("\$this->gate('adv', 'settings');", $rController);
	}

	public function testTheRecoveryBundleHoldsTheCustomTranslations(): void {
		if (!defined('CONFIG_PATH')) {
			define('CONFIG_PATH', MAIN_HOME . 'config/');
		}
		TranslationStore::save('tr', ['greeting' => 'Merhaba']);
		$rFiles = RecoveryBundle::files('correct horse battery staple');
		$this->assertSame($this->custom('tr'), $rFiles['lang/tr.ini'] ?? null);
		$this->assertSame(['lang/tr.ini'], array_values(array_filter(array_keys($rFiles), static fn(string $rName): bool => str_starts_with($rName, 'lang/'))));
	}
}
