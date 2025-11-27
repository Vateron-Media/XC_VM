<?php

/**
 * Translator PHP class - multilingual support system with auto-key generation
 *
 * @package VateronMedia_Translator
 * @author Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025 Vateron Media
 * @link https://github.com/Vateron-Media/XC_VM
 * @version 1.0.0
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 *
 * A PHP class created specifically for the XC_VM project to provide multilingual support.
 * Features automatic translation key generation, INI file management, language switching,
 * and missing key auto-creation with file locking for concurrent access.
 */

class Translator {
    /** @var array<string, string> Currently loaded translations */
    private static array $translations = [];

    /** @var string Current language */
    private static string $currentLang = 'en';

    /** @var string Path to translations folder (with trailing slash) */
    private static string $langsDir = __DIR__ . '/lang/';

    /** @var array<string> Cache of available languages */
    private static array $availableLanguages = [];

    /**
     * Initialize translator
     *
     * @param string|null $langsDir Path to .ini files folder (e.g., /var/www/lang/)
     */
    public static function init(?string $langsDir = null): void {
        if ($langsDir !== null) {
            self::$langsDir = rtrim($langsDir, '/') . '/';
        }

        // Get and cache list of languages
        self::$availableLanguages = self::scanAvailableLanguages();

        // Detect user language
        $requestedLang = $_COOKIE['lang'] ?? 'en';
        self::$currentLang = in_array($requestedLang, self::$availableLanguages)
            ? $requestedLang
            : 'en';

        self::loadLanguage(self::$currentLang);
    }

    /**
     * Change language at runtime + update cookie
     */
    public static function setLanguage(string $lang): bool {
        if (!in_array($lang, self::$availableLanguages)) {
            return false;
        }

        self::$currentLang = $lang;
        self::loadLanguage($lang);

        // Cookie for one year
        setcookie('lang', $lang, time() + 365 * 24 * 3600, '/');

        return true;
    }

    /**
     * Get translation by key
     * If key doesn't exist — it will be automatically added to all language files
     */
    public static function get(string $key, array $replace = []): string {
        $text = self::$translations[$key] ?? null;

        if ($text === null) {
            self::addMissingKeyToAllLanguages($key);
            $text = $key; // fallback to the key itself
            // Update current loaded array so subsequent calls see the key immediately
            self::$translations[$key] = $key;
        }

        return !empty($replace) ? strtr($text, $replace) : $text;
    }

    /**
     * Current language
     */
    public static function current(): string {
        return self::$currentLang;
    }

    /**
     * All available languages
     */
    public static function available(): array {
        return self::$availableLanguages;
    }

    // ═════════════════════════════════════════════════════════════════
    // Internal methods
    // ═════════════════════════════════════════════════════════════════

    private static function scanAvailableLanguages(): array {
        $languages = [];
        $files = glob(self::$langsDir . '*.ini');

        foreach ($files as $file) {
            if (is_file($file) && is_readable($file)) {
                $code = pathinfo($file, PATHINFO_FILENAME);
                // Optionally filter by valid language codes if needed
                $languages[] = $code;
            }
        }

        // If nothing found at all — guarantee at least en
        $languages = array_unique($languages);
        if (empty($languages)) {
            $languages = ['en'];
        }

        return $languages;
    }

    private static function loadLanguage(string $lang): void {
        $file = self::$langsDir . $lang . '.ini';

        // If current language file is not readable — fallback to en
        if (!is_readable($file)) {
            $file = self::$langsDir . 'en.ini';
        }

        $data = parse_ini_file($file, false, INI_SCANNER_RAW);
        self::$translations = ($data !== false) ? $data : [];
    }

    private static function addMissingKeyToAllLanguages(string $key): void {
        $lineToAdd = "\n{$key} = \"{$key}\"\n";

        foreach (self::$availableLanguages as $lang) {
            $file = self::$langsDir . $lang . '.ini';

            // If file doesn't exist — create empty one
            if (!file_exists($file)) {
                file_put_contents($file, "; {$lang} language file\n");
            }

            // Check if key already exists
            $content = file_get_contents($file);
            if (str_contains($content, "\n{$key} =") || str_contains($content, "\r{$key} =")) {
                continue; // key already exists
            }

            // Safe write with locking
            $fp = fopen($file, 'a');
            if ($fp && flock($fp, LOCK_EX)) {
                fwrite($fp, $lineToAdd);
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }
}




// // At the beginning of the application (once)
// Translator::init('/path/to/your/lang/'); // or just Translator::init(); if lang folder is nearby

// // Anywhere in the code
// echo Translator::get('welcome_title');
// echo Translator::get('users_online', [':count' => 153]);

// // Change language
// Translator::setLanguage('de');

// // Utility methods
// echo Translator::current();     // → "de"
// print_r(Translator::available()); // → ['en', 'ru', 'de', 'fr', ...]