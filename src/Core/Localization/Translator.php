<?php

/**
 * Translator — XC_VM multilingual support system.
 *
 * Loads translations from INI files, switches language via cookie,
 * automatically copies missing keys from en.ini into the current language.
 *
 * @package XC_VM\Core\Localization
 * @author Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class Translator {
	/** @var array<string, string> */
	private static array $translations = [];

	/** @var string */
	private static string $currentLang = 'en';

	/** @var string */
	private static string $langsDir = __DIR__ . '/lang/';

	/** @var string[] */
	private static array $availableLanguages = [];

	/**
	 * Initialize translator: scans available languages, detects current from cookie.
	 *
	 * @param string|null $langsDir Path to .ini files directory
	 */
	public static function init(?string $langsDir = null): void {
		if ($langsDir !== null) {
			self::$langsDir = rtrim($langsDir, '/') . '/';
		}

		self::$availableLanguages = self::scanAvailableLanguages();

		$requestedLang = $_COOKIE['lang'] ?? 'en';
		self::$currentLang = in_array($requestedLang, self::$availableLanguages)
			? $requestedLang
			: 'en';

		self::loadLanguage(self::$currentLang);
	}

	/**
	 * Switch language at runtime and set cookie for 1 year.
	 *
	 * @param string $lang Language code (en, ru, de, ...)
	 * @return bool true if language exists and was switched
	 */
	public static function setLanguage(string $lang): bool {
		if (!in_array($lang, self::$availableLanguages)) {
			return false;
		}

		self::$currentLang = $lang;
		self::loadLanguage($lang);

		if (!headers_sent()) {
			setcookie('lang', $lang, time() + 365 * 24 * 3600, '/');
		}

		return true;
	}

	/**
	 * Get translation by key. If missing — copies from en.ini into current language.
	 *
	 * @param string $key Translation key
	 * @param array<string, string> $replace Substitutions for strtr()
	 * @return string Translated string or key as fallback
	 */
	public static function get(string $key, array $replace = []): string {
		$text = self::$translations[$key] ?? null;

		if ($text === null) {
			$enFallback = self::copyMissingKeyFromEnglish($key);
			$text = $enFallback ?? $key;
			self::$translations[$key] = $text;
		}

		return !empty($replace) ? strtr($text, $replace) : $text;
	}

	/**
	 * @return string Current language code
	 */
	public static function current(): string {
		return self::$currentLang;
	}

	/**
	 * @return string[] List of available language codes
	 */
	public static function available(): array {
		return self::$availableLanguages;
	}

	/**
	 * Scan langs directory for .ini files.
	 *
	 * @return string[]
	 */
	private static function scanAvailableLanguages(): array {
		$languages = [];
		$files = glob(self::$langsDir . '*.ini');

		foreach ($files as $file) {
			if (is_file($file) && is_readable($file)) {
				$languages[] = pathinfo($file, PATHINFO_FILENAME);
			}
		}

		$languages = array_unique($languages);
		if (empty($languages)) {
			$languages = ['en'];
		}

		return $languages;
	}

	/**
	 * Load translations from .ini file into $translations. Falls back to en.ini.
	 *
	 * @param string $lang Language code
	 */
	private static function loadLanguage(string $lang): void {
		$file = self::$langsDir . $lang . '.ini';

		if (!is_readable($file)) {
			$file = self::$langsDir . 'en.ini';
		}

		$data = parse_ini_file($file, false, INI_SCANNER_RAW);
		self::$translations = ($data !== false) ? $data : [];
	}

	/**
	 * Copy a missing key from en.ini into the current language file.
	 * Uses file locking to prevent race conditions on concurrent requests.
	 *
	 * @param string $key Translation key
	 * @return string|null Value from en.ini, or null if key not found
	 */
	private static function copyMissingKeyFromEnglish(string $key): ?string {
		$enFile = self::$langsDir . 'en.ini';
		$enValue = null;
		if (is_readable($enFile)) {
			$enData = parse_ini_file($enFile, false, INI_SCANNER_RAW);
			if ($enData !== false && isset($enData[$key])) {
				$enValue = $enData[$key];
			}
		}

		$file = self::$langsDir . self::$currentLang . '.ini';

		if (!file_exists($file)) {
			file_put_contents($file, "; " . self::$currentLang . " language file\n");
		}

		$content = file_get_contents($file);
		if (str_contains($content, "\n{$key} =") || str_contains($content, "\r{$key} =")) {
			return $enValue;
		}

		$value = $enValue ?? $key;
		$escapedValue = str_replace('"', '\\"', $value);
		$endsWithNewline = str_ends_with($content, "\n");
		$lineToAdd = ($endsWithNewline ? '' : "\n") . "{$key} = \"{$escapedValue}\"\n";

		$fp = fopen($file, 'a');
		if ($fp && flock($fp, LOCK_EX)) {
			fwrite($fp, $lineToAdd);
			flock($fp, LOCK_UN);
			fclose($fp);
		}

		return $enValue;
	}
}