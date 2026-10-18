<?php

namespace XcVm\Core\Localization;

/**
 * Translator — XC_VM multilingual support system.
 *
 * Loads translations from INI files, switches language via cookie, and shows
 * the English text for a key the current language lacks.
 *
 * A language is read from two directories: the bundled one, which is part of
 * the code and which every update replaces, and over it the custom one
 * (config/lang/), which holds what an administrator uploaded or edited
 * (TranslationStore). A loaded module's own lang/ directory (addModuleDir())
 * adds the keys the bundled files lack: its strings belong to the panel's
 * text like the core ones, and nothing of it is copied into the core files.
 * Nothing here writes a language file: a missing key used to be appended to
 * the bundled file at runtime, and the Translations page lists what a
 * language lacks instead.
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

	/** @var string|null Custom translations directory; null: not resolved yet, '': none */
	private static ?string $customDir = null;

	/** @var string[] */
	private static array $availableLanguages = [];

	/** @var string[] The lang/ directories of the loaded modules, in load order */
	private static array $moduleDirs = [];

	/** @var array<string, string>|null Lazily loaded en.ini — the source of truth for fallbacks */
	private static ?array $enTranslations = null;

	/**
	 * Initialize translator: scans available languages, detects current from cookie.
	 *
	 * @param string|null $langsDir  Path to the bundled .ini files directory
	 * @param string|null $customDir Path to the custom .ini files directory, read over
	 *                               the bundled one. A caller that names the bundled
	 *                               directory (a test) names this one too, or has none:
	 *                               the panel's own config/lang/ is not read or written for it.
	 */
	public static function init(?string $langsDir = null, ?string $customDir = null): void {
		if ($langsDir !== null) {
			self::$langsDir = rtrim($langsDir, '/') . '/';
			self::$customDir = $customDir !== null ? rtrim($customDir, '/') . '/' : '';
			self::$moduleDirs = [];
			self::$enTranslations = null; // Directory changed — drop the cached en.ini
		}

		self::$availableLanguages = self::scanAvailableLanguages();

		$requestedLang = $_COOKIE['lang'] ?? 'en';
		self::$currentLang = in_array($requestedLang, self::$availableLanguages, true)
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
		if (!in_array($lang, self::$availableLanguages, true)) {
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
	 * Get translation by key. If missing — the English text, else the key itself.
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
	 * Whether a language (or the current one) is written right-to-left.
	 *
	 * @param string|null $lang Language code, or null for the current language.
	 */
	public static function isRtl(?string $lang = null): bool {
		$lang ??= self::$currentLang;
		return in_array(strtolower($lang), ['ar', 'fa', 'ur', 'he'], true);
	}

	/**
	 * @return string[] List of available language codes
	 */
	public static function available(): array {
		return self::$availableLanguages;
	}

	/** Display the shared Latin-script language without changing its stored code. */
	public static function displayName(string $lang): string {
		return $lang === 'exyu' ? 'BA_SRB_CRO' : $lang;
	}

	/**
	 * @return string Bundled language files directory (part of the code)
	 */
	public static function bundledDir(): string {
		return self::$langsDir;
	}

	/**
	 * @return string Custom language files directory (config/lang/), or '' when there is none
	 */
	public static function customDir(): string {
		// Resolved on first use: the bootstrap defines CONFIG_PATH, not the class loader.
		return self::$customDir ??= defined('CONFIG_PATH') ? CONFIG_PATH . 'lang/' : '';
	}

	/**
	 * Read a loaded module's language files (lang/<code>.ini) under the
	 * bundled ones: a key a bundled file has keeps its value. The modules
	 * load after the language was, so the current one takes the module's
	 * keys at once.
	 *
	 * @param string $dir The module's lang/ directory; one it lacks is no error
	 */
	public static function addModuleDir(string $dir): void {
		$dir = rtrim($dir, '/') . '/';
		if (in_array($dir, self::$moduleDirs, true) || !is_dir($dir)) {
			return;
		}
		self::$moduleDirs[] = $dir;
		self::$enTranslations = null;
		if (self::$translations !== []) {
			self::$translations += self::readFile($dir . self::$currentLang . '.ini') ?? [];
		}
	}

	/**
	 * What the panel ships of a language: its bundled file, and the keys of
	 * the modules' files of it that the bundled one lacks.
	 *
	 * @param string $lang Language code
	 * @return array<string, string>|null null when neither has the language
	 */
	public static function shipped(string $lang): ?array {
		$shipped = self::readFile(self::$langsDir . $lang . '.ini');
		foreach (self::$moduleDirs as $dir) {
			$module = self::readFile($dir . $lang . '.ini');
			if ($module !== null) {
				$shipped = ($shipped ?? []) + $module;
			}
		}

		return $shipped;
	}

	/**
	 * Whether a value read from a language file translates its key. A value that
	 * only repeats the English text, or the key itself, is what an exported file
	 * holds for a key it lacks (and what the runtime once wrote for one), and is
	 * no translation.
	 */
	public static function translates(string $key, string $value): bool {
		return $value !== '' && $value !== $key && $value !== (self::englishTranslations()[$key] ?? null);
	}

	/**
	 * A language as the panel shows it: what it ships (shipped()) under its custom file.
	 *
	 * @param string $lang Language code
	 * @return array<string, string>|null null when no directory has the language
	 */
	public static function load(string $lang): ?array {
		$shipped = self::shipped($lang);
		$custom = self::customDir() !== '' ? self::readFile(self::customDir() . $lang . '.ini') : null;

		return $custom === null ? $shipped : $custom + ($shipped ?? []);
	}

	/**
	 * @return array<string, string>|null Parsed .ini file, null when it cannot be read
	 */
	public static function readFile(string $file): ?array {
		$data = is_readable($file) ? @parse_ini_file($file, false, INI_SCANNER_RAW) : false;

		return ($data !== false) ? $data : null;
	}

	/**
	 * Scan the bundled and the custom directory for .ini files.
	 *
	 * @return string[]
	 */
	public static function scanAvailableLanguages(): array {
		$languages = [];
		$files = glob(self::$langsDir . '*.ini') ?: [];
		if (self::customDir() !== '') {
			$files = array_merge($files, glob(self::customDir() . '*.ini') ?: []);
		}

		foreach ($files as $file) {
			if (is_file($file) && is_readable($file)) {
				$languages[] = pathinfo($file, PATHINFO_FILENAME);
			}
		}

		$languages = array_values(array_unique($languages));
		if ($languages === []) {
			return ['en'];
		}

		return $languages;
	}

	/**
	 * Load a language (load()) into $translations. Falls back to en.ini.
	 *
	 * @param string $lang Language code
	 */
	private static function loadLanguage(string $lang): void {
		$data = self::load($lang);

		if ($data === null) {
			// The requested file is gone — fall back to English and report it as
			// the active language so current() never lies about what is loaded.
			self::$currentLang = 'en';
			$data = self::load('en');
		}

		self::$translations = $data ?? [];
	}

	/**
	 * Resolve a key that is absent from the current language.
	 *
	 * @param string $key Translation key
	 * @return string English value if known, otherwise the key itself: a visible mark of an undefined string
	 */
	private static function resolveMissingKey(string $key): string {
		return self::englishTranslations()[$key] ?? $key;
	}

	/**
	 * Read the shipped English (en.ini and the modules' en.ini) once per request
	 * and cache it — every missing-key lookup would otherwise re-parse the whole
	 * file from disk.
	 *
	 * @return array<string, string>
	 */
	public static function englishTranslations(): array {
		return self::$enTranslations ??= self::shipped('en') ?? [];
	}
}
