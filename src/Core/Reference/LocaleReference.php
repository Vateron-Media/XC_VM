<?php

declare(strict_types=1);

namespace XcVm\Core\Reference;

/**
 * Static locale reference data (TMDb language list) for admin UI.
 *
 * Replaces the legacy `$rTMDBLanguages` global from
 * resources/data/admin_constants.php with a typed accessor.
 *
 * @package XC_VM_Core_Reference
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class LocaleReference {
    /** ISO 639-1 language code => display name (leading "Default - EN"). */
    private const TMDB_LANGUAGES = array('' => 'Default - EN', 'aa' => 'Afar', 'af' => 'Afrikaans', 'ak' => 'Akan', 'an' => 'Aragonese', 'as' => 'Assamese', 'av' => 'Avaric', 'ae' => 'Avestan', 'ay' => 'Aymara', 'az' => 'Azerbaijani', 'ba' => 'Bashkir', 'bm' => 'Bambara', 'bi' => 'Bislama', 'bo' => 'Tibetan', 'br' => 'Breton', 'ca' => 'Catalan', 'cs' => 'Czech', 'ce' => 'Chechen', 'cu' => 'Slavic', 'cv' => 'Chuvash', 'kw' => 'Cornish', 'co' => 'Corsican', 'cr' => 'Cree', 'cy' => 'Welsh', 'da' => 'Danish', 'de' => 'German', 'dv' => 'Divehi', 'dz' => 'Dzongkha', 'eo' => 'Esperanto', 'et' => 'Estonian', 'eu' => 'Basque', 'fo' => 'Faroese', 'fj' => 'Fijian', 'fi' => 'Finnish', 'fr' => 'French', 'fy' => 'Frisian', 'ff' => 'Fulah', 'gd' => 'Gaelic', 'ga' => 'Irish', 'gl' => 'Galician', 'gv' => 'Manx', 'gn' => 'Guarani', 'gu' => 'Gujarati', 'ht' => 'Haitian', 'ha' => 'Hausa', 'sh' => 'Serbo-Croatian', 'hz' => 'Herero', 'ho' => 'Hiri Motu', 'hr' => 'Croatian', 'hu' => 'Hungarian', 'ig' => 'Igbo', 'io' => 'Ido', 'ii' => 'Yi', 'iu' => 'Inuktitut', 'ie' => 'Interlingue', 'ia' => 'Interlingua', 'id' => 'Indonesian', 'ik' => 'Inupiaq', 'is' => 'Icelandic', 'it' => 'Italian', 'ja' => 'Japanese', 'kl' => 'Kalaallisut', 'kn' => 'Kannada', 'ks' => 'Kashmiri', 'kr' => 'Kanuri', 'kk' => 'Kazakh', 'km' => 'Khmer', 'ki' => 'Kikuyu', 'rw' => 'Kinyarwanda', 'ky' => 'Kirghiz', 'kv' => 'Komi', 'kg' => 'Kongo', 'ko' => 'Korean', 'kj' => 'Kuanyama', 'ku' => 'Kurdish', 'lo' => 'Lao', 'la' => 'Latin', 'lv' => 'Latvian', 'li' => 'Limburgish', 'ln' => 'Lingala', 'lt' => 'Lithuanian', 'lb' => 'Letzeburgesch', 'lu' => 'Luba-Katanga', 'lg' => 'Ganda', 'mh' => 'Marshall', 'ml' => 'Malayalam', 'mr' => 'Marathi', 'mg' => 'Malagasy', 'mt' => 'Maltese', 'mo' => 'Moldavian', 'mn' => 'Mongolian', 'mi' => 'Maori', 'ms' => 'Malay', 'my' => 'Burmese', 'na' => 'Nauru', 'nv' => 'Navajo', 'nr' => 'Ndebele', 'nd' => 'Ndebele', 'ng' => 'Ndonga', 'ne' => 'Nepali', 'nl' => 'Dutch', 'nn' => 'Norwegian Nynorsk', 'nb' => 'Norwegian Bokmal', 'no' => 'Norwegian', 'ny' => 'Chichewa', 'oc' => 'Occitan', 'oj' => 'Ojibwa', 'or' => 'Oriya', 'om' => 'Oromo', 'os' => 'Ossetian; Ossetic', 'pi' => 'Pali', 'pl' => 'Polish', 'pt' => 'Portuguese', 'pt-BR' => 'Portuguese - Brazil', 'qu' => 'Quechua', 'rm' => 'Raeto-Romance', 'ro' => 'Romanian', 'rn' => 'Rundi', 'ru' => 'Russian', 'sg' => 'Sango', 'sa' => 'Sanskrit', 'si' => 'Sinhalese', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'se' => 'Northern Sami', 'sm' => 'Samoan', 'sn' => 'Shona', 'sd' => 'Sindhi', 'so' => 'Somali', 'st' => 'Sotho', 'es' => 'Spanish', 'sq' => 'Albanian', 'sc' => 'Sardinian', 'sr' => 'Serbian', 'ss' => 'Swati', 'su' => 'Sundanese', 'sw' => 'Swahili', 'sv' => 'Swedish', 'ty' => 'Tahitian', 'ta' => 'Tamil', 'tt' => 'Tatar', 'te' => 'Telugu', 'tg' => 'Tajik', 'tl' => 'Tagalog', 'th' => 'Thai', 'ti' => 'Tigrinya', 'to' => 'Tonga', 'tn' => 'Tswana', 'ts' => 'Tsonga', 'tk' => 'Turkmen', 'tr' => 'Turkish', 'tw' => 'Twi', 'ug' => 'Uighur', 'uk' => 'Ukrainian', 'ur' => 'Urdu', 'uz' => 'Uzbek', 've' => 'Venda', 'vi' => 'Vietnamese', 'vo' => 'Volapük', 'wa' => 'Walloon', 'wo' => 'Wolof', 'xh' => 'Xhosa', 'yi' => 'Yiddish', 'za' => 'Zhuang', 'zu' => 'Zulu', 'ab' => 'Abkhazian', 'zh' => 'Mandarin', 'ps' => 'Pushto', 'am' => 'Amharic', 'ar' => 'Arabic', 'bg' => 'Bulgarian', 'cn' => 'Cantonese', 'mk' => 'Macedonian', 'el' => 'Greek', 'fa' => 'Persian', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hy' => 'Armenian', 'en' => 'English', 'ee' => 'Ewe', 'ka' => 'Georgian', 'pa' => 'Punjabi', 'bn' => 'Bengali', 'bs' => 'Bosnian', 'ch' => 'Chamorro', 'be' => 'Belarusian', 'yo' => 'Yoruba');

    /**
     * ISO 639-1 language code => display name for TMDb metadata language
     * selectors (leading empty key = "Default - EN").
     *
     * @return array<string, string>
     */
    public static function tmdbLanguages(): array {
        return self::TMDB_LANGUAGES;
    }
}
