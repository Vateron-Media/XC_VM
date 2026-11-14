<?php

declare(strict_types=1);

namespace XcVm\Domain\Epg;

/**
 * Normalizes channel names for read-only EPG search ranking.
 *
 * @package XC_VM_Domain_Epg
 */
final class EpgSearchProfile {
	private const COUNTRIES = [
		'de' => 'de', 'deu' => 'de', 'ger' => 'de', 'germany' => 'de', 'deutschland' => 'de',
		'at' => 'at', 'aut' => 'at', 'austria' => 'at', 'osterreich' => 'at',
		'ch' => 'ch', 'che' => 'ch', 'sui' => 'ch', 'switzerland' => 'ch', 'schweiz' => 'ch',
		'rs' => 'rs', 'sr' => 'rs', 'srb' => 'rs', 'serbia' => 'rs', 'serbien' => 'rs', 'srbija' => 'rs',
		'ba' => 'ba', 'bh' => 'ba', 'bih' => 'ba', 'bosnia' => 'ba', 'bosnien' => 'ba', 'bosna' => 'ba',
		'hr' => 'hr', 'hrv' => 'hr', 'cro' => 'hr', 'croatia' => 'hr', 'kroatien' => 'hr', 'hrvatska' => 'hr',
		'gb' => 'gb', 'uk' => 'gb', 'gbr' => 'gb', 'eng' => 'gb', 'us' => 'us', 'usa' => 'us',
		'fr' => 'fr', 'fra' => 'fr', 'it' => 'it', 'ita' => 'it', 'italia' => 'it',
		'es' => 'es', 'esp' => 'es', 'pt' => 'pt', 'por' => 'pt', 'nl' => 'nl', 'nld' => 'nl',
		'be' => 'be', 'bel' => 'be', 'tr' => 'tr', 'tur' => 'tr', 'gr' => 'gr', 'gre' => 'gr',
		'pl' => 'pl', 'pol' => 'pl', 'ro' => 'ro', 'rou' => 'ro', 'bg' => 'bg', 'bgr' => 'bg',
		'cz' => 'cz', 'cze' => 'cz', 'sk' => 'sk', 'svk' => 'sk', 'si' => 'si', 'slo' => 'si', 'svn' => 'si',
		'me' => 'me', 'mne' => 'me', 'mk' => 'mk', 'mkd' => 'mk', 'al' => 'al', 'alb' => 'al',
		'hu' => 'hu', 'hun' => 'hu', 'fi' => 'fi', 'fin' => 'fi', 'ie' => 'ie', 'irl' => 'ie', 'ru' => 'ru', 'rus' => 'ru', 'ua' => 'ua', 'ukr' => 'ua',
		'ca' => 'ca', 'can' => 'ca', 'au' => 'au', 'aus' => 'au', 'br' => 'br', 'bra' => 'br', 'mx' => 'mx', 'mex' => 'mx', 'il' => 'il', 'isr' => 'il',
		'qa' => 'qa', 'qat' => 'qa', 'ae' => 'ae', 'are' => 'ae', 'sa' => 'sa', 'sau' => 'sa', 'eg' => 'eg', 'egy' => 'eg', 'in' => 'in', 'ind' => 'in', 'pk' => 'pk', 'pak' => 'pk',
		'sw' => 'se', 'swiss' => 'ch', 'suisse' => 'ch', 'poland' => 'pl', 'polska' => 'pl', 'polen' => 'pl',
		'belgium' => 'be', 'belgique' => 'be', 'belgie' => 'be', 'norway' => 'no', 'norge' => 'no', 'norwegen' => 'no',
		'sweden' => 'se', 'sverige' => 'se', 'svedska' => 'se', 'romania' => 'ro', 'romanija' => 'ro', 'portugal' => 'pt', 'france' => 'fr',
		'turkey' => 'tr', 'turkiye' => 'tr', 'holland' => 'nl', 'netherlands' => 'nl', 'nederland' => 'nl', 'canada' => 'ca', 'italy' => 'it',
		'se' => 'se', 'swe' => 'se', 'no' => 'no', 'nor' => 'no', 'dk' => 'dk', 'dnk' => 'dk',
	];
	private const NOISE = ['hd', 'fhd', 'uhd', 'sd', 'hq', 'lq', '4k', '8k', 'hevc', 'h264', 'h265', '1080p', '1080i', '720p', '50fps', '60fps', 'live', 'event', 'events', 'backup', 'vip', 'raw'];

