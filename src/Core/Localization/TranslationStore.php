<?php

namespace XcVm\Core\Localization;

use XcVm\Core\Util\AtomicFile;

/**
 * TranslationStore — the custom translations of the panel (Settings > Translations).
 *
 * What an administrator uploads or edits is kept in the Translator's custom
 * directory (config/lang/<code>.ini), never in the bundled one: config/ is
 * outside the code, so an update keeps it. A custom file holds only what
 * differs from the bundled file of its language, and the Translator reads it
 * over that file.
 *
 * An uploaded file and an edited value are not the panel's own text, and views
 * print a translation as HTML: every value is stored through sanitise(), a
 * language code is the only source of a file name, and only the keys en.ini
 * has are kept.
 *
 * MAIN only: a load balancer has no panel, and no bundled language files.
 *
 * @package XC_VM_Core_Localization
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class TranslationStore {
	/** The largest file import() takes. */
	public const MAX_BYTES = 1048576;

	/** The tags a value keeps, bare (no attribute); `<br/>` and `<br />` are stored as `<br>`. */
	private const TAGS = '~(</?(?:b|i|strong|em|code)>|<br\s*/?>)~i';

	/** A language code, and so a file name: `tr`, `fil`, `pt-BR`. */
	public static function validCode(string $rCode): bool {
		// D: without it `$` would also match before a trailing line feed.
		return $rCode === 'exyu' || preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/D', $rCode) === 1;
	}

	/**
	 * The languages the panel offers, by code: how many of en.ini's keys each
	 * translates, whether the panel ships it, and how many of those translations
	 * are custom ones.
	 *
	 * @return array<string, array{translated: int, bundled: bool, custom: int}>
	 */
	public static function languages(): array {
		$rLanguages = [];
		foreach (Translator::scanAvailableLanguages() as $rCode) {
			if (self::validCode($rCode)) {
				$rValues = self::values($rCode);
				$rLanguages[$rCode] = [
					'translated' => count($rValues),
					'bundled' => is_file(Translator::bundledDir() . $rCode . '.ini'),
					'custom' => count(array_intersect_assoc($rValues, self::custom($rCode))),
				];
			}
		}
		ksort($rLanguages);
		return $rLanguages;
	}

	/**
	 * What a language translates of en.ini's keys, its bundled file under its
	 * custom one. A value that only repeats the English text is no translation
	 * (Translator::translates()), as for `make lang-translate`; English itself
	 * has every key.
	 *
	 * @return array<string, string> key => translation
	 */
	public static function values(string $rCode): array {
		$rValues = array_intersect_key(self::validCode($rCode) ? Translator::load($rCode) ?? [] : [], Translator::englishTranslations());
		if ($rCode === 'en') {
			return $rValues;
		}
		return array_filter($rValues, static fn($rValue, $rKey): bool => is_string($rValue) && Translator::translates((string) $rKey, $rValue), ARRAY_FILTER_USE_BOTH);
	}

	/**
	 * A value as it is stored, safe to print as HTML: one line, the bare tags
	 * of TAGS kept, and every other `<`, `>`, `&` and `"` as an entity (an
	 * entity already there is left alone, so `&amp;` stays `&amp;`).
	 * strip_tags() would keep the attributes of a tag it allows.
	 *
	 * Two characters mean something inside a script string, where some views
	 * print a translation: the backslash is stored as an entity, and the
	 * apostrophe as the typographic one, which no toast or dialog shows as
	 * `&#039;`. `{placeholders}` are plain text and stay as they are.
	 */
	public static function sanitise(string $rValue): string {
		$rValue = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $rValue));
		$rParts = preg_split(self::TAGS, $rValue, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
		foreach ($rParts as $rIndex => $rPart) {
			// preg_split() puts the tags it split on at the odd indexes.
			if ($rIndex % 2 === 1) {
				$rParts[$rIndex] = stripos($rPart, '<br') === 0 ? '<br>' : strtolower($rPart);
			} else {
				$rParts[$rIndex] = strtr(htmlspecialchars($rPart, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8', false), ["'" => '’', '\\' => '&#92;']);
			}
		}
		return implode('', $rParts);
	}

	/**
	 * Store translations of a language: an upload's, or the editor's.
	 *
	 * Each value is sanitised and kept when it translates a key of en.ini and
	 * the bundled file does not say the same; a value that is empty, or the
	 * English text again, takes the key's custom translation away. Keys not
	 * named keep what they have. A language left with nothing loses its file.
	 *
	 * @param array<array-key, mixed> $rValues key => translation
	 * @return array{result: bool, error?: string, saved?: int} `saved`: how many of $rValues are custom translations now
	 */
	public static function save(string $rCode, array $rValues): array {
		if (!self::validCode($rCode)) {
			return ['result' => false, 'error' => 'code'];
		}
		$rEnglish = Translator::englishTranslations();
		$rValues = array_intersect_key($rValues, $rEnglish);
		if ($rValues === []) {
			return ['result' => false, 'error' => 'empty'];
		}
		$rDir = Translator::customDir();
		if ($rDir === '' || (!is_dir($rDir) && !@mkdir($rDir, 0755, true) && !is_dir($rDir))) {
			return ['result' => false, 'error' => 'write'];
		}

		// One writer at a time: a save applies its values to the file it read.
		$rLock = @fopen($rDir . '.lock', 'c');
		if ($rLock === false || !flock($rLock, LOCK_EX)) {
			return ['result' => false, 'error' => 'write'];
		}
		try {
			$rFile = $rDir . $rCode . '.ini';
			$rBundled = Translator::readFile(Translator::bundledDir() . $rCode . '.ini') ?? [];
			$rCustom = array_replace(self::custom($rCode), $rValues);

			// The whole file again, in en.ini's order: what was there is sanitised
			// as well, and a line that is no translation (added by hand) goes.
			$rKept = [];
			foreach (array_intersect_key($rEnglish, $rCustom) as $rKey => $rUnused) {
				$rValue = $rCustom[$rKey];
				// As it came: the English text again is no translation whatever sanitising makes
				// of it, and the bundled file's own value is the panel's text, which needs no copy.
				if (!is_string($rValue) || !Translator::translates((string) $rKey, $rValue) || $rValue === ($rBundled[$rKey] ?? null)) {
					continue;
				}
				$rValue = self::sanitise($rValue);
				if (Translator::translates((string) $rKey, $rValue) && $rValue !== ($rBundled[$rKey] ?? null)) {
					$rKept[$rKey] = $rValue;
				}
			}

			if ($rKept === []) {
				if (is_file($rFile)) {
					return @unlink($rFile) ? ['result' => true, 'saved' => 0] : ['result' => false, 'error' => 'write'];
				}
				// Nothing to keep and nothing kept before: for a language the panel does not ship, no language at all.
				return $rBundled !== [] ? ['result' => true, 'saved' => 0] : ['result' => false, 'error' => 'empty'];
			}

			$rText = "; Custom translations of the panel (Settings > Translations), read over the bundled {$rCode}.ini.\n[Language]\n";
			foreach ($rKept as $rKey => $rValue) {
				$rText .= self::line((string) $rKey, $rValue);
			}
			if (!AtomicFile::write($rFile, $rText, 0644)) {
				return ['result' => false, 'error' => 'write'];
			}
			return ['result' => true, 'saved' => count(array_intersect_key($rKept, $rValues))];
		} finally {
			flock($rLock, LOCK_UN);
			fclose($rLock);
		}
	}

	/**
	 * Store an uploaded .ini file as translations of a language (save()).
	 *
	 * @return array{result: bool, error?: string, line?: int, saved?: int}
	 */
	public static function import(string $rCode, string $rText): array {
		if (!self::validCode($rCode)) {
			return ['result' => false, 'error' => 'code'];
		}
		if (strlen($rText) > self::MAX_BYTES) {
			return ['result' => false, 'error' => 'size'];
		}
		// A byte order mark (Notepad writes one) would be read as part of the first line.
		// INI_SCANNER_RAW: no constant and no ${variable} of the panel is expanded into a value.
		error_clear_last();
		$rValues = @parse_ini_string((string) preg_replace('/^\xEF\xBB\xBF/', '', $rText), false, INI_SCANNER_RAW);
		if (!is_array($rValues)) {
			preg_match('/on line (\d+)/', (string) (error_get_last()['message'] ?? ''), $rLine);
			return ['result' => false, 'error' => 'parse', 'line' => (int) ($rLine[1] ?? 0)];
		}
		return self::save($rCode, $rValues);
	}

	/**
	 * A language as an .ini file, in en.ini's order: its translation of a key,
	 * and the English text for a key it does not translate. `en` is the
	 * template to translate.
	 *
	 * @return string|null null: the panel has no such language
	 */
	public static function export(string $rCode): ?string {
		if (!self::validCode($rCode) || !in_array($rCode, Translator::scanAvailableLanguages(), true)) {
			return null;
		}
		$rText = "[Language]\n";
		foreach (array_replace(Translator::englishTranslations(), self::values($rCode)) as $rKey => $rValue) {
			$rText .= self::line((string) $rKey, (string) $rValue);
		}
		return $rText;
	}

	/**
	 * A line of a language file, read back as it is written: INI_SCANNER_RAW
	 * takes the value from the first quote to the last and unescapes nothing,
	 * so a quote, a backslash, `;` and `=` inside need no escape (a backslash
	 * before a quote would be read back as part of the value).
	 */
	private static function line(string $rKey, string $rValue): string {
		return $rKey . ' = "' . $rValue . "\"\n";
	}

	/** @return array<array-key, mixed> The entries of a language's custom file; none when it has no such file. */
	private static function custom(string $rCode): array {
		return Translator::customDir() === '' ? [] : Translator::readFile(Translator::customDir() . $rCode . '.ini') ?? [];
	}
}
