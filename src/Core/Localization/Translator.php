<?php

namespace XcVm\Core\Localization;

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

	private static string $currentLang = 'en';

	private static string $langsDir = __DIR__ . '/lang/';

	/** @var string[] */
	private static array $availableLanguages = [];

	/** @var array<string, string>|null Lazily loaded en.ini — the source of truth for fallbacks */
	private static ?array $enTranslations = null;

	/**
	 * Initialize translator: scans available languages, detects current from cookie.
	 *
	 * @param string|null $langsDir Path to .ini files directory
	 */
	public static function init(?string $langsDir = null): void {
		if ($langsDir !== null) {
			self::$langsDir = rtrim($langsDir, '/') . '/';
			self::$enTranslations = null; // Directory changed — drop the cached en.ini
		}

		self::$availableLanguages = self::scanAvailableLanguages();

		$defaultLang = class_exists(\XcVm\Core\Config\SettingsManager::class)
			? (string) \XcVm\Core\Config\SettingsManager::get('language', 'en')
			: 'en';

		$requestedLang = $_COOKIE['lang'] ?? $defaultLang;
		self::$currentLang = in_array($requestedLang, self::$availableLanguages, true)
			? $requestedLang
			: (in_array($defaultLang, self::$availableLanguages, true) ? $defaultLang : 'en');

		self::loadLanguage(self::$currentLang);
	}

	/**
	 * Switch language at runtime and set cookie for 1 year.
	 *
	 * @param string|null $lang Language code (en, ru, de, ...) or null
	 * @return bool true if language exists and was switched
	 */
	public static function setLanguage(?string $lang): bool {
		if (empty(self::$availableLanguages)) {
			self::$availableLanguages = self::scanAvailableLanguages();
		}

		if ($lang === null || $lang === '' || !in_array($lang, self::$availableLanguages, true)) {
			return false;
		}

		self::$currentLang = $lang;
		$_COOKIE['lang'] = $lang;
		self::loadLanguage($lang);

		if (!headers_sent()) {
			setcookie('lang', $lang, time() + 365 * 24 * 3600, '/');
		}

		return true;
	}

	/**
	 * Check if a language (or the current language) is RTL.
	 *
	 * @param string|null $lang Language code or null for current
	 * @return bool
	 */
	public static function isRtl(?string $lang = null): bool {
		$lang ??= self::$currentLang;
		return in_array(strtolower($lang), ['ar', 'fa', 'ur', 'he'], true);
	}

	/**
	 * Get metadata for available languages (code, native name, english name, flag, direction).
	 *
	 * @return array<string, array{name: string, native: string, flag: string, dir: string}>
	 */
	public static function getLanguagesWithMeta(): array {
		$metadata = [
			'en' => ['name' => 'English',    'native' => 'English',    'flag' => '🇬🇧', 'dir' => 'ltr'],
			'ar' => ['name' => 'Arabic',     'native' => 'العربية',    'flag' => '🇸🇦', 'dir' => 'rtl'],
			'ru' => ['name' => 'Russian',    'native' => 'Русский',    'flag' => '🇷🇺', 'dir' => 'ltr'],
			'de' => ['name' => 'German',     'native' => 'Deutsch',    'flag' => '🇩🇪', 'dir' => 'ltr'],
			'fr' => ['name' => 'French',     'native' => 'Français',   'flag' => '🇫🇷', 'dir' => 'ltr'],
			'es' => ['name' => 'Spanish',    'native' => 'Español',    'flag' => '🇪🇸', 'dir' => 'ltr'],
			'pt' => ['name' => 'Portuguese', 'native' => 'Português',  'flag' => '🇵🇹', 'dir' => 'ltr'],
			'bg' => ['name' => 'Bulgarian',  'native' => 'Български',  'flag' => '🇧🇬', 'dir' => 'ltr'],
		];

		$result = [];
		foreach (self::$availableLanguages as $code) {
			$result[$code] = $metadata[$code] ?? [
				'name'   => strtoupper($code),
				'native' => strtoupper($code),
				'flag'   => '🌐',
				'dir'    => self::isRtl($code) ? 'rtl' : 'ltr',
			];
		}

		return $result;
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
			$text = self::resolveMissingKey($key);
			self::$translations[$key] = $text;
		}

		return $replace !== [] ? strtr($text, $replace) : $text;
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
		$files = glob(self::$langsDir . '*.ini') ?: [];

		foreach ($files as $file) {
			if (is_file($file) && is_readable($file)) {
				$languages[] = pathinfo($file, PATHINFO_FILENAME);
			}
		}

		$languages = array_unique($languages);
		if ($languages === []) {
			return ['en'];
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
			// The requested file is gone — fall back to English and report it as
			// the active language so current() never lies about what is loaded.
			$file = self::$langsDir . 'en.ini';
			self::$currentLang = 'en';
		}

		$data = parse_ini_file($file, false, INI_SCANNER_RAW);
		self::$translations = ($data !== false) ? $data : [];
	}

	/**
	 * Resolve a key that is absent from the current language.
	 *
	 * The key is always backfilled into the active language file so a developer
	 * or translator can see exactly which strings still need work. The stored
	 * value is the English source when it exists, otherwise the key itself acts
	 * as a visible placeholder marking an untranslated (or undefined) string.
	 *
	 * @param string $key Translation key
	 * @return string English value if known, otherwise the key itself
	 */
	private static function resolveMissingKey(string $key): string {
		$enValue = self::englishTranslations()[$key] ?? null;

		self::appendKeyToLanguageFile($key, $enValue ?? $key);

		return $enValue ?? $key;
	}

	/**
	 * Read en.ini once per request and cache it — every missing-key lookup would
	 * otherwise re-parse the whole file from disk.
	 *
	 * @return array<string, string>
	 */
	private static function englishTranslations(): array {
		if (self::$enTranslations === null) {
			$enFile = self::$langsDir . 'en.ini';
			$data = is_readable($enFile)
				? parse_ini_file($enFile, false, INI_SCANNER_RAW)
				: false;
			self::$enTranslations = ($data !== false) ? $data : [];
		}

		return self::$enTranslations;
	}

	/**
	 * Append "key = value" to the current language file if it is not already there.
	 *
	 * The whole read-check-append runs under a single LOCK_EX so concurrent
	 * requests cannot each pass a stale "key absent" check and write duplicate
	 * lines — the flaw of checking the file before locking it.
	 *
	 * @param string $key   Translation key
	 * @param string $value Value to store: the English source, or the key itself as a placeholder
	 */
	private static function appendKeyToLanguageFile(string $key, string $value): void {
		$file = self::$langsDir . self::$currentLang . '.ini';

		// 'c+' opens for read/write, creates the file if absent, and never
		// truncates — so the exclusive lock can guard the check-then-append.
		$fp = fopen($file, 'c+');
		if ($fp === false) {
			return;
		}

		if (!flock($fp, LOCK_EX)) {
			fclose($fp);
			return;
		}

		$content = stream_get_contents($fp) ?: '';
		$alreadyPresent = str_contains($content, "\n{$key} =")
			|| str_starts_with($content, "{$key} =");

		if (!$alreadyPresent) {
			$prefix = ($content === '')
				? "; " . self::$currentLang . " language file\n"
				: (str_ends_with($content, "\n") ? '' : "\n");
			// Escape quotes so the closing quote is not parsed prematurely; keys
			// are developer-controlled, so no further sanitisation is needed.
			$escaped = str_replace('"', '\\"', $value);
			fwrite($fp, "{$prefix}{$key} = \"{$escaped}\"\n");
		}

		flock($fp, LOCK_UN);
		fclose($fp);
	}
}