	/**
	 * Keep brand, service numbers, plus/timeshift and named variants intact.
	 *
	 * @param string $name
	 * @return array
	 */
	public static function profile(string $name): array {
		$name = preg_replace('/\bex[\s_-]*yu\b/i', ' ', $name);
		$name = preg_replace('/^(?:mu|music)\s*:\s*/i', '', $name);
		$name = preg_replace('/\bbein/i', 'bein', $name);
		$name = preg_replace('/[\[(]\s*multi[\s_-]*sub\s*[\])]/i', ' ', $name);
		$name = preg_replace('/[\[(]\s*event\s+only\s*[\])]/i', ' ', $name);
		$name = preg_replace('/\bvodaf(?:on|one)\b/i', ' ', $name);
		$name = preg_replace('/\bstream\s+[a-z]\b/i', ' ', $name);
		$name = str_replace(['+', '&'], [' plus ', ' and '], $name);
		$name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
		$name = mb_strtolower($name, 'UTF-8');
		$name = strtr($name, ['đ' => 'd', 'ð' => 'd', 'ß' => 'ss', 'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ђ' => 'dj', 'е' => 'e', 'ж' => 'z', 'з' => 'z', 'и' => 'i', 'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'lj', 'м' => 'm', 'н' => 'n', 'њ' => 'nj', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'ћ' => 'c', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'c', 'џ' => 'dz', 'ш' => 's']);
		$name = strtr($name, ['ä' => 'a','ö' => 'o','ü' => 'u','á' => 'a','à' => 'a','â' => 'a','ã' => 'a','å' => 'a','é' => 'e','è' => 'e','ê' => 'e','ë' => 'e','í' => 'i','ì' => 'i','î' => 'i','ï' => 'i','ó' => 'o','ò' => 'o','ô' => 'o','õ' => 'o','ø' => 'o','ú' => 'u','ù' => 'u','û' => 'u','ç' => 'c','ć' => 'c','č' => 'c','š' => 's','ž' => 'z','ñ' => 'n','ń' => 'n','ł' => 'l','ý' => 'y','ř' => 'r','ě' => 'e']);
		// These brands contain country words; they are identities, not regional tags.
		// Austria is part of this sports brand, not a removable catalog-country tag.
		$brandCountry = preg_match('/\bsky\s*sport\s*austria(?=\s|\d|$)/', $name) ? 'at' : '';
		$name = preg_replace('/\bsky\s*sport\s*austria(?=\s|\d|$)/', 'skysportaustria', $name);
		$name = preg_replace('/\bfrance\s*24\b/', 'france24', $name);
		$name = preg_replace('/\busa\s+network\b/', 'usanetwork', $name);
		$name = preg_replace('/\b(\d+)(hd|fhd|uhd)\b/', '$1 $2', $name);
		$name = preg_replace('/\b(hd|fhd|uhd)(\d+)\b/', '$1 $2', $name);
		$name = preg_replace('/\bsk\s*(?=\d)/', 'sport klub ', $name);
		$name = preg_replace('/\bh[ ._-]?(264|265)\b/', ' h$1 ', $name);
		$name = preg_replace('/\b(?:720|1080|2160)[pi](?:25|30|50|60)?\b/', ' ', $name);
		$name = preg_replace('/\b(?:25|30|50|60)\s*fps\b/', ' ', $name);
		$name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
		$tokens = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
		$countries = [];
		$identity = [];
		foreach ($tokens as $token) {
			if (isset(self::COUNTRIES[$token])) {
				$countries[] = self::COUNTRIES[$token];
			} elseif (!in_array($token, self::NOISE, true)) {
				$identity[] = $token;
			}
		}
		$identity = implode(' ', $identity);
		$identity = strtr($identity, ['national geographic' => 'nat geo', 'discovery channel' => 'discovery']);
		$identity = preg_replace('/\brtl\s+ii\b/', 'rtl 2', $identity);
		$identity = preg_replace('/^sk\s*(\d+)/', 'sport klub $1', $identity);
		$identity = preg_replace('/^orf\s+eins\b/', 'orf 1', $identity);
		$identity = preg_replace('/^bbc\s+one\b/', 'bbc 1', $identity);
		$identity = preg_replace('/^bbc\s+two\b/', 'bbc 2', $identity);
		$identity = preg_replace('/(?<=\w)\s+tv$/', '', $identity);
		$key = str_replace(' ', '', $identity);
		$key = ['ard' => 'daserste', 'prosieben' => 'pro7'][$key] ?? $key;
		preg_match_all('/\p{N}+/u', $key, $numbers);
		return ['key' => $key, 'country' => $brandCountry !== '' ? $brandCountry : ($countries[0] ?? ''), 'numbers' => $numbers[0], 'sport' => (bool) preg_match('/sport|arena|espn|dazn|bein|premier(?!e)|ligue|bundesliga|football|futbol|fussball|tennis|golf|racing|motogp|f1/', $key)];
	}
}
