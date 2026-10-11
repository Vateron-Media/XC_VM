<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Localization\TranslationStore;

/**
 * Add a language on the Translations page: a new code starts a translation
 * from English in the editor, and "Copy English" seeds the blank cells. The
 * save path (a new custom code becomes available) is covered by
 * TranslationStoreTest; here we lock the page wiring and the strings so the
 * controls cannot drift from the editor they drive.
 */
final class TranslationAddLanguageTest extends TestCase {
	private function view(): string {
		return (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/translations.php');
	}

	public function testTheAddLanguageFormAndCopyEnglishControlArePresent(): void {
		$rView = $this->view();
		foreach (['translation-add-form', 'translation-add-code', 'translation-copy-english'] as $rId) {
			$this->assertStringContainsString($rId, $rView, $rId);
		}
		// The add form opens the same editor the edit buttons use (load()), and
		// Copy English reads the English column the editor already shows.
		$this->assertStringContainsString("load(code)", $rView);
		$this->assertStringContainsString('english = d.english', $rView);
	}

	public function testTheStringsTheControlsUseExist(): void {
		$rEn = parse_ini_file(MAIN_HOME . 'Core/Localization/lang/en.ini', false, INI_SCANNER_RAW);
		foreach (['translation_add', 'translation_add_intro', 'translation_add_button', 'translation_copy_english'] as $rKey) {
			$this->assertArrayHasKey($rKey, $rEn, $rKey);
			$this->assertNotSame('', trim((string) $rEn[$rKey]), $rKey);
		}
		// The intro is printed unescaped into the page, so it must carry no raw HTML tag.
		$this->assertDoesNotMatchRegularExpression('/[<>]/', (string) $rEn['translation_add_intro']);
	}

	public function testABrandNewCodeIsValidAndNotYetALanguage(): void {
		// The flow's starting point: a code with no file is accepted (so the
		// editor opens) and translates nothing until the first save.
		$this->assertTrue(TranslationStore::validCode('sw'));
		$this->assertTrue(TranslationStore::validCode('fil'));
		$this->assertTrue(TranslationStore::validCode('pt-BR'));
		$this->assertFalse(TranslationStore::validCode('english'));
	}
}
