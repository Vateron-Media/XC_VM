<?php

use PHPUnit\Framework\TestCase;

/**
 * A translation keeps the placeholders of its English text ({names}, {bin}):
 * Translator::get() replaces them by name, so a translator engine that renames
 * one ({عدد}) or breaks it ({1 }, _BOS_0}) leaves the raw marker on the page.
 */
final class LanguageFilesPlaceholderTest extends TestCase {
	/** @return list<string> the named placeholders of a text, sorted */
	private static function placeholders(string $rText): array {
		preg_match_all('/\{[A-Za-z_]\w*\}/', $rText, $rMatches);
		$rFound = $rMatches[0];
		sort($rFound);
		return $rFound;
	}

	public function testEveryTranslationKeepsTheEnglishPlaceholders(): void {
		$rDir = MAIN_HOME . 'Core/Localization/lang/';
		$rEnglish = parse_ini_file($rDir . 'en.ini', false, INI_SCANNER_RAW);
		$rWrong = [];
		foreach (glob($rDir . '*.ini') as $rFile) {
			$rLang = basename($rFile, '.ini');
			foreach (parse_ini_file($rFile, false, INI_SCANNER_RAW) ?: [] as $rKey => $rText) {
				if (isset($rEnglish[$rKey]) && (self::placeholders((string) $rText) !== self::placeholders((string) $rEnglish[$rKey]) || str_contains((string) $rText, '_BOS_'))) {
					$rWrong[] = $rLang . ': ' . $rKey . ' = ' . $rText;
				}
			}
		}
		$this->assertSame([], $rWrong);
	}
}
