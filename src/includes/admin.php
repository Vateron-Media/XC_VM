<?php

if (session_status() == PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
	$rParams = (session_get_cookie_params() ?: array());
	$rParams['samesite'] = 'Strict';
	session_set_cookie_params($rParams);
	session_start();
}

define('STATUS_FAILURE', 0);
define('STATUS_SUCCESS', 1);
define('STATUS_SUCCESS_MULTI', 2);
define('STATUS_CODE_LENGTH', 3);
define('STATUS_NO_SOURCES', 4);
define('STATUS_DISABLED', 5);
define('STATUS_NOT_ADMIN', 6);
define('STATUS_INVALID_EMAIL', 7);
define('STATUS_INVALID_PASSWORD', 8);
define('STATUS_INVALID_IP', 9);
define('STATUS_INVALID_PLAYLIST', 10);
define('STATUS_INVALID_NAME', 11);
define('STATUS_INVALID_CAPTCHA', 12);
define('STATUS_INVALID_CODE', 13);
define('STATUS_INVALID_DATE', 14);
define('STATUS_INVALID_FILE', 15);
define('STATUS_INVALID_GROUP', 16);
define('STATUS_INVALID_DATA', 17);
define('STATUS_INVALID_DIR', 18);
define('STATUS_INVALID_MAC', 19);
define('STATUS_EXISTS_CODE', 20);
define('STATUS_EXISTS_NAME', 21);
define('STATUS_EXISTS_USERNAME', 22);
define('STATUS_EXISTS_MAC', 23);
define('STATUS_EXISTS_SOURCE', 24);
define('STATUS_EXISTS_IP', 25);
define('STATUS_EXISTS_DIR', 26);
define('STATUS_SUCCESS_REPLACE', 27);
define('STATUS_FLUSH', 28);
define('STATUS_TOO_MANY_RESULTS', 29);
define('STATUS_SPACE_ISSUE', 30);
define('STATUS_INVALID_USER', 31);
define('STATUS_CERTBOT', 32);
define('STATUS_CERTBOT_INVALID', 33);
define('STATUS_INVALID_INPUT', 34);
define('STATUS_NOT_RESELLER', 35);
define('STATUS_NO_TRIALS', 36);
define('STATUS_INSUFFICIENT_CREDITS', 37);
define('STATUS_INVALID_PACKAGE', 38);
define('STATUS_INVALID_TYPE', 39);
define('STATUS_INVALID_USERNAME', 40);
define('STATUS_INVALID_SUBRESELLER', 41);
define('STATUS_NO_DESCRIPTION', 42);
define('STATUS_NO_KEY', 43);
define('STATUS_EXISTS_HMAC', 44);
define('STATUS_CERTBOT_RUNNING', 45);
define('STATUS_RESERVED_CODE', 46);
define('STATUS_NO_TITLE', 47);
define('STATUS_NO_SOURCE', 48);
require_once '/home/xc_vm/www/constants.php';
require_once MAIN_HOME . 'core/Database/DatabaseHandler.php';
require_once INCLUDES_PATH . 'CoreUtilities.php';
require_once INCLUDES_PATH . 'libs/mobiledetect.php';
require_once INCLUDES_PATH . 'libs/Translator.php';
require_once INCLUDES_PATH . 'admin_api.php';
require_once INCLUDES_PATH . 'reseller_api.php';
register_shutdown_function('shutdown_admin');
$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
CoreUtilities::$db = &$db;
CoreUtilities::init();
API::$db = &$db;
API::init();
ResellerAPI::$db = &$db;
ResellerAPI::init();
CoreUtilities::connectRedis();
if (defined('SERVER_ID') === false) {
	define('SERVER_ID', intval(CoreUtilities::$rConfig['server_id']));
}
$rDetect = new Mobile_Detect();
$rMobile = $rDetect->isMobile();
$rTimeout = 15;
$rSQLTimeout = 10;
set_time_limit($rTimeout);
ini_set('mysql.connect_timeout', $rSQLTimeout);
ini_set('max_execution_time', $rTimeout);
ini_set('default_socket_timeout', $rTimeout);
$rProtocol = getProtocol();
$allServers = getAllServers();
$rServers = getStreamingServers();
$rSettings = CoreUtilities::$rSettings;
$rProxyServers = getProxyServers();

// Multilingual support
$language = Translator::class;
$language::init(MAIN_HOME . 'includes/langs/');
$allowedLangs = $language::available();


uasort(
	$rServers,
	function ($a, $b) {
		return $a['order'] - $b['order'];
	}
);

$rMAGs = array('AuraHD', 'AuraHD2', 'AuraHD3', 'AuraHD4', 'AuraHD5', 'AuraHD6', 'AuraHD7', 'AuraHD8', 'AuraHD9', 'MAG200', 'MAG245', 'MAG245D', 'MAG250', 'MAG254', 'MAG255', 'MAG256', 'MAG257', 'MAG260', 'MAG270', 'MAG275', 'MAG322', 'MAG323', 'MAG324', 'MAG325', 'MAG349', 'MAG350', 'MAG351', 'MAG352', 'MAG420', 'WR320', 'TH100', 'MAG424', 'MAG424W3');
$rCountryCodes = array('AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa', 'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica', 'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda', 'BT' => 'Bhutan', 'BO' => 'Bolivia (Plurinational State of)', 'BQ' => 'Bonaire, Sint Eustatius and Saba', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BV' => 'Bouvet Island', 'BR' => 'Brazil', 'IO' => 'British Indian Ocean Territory', 'BN' => 'Brunei Darussalam', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'CV' => 'Cabo Verde', 'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'KY' => 'Cayman Islands', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CX' => 'Christmas Island', 'CC' => 'Cocos (Keeling) Islands', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CD' => 'Congo (the Democratic Republic of the)', 'CG' => 'Congo', 'CK' => 'Cook Islands', 'CR' => 'Costa Rica', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CW' => 'Curaçao', 'CY' => 'Cyprus', 'CZ' => 'Czechia', 'CI' => "Côte d'Ivoire", 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia', 'FK' => 'Falkland Islands [Malvinas]', 'FO' => 'Faroe Islands', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GF' => 'French Guiana', 'PF' => 'French Polynesia', 'TF' => 'French Southern Territories', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GR' => 'Greece', 'GL' => 'Greenland', 'GD' => 'Grenada', 'GP' => 'Guadeloupe', 'GU' => 'Guam', 'GT' => 'Guatemala', 'GG' => 'Guernsey', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti', 'HM' => 'Heard Island and McDonald Islands', 'VA' => 'Holy See', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran (Islamic Republic of)', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IM' => 'Isle of Man', 'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan', 'JE' => 'Jersey', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KP' => "Korea (the Democratic People's Republic of)", 'KR' => 'Korea (the Republic of)', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => "Lao People's Democratic Republic", 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MO' => 'Macao', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'YT' => 'Mayotte', 'MX' => 'Mexico', 'FM' => 'Micronesia (Federated States of)', 'MD' => 'Moldova (the Republic of)', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MS' => 'Montserrat', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NC' => 'New Caledonia', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NU' => 'Niue', 'NF' => 'Norfolk Island', 'MP' => 'Northern Mariana Islands', 'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestine, State of', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PN' => 'Pitcairn', 'PL' => 'Poland', 'PT' => 'Portugal', 'PR' => 'Puerto Rico', 'QA' => 'Qatar', 'MK' => 'Republic of North Macedonia', 'RO' => 'Romania', 'RU' => 'Russian Federation', 'RW' => 'Rwanda', 'RE' => 'Réunion', 'BL' => 'Saint Barthélemy', 'SH' => 'Saint Helena, Ascension and Tristan da Cunha', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia', 'MF' => 'Saint Martin (French part)', 'PM' => 'Saint Pierre and Miquelon', 'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome and Principe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SX' => 'Sint Maarten (Dutch part)', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'GS' => 'South Georgia and the South Sandwich Islands', 'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SJ' => 'Svalbard and Jan Mayen', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syrian Arab Republic', 'TW' => 'Taiwan (Province of China)', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania, United Republic of', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TK' => 'Tokelau', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'TC' => 'Turks and Caicos Islands', 'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'UM' => 'United States Minor Outlying Islands', 'US' => 'United States of America', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela (Bolivarian Republic of)', 'VN' => 'Viet Nam', 'VG' => 'Virgin Islands (British)', 'VI' => 'Virgin Islands (U.S.)', 'WF' => 'Wallis and Futuna', 'EH' => 'Western Sahara', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe', 'AX' => 'Åland Islands');
$rCountries = array(array('id' => '', 'name' => 'Off'), array('id' => 'A1', 'name' => 'Anonymous Proxy'), array('id' => 'A2', 'name' => 'Satellite Provider'), array('id' => 'O1', 'name' => 'Other Country'), array('id' => 'AF', 'name' => 'Afghanistan'), array('id' => 'AX', 'name' => 'Aland Islands'), array('id' => 'AL', 'name' => 'Albania'), array('id' => 'DZ', 'name' => 'Algeria'), array('id' => 'AS', 'name' => 'American Samoa'), array('id' => 'AD', 'name' => 'Andorra'), array('id' => 'AO', 'name' => 'Angola'), array('id' => 'AI', 'name' => 'Anguilla'), array('id' => 'AQ', 'name' => 'Antarctica'), array('id' => 'AG', 'name' => 'Antigua And Barbuda'), array('id' => 'AR', 'name' => 'Argentina'), array('id' => 'AM', 'name' => 'Armenia'), array('id' => 'AW', 'name' => 'Aruba'), array('id' => 'AU', 'name' => 'Australia'), array('id' => 'AT', 'name' => 'Austria'), array('id' => 'AZ', 'name' => 'Azerbaijan'), array('id' => 'BS', 'name' => 'Bahamas'), array('id' => 'BH', 'name' => 'Bahrain'), array('id' => 'BD', 'name' => 'Bangladesh'), array('id' => 'BB', 'name' => 'Barbados'), array('id' => 'BY', 'name' => 'Belarus'), array('id' => 'BE', 'name' => 'Belgium'), array('id' => 'BZ', 'name' => 'Belize'), array('id' => 'BJ', 'name' => 'Benin'), array('id' => 'BM', 'name' => 'Bermuda'), array('id' => 'BT', 'name' => 'Bhutan'), array('id' => 'BO', 'name' => 'Bolivia'), array('id' => 'BA', 'name' => 'Bosnia And Herzegovina'), array('id' => 'BW', 'name' => 'Botswana'), array('id' => 'BV', 'name' => 'Bouvet Island'), array('id' => 'BR', 'name' => 'Brazil'), array('id' => 'IO', 'name' => 'British Indian Ocean Territory'), array('id' => 'BN', 'name' => 'Brunei Darussalam'), array('id' => 'BG', 'name' => 'Bulgaria'), array('id' => 'BF', 'name' => 'Burkina Faso'), array('id' => 'BI', 'name' => 'Burundi'), array('id' => 'KH', 'name' => 'Cambodia'), array('id' => 'CM', 'name' => 'Cameroon'), array('id' => 'CA', 'name' => 'Canada'), array('id' => 'CV', 'name' => 'Cape Verde'), array('id' => 'KY', 'name' => 'Cayman Islands'), array('id' => 'CF', 'name' => 'Central African Republic'), array('id' => 'TD', 'name' => 'Chad'), array('id' => 'CL', 'name' => 'Chile'), array('id' => 'CN', 'name' => 'China'), array('id' => 'CX', 'name' => 'Christmas Island'), array('id' => 'CC', 'name' => 'Cocos (Keeling) Islands'), array('id' => 'CO', 'name' => 'Colombia'), array('id' => 'KM', 'name' => 'Comoros'), array('id' => 'CG', 'name' => 'Congo'), array('id' => 'CD', 'name' => 'Congo, Democratic Republic'), array('id' => 'CK', 'name' => 'Cook Islands'), array('id' => 'CR', 'name' => 'Costa Rica'), array('id' => 'CI', 'name' => "Cote D'Ivoire"), array('id' => 'HR', 'name' => 'Croatia'), array('id' => 'CU', 'name' => 'Cuba'), array('id' => 'CY', 'name' => 'Cyprus'), array('id' => 'CZ', 'name' => 'Czech Republic'), array('id' => 'DK', 'name' => 'Denmark'), array('id' => 'DJ', 'name' => 'Djibouti'), array('id' => 'DM', 'name' => 'Dominica'), array('id' => 'DO', 'name' => 'Dominican Republic'), array('id' => 'EC', 'name' => 'Ecuador'), array('id' => 'EG', 'name' => 'Egypt'), array('id' => 'SV', 'name' => 'El Salvador'), array('id' => 'GQ', 'name' => 'Equatorial Guinea'), array('id' => 'ER', 'name' => 'Eritrea'), array('id' => 'EE', 'name' => 'Estonia'), array('id' => 'ET', 'name' => 'Ethiopia'), array('id' => 'FK', 'name' => 'Falkland Islands (Malvinas)'), array('id' => 'FO', 'name' => 'Faroe Islands'), array('id' => 'FJ', 'name' => 'Fiji'), array('id' => 'FI', 'name' => 'Finland'), array('id' => 'FR', 'name' => 'France'), array('id' => 'GF', 'name' => 'French Guiana'), array('id' => 'PF', 'name' => 'French Polynesia'), array('id' => 'TF', 'name' => 'French Southern Territories'), array('id' => 'MK', 'name' => 'Fyrom'), array('id' => 'GA', 'name' => 'Gabon'), array('id' => 'GM', 'name' => 'Gambia'), array('id' => 'GE', 'name' => 'Georgia'), array('id' => 'DE', 'name' => 'Germany'), array('id' => 'GH', 'name' => 'Ghana'), array('id' => 'GI', 'name' => 'Gibraltar'), array('id' => 'GR', 'name' => 'Greece'), array('id' => 'GL', 'name' => 'Greenland'), array('id' => 'GD', 'name' => 'Grenada'), array('id' => 'GP', 'name' => 'Guadeloupe'), array('id' => 'GU', 'name' => 'Guam'), array('id' => 'GT', 'name' => 'Guatemala'), array('id' => 'GG', 'name' => 'Guernsey'), array('id' => 'GN', 'name' => 'Guinea'), array('id' => 'GW', 'name' => 'Guinea-Bissau'), array('id' => 'GY', 'name' => 'Guyana'), array('id' => 'HT', 'name' => 'Haiti'), array('id' => 'HM', 'name' => 'Heard Island & Mcdonald Islands'), array('id' => 'VA', 'name' => 'Holy See (Vatican City State)'), array('id' => 'HN', 'name' => 'Honduras'), array('id' => 'HK', 'name' => 'Hong Kong'), array('id' => 'HU', 'name' => 'Hungary'), array('id' => 'IS', 'name' => 'Iceland'), array('id' => 'IN', 'name' => 'India'), array('id' => 'ID', 'name' => 'Indonesia'), array('id' => 'IR', 'name' => 'Iran, Islamic Republic Of'), array('id' => 'IQ', 'name' => 'Iraq'), array('id' => 'IE', 'name' => 'Ireland'), array('id' => 'IM', 'name' => 'Isle Of Man'), array('id' => 'IL', 'name' => 'Israel'), array('id' => 'IT', 'name' => 'Italy'), array('id' => 'JM', 'name' => 'Jamaica'), array('id' => 'JP', 'name' => 'Japan'), array('id' => 'JE', 'name' => 'Jersey'), array('id' => 'JO', 'name' => 'Jordan'), array('id' => 'KZ', 'name' => 'Kazakhstan'), array('id' => 'KE', 'name' => 'Kenya'), array('id' => 'KI', 'name' => 'Kiribati'), array('id' => 'KR', 'name' => 'Korea'), array('id' => 'KW', 'name' => 'Kuwait'), array('id' => 'KG', 'name' => 'Kyrgyzstan'), array('id' => 'LA', 'name' => "Lao People's Democratic Republic"), array('id' => 'LV', 'name' => 'Latvia'), array('id' => 'LB', 'name' => 'Lebanon'), array('id' => 'LS', 'name' => 'Lesotho'), array('id' => 'LR', 'name' => 'Liberia'), array('id' => 'LY', 'name' => 'Libyan Arab Jamahiriya'), array('id' => 'LI', 'name' => 'Liechtenstein'), array('id' => 'LT', 'name' => 'Lithuania'), array('id' => 'LU', 'name' => 'Luxembourg'), array('id' => 'MO', 'name' => 'Macao'), array('id' => 'MG', 'name' => 'Madagascar'), array('id' => 'MW', 'name' => 'Malawi'), array('id' => 'MY', 'name' => 'Malaysia'), array('id' => 'MV', 'name' => 'Maldives'), array('id' => 'ML', 'name' => 'Mali'), array('id' => 'MT', 'name' => 'Malta'), array('id' => 'MH', 'name' => 'Marshall Islands'), array('id' => 'MQ', 'name' => 'Martinique'), array('id' => 'MR', 'name' => 'Mauritania'), array('id' => 'MU', 'name' => 'Mauritius'), array('id' => 'YT', 'name' => 'Mayotte'), array('id' => 'MX', 'name' => 'Mexico'), array('id' => 'FM', 'name' => 'Micronesia, Federated States Of'), array('id' => 'MD', 'name' => 'Moldova'), array('id' => 'MC', 'name' => 'Monaco'), array('id' => 'MN', 'name' => 'Mongolia'), array('id' => 'ME', 'name' => 'Montenegro'), array('id' => 'MS', 'name' => 'Montserrat'), array('id' => 'MA', 'name' => 'Morocco'), array('id' => 'MZ', 'name' => 'Mozambique'), array('id' => 'MM', 'name' => 'Myanmar'), array('id' => 'NA', 'name' => 'Namibia'), array('id' => 'NR', 'name' => 'Nauru'), array('id' => 'NP', 'name' => 'Nepal'), array('id' => 'NL', 'name' => 'Netherlands'), array('id' => 'AN', 'name' => 'Netherlands Antilles'), array('id' => 'NC', 'name' => 'New Caledonia'), array('id' => 'NZ', 'name' => 'New Zealand'), array('id' => 'NI', 'name' => 'Nicaragua'), array('id' => 'NE', 'name' => 'Niger'), array('id' => 'NG', 'name' => 'Nigeria'), array('id' => 'NU', 'name' => 'Niue'), array('id' => 'NF', 'name' => 'Norfolk Island'), array('id' => 'MP', 'name' => 'Northern Mariana Islands'), array('id' => 'NO', 'name' => 'Norway'), array('id' => 'OM', 'name' => 'Oman'), array('id' => 'PK', 'name' => 'Pakistan'), array('id' => 'PW', 'name' => 'Palau'), array('id' => 'PS', 'name' => 'Palestinian Territory, Occupied'), array('id' => 'PA', 'name' => 'Panama'), array('id' => 'PG', 'name' => 'Papua New Guinea'), array('id' => 'PY', 'name' => 'Paraguay'), array('id' => 'PE', 'name' => 'Peru'), array('id' => 'PH', 'name' => 'Philippines'), array('id' => 'PN', 'name' => 'Pitcairn'), array('id' => 'PL', 'name' => 'Poland'), array('id' => 'PT', 'name' => 'Portugal'), array('id' => 'PR', 'name' => 'Puerto Rico'), array('id' => 'QA', 'name' => 'Qatar'), array('id' => 'RE', 'name' => 'Reunion'), array('id' => 'RO', 'name' => 'Romania'), array('id' => 'RU', 'name' => 'Russian Federation'), array('id' => 'RW', 'name' => 'Rwanda'), array('id' => 'BL', 'name' => 'Saint Barthelemy'), array('id' => 'SH', 'name' => 'Saint Helena'), array('id' => 'KN', 'name' => 'Saint Kitts And Nevis'), array('id' => 'LC', 'name' => 'Saint Lucia'), array('id' => 'MF', 'name' => 'Saint Martin'), array('id' => 'PM', 'name' => 'Saint Pierre And Miquelon'), array('id' => 'VC', 'name' => 'Saint Vincent And Grenadines'), array('id' => 'WS', 'name' => 'Samoa'), array('id' => 'SM', 'name' => 'San Marino'), array('id' => 'ST', 'name' => 'Sao Tome And Principe'), array('id' => 'SA', 'name' => 'Saudi Arabia'), array('id' => 'SN', 'name' => 'Senegal'), array('id' => 'RS', 'name' => 'Serbia'), array('id' => 'SC', 'name' => 'Seychelles'), array('id' => 'SL', 'name' => 'Sierra Leone'), array('id' => 'SG', 'name' => 'Singapore'), array('id' => 'SK', 'name' => 'Slovakia'), array('id' => 'SI', 'name' => 'Slovenia'), array('id' => 'SB', 'name' => 'Solomon Islands'), array('id' => 'SO', 'name' => 'Somalia'), array('id' => 'ZA', 'name' => 'South Africa'), array('id' => 'GS', 'name' => 'South Georgia And Sandwich Isl.'), array('id' => 'ES', 'name' => 'Spain'), array('id' => 'LK', 'name' => 'Sri Lanka'), array('id' => 'SD', 'name' => 'Sudan'), array('id' => 'SR', 'name' => 'Suriname'), array('id' => 'SJ', 'name' => 'Svalbard And Jan Mayen'), array('id' => 'SZ', 'name' => 'Swaziland'), array('id' => 'SE', 'name' => 'Sweden'), array('id' => 'CH', 'name' => 'Switzerland'), array('id' => 'SY', 'name' => 'Syrian Arab Republic'), array('id' => 'TW', 'name' => 'Taiwan'), array('id' => 'TJ', 'name' => 'Tajikistan'), array('id' => 'TZ', 'name' => 'Tanzania'), array('id' => 'TH', 'name' => 'Thailand'), array('id' => 'TL', 'name' => 'Timor-Leste'), array('id' => 'TG', 'name' => 'Togo'), array('id' => 'TK', 'name' => 'Tokelau'), array('id' => 'TO', 'name' => 'Tonga'), array('id' => 'TT', 'name' => 'Trinidad And Tobago'), array('id' => 'TN', 'name' => 'Tunisia'), array('id' => 'TR', 'name' => 'Turkey'), array('id' => 'TM', 'name' => 'Turkmenistan'), array('id' => 'TC', 'name' => 'Turks And Caicos Islands'), array('id' => 'TV', 'name' => 'Tuvalu'), array('id' => 'UG', 'name' => 'Uganda'), array('id' => 'UA', 'name' => 'Ukraine'), array('id' => 'AE', 'name' => 'United Arab Emirates'), array('id' => 'GB', 'name' => 'United Kingdom'), array('id' => 'US', 'name' => 'United States'), array('id' => 'UM', 'name' => 'United States Outlying Islands'), array('id' => 'UY', 'name' => 'Uruguay'), array('id' => 'UZ', 'name' => 'Uzbekistan'), array('id' => 'VU', 'name' => 'Vanuatu'), array('id' => 'VE', 'name' => 'Venezuela'), array('id' => 'VN', 'name' => 'Viet Nam'), array('id' => 'VG', 'name' => 'Virgin Islands, British'), array('id' => 'VI', 'name' => 'Virgin Islands, U.S.'), array('id' => 'WF', 'name' => 'Wallis And Futuna'), array('id' => 'EH', 'name' => 'Western Sahara'), array('id' => 'YE', 'name' => 'Yemen'), array('id' => 'ZM', 'name' => 'Zambia'), array('id' => 'ZW', 'name' => 'Zimbabwe'));
$rGeoCountries = array('ALL' => 'All Countries', 'A1' => 'Anonymous Proxy', 'A2' => 'Satellite Provider', 'O1' => 'Other Country', 'AF' => 'Afghanistan', 'AX' => 'Aland Islands', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa', 'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica', 'AG' => 'Antigua And Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda', 'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BA' => 'Bosnia And Herzegovina', 'BW' => 'Botswana', 'BV' => 'Bouvet Island', 'BR' => 'Brazil', 'IO' => 'British Indian Ocean Territory', 'BN' => 'Brunei Darussalam', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CV' => 'Cape Verde', 'KY' => 'Cayman Islands', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CX' => 'Christmas Island', 'CC' => 'Cocos (Keeling) Islands', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo, Democratic Republic', 'CK' => 'Cook Islands', 'CR' => 'Costa Rica', 'CI' => "Cote D'Ivoire", 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'ET' => 'Ethiopia', 'FK' => 'Falkland Islands (Malvinas)', 'FO' => 'Faroe Islands', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GF' => 'French Guiana', 'PF' => 'French Polynesia', 'TF' => 'French Southern Territories', 'MK' => 'Fyrom', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GR' => 'Greece', 'GL' => 'Greenland', 'GD' => 'Grenada', 'GP' => 'Guadeloupe', 'GU' => 'Guam', 'GT' => 'Guatemala', 'GG' => 'Guernsey', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti', 'HM' => 'Heard Island & Mcdonald Islands', 'VA' => 'Holy See (Vatican City State)', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran, Islamic Republic Of', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IM' => 'Isle Of Man', 'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan', 'JE' => 'Jersey', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KR' => 'Korea', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => "Lao People's Democratic Republic", 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libyan Arab Jamahiriya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MO' => 'Macao', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'YT' => 'Mayotte', 'MX' => 'Mexico', 'FM' => 'Micronesia, Federated States Of', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MS' => 'Montserrat', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'AN' => 'Netherlands Antilles', 'NC' => 'New Caledonia', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NU' => 'Niue', 'NF' => 'Norfolk Island', 'MP' => 'Northern Mariana Islands', 'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestinian Territory, Occupied', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PN' => 'Pitcairn', 'PL' => 'Poland', 'PT' => 'Portugal', 'PR' => 'Puerto Rico', 'QA' => 'Qatar', 'RE' => 'Reunion', 'RO' => 'Romania', 'RU' => 'Russian Federation', 'RW' => 'Rwanda', 'BL' => 'Saint Barthelemy', 'SH' => 'Saint Helena', 'KN' => 'Saint Kitts And Nevis', 'LC' => 'Saint Lucia', 'MF' => 'Saint Martin', 'PM' => 'Saint Pierre And Miquelon', 'VC' => 'Saint Vincent And Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome And Principe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'GS' => 'South Georgia And Sandwich Isl.', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SJ' => 'Svalbard And Jan Mayen', 'SZ' => 'Swaziland', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syrian Arab Republic', 'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TK' => 'Tokelau', 'TO' => 'Tonga', 'TT' => 'Trinidad And Tobago', 'TN' => 'Tunisia', 'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'TC' => 'Turks And Caicos Islands', 'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States', 'UM' => 'United States Outlying Islands', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela', 'VN' => 'Viet Nam', 'VG' => 'Virgin Islands, British', 'VI' => 'Virgin Islands, U.S.', 'WF' => 'Wallis And Futuna', 'EH' => 'Western Sahara', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe');
$rHues = array('' => 'Default', 'primary' => 'Blue', 'info' => 'Light Blue', 'success' => 'Green', 'danger' => 'Red', 'warning' => 'Orange', 'purple' => 'Purple', 'pink' => 'Pink', 'dark' => 'Dark Grey', 'secondary' => 'Light Grey');
$rTMDBLanguages = array('' => 'Default - EN', 'aa' => 'Afar', 'af' => 'Afrikaans', 'ak' => 'Akan', 'an' => 'Aragonese', 'as' => 'Assamese', 'av' => 'Avaric', 'ae' => 'Avestan', 'ay' => 'Aymara', 'az' => 'Azerbaijani', 'ba' => 'Bashkir', 'bm' => 'Bambara', 'bi' => 'Bislama', 'bo' => 'Tibetan', 'br' => 'Breton', 'ca' => 'Catalan', 'cs' => 'Czech', 'ce' => 'Chechen', 'cu' => 'Slavic', 'cv' => 'Chuvash', 'kw' => 'Cornish', 'co' => 'Corsican', 'cr' => 'Cree', 'cy' => 'Welsh', 'da' => 'Danish', 'de' => 'German', 'dv' => 'Divehi', 'dz' => 'Dzongkha', 'eo' => 'Esperanto', 'et' => 'Estonian', 'eu' => 'Basque', 'fo' => 'Faroese', 'fj' => 'Fijian', 'fi' => 'Finnish', 'fr' => 'French', 'fy' => 'Frisian', 'ff' => 'Fulah', 'gd' => 'Gaelic', 'ga' => 'Irish', 'gl' => 'Galician', 'gv' => 'Manx', 'gn' => 'Guarani', 'gu' => 'Gujarati', 'ht' => 'Haitian', 'ha' => 'Hausa', 'sh' => 'Serbo-Croatian', 'hz' => 'Herero', 'ho' => 'Hiri Motu', 'hr' => 'Croatian', 'hu' => 'Hungarian', 'ig' => 'Igbo', 'io' => 'Ido', 'ii' => 'Yi', 'iu' => 'Inuktitut', 'ie' => 'Interlingue', 'ia' => 'Interlingua', 'id' => 'Indonesian', 'ik' => 'Inupiaq', 'is' => 'Icelandic', 'it' => 'Italian', 'ja' => 'Japanese', 'kl' => 'Kalaallisut', 'kn' => 'Kannada', 'ks' => 'Kashmiri', 'kr' => 'Kanuri', 'kk' => 'Kazakh', 'km' => 'Khmer', 'ki' => 'Kikuyu', 'rw' => 'Kinyarwanda', 'ky' => 'Kirghiz', 'kv' => 'Komi', 'kg' => 'Kongo', 'ko' => 'Korean', 'kj' => 'Kuanyama', 'ku' => 'Kurdish', 'lo' => 'Lao', 'la' => 'Latin', 'lv' => 'Latvian', 'li' => 'Limburgish', 'ln' => 'Lingala', 'lt' => 'Lithuanian', 'lb' => 'Letzeburgesch', 'lu' => 'Luba-Katanga', 'lg' => 'Ganda', 'mh' => 'Marshall', 'ml' => 'Malayalam', 'mr' => 'Marathi', 'mg' => 'Malagasy', 'mt' => 'Maltese', 'mo' => 'Moldavian', 'mn' => 'Mongolian', 'mi' => 'Maori', 'ms' => 'Malay', 'my' => 'Burmese', 'na' => 'Nauru', 'nv' => 'Navajo', 'nr' => 'Ndebele', 'nd' => 'Ndebele', 'ng' => 'Ndonga', 'ne' => 'Nepali', 'nl' => 'Dutch', 'nn' => 'Norwegian Nynorsk', 'nb' => 'Norwegian Bokmal', 'no' => 'Norwegian', 'ny' => 'Chichewa', 'oc' => 'Occitan', 'oj' => 'Ojibwa', 'or' => 'Oriya', 'om' => 'Oromo', 'os' => 'Ossetian; Ossetic', 'pi' => 'Pali', 'pl' => 'Polish', 'pt' => 'Portuguese', 'pt-BR' => 'Portuguese - Brazil', 'qu' => 'Quechua', 'rm' => 'Raeto-Romance', 'ro' => 'Romanian', 'rn' => 'Rundi', 'ru' => 'Russian', 'sg' => 'Sango', 'sa' => 'Sanskrit', 'si' => 'Sinhalese', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'se' => 'Northern Sami', 'sm' => 'Samoan', 'sn' => 'Shona', 'sd' => 'Sindhi', 'so' => 'Somali', 'st' => 'Sotho', 'es' => 'Spanish', 'sq' => 'Albanian', 'sc' => 'Sardinian', 'sr' => 'Serbian', 'ss' => 'Swati', 'su' => 'Sundanese', 'sw' => 'Swahili', 'sv' => 'Swedish', 'ty' => 'Tahitian', 'ta' => 'Tamil', 'tt' => 'Tatar', 'te' => 'Telugu', 'tg' => 'Tajik', 'tl' => 'Tagalog', 'th' => 'Thai', 'ti' => 'Tigrinya', 'to' => 'Tonga', 'tn' => 'Tswana', 'ts' => 'Tsonga', 'tk' => 'Turkmen', 'tr' => 'Turkish', 'tw' => 'Twi', 'ug' => 'Uighur', 'uk' => 'Ukrainian', 'ur' => 'Urdu', 'uz' => 'Uzbek', 've' => 'Venda', 'vi' => 'Vietnamese', 'vo' => 'Volapük', 'wa' => 'Walloon', 'wo' => 'Wolof', 'xh' => 'Xhosa', 'yi' => 'Yiddish', 'za' => 'Zhuang', 'zu' => 'Zulu', 'ab' => 'Abkhazian', 'zh' => 'Mandarin', 'ps' => 'Pushto', 'am' => 'Amharic', 'ar' => 'Arabic', 'bg' => 'Bulgarian', 'cn' => 'Cantonese', 'mk' => 'Macedonian', 'el' => 'Greek', 'fa' => 'Persian', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hy' => 'Armenian', 'en' => 'English', 'ee' => 'Ewe', 'ka' => 'Georgian', 'pa' => 'Punjabi', 'bn' => 'Bengali', 'bs' => 'Bosnian', 'ch' => 'Chamorro', 'be' => 'Belarusian', 'yo' => 'Yoruba');
$rResellerActions = array('new' => 'Create', 'extend' => 'Extend', 'convert' => 'Convert', 'edit' => 'Edit', 'enable' => 'Enable', 'disable' => 'Disable', 'delete' => 'Delete', 'send_event' => 'MAG Event', 'adjust_credits' => 'Adjust Credits');
$rClientFilters = array('LB_TOKEN_INVALID' => 'Token Failure', 'NOT_IN_BOUQUET' => 'Not in Bouquet', 'BLOCKED_ASN' => 'Blocked ASN', 'ISP_LOCK_FAILED' => 'ISP Lock Failed', 'USER_DISALLOW_EXT' => 'Extension Disallowed', 'AUTH_FAILED' => 'Authentication Failed', 'USER_EXPIRED' => 'User Expired', 'USER_DISABLED' => 'User Disabled', 'USER_BAN' => 'User Banned', 'MAG_TOKEN_INVALID' => 'MAG Token Invalid', 'STALKER_CHANNEL_MISMATCH' => 'Stalker Channel Mismatch', 'STALKER_IP_MISMATCH' => 'Stalker IP Mismatch', 'STALKER_KEY_EXPIRED' => 'Stalker Key Expired', 'STALKER_DECRYPT_FAILED' => 'Stalker Decrypt Failed', 'EMPTY_UA' => 'Empty User-Agent', 'IP_BAN' => 'IP Banned', 'COUNTRY_DISALLOW' => 'Country Disallowed', 'USER_AGENT_BAN' => 'User-Agent Disallowed', 'USER_ALREADY_CONNECTED' => 'IP Limit Reached', 'RESTREAM_DETECT' => 'Restream Detected', 'PROXY_DETECT' => 'Proxy / VPN Detected', 'HOSTING_DETECT' => 'Hosting Server Detected', 'LINE_CREATE_FAIL' => 'Connection Failed', 'CONNECTION_LOOP' => 'Connection Loop', 'TOKEN_EXPIRED' => 'Token Expired', 'IP_MISMATCH' => 'IP Mismatch');
$rStatusArray = array(-1 => "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light btn-fixed-xl'>NO SERVERS</button>", 0 => "<button type='button' class='btn btn-dark btn-xs waves-effect waves-light btn-fixed-xl'>STOPPED</button>", 1 => "<button type='button' class='btn btn-success btn-xs waves-effect waves-light btn-fixed-xl'>ONLINE</button>", 2 => "<button type='button' class='btn btn-warning btn-xs waves-effect waves-light btn-fixed'>STARTING</button>", 3 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light btn-fixed'>DOWN</button>", 4 => "<button type='button' class='btn btn-info btn-xs waves-effect waves-light btn-fixed-xl'>ON DEMAND</button>", 5 => "<button type='button' class='btn btn-purple btn-xs waves-effect waves-light btn-fixed-xl'>DIRECT SOURCE</button>", 6 => "<button type='button' class='btn btn-primary btn-xs waves-effect waves-light btn-fixed-xl'>CREATING...</button>", 7 => "<button type='button' class='btn btn-purple btn-xs waves-effect waves-light btn-fixed-xl'>DIRECT STREAM</button>");
$rSearchStatusArray = array(-1 => "<button type='button' class='btn bg-animate-secondary btn-xs waves-effect waves-light no-border btn-fixed-xl'>NO SERVERS</button>", 0 => "<button type='button' class='btn bg-animate-dark btn-xs waves-effect waves-light no-border btn-fixed-xl'>STOPPED</button>", "<button type='button' class='btn bg-animate-warning btn-xs waves-effect waves-light no-border btn-fixed-xl'>STARTING</button>", "<button type='button' class='btn bg-animate-danger btn-xs waves-effect waves-light no-border btn-fixed-xl'>DOWN</button>", "<button type='button' class='btn bg-animate-success btn-xs waves-effect waves-light no-border btn-fixed-xl'>ON DEMAND</button>", "<button type='button' class='btn bg-animate-purple btn-xs waves-effect waves-light no-border btn-fixed-xl'>DIRECT</button>", 7 => "<button type='button' class='btn bg-animate-warning btn-xs waves-effect waves-light no-border btn-fixed-xl'>ENCODING</button>", 8 => "<button type='button' class='btn bg-animate-dark btn-xs waves-effect waves-light no-border btn-fixed-xl'>NOT ENCODED</button>", 9 => "<button type='button' class='btn bg-animate-info btn-xs waves-effect waves-light no-border btn-fixed-xl'>ENCODED</button>", 10 => "<button type='button' class='btn bg-animate-danger btn-xs waves-effect waves-light no-border btn-fixed-xl'>BROKEN</button>");
$rVODStatusArray = array(-1 => "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light tooltip' title='No Server Selected'><i class='text-white mdi mdi-triangle'></i></button>", 0 => "<button type='button' class='btn btn-dark btn-xs waves-effect waves-light tooltip' title='Not Encoded'><i class='text-white mdi mdi-checkbox-blank-circle'></i></button>", 1 => "<button type='button' class='btn btn-success btn-xs waves-effect waves-light tooltip' title='Encoded'><i class='text-white mdi mdi-check-circle'></i></button>", 2 => "<button type='button' class='btn btn-warning btn-xs waves-effect waves-light tooltip' title='Encoding'><i class='text-white mdi mdi-checkbox-blank-circle'></i></button>", 3 => "<button type='button' class='btn btn-primary btn-xs waves-effect waves-light tooltip' title='Direct Source'><i class='text-white mdi mdi mdi-web'></i></button>", 4 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light tooltip' title='Down'><i class='text-white mdi mdi-triangle'></i></button>", 5 => "<button type='button' class='btn btn-info btn-xs waves-effect waves-light tooltip' title='Direct Stream'><i class='text-white mdi mdi mdi-web'></i></button>");
$rWatchStatusArray = array(1 => "<button type='button' class='btn btn-success btn-xs waves-effect waves-light'>ADDED</button>", 2 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light'>SQL FAILED</button>", 3 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light'>NO CATEGORY</button>", 4 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light'>NO TMDb MATCH</button>", 5 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light'>INVALID FILE</button>", 6 => "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>UPGRADED</button>");
$rFailureStatusArray = array('STREAM_STOP' => "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light btn-fixed-xl'>STOPPED</button>", 'STREAM_START_FAIL' => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light btn-fixed-xl'>START FAILED</button>", 'STREAM_START' => "<button type='button' class='btn btn-success btn-xs waves-effect waves-light btn-fixed-xl'>STARTED</button>", 'STREAM_RESTART' => "<button type='button' class='btn btn-info btn-xs waves-effect waves-light btn-fixed-xl'>RESTARTED</button>", 'STREAM_FAILED' => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light btn-fixed-xl'>STREAM FAILED</button>");
$rStreamLogsArray = array('STREAM_FAILED' => 'Stream Failed', 'STREAM_START' => 'Stream Started', 'STREAM_RESTART' => 'Stream Restarted', 'STREAM_STOP' => 'Stream Stopped', 'FORCE_SOURCE' => 'Force Change Source', 'AUTO_RESTART' => 'Timed Auto Restart', 'AUDIO_LOSS' => 'Audio Lost', 'PRIORITY_SWITCH' => 'Priority Switch', 'DELAY_START' => 'Delay Started', 'FFMPEG_ERROR' => 'FFMPEG Error');
$rThemes = array(array('name' => 'Light', 'dark' => false, 'image' => null), array('name' => 'Dark', 'dark' => true, 'image' => null));
$rPermissionsFile = MAIN_HOME . 'resources/data/permissions.php';
if (!file_exists($rPermissionsFile)) {
	$rPermissionsFile = INCLUDES_PATH . 'data/permissions.php';
}
$rAdvPermissions = require $rPermissionsFile;

function getUserInfo($rUsername, $rPassword) {
	global $db;
	return UserRepository::getAuthUserByCredentials($db, $rUsername, $rPassword);
}

function getSeriesList() {
	global $db;
	return SeriesRepository::getList($db);
}

function secondsToTime($inputSeconds) {
	$secondsInAMinute = 60;
	$secondsInAnHour = 60 * $secondsInAMinute;
	$secondsInADay = 24 * $secondsInAnHour;
	$days = floor($inputSeconds / $secondsInADay);
	$hourSeconds = $inputSeconds % $secondsInADay;
	$hours = floor($hourSeconds / $secondsInAnHour);
	$minuteSeconds = $hourSeconds % $secondsInAnHour;
	$minutes = floor($minuteSeconds / $secondsInAMinute);
	$remainingSeconds = $minuteSeconds % $secondsInAMinute;
	$seconds = ceil($remainingSeconds);

	return array('d' => (int) $days, 'h' => (int) $hours, 'm' => (int) $minutes, 's' => (int) $seconds);
}

function updateSeries($rID) {
	global $db;
	SeriesRepository::updateFromTMDB($db, $rID);
}

function updateSeriesAsync($rID) {
	global $db;
	SeriesRepository::queueRefresh($db, $rID);
}

function validateCIDR($rCIDR) {
	$rParts = explode('/', $rCIDR);
	$rIP = $rParts[0];
	$rNetmask = null;

	if (count($rParts) != 2) {
	} else {
		$rNetmask = intval($rParts[1]);

		if ($rNetmask >= 0) {
		} else {
			return false;
		}
	}

	if (!filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		if (!filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			return false;
		}


		return (is_null($rNetmask) ? true : $rNetmask <= 128);
	}

	return (is_null($rNetmask) ? true : $rNetmask <= 32);
}

function getFreeSpace($rServerID) {
	return ServerRepository::getFreeSpace('systemapirequest', $rServerID);
}

function getStreamsRamdisk($rServerID) {
	return ServerRepository::getStreamsRamdisk('systemapirequest', $rServerID);
}


function killPID($rServerID, $rPID) {
	ServerRepository::killPID('systemapirequest', $rServerID, $rPID);
}


function getRTMPStats($rServerID) {
	return ServerRepository::getRTMPStats('systemapirequest', $rServerID);
}

function getStreamArguments() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `streams_arguments` ORDER BY `id` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[$rRow['argument_key']] = $rRow;
		}
	}

	return $rReturn;
}

function getTranscodeProfiles() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `profiles` ORDER BY `profile_id` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getWatchFolders($rType = null) {
	global $db;
	$rReturn = array();

	if ($rType) {
		$db->query("SELECT * FROM `watch_folders` WHERE `type` = ? AND `type` <> 'plex' ORDER BY `id` ASC;", $rType);
	} else {

		$db->query("SELECT * FROM `watch_folders` WHERE `type` <> 'plex' ORDER BY `id` ASC;");
	}

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getPlexServers() {
	global $db;
	$rReturn = array();
	$db->query("SELECT * FROM `watch_folders` WHERE `type` = 'plex' ORDER BY `id` ASC;");

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getWatchCategories($rType = null) {
	global $db;
	$rReturn = array();

	if ($rType) {
		$db->query('SELECT * FROM `watch_categories` WHERE `type` = ? ORDER BY `genre_id` ASC;', $rType);
	} else {
		$db->query('SELECT * FROM `watch_categories` ORDER BY `genre_id` ASC;');
	}

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[$rRow['genre_id']] = $rRow;
		}
	}

	return $rReturn;
}

function syncDevices($rUserID, $rDeviceID = null) {
	global $db;
	DeviceSync::syncLineDevices($db, $rUserID, $rDeviceID);
}

function encodeRow($rRow) {
	foreach ($rRow as $rKey => $rValue) {
		if (!is_array($rValue)) {
		} else {
			$rRow[$rKey] = json_encode($rValue, JSON_UNESCAPED_UNICODE);
		}
	}

	return $rRow;
}

function getArchiveFiles($rServerID, $rStreamID) {
	return json_decode(systemapirequest($rServerID, array('action' => 'get_archive_files', 'stream_id' => $rStreamID)), true)['data'];
}

function getArchive($rStreamID) {
	$rReturn = array();
	$rStream = getStream($rStreamID);
	$rEPG = getchannelepg($rStreamID, true);
	$rFiles = getArchiveFiles($rStream['tv_archive_server_id'], $rStreamID);

	if (!empty($rFiles) && !empty($rEPG)) {
		foreach ($rFiles as $rFile) {
			$rFilename = pathinfo($rFile)['filename'];
			$rTimestamp = strtotime(explode(':', $rFilename)[0] . 'T' . implode(':', explode('-', explode(':', $rFilename)[1])) . ':00Z ' . str_replace(':', '', gmdate('P')));
			$rEPGID = null;
			$rI = 0;

			foreach ($rEPG as $rEPGItem) {
				if (!filter_var($rTimestamp, FILTER_VALIDATE_INT, array('options' => array('min_range' => $rEPGItem['start'], 'max_range' => $rEPGItem['end'] - 1)))) {
					$rI++;
				} else {
					$rEPGID = $rI;

					break;
				}
			}

			if ($rEPGID) {
				if (!isset($rReturn[$rEPGID])) {
					$rReturn[$rEPGID] = $rEPG[$rEPGID];
					$rReturn[$rEPGID]['archive_stop'] = null;
					$rReturn[$rEPGID]['archive_start'] = $rReturn[$rEPGID]['archive_stop'];
				}

				if ($rTimestamp - 60 >= $rReturn[$rEPGID]['archive_start'] && $rReturn[$rEPGID]['archive_start']) {
				} else {
					$rReturn[$rEPGID]['archive_start'] = $rTimestamp - 60;
				}

				if ($rReturn[$rEPGID]['archive_stop'] >= $rTimestamp && $rReturn[$rEPGID]['archive_stop']) {
				} else {
					$rReturn[$rEPGID]['archive_stop'] = $rTimestamp;
				}
			}
		}
	}

	foreach ($rReturn as $rKey => $rItem) {
		if (time() < $rItem['end']) {
			$rReturn[$rKey]['in_progress'] = true;
		} else {
			$rReturn[$rKey]['in_progress'] = false;
		}

		if (!$rReturn[$rKey]['in_progress'] && filter_var($rItem['start'], FILTER_VALIDATE_INT, array('options' => array('min_range' => $rItem['archive_start'] - 60, 'max_range' => $rItem['archive_start'] + 60))) && filter_var($rItem['end'], FILTER_VALIDATE_INT, array('options' => array('min_range' => $rItem['archive_stop'] - 60, 'max_range' => $rItem['archive_stop'] + 60)))) {
			$rReturn[$rKey]['complete'] = true;
		} else {
			$rReturn[$rKey]['complete'] = false;
		}
	}

	return $rReturn;
}

function getPlexSections($rIP, $rPort, $rToken) {
	$URL = 'http://' . $rIP . ':' . $rPort . '/library/sections?X-Plex-Token=' . $rToken;
	$rSections = json_decode(json_encode(simplexml_load_string(file_get_contents($URL))), true);

	if (!isset($rSections['Directory'])) {
		return array();
	}

	if (isset($rSections['Directory']['@attributes'])) {
		$rSections['Directory'] = array($rSections['Directory']);
	}

	return $rSections['Directory'];
}

function getMovieTMDB($rID) {
	require_once MAIN_HOME . 'includes/libs/tmdb.php';

	if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
		$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
	} else {
		$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
	}

	return ($rTMDB->getMovie($rID) ?: null);
}

function getSeriesTMDB($rID) {
	require_once MAIN_HOME . 'includes/libs/tmdb.php';

	if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
		$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
	} else {
		$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
	}

	return (json_decode($rTMDB->getTVShow($rID)->getJSON(), true) ?: null);
}

function getSeasonTMDB($rID, $rSeason) {
	require_once MAIN_HOME . 'includes/libs/tmdb.php';


	if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
		$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
	} else {
		$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
	}

	return json_decode($rTMDB->getSeason($rID, intval($rSeason))->getJSON(), true);
}

function getResellers($rOwner, $rIncludeSelf = true) {
	global $db;
	return UserRepository::getResellers($db, $rOwner, $rIncludeSelf);
}

function getDirectReports($rIncludeSelf = true) {
	global $db;
	global $rPermissions;
	global $rUserInfo;
	return UserRepository::getDirectReports($db, $rPermissions, $rUserInfo, $rIncludeSelf);
}

function hasResellerPermissions($rType) {
	global $rPermissions;
	return Authorization::hasResellerPermissions($rPermissions, $rType);
}


function hasPermissions($rType, $rID) {
	global $rUserInfo;
	global $db;
	global $rPermissions;
	return Authorization::check($rType, $rID, $rUserInfo, $rPermissions, $db);
}

function getMemberGroups() {
	global $db;
	return GroupRepository::getAll($db);
}

function getHMACTokens() {
	global $db;
	return HMACRepository::getAll($db);
}

function getHMACToken($rID) {
	global $db;
	return HMACRepository::getById($db, $rID);
}

function getActiveCodes() {
	return CodeRepository::getActiveCodes(MAIN_HOME);
}

function updateCodes() {
	global $db;
	CodeRepository::updateCodes($db, MAIN_HOME, SERVER_ID, 'getcodes', 'reloadNginx');
}

function getCurrentCode($rInfo = false) {
	global $db;
	return CodeRepository::getCurrentCode($db, $rInfo);
}

function overwriteData($rData, $rOverwrite, $rSkip = array()) {
	foreach ($rOverwrite as $rKey => $rValue) {
		if (!array_key_exists($rKey, $rData) || in_array($rKey, $rSkip)) {
		} else {
			if (empty($rValue) && is_null($rData[$rKey])) {
				$rData[$rKey] = null;
			} else {
				$rData[$rKey] = $rValue;
			}
		}
	}

	return $rData;
}

function verifyPostTable($rTable, $rData = array(), $rOnlyExisting = false) {
	global $db;
	$rReturn = array();
	$db->query('SELECT `column_name`, `column_default`, `is_nullable`, `data_type` FROM `information_schema`.`columns` WHERE `table_schema` = (SELECT DATABASE()) AND `table_name` = ? ORDER BY `ordinal_position`;', $rTable);

	foreach ($db->get_rows() as $rRow) {
		if ($rRow['column_default'] != 'NULL') {
		} else {
			$rRow['column_default'] = null;
		}

		$rForceDefault = false;


		if ($rRow['is_nullable'] != 'NO' || $rRow['column_default']) {
		} else {
			if (in_array($rRow['data_type'], array('int', 'float', 'tinyint', 'double', 'decimal', 'smallint', 'mediumint', 'bigint', 'bit'))) {
				$rRow['column_default'] = 0;
			} else {
				$rRow['column_default'] = '';
			}

			$rForceDefault = true;
		}

		if (array_key_exists($rRow['column_name'], $rData)) {
			if (empty($rData[$rRow['column_name']]) && !is_numeric($rData[$rRow['column_name']]) && is_null($rRow['column_default'])) {
				$rReturn[$rRow['column_name']] = ($rForceDefault ? $rRow['column_default'] : null);
			} else {
				$rReturn[$rRow['column_name']] = $rData[$rRow['column_name']];
			}
		} else {
			if ($rOnlyExisting) {
			} else {
				$rReturn[$rRow['column_name']] = $rRow['column_default'];
			}
		}
	}

	return $rReturn;
}

function preparecolumn($rValue) {
	return strtolower(preg_replace('/[^a-z0-9_]+/i', '', $rValue));
}

function prepareArray($rArray) {
	$UpdateData = $rColumns = $rPlaceholder = $rData = array();


	foreach (array_keys($rArray) as $rKey) {
		$rColumns[] = '`' . preparecolumn($rKey) . '`';
		$UpdateData[] = '`' . preparecolumn($rKey) . '` = ?';
	}

	foreach (array_values($rArray) as $rValue) {
		if (is_array($rValue)) {
			$rValue = json_encode($rValue, JSON_UNESCAPED_UNICODE);
		} else {
			if (is_null($rValue) || strtolower($rValue) == 'null') {
				$rValue = null;
			}
		}

		$rPlaceholder[] = '?';
		$rData[] = $rValue;
	}

	return array('placeholder' => implode(',', $rPlaceholder), 'columns' => implode(',', $rColumns), 'data' => $rData, 'update' => implode(',', $UpdateData));
}

function setArgs($rArgs, $rGet = true) {
	$rURL = getPageName();

	if (count($rArgs) > 0) {
		$rURL .= '?' . http_build_query($rArgs);

		if ($rGet) {
			foreach ($rArgs as $rKey => $rValue) {
				CoreUtilities::$rRequest[$rKey] = $rValue;
			}
		}
	}

	return "<script>history.replaceState({},'','" . $rURL . "');</script>";
}

function getParent($rID) {
	global $rPermissions;
	global $rUserInfo;
	return UserRepository::getParent($rPermissions, $rUserInfo, $rID);
}

function getSubUsers($rUser) {
	global $db;
	return UserRepository::getSubUsers($db, $rUser);
}

function getAdminImage($rURL, $rMaxW, $rMaxH) {
	return ImageUtils::resize($rURL, $rMaxW, $rMaxH);
}

function getStreamErrors($rStreamID, $rAmount = 250) {
	global $db;
	return StreamRepository::getErrors($db, $rStreamID, $rAmount);
}

function getPageFromURL($rURL) {
	if ($rURL) {
		return strtolower(basename(ltrim(parse_url($rURL)['path'], '/'), '.php'));
	}

	return null;
}

function verifyCode() {
	global $rUserInfo;

	if (isset($rUserInfo)) {
		$rAccessCode = getCurrentCode(true);

		if (in_array($rUserInfo['member_group_id'], json_decode($rAccessCode['groups'], true)) || count(getActiveCodes()) == 0) {
			if (isset($_SESSION['code']) && $_SESSION['code'] != $rAccessCode['code']) {
				return false;
			}


			return true;
		}

		return false;
	}








	return false;
}

function getNearest($arr, $search) {
	$closest = null;



	foreach ($arr as $item) {
		if (!($closest === null || abs($item - $search) < abs($search - $closest))) {
		} else {
			$closest = $item;
		}
	}

	return $closest;
}

function generateUniqueCode() {
	return substr(md5(CoreUtilities::$rSettings['live_streaming_pass']), 0, 15);
}

function checkExists($rTable, $rColumn, $rValue, $rExcludeColumn = null, $rExclude = null) {
	global $db;


	if ($rExcludeColumn && $rExclude) {
		$db->query('SELECT COUNT(*) AS `count` FROM `' . preparecolumn($rTable) . '` WHERE `' . preparecolumn($rColumn) . '` = ? AND `' . preparecolumn($rExcludeColumn) . '` <> ?;', $rValue, $rExclude);
	} else {
		$db->query('SELECT COUNT(*) AS `count` FROM `' . preparecolumn($rTable) . '` WHERE `' . preparecolumn($rColumn) . '` = ?;', $rValue);
	}




	return 0 < $db->get_row()['count'];
}

function parseM3U($rData, $rFile = true) {
	return M3UParser::parse($rData, $rFile);
}

function deleteLines($rIDs) {
	global $db;
	return LineRepository::deleteMany($db, $rIDs);
}

function deleteMAG($rID, $rDeletePaired = false, $rCloseCons = true, $rConvert = false) {
	global $db;
	$rMag = getMag($rID);

	if (!$rMag) {
		return false;
	}

	$db->query('DELETE FROM `mag_devices` WHERE `mag_id` = ?;', $rID);
	$db->query('DELETE FROM `mag_claims` WHERE `mag_id` = ?;', $rID);
	$db->query('DELETE FROM `mag_events` WHERE `mag_device_id` = ?;', $rID);
	$db->query('DELETE FROM `mag_logs` WHERE `mag_id` = ?;', $rID);

	if (!$rMag['user']) {
	} else {
		if ($rConvert) {
			$db->query('UPDATE `lines` SET `is_mag` = 0 WHERE `id` = ?;', $rMag['user']['id']);
			CoreUtilities::updateLine($rMag['user']['id']);
		} else {
			$rCount = 0;
			$db->query('SELECT `mag_id` FROM `mag_devices` WHERE `user_id` = ?;', $rMag['user']['id']);
			$rCount += $db->num_rows();
			$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE `user_id` = ?;', $rMag['user']['id']);
			$rCount += $db->num_rows();

			if ($rCount != 0) {
			} else {
				deleteLine($rMag['user']['id'], $rDeletePaired, $rCloseCons);
			}
		}
	}

	return true;
}

function deleteMAGs($rIDs) {
	global $db;
	$rIDs = confirmIDs($rIDs);


	if (0 >= count($rIDs)) {
		return false;
	}

	$rUserIDs = array();
	$db->query('SELECT `user_id` FROM `mag_devices` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');

	foreach ($db->get_rows() as $rRow) {
		$rUserIDs[] = $rRow['user_id'];
	}
	$db->query('DELETE FROM `mag_devices` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `mag_claims` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `mag_events` WHERE `mag_device_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `mag_logs` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');

	if (0 >= count($rUserIDs)) {
	} else {
		deletelines($rUserIDs);
	}

	return true;
}

function deleteEnigma($rID, $rDeletePaired = false, $rCloseCons = true, $rConvert = false) {
	global $db;
	$rEnigma = getEnigma($rID);

	if (!$rEnigma) {
		return false;
	}

	$db->query('DELETE FROM `enigma2_devices` WHERE `device_id` = ?;', $rID);
	$db->query('DELETE FROM `enigma2_actions` WHERE `device_id` = ?;', $rID);


	if (!$rEnigma['user']) {
	} else {
		if ($rConvert) {
			$db->query('UPDATE `lines` SET `is_e2` = 0 WHERE `id` = ?;', $rEnigma['user']['id']);
			CoreUtilities::updateLine($rEnigma['user']['id']);
		} else {
			$rCount = 0;
			$db->query('SELECT `mag_id` FROM `mag_devices` WHERE `user_id` = ?;', $rEnigma['user']['id']);
			$rCount += $db->num_rows();
			$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE `user_id` = ?;', $rEnigma['user']['id']);
			$rCount += $db->num_rows();

			if ($rCount != 0) {
			} else {
				deleteLine($rEnigma['user']['id'], $rDeletePaired, $rCloseCons);
			}
		}
	}

	return true;
}

function deleteEnigmas($rIDs) {
	global $db;
	$rIDs = confirmIDs($rIDs);


	if (0 >= count($rIDs)) {
		return false;
	}

	$rUserIDs = array();
	$db->query('SELECT `user_id` FROM `enigma2_devices` WHERE `device_id` IN (' . implode(',', $rIDs) . ');');


	foreach ($db->get_rows() as $rRow) {
		$rUserIDs[] = $rRow['user_id'];
	}
	$db->query('DELETE FROM `enigma2_devices` WHERE `device_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `enigma2_actions` WHERE `device_id` IN (' . implode(',', $rIDs) . ');');

	if (0 >= count($rUserIDs)) {
	} else {
		deletelines($rUserIDs);
	}

	return true;
}

function deleteSeries($rID, $rDeleteFiles = true) {
	global $db;
	$rSeries = getSerie($rID);

	if (!$rSeries) {
		return false;
	}

	$db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` = ?;', $rID);

	foreach ($db->get_rows() as $rRow) {
		deleteStream($rRow['stream_id'], -1, $rDeleteFiles);
	}
	$db->query('DELETE FROM `streams_episodes` WHERE `series_id` = ?;', $rID);
	$db->query('DELETE FROM `streams_series` WHERE `id` = ?;', $rID);
	scanBouquets();

	return true;
}

function deleteSeriesMass($rIDs) {
	global $db;
	$rIDs = confirmIDs($rIDs);

	if (0 >= count($rIDs)) {
		return false;
	}

	$db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` IN (' . implode(',', $rIDs) . ');');


	foreach ($db->get_rows() as $rRow) {
		$rStreamIDs[] = $rRow['stream_id'];
	}
	$db->query('DELETE FROM `streams_episodes` WHERE `series_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `streams_series` WHERE `id` IN (' . implode(',', $rIDs) . ');');

	if (0 >= count($rStreamIDs)) {
	} else {
		deleteStreams($rStreamIDs, true);
	}

	scanBouquets();

	return true;
}

function deleteBouquet($rID) {
	global $db;
	$rBouquet = getBouquet($rID);


	if (!$rBouquet) {
		return false;
	}

	$db->query("SELECT `id`, `bouquet` FROM `lines` WHERE JSON_CONTAINS(`bouquet`, ?, '\$');", $rID);


	foreach ($db->get_rows() as $rRow) {
		$rRow['bouquet'] = json_decode($rRow['bouquet'], true);




		if (($rKey = array_search($rID, $rRow['bouquet'])) === false) {
		} else {
			unset($rRow['bouquet'][$rKey]);
		}

		$db->query("UPDATE `lines` SET `bouquet` = '[" . implode(',', array_map('intval', $rRow['bouquet'])) . "]' WHERE `id` = ?;", $rRow['id']);
		CoreUtilities::updateLine($rRow['id']);
	}
	$db->query("SELECT `id`, `bouquets` FROM `users_packages` WHERE JSON_CONTAINS(`bouquets`, ?, '\$');", $rID);

	foreach ($db->get_rows() as $rRow) {
		$rRow['bouquets'] = json_decode($rRow['bouquets'], true);

		if (($rKey = array_search($rID, $rRow['bouquets'])) === false) {
		} else {
			unset($rRow['bouquets'][$rKey]);
		}

		$db->query("UPDATE `users_packages` SET `bouquets` = '[" . implode(',', array_map('intval', $rRow['bouquets'])) . "]' WHERE `id` = ?;", $rRow['id']);
	}
	$db->query("SELECT `id`, `bouquets` FROM `watch_folders` WHERE JSON_CONTAINS(`bouquets`, ?, '\$') OR JSON_CONTAINS(`fb_bouquets`, ?, '\$');", $rID, $rID);

	foreach ($db->get_rows() as $rRow) {
		$rRow['bouquets'] = json_decode($rRow['bouquets'], true);

		if (($rKey = array_search($rID, $rRow['bouquets'])) === false) {
		} else {
			unset($rRow['bouquets'][$rKey]);
		}

		$rRow['fb_bouquets'] = json_decode($rRow['fb_bouquets'], true);





		if (($rKey = array_search($rID, $rRow['fb_bouquets'])) === false) {
		} else {
			unset($rRow['fb_bouquets'][$rKey]);
		}

		$db->query("UPDATE `watch_folders` SET `bouquets` = '[" . implode(',', array_map('intval', $rRow['bouquets'])) . "]', `fb_bouquets` = '[" . implode(',', array_map('intval', $rRow['fb_bouquets'])) . "]' WHERE `id` = ?;", $rRow['id']);
	}
	$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
	scanBouquets();

	return true;
}

function deleteCategory($rID) {
	global $db;
	$rCategory = getCategory($rID);


	if (!$rCategory) {
		return false;
	}

	$db->query("SELECT `id`, `category_id` FROM `streams` WHERE JSON_CONTAINS(`category_id`, ?, '\$');", $rID);

	foreach ($db->get_rows() as $rRow) {
		$rRow['category_id'] = json_decode($rRow['category_id'], true);

		if (($rKey = array_search($rID, $rRow['category_id'])) === false) {
		} else {
			unset($rRow['category_id'][$rKey]);
		}

		$db->query("UPDATE `streams` SET `category_id` = '[" . implode(',', array_map('intval', $rRow['category_id'])) . "]' WHERE `id` = ?;", $rRow['id']);
	}
	$db->query("SELECT `id`, `category_id` FROM `streams_series` WHERE JSON_CONTAINS(`category_id`, ?, '\$');", $rID);

	foreach ($db->get_rows() as $rRow) {
		$rRow['category_id'] = json_decode($rRow['category_id'], true);

		if (($rKey = array_search($rID, $rRow['category_id'])) === false) {
		} else {
			unset($rRow['category_id'][$rKey]);
		}

		$db->query("UPDATE `streams_series` SET `category_id` = '[" . implode(',', array_map('intval', $rRow['category_id'])) . "]' WHERE `id` = ?;", $rRow['id']);
	}
	$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
	$db->query('UPDATE `watch_folders` SET `category_id` = null WHERE `category_id` = ?;', $rID);
	$db->query('UPDATE `watch_folders` SET `fb_category_id` = null WHERE `fb_category_id` = ?;', $rID);

	return true;
}

function deleteProfile($rID) {
	global $db;
	$rProfile = getTranscodeProfile($rID);

	if (!$rProfile) {
		return false;
	}

	$db->query('DELETE FROM `profiles` WHERE `profile_id` = ?;', $rID);
	$db->query('UPDATE `streams` SET `transcode_profile_id` = 0 WHERE `transcode_profile_id` = ?;', $rID);
	$db->query('UPDATE `watch_folders` SET `transcode_profile_id` = 0 WHERE `transcode_profile_id` = ?;', $rID);

	return true;
}

function AsyncAPIRequest($rServerIDs, $rData) {
	$rURLs = array();


	foreach ($rServerIDs as $rServerID) {
		if (!CoreUtilities::$rServers[$rServerID]['server_online']) {
		} else {
			$rURLs[$rServerID] = array('url' => CoreUtilities::$rServers[$rServerID]['api_url'], 'postdata' => $rData);
		}
	}
	CoreUtilities::getMultiCURL($rURLs);

	return array('result' => true);
}

function changePort($rServerID, $rType, $rPorts, $rReload = false) {
	global $db;
	$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_port', 'type' => intval($rType), 'ports' => $rPorts, 'reload' => $rReload)));
}

function setServices($rServerID, $rNumServices, $rReload = true) {
	global $db;
	$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_services', 'count' => intval($rNumServices), 'reload' => $rReload)));
}

function setGovernor($rServerID, $rGovernor) {
	global $db;
	$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_governor', 'data' => $rGovernor)));
}

function setSysctl($rServerID, $rSysCtl) {
	global $db;
	$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_sysctl', 'data' => $rSysCtl)));
}

function resetSTB($rID) {
	global $db;
	$db->query("UPDATE `mag_devices` SET `ip` = '', `ver` = '', `image_version` = '', `stb_type` = '', `sn` = '', `device_id` = '', `device_id2` = '', `hw_version` = '', `token` = '' WHERE `mag_id` = ?;", $rID);
}

function formatUptime($rUptime) {
	if (86400 <= $rUptime) {
		$rUptime = sprintf('%02dd %02dh %02dm', $rUptime / 86400, ($rUptime / 3600) % 24, ($rUptime / 60) % 60);
	} else {
		$rUptime = sprintf('%02dh %02dm %02ds', $rUptime / 3600, ($rUptime / 60) % 60, $rUptime % 60);
	}




	return $rUptime;
}

function getSettings() {
	global $db;
	$db->query('SELECT * FROM `settings` LIMIT 1;');



	return $db->get_row();
}


function APIRequest($rData, $rTimeout = 5) {
	ini_set('default_socket_timeout', $rTimeout);
	$rAPI = 'http://127.0.0.1:' . intval(CoreUtilities::$rServers[SERVER_ID]['http_broadcast_port']) . '/admin/api';

	if (!empty(CoreUtilities::$rSettings['api_pass'])) {
		$rData['api_pass'] = CoreUtilities::$rSettings['api_pass'];
	}

	$rPost = http_build_query($rData);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $rAPI);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $rPost);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $rTimeout);
	curl_setopt($ch, CURLOPT_TIMEOUT, $rTimeout);

	return curl_exec($ch);
}

function systemapirequest($rServerID, $rData, $rTimeout = 5) {
	ini_set('default_socket_timeout', $rTimeout);
	if (!is_array(CoreUtilities::$rServers) || !isset(CoreUtilities::$rServers[$rServerID])) {
		return null;
	}
	if (CoreUtilities::$rServers[$rServerID]['server_online']) {
		$rAPI = 'http://' . CoreUtilities::$rServers[intval($rServerID)]['server_ip'] . ':' . CoreUtilities::$rServers[intval($rServerID)]['http_broadcast_port'] . '/api';
		$rData['password'] = CoreUtilities::$rSettings['live_streaming_pass'];
		$rPost = http_build_query($rData);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $rAPI);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $rPost);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $rTimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $rTimeout);

		return curl_exec($ch);
	}
	return null;
}

function getWatchFolder($rID) {
	global $db;
	$db->query('SELECT * FROM `watch_folders` WHERE `id` = ?;', $rID);


	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getSeriesByTMDB($rID) {
	global $db;
	$db->query('SELECT * FROM `streams_series` WHERE `tmdb_id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getSeries() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `streams_series` ORDER BY `title` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getSerie($rID) {
	global $db;
	$db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rID);


	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getSeriesTrailer($rTMDBID, $rLanguage = null) {
	$rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/videos?api_key=' . urlencode(CoreUtilities::$rSettings['tmdb_api_key']);



	if ($rLanguage) {
		$rURL .= '&language=' . urlencode($rLanguage);
	} else {
		if (0 >= strlen(CoreUtilities::$rSettings['tmdb_language'])) {
		} else {
			$rURL .= '&language=' . urlencode(CoreUtilities::$rSettings['tmdb_language']);
		}
	}

	$rJSON = json_decode(file_get_contents($rURL), true);


	foreach ($rJSON['results'] as $rVideo) {
		if (!(strtolower($rVideo['type']) == 'trailer' && strtolower($rVideo['site']) == 'youtube')) {
		} else {
			return $rVideo['key'];
		}
	}

	return '';
}

function getBouquet($rID) {
	global $db;
	$db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getLanguages() {
	return array();
}

function addToBouquet($rType, $rBouquetID, $rIDs) {
	global $db;

	if (is_array($rIDs)) {
	} else {
		$rIDs = array($rIDs);
	}

	$rBouquet = getBouquet($rBouquetID);

	if (!$rBouquet) {
	} else {
		if ($rType == 'stream') {
			$rColumn = 'bouquet_channels';
		} else {
			if ($rType == 'movie') {
				$rColumn = 'bouquet_movies';
			} else {
				if ($rType == 'radio') {
					$rColumn = 'bouquet_radios';
				} else {

					$rColumn = 'bouquet_series';
				}
			}
		}

		$rChanged = false;
		$rChannels = confirmIDs(json_decode($rBouquet[$rColumn], true));

		foreach ($rIDs as $rID) {
			if (0 >= intval($rID) || in_array($rID, $rChannels)) {
			} else {
				$rChannels[] = $rID;
				$rChanged = true;
			}
		}

		if (!$rChanged) {
		} else {
			$db->query('UPDATE `bouquets` SET `' . $rColumn . '` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rChannels)) . ']', $rBouquetID);
		}
	}
}

function removeFromBouquet($rType, $rBouquetID, $rIDs) {
	global $db;

	if (is_array($rIDs)) {
	} else {
		$rIDs = array($rIDs);
	}

	$rBouquet = getBouquet($rBouquetID);

	if (!$rBouquet) {
	} else {
		if ($rType == 'stream') {
			$rColumn = 'bouquet_channels';
		} else {
			if ($rType == 'movie') {
				$rColumn = 'bouquet_movies';
			} else {
				if ($rType == 'radio') {
					$rColumn = 'bouquet_radios';
				} else {
					$rColumn = 'bouquet_series';
				}
			}
		}










		$rChanged = false;
		$rChannels = confirmIDs(json_decode($rBouquet[$rColumn], true));

		foreach ($rIDs as $rID) {
			if (($rKey = array_search($rID, $rChannels)) === false) {
			} else {
				unset($rChannels[$rKey]);
				$rChanged = true;
			}
		}

		if (!$rChanged) {
		} else {
			$db->query('UPDATE `bouquets` SET `' . $rColumn . '` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rChannels)) . ']', $rBouquetID);
		}
	}
}

function confirmIDs($rIDs) {
	$rReturn = array();

	foreach ($rIDs as $rID) {
		if (0 >= intval($rID)) {
		} else {
			$rReturn[] = $rID;
		}
	}

	return array_unique($rReturn);
}

function downloadRemoteBackup($rPath, $rFilename) {
	require_once MAIN_HOME . 'includes/libs/Dropbox.php';
	$rClient = new DropboxClient();

	try {
		$rClient->SetBearerToken(array('t' => CoreUtilities::$rSettings['dropbox_token']));
		$rClient->downloadFile($rPath, $rFilename);



		return true;
	} catch (exception $e) {
		return false;
	}
}

function deleteRemoteBackup($rPath) {
	require_once MAIN_HOME . 'includes/libs/Dropbox.php';
	$rClient = new DropboxClient();








	try {
		$rClient->SetBearerToken(array('t' => CoreUtilities::$rSettings['dropbox_token']));
		$rClient->Delete($rPath);




		return true;
	} catch (exception $e) {







		return false;
	}
}

function parserelease($rRelease) {
	if (CoreUtilities::$rSettings['parse_type'] == 'guessit') {
		$rCommand = MAIN_HOME . 'bin/guess ' . escapeshellarg(pathinfo($rRelease)['filename'] . '.mkv');
	} else {
		$rCommand = '/usr/bin/python3 ' . MAIN_HOME . 'includes/python/release.py ' . escapeshellarg(pathinfo(str_replace('-', '_', $rRelease))['filename']);
	}

	return json_decode(shell_exec($rCommand), true);
}

function scanRecursive($rServerID, $rDirectory, $rAllowed = null) {
	return json_decode(systemapirequest($rServerID, array('action' => 'scandir_recursive', 'dir' => $rDirectory, 'allowed' => implode('|', $rAllowed))), true);
}

function listDir($rServerID, $rDirectory, $rAllowed = null) {
	return json_decode(systemapirequest($rServerID, array('action' => 'scandir', 'dir' => $rDirectory, 'allowed' => implode('|', $rAllowed))), true);
}

function rdeleteBlockedIP($rID) {
	global $db;
	$db->query('SELECT `id`, `ip` FROM `blocked_ips` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$rRow = $db->get_row();
	$db->query('DELETE FROM `blocked_ips` WHERE `id` = ?;', $rID);

	if (!file_exists(FLOOD_TMP_PATH . 'block_' . $rRow['ip'])) {
	} else {
		unlink(FLOOD_TMP_PATH . 'block_' . $rRow['ip']);
	}

	return true;
}

function rdeleteBlockedISP($rID) {
	global $db;
	$db->query('SELECT `id` FROM `blocked_isps` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$db->query('DELETE FROM `blocked_isps` WHERE `id` = ?;', $rID);








	return true;
}

function rdeleteBlockedUA($rID) {
	global $db;
	$db->query('SELECT `id` FROM `blocked_uas` WHERE `id` = ?;', $rID);


	if (0 >= $db->num_rows()) {



		return false;
	}


	$db->query('DELETE FROM `blocked_uas` WHERE `id` = ?;', $rID);






	return true;
}

function removeAccessEntry($rID) {
	global $db;
	$db->query('SELECT `id` FROM `access_codes` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}


	$db->query('DELETE FROM `access_codes` WHERE `id` = ?;', $rID);
	updateCodes();




	return true;
}

function validateHMAC($rID) {
	global $db;
	$db->query('SELECT `id` FROM `hmac_keys` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$db->query('DELETE FROM `hmac_keys` WHERE `id` = ?;', $rID);








	return true;
}

function getStills($rTMDBID, $rSeason, $rEpisode) {
	$rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/season/' . intval($rSeason) . '/episode/' . intval($rEpisode) . '/images?api_key=' . urlencode(CoreUtilities::$rSettings['tmdb_api_key']);


	if (0 >= strlen(CoreUtilities::$rSettings['tmdb_language'])) {
	} else {
		$rURL .= '&language=' . urlencode(CoreUtilities::$rSettings['tmdb_language']);
	}

	return json_decode(file_get_contents($rURL), true);
}

function getUserAgents() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `blocked_uas` ORDER BY `id` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getISPs() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `blocked_isps` ORDER BY `id` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getStreamProviders() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `providers` ORDER BY `last_changed` DESC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getStreamProvider($rID) {
	global $db;
	$db->query('SELECT * FROM `providers` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getExpiring($rLimit = 2419200) {
	global $db;
	global $rUserInfo;
	global $rPermissions;
	$rReturn = array();
	$rReports = array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']));

	if (0 >= count($rReports)) {
	} else {
		$db->query('SELECT `is_mag`, `is_e2`, `lines`.`id` AS `line_id`, `lines`.`reseller_notes`, `mag_devices`.`mag_id`, `enigma2_devices`.`device_id` AS `e2_id`, `member_id`, `username`, `password`, `exp_date`, `mag_devices`.`mac` AS `mag_mac`, `enigma2_devices`.`mac` AS `e2_mac` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `member_id` IN (' . implode(',', $rReports) . ') AND `exp_date` IS NOT NULL AND `exp_date` >= ? AND `exp_date` < ? ORDER BY `exp_date` ASC LIMIT 250;', time(), time() + $rLimit);

		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getTickets($rID = null, $rAdmin = false) {
	global $db;
	global $rUserInfo;
	global $rPermissions;
	$rReturn = array();

	if ($rID) {
		if ($rAdmin) {
			$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, `users`.`username` FROM `tickets`, `users` WHERE `member_id` IN (SELECT `id` FROM `users` WHERE `owner_id` = ?) AND `users`.`id` = `tickets`.`member_id` ORDER BY `id` DESC;', $rID);
		} else {
			$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, `users`.`username` FROM `tickets`, `users` WHERE `member_id` IN (' . implode(',', array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']))) . ') AND `users`.`id` = `tickets`.`member_id` ORDER BY `id` DESC;');
		}
	} else {
		$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, `users`.`username` FROM `tickets`, `users` WHERE `users`.`id` = `tickets`.`member_id` ORDER BY `id` DESC;');
	}

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$db->query('SELECT MIN(`date`) AS `date` FROM `tickets_replies` WHERE `ticket_id` = ?;', $rRow['id']);

			if ($rDate = $db->get_row()['date']) {
				$rRow['created'] = date('Y-m-d H:i', $rDate);
			} else {
				$rRow['created'] = '';
			}

			$db->query('SELECT * FROM `tickets_replies` WHERE `ticket_id` = ? ORDER BY `id` DESC LIMIT 1;', $rRow['id']);
			$rLastResponse = $db->get_row();
			$rRow['last_reply'] = date('Y-m-d H:i', $rLastResponse['date']);

			if ($rRow['member_id'] == $rID) {
				if ($rRow['status'] == 0) {
				} else {
					if ($rLastResponse['admin_reply']) {
						if ($rRow['user_read'] == 1) {
							$rRow['status'] = 3;
						} else {
							$rRow['status'] = 4;
						}
					} else {
						if ($rRow['admin_read'] == 1) {
							$rRow['status'] = 5;
						} else {
							$rRow['status'] = 2;
						}
					}
				}
			} else {
				if ($rRow['status'] == 0) {
				} else {
					if ($rLastResponse['admin_reply']) {
						if ($rRow['user_read'] == 1) {
							$rRow['status'] = 6;
						} else {
							$rRow['status'] = 2;
						}
					} else {
						if ($rRow['admin_read'] == 1) {
							$rRow['status'] = 5;
						} else {
							$rRow['status'] = 4;
						}
					}
				}
			}

			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function cryptPassword($rPassword, $rSalt = 'xc_vm', $rRounds = 20000) {
	if ($rSalt != '') {
	} else {
		$rSalt = substr(bin2hex(openssl_random_pseudo_bytes(16)), 0, 16);
	}

	if (stripos($rSalt, 'rounds=')) {
	} else {
		$rSalt = sprintf('$6$rounds=%d$%s$', $rRounds, $rSalt);
	}

	return crypt($rPassword, $rSalt);
}

function getIP() {
	return CoreUtilities::getUserIP();
}

function getPermissions($rID) {
	global $db;
	$db->query('SELECT * FROM `users_groups` WHERE `group_id` = ?;', $rID);

	if ($db->num_rows() == 1) {
		$rRow = $db->get_row();
		$rRow['subresellers'] = !empty($rRow['subresellers'])? json_decode($rRow['subresellers'], true): [];

		if (count($rRow['subresellers'] ?? []) == 0) {
			$rRow['create_sub_resellers'] = 0;
		}

		return $rRow;
	}
}

function destroySession($rType = 'admin') {
	global $_SESSION;
	$rKeys = array('admin' => array('hash', 'ip', 'code', 'verify', 'last_activity'), 'reseller' => array('reseller', 'rip', 'rcode', 'rverify', 'rlast_activity'), 'player' => array('phash', 'pverify'));

	foreach ($rKeys[$rType] as $rKey) {
		if (!isset($_SESSION[$rKey])) {
		} else {
			unset($_SESSION[$rKey]);
		}
	}
}

function getSelections($rSources) {
	global $db;
	$rReturn = array();

	foreach ($rSources as $rSource) {
		$db->query("SELECT `id` FROM `streams` WHERE `type` IN (2,5) AND `stream_source` LIKE ? ESCAPE '|' LIMIT 1;", '%' . str_replace('/', '\\/', $rSource) . '"%');

		if ($db->num_rows() != 1) {
		} else {
			$rReturn[] = intval($db->get_row()['id']);
		}
	}

	return $rReturn;
}

function getBackups() {
	$rBackups = array();

	foreach (scandir(MAIN_HOME . 'backups/') as $rBackup) {
		$rInfo = pathinfo(MAIN_HOME . 'backups/' . $rBackup);

		if ($rInfo['extension'] != 'sql') {
		} else {
			$rBackups[] = array('filename' => $rBackup, 'timestamp' => filemtime(MAIN_HOME . 'backups/' . $rBackup), 'date' => date('Y-m-d H:i:s', filemtime(MAIN_HOME . 'backups/' . $rBackup)), 'filesize' => filesize(MAIN_HOME . 'backups/' . $rBackup));
		}
	}
	usort(
		$rBackups,
		function ($a, $b) {
			return $a['timestamp'];
		}
	);

	return $rBackups;
}

function checkRemote() {
	require_once MAIN_HOME . 'includes/libs/Dropbox.php';

	try {
		$rClient = new DropboxClient();
		$rClient->SetBearerToken(array('t' => CoreUtilities::$rSettings['dropbox_token']));
		$rClient->GetFiles();

		return true;
	} catch (exception $e) {
		return false;
	}
}

function getRemoteBackups() {

	require_once MAIN_HOME . 'includes/libs/Dropbox.php';


	try {
		$rClient = new DropboxClient();
		$rClient->SetBearerToken(array('t' => CoreUtilities::$rSettings['dropbox_token']));
		$rFiles = $rClient->GetFiles();
	} catch (exception $e) {
		$rFiles = array();
	}
	$rBackups = array();

	foreach ($rFiles as $rFile) {
		try {
			if (!(!$rFile->isDir && strtolower(pathinfo($rFile->name)['extension']) == 'sql' && 0 < $rFile->size)) {
			} else {
				$rJSON = json_decode(json_encode($rFile, JSON_UNESCAPED_UNICODE), true);
				$rJSON['time'] = strtotime($rFile->server_modified);
				$rBackups[] = $rJSON;
			}
		} catch (exception $e) {
		}
	}
	array_multisort(array_column($rBackups, 'time'), SORT_ASC, $rBackups);

	return $rBackups;
}

function uploadRemoteBackup($rPath, $rFilename, $rOverwrite = true) {
	require_once MAIN_HOME . 'includes/libs/Dropbox.php';
	$rClient = new DropboxClient();

	try {
		$rClient->SetBearerToken(array('t' => CoreUtilities::$rSettings['dropbox_token']));

		return $rClient->UploadFile($rFilename, $rPath, $rOverwrite);
	} catch (exception $e) {
		return (object) array('error' => $e);
	}
}

function restoreImages() {
	global $db;


	foreach (array_keys(CoreUtilities::$rServers) as $rServerID) {
		if (!CoreUtilities::$rServers[$rServerID]['server_online']) {
		} else {
			systemapirequest($rServerID, array('action' => 'restore_images'));
		}
	}

	return true;
}

function killWatchFolder() {
	global $db;
	$db->query("SELECT DISTINCT(`server_id`) AS `server_id` FROM `watch_folders` WHERE `active` = 11 AND `type` <> 'plex';");

	foreach ($db->get_rows() as $rRow) {
		if (!CoreUtilities::$rServers[$rRow['server_id']]['server_online']) {
		} else {
			systemapirequest($rRow['server_id'], array('action' => 'kill_watch'));
		}
	}

	return true;
}

function killPlexSync() {
	global $db;
	$db->query("SELECT DISTINCT(`server_id`) AS `server_id` FROM `watch_folders` WHERE `active` = 1 AND `type` = 'plex';");

	foreach ($db->get_rows() as $rRow) {
		if (!CoreUtilities::$rServers[$rRow['server_id']]['server_online']) {
		} else {
			systemapirequest($rRow['server_id'], array('action' => 'kill_plex'));
		}
	}

	return true;
}

function getPIDs($rServerID) {
	$rReturn = array();
	$rProcesses = json_decode(systemapirequest($rServerID, array('action' => 'get_pids')), true);
	if (!is_array($rProcesses)) {
		return $rReturn;
	}
	array_shift($rProcesses);

	foreach ($rProcesses as $rProcess) {
		$rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rProcess)));

		if ($rSplit[0] == 'xc_vm') {
			$rUsage = array(0, 0, 0);
			$rTimer = explode('-', $rSplit[9]);

			if (1 < count($rTimer)) {
				$rDays = intval($rTimer[0]);
				$rTime = $rTimer[1];
			} else {
				$rDays = 0;
				$rTime = $rTimer[0];
			}

			$rTime = explode(':', $rTime);

			if (count($rTime) == 3) {
				$rSeconds = intval($rTime[0]) * 3600 + intval($rTime[1]) * 60 + intval($rTime[2]);
			} else {
				if (count($rTime) == 2) {
					$rSeconds = intval($rTime[0]) * 60 + intval($rTime[1]);
				} else {
					$rSeconds = intval($rTime[2]);
				}
			}


			$rUsage[0] = $rSeconds + $rDays * 86400;
			$rTimer = explode('-', $rSplit[8]);

			if (1 < count($rTimer)) {
				$rDays = intval($rTimer[0]);
				$rTime = $rTimer[1];
			} else {
				$rDays = 0;
				$rTime = $rTimer[0];
			}

			$rTime = explode(':', $rTime);

			if (count($rTime) == 3) {
				$rSeconds = intval($rTime[0]) * 3600 + intval($rTime[1]) * 60 + intval($rTime[2]);
			} else {
				if (count($rTime) == 2) {
					$rSeconds = intval($rTime[0]) * 60 + intval($rTime[1]);
				} else {
					$rSeconds = intval($rTime[2]);
				}
			}

			$rUsage[1] = $rSeconds + $rDays * 86400;
			if ($rUsage[0] != 0) {
				$rUsage[2] = $rUsage[1] / $rUsage[0] * 100;
			} else {
				$rUsage[2] = 0;
			}

			$rReturn[] = array('user' => $rSplit[0], 'pid' => $rSplit[1], 'cpu' => $rSplit[2], 'mem' => $rSplit[3], 'vsz' => $rSplit[4], 'rss' => $rSplit[5], 'tty' => $rSplit[6], 'stat' => $rSplit[7], 'time' => $rUsage[1], 'etime' => $rUsage[0], 'load_average' => $rUsage[2], 'command' => implode(' ', array_splice($rSplit, 10, count($rSplit) - 10)));
		}
	}

	return $rReturn;
}

function clearSettingsCache() {
	unlink(CACHE_TMP_PATH . 'settings');
}

function deleteUser($rID, $rDeleteSubUsers = false, $rDeleteLines = false, $rReplaceWith = null) {
	global $db;
	$rUser = getRegisteredUser($rID);

	if (!$rUser) {
		return false;
	}

	$db->query('DELETE FROM `users` WHERE `id` = ?;', $rID);
	$db->query('DELETE FROM `users_credits_logs` WHERE `admin_id` = ?;', $rID);
	$db->query('DELETE FROM `users_logs` WHERE `owner` = ?;', $rID);
	$db->query('DELETE FROM `tickets_replies` WHERE `ticket_id` IN (SELECT `id` FROM `tickets` WHERE `member_id` = ?);', $rID);
	$db->query('DELETE FROM `tickets` WHERE `member_id` = ?;', $rID);

	if ($rDeleteSubUsers) {
		$db->query('SELECT `id` FROM `users` WHERE `owner_id` = ?;', $rID);

		foreach ($db->get_rows() as $rRow) {
			deleteUser($rRow['id'], $rDeleteSubUsers, $rDeleteLines, $rReplaceWith);
		}
	} else {
		$db->query('UPDATE `users` SET `owner_id` = ? WHERE `owner_id` = ?;', $rReplaceWith, $rID);
	}

	if ($rDeleteLines) {
		$db->query('SELECT `id` FROM `lines` WHERE `member_id` = ?;', $rID);

		foreach ($db->get_rows() as $rRow) {
			deleteLine($rRow['id']);
		}
	} else {
		$db->query('UPDATE `lines` SET `member_id` = ? WHERE `member_id` = ?;', $rReplaceWith, $rID);
	}

	return true;
}

function deleteUsers($rIDs) {
	global $db;
	$rIDs = confirmids($rIDs);

	if (0 >= count($rIDs)) {
		return false;
	}

	$db->query('DELETE FROM `users` WHERE `id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `users_credits_logs` WHERE `admin_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `users_logs` WHERE `owner` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `tickets_replies` WHERE `ticket_id` IN (SELECT `id` FROM `tickets` WHERE `member_id` IN (' . implode(',', $rIDs) . '));');
	$db->query('DELETE FROM `tickets` WHERE `member_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('UPDATE `users` SET `owner_id` = NULL WHERE `owner_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('UPDATE `lines` SET `member_id` = NULL WHERE `member_id` IN (' . implode(',', $rIDs) . ');');

	return true;
}

function deleteStream($rID, $rServerID = -1, $rDeleteFiles = true, $f2d619cb38696890 = true) {
	global $db;
	$db->query('SELECT `id`, `type` FROM `streams` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$rType = $db->get_row()['type'];
	$rRemaining = 0;

	if ($rServerID == -1) {
	} else {
		$db->query('SELECT `server_stream_id` FROM `streams_servers` WHERE `stream_id` = ? AND `server_id` <> ?;', $rID, $rServerID);
		$rRemaining = $db->num_rows();
	}

	if ($rRemaining == 0 && $f2d619cb38696890) {
		$db->query('DELETE FROM `lines_logs` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `mag_claims` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `streams` WHERE `id` = ?;', $rID);
		$db->query('DELETE FROM `streams_episodes` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `streams_errors` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `streams_logs` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `streams_options` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `streams_stats` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `watch_refresh` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `watch_logs` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `recordings` WHERE `created_id` = ? OR `stream_id` = ?;', $rID, $rID);
		$db->query('UPDATE `lines_activity` SET `stream_id` = 0 WHERE `stream_id` = ?;', $rID);
		$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rID);
		$rServerIDs = array();

		foreach ($db->get_rows() as $rRow) {
			$rServerIDs[] = $rRow['server_id'];
		}

		if (!($rDeleteFiles && 0 < count($rServerIDs) && in_array($rType, array(2, 5)))) {
		} else {
			deleteMovieFile($rServerIDs, $rID);
		}

		$db->query('DELETE FROM `streams_servers` WHERE `stream_id` = ?;', $rID);
	} else {
		$rServerIDs = array($rServerID);
		$db->query('DELETE FROM `streams_servers` WHERE `stream_id` = ? AND `server_id` = ?;', $rID, $rServerID);

		if (!($rDeleteFiles && in_array($rType, array(2, 5)))) {
		} else {
			deleteMovieFile(array($rServerID), $rID);
		}
	}

	$db->query('DELETE FROM `streams_servers` WHERE `parent_id` IS NOT NULL AND `parent_id` > 0 AND `parent_id` NOT IN (SELECT `id` FROM `servers` WHERE `server_type` = 0);');
	CoreUtilities::updateStream($rID);
	scanBouquets();

	return true;
}

function deleteStreams($rIDs, $rDeleteFiles = false) {
	global $db;
	$rIDs = confirmids($rIDs);


	if (0 >= count($rIDs)) {
	} else {
		$db->query('DELETE FROM `lines_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `mag_claims` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams` WHERE `id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_episodes` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_errors` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_options` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_stats` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `watch_refresh` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `watch_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `lines_live` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `recordings` WHERE `created_id` IN (' . implode(',', $rIDs) . ') OR `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('UPDATE `lines_activity` SET `stream_id` = 0 WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_servers` WHERE `parent_id` IS NOT NULL AND `parent_id` > 0 AND `parent_id` NOT IN (SELECT `id` FROM `servers` WHERE `server_type` = 0);');
		$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', SERVER_ID, time(), json_encode(array('type' => 'update_streams', 'id' => $rIDs)));

		if ($rDeleteFiles) {
			foreach (array_keys(CoreUtilities::$rServers) as $rServerID) {
				$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vods', 'id' => $rIDs)));
			}
		}

		scanBouquets();
	}

	return true;
}

function deleteStreamsByServer($rIDs, $rServerID, $rDeleteFiles = false) {
	global $db;
	$rIDs = confirmids($rIDs);

	if (0 >= count($rIDs)) {
	} else {
		$db->query('DELETE FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` IN (' . implode(',', $rIDs) . ');', $rServerID);
		$db->query('UPDATE `streams_servers` SET `parent_id` = NULL WHERE `parent_id` = ? AND `stream_id` IN (' . implode(',', $rIDs) . ');', $rServerID);

		if ($rDeleteFiles) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vods', 'id' => $rIDs)));
		}

		// The panel will rescan itself within a minute.
		// scanBouquets();
	}

	return true;
}

function flushIPs() {
	global $db;
	global $rServers;
	global $rProxyServers;
	$db->query('TRUNCATE `blocked_ips`;');
	shell_exec('rm ' . FLOOD_TMP_PATH . 'block_*');

	foreach ($rServers as $rServer) {
		$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'flush')));
	}

	foreach ($rProxyServers as $rServer) {
		$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'flush')));
	}

	return true;
}

function addTMDbCategories() {
	global $db;
	require_once MAIN_HOME . 'includes/libs/tmdb.php';

	if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
		$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
	} else {
		$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
	}

	$rCurrentCats = array('movie' => array(), 'series' => array());

	$db->query('SELECT `id`, `category_type`, `category_name` FROM `streams_categories`;');

	if ($db->num_rows() > 0) {
		foreach ($db->get_rows() as $rRow) {
			if (array_key_exists($rRow['category_type'], $rCurrentCats)) {
				$rCurrentCats[$rRow['category_type']][] = $rRow['category_name'];
			}
		}
	}

	$rMovieGenres = $rTMDB->getMovieGenres();
	foreach ($rMovieGenres as $rMovieGenre) {
		$movieGenreName = $rMovieGenre->getName();
		if (!in_array($movieGenreName, $rCurrentCats['movie'])) {
			$db->query("INSERT INTO `streams_categories`(`category_type`, `category_name`) VALUES('movie', ?);", $movieGenreName);
		}
	}

	$rTVGenres = $rTMDB->getTVGenres();
	foreach ($rTVGenres as $rTVGenre) {
		$seriesGenreName = $rTVGenre->getName();
		if (!in_array($seriesGenreName, $rCurrentCats['series'])) {
			$db->query("INSERT INTO `streams_categories`(`category_type`, `category_name`) VALUES('series', ?);", $seriesGenreName);
		}
	}

	return true;
}

function updateTMDbCategories() {
	global $db;
	require_once MAIN_HOME . 'includes/libs/tmdb.php';

	if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
		$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
	} else {
		$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
	}

	$rCurrentCats = array(1 => array(), 2 => array());
	$db->query('SELECT `id`, `type`, `genre_id` FROM `watch_categories`;');

	if ($db->num_rows() > 0) {
		foreach ($db->get_rows() as $rRow) {
			if (array_key_exists($rRow['type'], $rCurrentCats)) {

				if (in_array($rRow['genre_id'], $rCurrentCats[$rRow['type']])) {
					$db->query('DELETE FROM `watch_categories` WHERE `id` = ?;', $rRow['id']);
				}
				$rCurrentCats[$rRow['type']][] = $rRow['genre_id'];
			}
		}
	}

	$rMovieGenres = $rTMDB->getMovieGenres();

	foreach ($rMovieGenres as $rMovieGenre) {
		if (!in_array($rMovieGenre->getID(), $rCurrentCats[1])) {
			$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(1, ?, ?, 0, '[]');", $rMovieGenre->getID(), $rMovieGenre->getName());
		}

		if (!in_array($rMovieGenre->getID(), $rCurrentCats[2])) {
			$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(2, ?, ?, 0, '[]');", $rMovieGenre->getID(), $rMovieGenre->getName());
		}
	}

	$rTVGenres = $rTMDB->getTVGenres();

	foreach ($rTVGenres as $rTVGenre) {
		if (!in_array($rTVGenre->getID(), $rCurrentCats[1])) {
			$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(1, ?, ?, 0, '[]');", $rTVGenre->getID(), $rTVGenre->getName());
		}

		if (!in_array($rTVGenre->getID(), $rCurrentCats[2])) {
			$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(2, ?, ?, 0, '[]');", $rTVGenre->getID(), $rTVGenre->getName());
		}
	}
}

function goHome() {
	header('Location: dashboard');

	exit();
}

function checkResellerPermissions($rPage = null) {
	global $rPermissions;

	if ($rPage) {
	} else {
		$rPage = strtolower(basename($_SERVER['SCRIPT_FILENAME'], '.php'));
	}

	switch ($rPage) {
		case 'user':
		case 'users':
			return $rPermissions['create_sub_resellers'];

		case 'line':
		case 'lines':
			return $rPermissions['create_line'];

		case 'mag':
		case 'mags':
			return $rPermissions['create_mag'];

		case 'enigma':
		case 'enigmas':
			return $rPermissions['create_enigma'];

		case 'epg_view':
		case 'streams':
		case 'created_channels':
		case 'movies':
		case 'episodes':
		case 'radios':
			return $rPermissions['can_view_vod'];

		case 'live_connections':
		case 'line_activity':
			return $rPermissions['reseller_client_connection_logs'];
	}

	return true;
}

function checkPermissions($rPage = null) {
	if ($rPage) {
	} else {
		$rPage = strtolower(basename($_SERVER['SCRIPT_FILENAME'], '.php'));
	}

	switch ($rPage) {
		case 'isps':
		case 'isp':
		case 'asns':
			return hasPermissions('adv', 'block_isps');

		case 'bouquet':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_bouquet')) {
				return true;
			}

			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_bouquet')) {
			} else {
				return true;
			}

			// no break
		case 'bouquet_order':
		case 'bouquet_sort':
			return hasPermissions('adv', 'edit_bouquet');

		case 'bouquets':
			return hasPermissions('adv', 'bouquets');

		case 'channel_order':
			return hasPermissions('adv', 'channel_order');

		case 'client_logs':
			return hasPermissions('adv', 'client_request_log');

		case 'created_channel':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_cchannel')) {
				return true;
			}

			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'create_channel')) {
			} else {
				return true;
			}

			// no break
		case 'code':
		case 'codes':
			return hasPermissions('adv', 'add_code');

		case 'hmac':
		case 'hmacs':
			return hasPermissions('adv', 'add_hmac');

		case 'credit_logs':
			return hasPermissions('adv', 'credits_log');

		case 'enigmas':
			return hasPermissions('adv', 'manage_e2');

		case 'epg':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'epg_edit')) {
				return true;
			}

			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_epg')) {
			} else {
				return true;
			}

			// no break
		case 'epgs':
			return hasPermissions('adv', 'epg');

		case 'episode':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_episode')) {
				return true;
			}

			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_episode')) {
			} else {
				return true;
			}

			// no break
		case 'episodes':
			return hasPermissions('adv', 'episodes');

		case 'series_mass':
		case 'episodes_mass':
			return hasPermissions('adv', 'mass_sedits');

		case 'fingerprint':
			return hasPermissions('adv', 'fingerprint');

		case 'group':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_group')) {
				return true;
			}

			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_group')) {
			} else {
				return true;
			}

			// no break
		case 'groups':
			return hasPermissions('adv', 'mng_groups');

		case 'ip':
		case 'ips':
			return hasPermissions('adv', 'block_ips');

		case 'live_connections':
			return hasPermissions('adv', 'live_connections');

		case 'mag':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_mag')) {
				return true;
			}
			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_mag')) {
				break;
			}
			return true;
		case 'mag_events':
			return hasPermissions('adv', 'manage_events');
		case 'mags':
			return hasPermissions('adv', 'manage_mag');

		case 'mass_delete':
			return hasPermissions('adv', 'mass_delete');

		case 'record':
			return hasPermissions('adv', 'add_movie');
		case 'recordings':
			return hasPermissions('adv', 'movies');
		case 'queue':
			return hasPermissions('adv', 'streams') || hasPermissions('adv', 'episodes') || hasPermissions('adv', 'series');
		case 'movie':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_movie')) {
				return true;
			}
			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_movie')) {
			} else {
				if (isset(CoreUtilities::$rRequest['import']) && !hasPermissions('adv', 'import_movies')) {
				} else {
					return true;
				}
			}
			break;
		case 'movie_mass':
			return hasPermissions('adv', 'mass_sedits_vod');
		case 'movies':
			return hasPermissions('adv', 'movies');
		case 'package':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_package')) {
				return true;
			}

			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_packages')) {
				break;
			}
			return true;
		case 'packages':
		case 'addons':
			return hasPermissions('adv', 'mng_packages');

		case 'player':
			return hasPermissions('adv', 'player');

		case 'process_monitor':
			return hasPermissions('adv', 'process_monitor');

		case 'profile':
			return hasPermissions('adv', 'tprofile');

		case 'profiles':
			return hasPermissions('adv', 'tprofiles');

		case 'radio':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_radio')) {
				return true;
			}
			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_radio')) {
				break;
			}
			return true;
		case 'radio_mass':
			return hasPermissions('adv', 'mass_edit_radio');
		case 'radios':
			return hasPermissions('adv', 'radio');
		case 'user':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_reguser')) {
				return true;
			}

			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_reguser')) {
				break;
			}
			return true;
		case 'user_logs':
			return hasPermissions('adv', 'reg_userlog');
		case 'users':
			return hasPermissions('adv', 'mng_regusers');
		case 'rtmp_ip':
			return hasPermissions('adv', 'add_rtmp');
		case 'rtmp_ips':
		case 'rtmp_monitor':
			return hasPermissions('adv', 'rtmp');
		case 'serie':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_series')) {
				return true;
			}

			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_series')) {
				break;
			}
			return true;
		case 'series':
			return hasPermissions('adv', 'series');
		case 'series_order':
			return hasPermissions('adv', 'edit_series');
		case 'server':
		case 'proxy':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_server')) {
				return true;
			}
			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_server')) {
				break;
			}
			return true;
		case 'server_install':
			return hasPermissions('adv', 'add_server');
		case 'servers':
		case 'server_view':
		case 'server_order':
		case 'proxies':
			return hasPermissions('adv', 'servers');

		case 'settings':
			return hasPermissions('adv', 'settings');

		case 'backups':
		case 'cache':
		case 'setup':
			return hasPermissions('adv', 'database');

		case 'settings_watch':
		case 'settings_plex':
			return hasPermissions('adv', 'folder_watch_settings');

		case 'stream':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_stream')) {
				return true;
			}

			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_stream')) {
			} else {
				if (isset(CoreUtilities::$rRequest['import']) && !hasPermissions('adv', 'import_streams')) {
				} else {
					return true;
				}
			}

			break;

		case 'review':
			return hasPermissions('adv', 'import_streams');

		case 'mass_edit_streams':
			return hasPermissions('adv', 'edit_stream');

		case 'stream_categories':
			return hasPermissions('adv', 'categories');

		case 'stream_category':
			return hasPermissions('adv', 'add_cat');

		case 'stream_errors':
			return hasPermissions('adv', 'stream_errors');

		case 'created_channel_mass':
		case 'stream_mass':
			return hasPermissions('adv', 'mass_edit_streams');

		case 'user_mass':
			return hasPermissions('adv', 'mass_edit_users');

		case 'mag_mass':
			return hasPermissions('adv', 'mass_edit_mags');



		case 'enigma_mass':
			return hasPermissions('adv', 'mass_edit_enigmas');


		case 'quick_tools':
			return hasPermissions('adv', 'quick_tools');



		case 'stream_tools':
			return hasPermissions('adv', 'stream_tools');






		case 'stream_view':
		case 'provider':
		case 'providers':
		case 'streams':
		case 'epg_view':
		case 'created_channels':
		case 'stream_rank':
		case 'archive':
			return hasPermissions('adv', 'streams');

		case 'ticket':
			return hasPermissions('adv', 'ticket');

		case 'ticket_view':
		case 'tickets':
			return hasPermissions('adv', 'manage_tickets');

		case 'line':
			if (isset(CoreUtilities::$rRequest['id']) && hasPermissions('adv', 'edit_user')) {
				return true;
			}

			if (isset(CoreUtilities::$rRequest['id']) || !hasPermissions('adv', 'add_user')) {
				break;
			}



			return true;


		case 'line_activity':
		case 'theft_detection':
		case 'line_ips':
			return hasPermissions('adv', 'connection_logs');

		case 'line_mass':
			return hasPermissions('adv', 'mass_edit_lines');


		case 'useragents':
		case 'useragent':
			return hasPermissions('adv', 'block_uas');











		case 'lines':
			return hasPermissions('adv', 'users');


		case 'plex':
		case 'watch':
			return hasPermissions('adv', 'folder_watch');



		case 'plex_add':
		case 'watch_add':
			return hasPermissions('adv', 'folder_watch_add');

		case 'watch_output':
			return hasPermissions('adv', 'folder_watch_output');



		case 'mysql_syslog':
		case 'panel_logs':
			return hasPermissions('adv', 'panel_logs');







		case 'login_logs':
			return hasPermissions('adv', 'login_logs');

		case 'restream_logs':
			return hasPermissions('adv', 'restream_logs');




		default:
			return true;
	}
}

function getPackages($rGroup = null, $rType = null) {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `users_packages` ORDER BY `id` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			if (isset($rGroup) && !in_array(intval($rGroup), json_decode($rRow['groups'], true))) {
			} else {
				if ($rType && !$rRow['is_' . $rType]) {
				} else {
					$rReturn[intval($rRow['id'])] = $rRow;
				}
			}
		}
	}

	return $rReturn;
}





function checkCompatible($rIDA, $rIDB) {
	$rPackageA = getPackage($rIDA);
	$rPackageB = getPackage($rIDB);
	$rCompatible = true;

	if (!($rPackageA && $rPackageB)) {
	} else {
		foreach (array('bouquets', 'output_formats') as $rKey) {
			if (json_decode($rPackageA[$rKey], true) == json_decode($rPackageB[$rKey], true)) {
			} else {
				$rCompatible = false;
			}
		}

		foreach (array('is_restreamer', 'is_isplock', 'max_connections', 'force_server_id', 'forced_country', 'lock_device') as $rKey) {
			if ($rPackageA[$rKey] == $rPackageB[$rKey]) {
			} else {
				$rCompatible = false;
			}
		}
	}

	return $rCompatible;
}

function getPackage($rID) {
	global $db;
	$db->query('SELECT * FROM `users_packages` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getcodes($rType = null) {
	global $db;
	$rReturn = array();

	if (!is_null($rType)) {
		$db->query('SELECT * FROM `access_codes` WHERE `type` = ? ORDER BY `id` ASC;', $rType);
	} else {
		$db->query('SELECT * FROM `access_codes` ORDER BY `id` ASC;');
	}

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[intval($rRow['id'])] = $rRow;
		}
	}

	return $rReturn;
}

function getCode($rID) {
	global $db;
	$db->query('SELECT * FROM `access_codes` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function closeConnection($rServerID, $rActivityID) {
	return systemapirequest($rServerID, array('action' => 'closeConnection', 'activity_id' => intval($rActivityID)));
}

function getCertificateInfo($rServerID) {
	return systemapirequest($rServerID, array('action' => 'get_certificate_info'));
}

function getBarColour($rInt) {
	if (75 <= $rInt) {
		return 'bg-danger';
	}

	if (50 <= $rInt) {
		return 'bg-warning';
	}

	return 'bg-success';
}

function getNVENCProcesses($rServerID) {
	global $db;
	$rProcesses = array();
	$rServer = getStreamingServersByID($rServerID);
	$rGPUInfo = json_decode($rServer['gpu_info'], true);

	if (!is_array($rGPUInfo)) {
	} else {
		foreach ($rGPUInfo['gpus'] as $rGPU) {
			foreach ($rGPU['processes'] as $rProcess) {
				$rArray = array('pid' => $rProcess['pid'], 'memory' => $rProcess['memory'], 'stream_id' => null);
				$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `pid` = ? AND `server_id` = ?;', $rProcess['pid'], $rServerID);

				if (0 >= $db->num_rows()) {
				} else {
					$rArray['stream_id'] = $db->get_row()['stream_id'];
				}

				$rProcesses[] = $rArray;
			}
		}
	}

	return $rProcesses;
}

function deleteLine($rID, $rDeletePaired = false, $rCloseCons = true) {
	global $db;
	$rLine = getUser($rID);

	if (!$rLine) {
		return false;
	}


	CoreUtilities::deleteLine($rID);
	$db->query('DELETE FROM `lines` WHERE `id` = ?;', $rID);
	$db->query('DELETE FROM `lines_logs` WHERE `user_id` = ?;', $rID);
	$db->query('UPDATE `lines_activity` SET `user_id` = 0 WHERE `user_id` = ?;', $rID);

	if (!$rCloseCons) {
	} else {
		if (CoreUtilities::$rSettings['redis_handler']) {
			foreach (CoreUtilities::getRedisConnections($rID, null, null, true, false, false) as $rConnection) {
				CoreUtilities::closeConnection($rConnection);
			}
		} else {
			$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rID);

			foreach ($db->get_rows() as $rRow) {
				CoreUtilities::closeConnection($rRow);
			}
		}
	}

	$db->query('SELECT `id` FROM `lines` WHERE `pair_id` = ?;', $rID);

	foreach ($db->get_rows() as $rRow) {
		if ($rDeletePaired) {
			deleteLine($rRow['id'], true, $rCloseCons);
		} else {
			$db->query('UPDATE `lines` SET `pair_id` = null WHERE `id` = ?;', $rRow['id']);
			CoreUtilities::updateLine($rRow['id']);
		}
	}

	return true;
}

function deleteRTMPIP($rID) {
	global $db;
	$db->query('SELECT `id` FROM `rtmp_ips` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$db->query('DELETE FROM `rtmp_ips` WHERE `id` = ?;', $rID);

	return true;
}

function deleteWatchFolder($rID) {
	global $db;
	$db->query('SELECT `id` FROM `watch_folders` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}



	$db->query('DELETE FROM `watch_folders` WHERE `id` = ?;', $rID);





	return true;
}

function deleteTicket($rID) {
	global $db;
	$db->query('SELECT `id` FROM `tickets` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$db->query('DELETE FROM `tickets` WHERE `id` = ?;', $rID);
	$db->query('DELETE FROM `tickets_replies` WHERE `ticket_id` = ?;', $rID);

	return true;
}

function canGenerateTrials($rUserID) {
	global $db;
	global $rSettings;
	$rUser = getRegisteredUser($rUserID);
	$rPermissions = getPermissions($rUser['member_group_id']);

	if ($rSettings['disable_trial']) {
		return false;
	}

	if (floatval($rUser['credits']) < floatval($rPermissions['minimum_trial_credits'])) {
		return false;
	}

	$rTotal = $rPermissions['total_allowed_gen_trials'];





	if (0 >= $rTotal) {
		return false;
	}









	$rTotalIn = $rPermissions['total_allowed_gen_in'];





	if ($rTotalIn == 'hours') {
		$rTime = time() - intval($rTotal) * 3600;
	} else {
		$rTime = time() - intval($rTotal) * 3600 * 24;
	}

	$db->query('SELECT COUNT(`id`) AS `count` FROM `lines` WHERE `member_id` = ? AND `created_at` >= ? AND `is_trial` = 1;', $rUser['id'], $rTime);

	return $db->get_row()['count'] < $rTotal;
}

function getGroupPermissions($rUserID, $rStreams = true, $rUsers = true) {
	global $db;
	$rStart = round(microtime(true) * 1000);
	$rReturn = array('create_line' => false, 'create_mag' => false, 'create_enigma' => false, 'stream_ids' => array(), 'series_ids' => array(), 'category_ids' => array(), 'users' => array(), 'direct_reports' => array(), 'all_reports' => array(), 'report_map' => array());
	$rUser = getRegisteredUser($rUserID);

	if (!$rUser) {
	} else {
		if (!file_exists(CACHE_TMP_PATH . 'permissions_' . intval($rUser['member_group_id']))) {
		} else {
			$rReturn = array_merge($rReturn, igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'permissions_' . intval($rUser['member_group_id']))));
		}

		$db->query("SELECT * FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rUser['member_group_id']);



		foreach ($db->get_rows() as $rRow) {
			if (!$rRow['is_line']) {
			} else {
				$rReturn['create_line'] = true;
			}

			if (!$rRow['is_mag']) {
			} else {
				$rReturn['create_mag'] = true;
			}

			if (!$rRow['is_e2']) {
			} else {
				$rReturn['create_enigma'] = true;
			}
		}

		if (!$rUsers) {
		} else {
			$rReturn['users'] = getSubUsers($rUser['id']);

			foreach ($rReturn['users'] as $rUserID => $rUserData) {
				if ($rUser['id'] != $rUserData['parent']) {
				} else {
					$rReturn['direct_reports'][] = $rUserID;
				}

				$rReturn['all_reports'][] = $rUserID;
			}
		}
	}

	return $rReturn;
}

function reloadNginx($rServerID) {
	systemapirequest($rServerID, array('action' => 'reload_nginx'));
}

function getFPMStatus($rServerID) {
	return systemapirequest($rServerID, array('action' => 'fpm_status'));
}

function grantPrivilegesToAllServers() {
	global $rServers;

	foreach ($rServers as $rServerID => $rServerArray) {
		CoreUtilities::grantPrivileges($rServerArray['server_ip']);
	}
}

function getTranscodeProfile($rID) {
	global $db;
	$db->query('SELECT * FROM `profiles` WHERE `profile_id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getUserAgent($rID) {
	global $db;
	$db->query('SELECT * FROM `blocked_uas` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getCategory($rID) {
	global $db;
	$db->query('SELECT * FROM `streams_categories` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return false;
	}

	return $db->get_row();
}

function getMag($rID) {
	global $db;
	$db->query('SELECT * FROM `mag_devices` WHERE `mag_id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return array();
	}

	$rRow = $db->get_row();
	$rRow['user'] = getUser($rRow['user_id']);
	$db->query('SELECT `pair_id` FROM `lines` WHERE `id` = ?;', $rRow['user_id']);

	if ($db->num_rows() != 1) {
	} else {
		$rRow['paired'] = getUser($rRow['user']['pair_id']);
	}



	return $rRow;
}

function getEnigma($rID) {
	global $db;
	$db->query('SELECT * FROM `enigma2_devices` WHERE `device_id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return array();
	}

	$rRow = $db->get_row();
	$rRow['user'] = getUser($rRow['user_id']);

	$db->query('SELECT `pair_id` FROM `lines` WHERE `id` = ?;', $rRow['user_id']);

	if ($db->num_rows() != 1) {
	} else {
		$rRow['paired'] = getUser($rRow['user']['pair_id']);
	}

	return $rRow;
}

function getE2User($rID) {
	global $db;
	$db->query('SELECT * FROM `enigma2_devices` WHERE `user_id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return '';
	}

	return $db->get_row();
}

function getTicket($rID) {
	global $db;
	$db->query('SELECT * FROM `tickets` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
	} else {
		$rRow = $db->get_row();
		$rRow['replies'] = array();
		$rRow['title'] = htmlspecialchars($rRow['title']);
		$db->query('SELECT * FROM `tickets_replies` WHERE `ticket_id` = ? ORDER BY `date` ASC;', $rID);

		foreach ($db->get_rows() as $rReply) {
			$rReply['message'] = htmlspecialchars($rReply['message']);

			if (strlen($rReply['message']) >= 80) {
			} else {
				$rReply['message'] .= str_repeat('&nbsp; ', 80 - strlen($rReply['message']));
			}

			$rRow['replies'][] = $rReply;
		}
		$rRow['user'] = getRegisteredUser($rRow['member_id']);
		return $rRow;
	}
}

function getISP($rID) {
	global $db;
	$db->query('SELECT * FROM `blocked_isps` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getRTMPIP($rID) {
	global $db;
	$db->query('SELECT * FROM `rtmp_ips` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getEPGs() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `epg` ORDER BY `id` ASC;');

	if ($db->num_rows() > 0) {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[intval($rRow['id'])] = $rRow;
		}
	}

	return $rReturn;
}

function getChannels($rType = 'live') {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `streams_categories` WHERE `category_type` = ? ORDER BY `cat_order` ASC;', $rType);

	if (0 >= $db->num_rows()) {
	} else {

		foreach ($db->get_rows() as $rRow) {
			$rReturn[intval($rRow['id'])] = $rRow;
		}
	}

	return $rReturn;
}

function getChannelsByID($rID) {
	global $db;
	$db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return false;
	}

	return $db->get_row();
}

function getStreamingServersByID($rID) {
	global $db;
	$db->query('SELECT * FROM `servers` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return false;
	}

	return $db->get_row();
}

function getLiveConnections($rServerID, $rProxy = false) {
	global $db;

	if (CoreUtilities::$rSettings['redis_handler']) {
		$rCount = 0;


		if ($rProxy) {
			$rParentIDs = CoreUtilities::$rServers[$rServerID]['parent_id'];

			foreach ($rParentIDs as $rParentID) {
				foreach (CoreUtilities::getRedisConnections(null, $rParentID, null, true, false, false) as $rConnection) {
					if ($rConnection['proxy_id'] != $rServerID) {
					} else {
						$rCount++;
					}
				}
			}
		} else {
			list($rCount) = CoreUtilities::getRedisConnections(null, $rServerID, null, true, true, false);
		}

		return $rCount;
	} else {
		if ($rProxy) {
			$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `proxy_id` = ? AND `hls_end` = 0;', $rServerID);
		} else {
			$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', $rServerID);
		}

		return $db->get_row()['count'];
	}
}

function getEPGSources() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `epg`;');

	if (0 >= $db->num_rows()) {
	} else {

		foreach ($db->get_rows() as $rRow) {
			$rReturn[$rRow['id']] = $rRow;
		}
	}

	return $rReturn;
}

function getCategories($rType = 'live') {
	global $db;
	$rCategories = CategoryRepository::getFromDatabase($db, null, null, ($rType ?: null), true);
	$rReturn = array();
	foreach ($rCategories as $rID => $rRow) {
		$rReturn[intval($rID)] = $rRow;
	}
	return $rReturn;
}

function findEPG($rEPGName) {
	global $db;
	return EpgRepository::findByName($db, $rEPGName);
}

function deleteGroup($rID) {
	global $db;
	return GroupRepository::deleteById($db, $rID);
}

function deletePackage($rID) {
	global $db;
	return PackageRepository::deleteById($db, 'getPackage', $rID);
}

function deleteProvider($rID) {
	global $db;
	$rProvider = getstreamprovider($rID);

	if (!$rProvider) {
		return false;
	}

	$db->query('DELETE FROM `providers` WHERE `id` = ?;', $rID);
	$db->query('DELETE FROM `providers_streams` WHERE `provider_id` = ?;', $rID);

	return true;
}

function deleteEPG($rID) {
	global $db;
	$rEPG = getEPG($rID);

	if (!$rEPG) {
		return false;
	}

	$db->query('DELETE FROM `epg` WHERE `id` = ?;', $rID);
	$db->query('DELETE FROM `epg_channels` WHERE `epg_id` = ?;', $rID);
	$db->query('UPDATE `streams` SET `epg_id` = null, `channel_id` = null, `epg_lang` = null WHERE `epg_id` = ?;', $rID);

	return true;
}

function deleteServer($rID, $rReplaceWith = null) {
	global $db;
	return ServerRepository::deleteById($db, CoreUtilities::$rSettings, 'getStreamingServersByID', $rID, $rReplaceWith);
}

function getEncodeErrors($rID) {
	global $db;
	$rErrors = array();
	$db->query('SELECT `server_id`, `error` FROM `streams_errors` WHERE `stream_id` = ?;', $rID);

	foreach ($db->get_rows() as $rRow) {
		$rErrors[intval($rRow['server_id'])] = $rRow['error'];
	}

	return $rErrors;
}

function deleteRecording($rID) {
	global $db;
	$db->query('SELECT `created_id`, `source_id` FROM `recordings` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
	} else {
		$rRecording = $db->get_row();

		if (!$rRecording['created_id']) {
		} else {
			deleteStream($rRecording['created_id'], $rRecording['source_id'], true, true);
		}

		shell_exec("kill -9 `ps -ef | grep 'Record\\[" . intval($rID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
		$db->query('DELETE FROM `recordings` WHERE `id` = ?;', $rID);
	}
}

function getRecordings() {
	global $db;
	$rRecordings = array();
	$db->query('SELECT * FROM `recordings` ORDER BY `id` DESC;');

	foreach ($db->get_rows() as $rRow) {
		$rRecordings[] = $rRow;
	}

	return $rRecordings;
}

function issecure() {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $port443 = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443;

    return $https || $port443;
}

function getProtocol() {
	if (issecure()) {
		return 'https';
	}

	return 'http';
}

function deleteMovieFile($rServerIDs, $rID) {
	global $db;
	return MovieRepository::deleteFile($db, $rServerIDs, $rID);
}

function generateString($strength = 10) {
	$input = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
	$input_length = strlen($input);
	$random_string = '';

	for ($i = 0; $i < $strength; $i++) {
		$random_character = $input[mt_rand(0, $input_length - 1)];
		$random_string .= $random_character;
	}

	return $random_string;
}

function getAllServers() {
	global $db;
	return ServerRepository::getAllSimple($db);
}

function getStreamingServers(string $type = 'online') {
	global $db;
	global $rPermissions;
	return ServerRepository::getStreamingSimple($db, $rPermissions, $type);
}

function getProxyServers($rOnline = false) {
	global $db;
	global $rPermissions;
	return ServerRepository::getProxySimple($db, $rPermissions, $rOnline);
}

function getStreamPIDs($rServerID) {
	global $db;
	return StreamRepository::getPIDs($db, $rServerID, CoreUtilities::$rSettings);
}

function roundUpToAny($n, $x = 5) {
	return round(($n + $x / 2) / $x) * $x;
}

function checksource($rServerID, $rFilename) {
	return ServerRepository::checkSource(CoreUtilities::$rServers, CoreUtilities::$rFFPROBE, $rServerID, $rFilename);
}

function getSSLLog($rServerID) {
	return ServerRepository::getSSLLog(CoreUtilities::$rServers, $rServerID);
}

function getWatchdog($rID, $rLimit = 86400) {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `servers_stats` WHERE `server_id` = ? AND UNIX_TIMESTAMP() - `time` <= ? ORDER BY `time` DESC;', $rID, $rLimit);

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getMemberGroup($rID) {
	global $db;
	return GroupRepository::getById($db, $rID);
}

function getOutputs() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `output_formats` ORDER BY `access_output_id` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getUserBouquets() {
	global $db;
	return BouquetRepository::getUserBouquets($db);
}

function getBouquets() {
	global $db;
	return BouquetRepository::getAllSimple($db);
}

function getBouquetOrder() {
	global $db;
	return BouquetRepository::getOrder($db);
}

function getBlockedIPs() {
	global $db;
	return BlocklistRepository::getBlockedIPsSimple($db);
}

function getRTMPIPs() {
	global $db;
	return BlocklistRepository::getRTMPIPsSimple($db);
}

function getStream($rID) {
	global $db;
	return StreamRepository::getById($db, $rID);
}

function getUser($rID) {
	global $db;
	return UserRepository::getLineById($db, $rID);
}

function getRegisteredUser($rID) {
	global $db;
	return UserRepository::getRegisteredUserById($db, $rID);
}

function getPageName() {
	return strtolower(basename(get_included_files()[0], '.php'));
}

function sortArrayByArray($rArray, $rSort) {
	if (!(empty($rArray) || empty($rSort))) {
		$rOrdered = array();

		foreach ($rSort as $rValue) {
			if (($rKey = array_search($rValue, $rArray)) === false) {
			} else {
				$rOrdered[] = $rValue;
				unset($rArray[$rKey]);
			}
		}

		return $rOrdered + $rArray;
	} else {
		return array();
	}
}

function cleanValue($rValue) {
	if ($rValue != '') {
		$rValue = str_replace('&#032;', ' ', stripslashes($rValue));
		$rValue = str_replace(array("\r\n", "\n\r", "\r"), "\n", $rValue);
		$rValue = str_replace('<!--', '&#60;&#33;--', $rValue);
		$rValue = str_replace('-->', '--&#62;', $rValue);
		$rValue = str_ireplace('<script', '&#60;script', $rValue);
		$rValue = preg_replace('/&amp;#([0-9]+);/s', '&#\\1;', $rValue);
		$rValue = preg_replace('/&#(\\d+?)([^\\d;])/i', '&#\\1;\\2', $rValue);

		return trim($rValue);
	}

	return '';
}

function getStreamStats($rStreamID) {
	global $db;
	return StreamRepository::getStats($db, $rStreamID);
}

function getSimilarMovies($rID, $rPage = 1) {
	return MovieRepository::getSimilar($rID, $rPage);
}

function getSimilarSeries($rID, $rPage = 1) {
	return SeriesRepository::getSimilar($rID, $rPage);
}

function generateReport($rURL, $rParams) {
	$rPost = http_build_query($rParams);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $rURL);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $rPost);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($ch, CURLOPT_TIMEOUT, 300);

	return curl_exec($ch);
}

function convertToCSV($rData) {
	$rHeader = false;
	$rFilename = TMP_PATH . generateString(32) . '.csv';
	$rFile = fopen($rFilename, 'w');

	foreach ($rData as $rRow) {
		if (!empty($rHeader)) {
		} else {
			$rHeader = array_keys($rRow);
			fputcsv($rFile, $rHeader);
			$rHeader = array_flip($rHeader);
		}

		fputcsv($rFile, array_merge($rHeader, $rRow));
	}
	fclose($rFile);


	return $rFilename;
}

function forceWatch($rServerID, $rWatchID) {
	systemapirequest($rServerID, array('action' => 'watch_force', 'id' => $rWatchID));
}

function forcePlex($rServerID, $rPlexID) {
	systemapirequest($rServerID, array('action' => 'plex_force', 'id' => $rPlexID));
}

function freeTemp($rServerID) {
	ServerRepository::freeTemp('systemapirequest', $rServerID);
}

function freeStreams($rServerID) {
	ServerRepository::freeStreams('systemapirequest', $rServerID);
}

function probeSource($rServerID, $rURL, $rUserAgent = null, $rProxy = null, $rCookies = null, $rHeaders = null) {
	return ServerRepository::probeSource('systemapirequest', $rServerID, $rURL, $rUserAgent, $rProxy, $rCookies, $rHeaders);
}

function getchannelepg($rStreamID, $rArchive = false) {
	$rStream = getStream($rStreamID);
	return EpgService::getChannelEpg($rStream, $rArchive);
}

function getEPG($rID) {
	global $db;
	return EpgRepository::getById($db, $rID);
}

function getStreamOptions($rID) {
	global $db;
	return StreamRepository::getOptions($db, $rID);
}

function getStreamSys($rID) {
	global $db;
	return StreamRepository::getSystemRows($db, $rID);
}

function getRegisteredUsers($rOwner = null, $rIncludeSelf = true) {
	global $db;
	return UserRepository::getRegisteredUsers($db, $rOwner, $rIncludeSelf);
}

function getFooter() {
	return "&copy; 2025 <img height='20px' style='padding-left: 10px; padding-right: 10px; margin-top: -2px;' src='./assets/images/logo-topbar.png' /> v" . XC_VM_VERSION;
}

function scanBouquets() {
	BouquetService::scan();
}

/**
 * Scan and update bouquet with valid stream IDs
 * Removes invalid or non-existent streams from bouquet
 * 
 * @param int $rID Bouquet ID to scan
 */
function scanBouquet($rID) {
	global $db;
	BouquetService::scanOne($db, $rID, 'getBouquet', 'filterIDs');
}

/**
 * Filter and validate array of IDs
 * 
 * @param array $ids Array of IDs to filter
 * @param array $availableIDs Array of valid available IDs
 * @param bool $checkPositive Whether to check for positive integers
 * @return array Filtered array of valid IDs
 */
function filterIDs($ids, $availableIDs, $checkPositive = true) {
	$filtered = [];

	if (!is_array($ids)) {
		return $filtered;
	}

	foreach ($ids as $id) {
		$intID = (int)$id;
		$isValid = (!$checkPositive || $intID > 0) && in_array($intID, $availableIDs);

		if ($isValid) {
			$filtered[] = $intID;
		}
	}

	return $filtered;
}

function getNextOrder() {
	global $db;
	return StreamRepository::getNextOrder($db);
}

function generateSeriesPlaylist($rSeriesNo) {
	global $db;
	return SeriesRepository::generatePlaylist($db, $rSeriesNo);
}

function shutdown_admin() {
	global $db;

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}

/**
 * Retrieves a list of available time zones with their UTC offsets.
 *
 * This function generates an array of time zones, each containing the time zone
 * identifier and its current UTC offset based on the current timestamp. It includes
 * error handling to ensure safe execution and restores the original timezone after
 * processing.
 *
 * @return array An array of associative arrays containing:
 *               - 'zone': The time zone identifier (e.g., 'America/New_York')
 *               - 'diff_from_GMT': The UTC offset (e.g., 'UTC/GMT +00:00')
 * @throws RuntimeException If setting a timezone fails or if timezone_identifiers_list() is unavailable.
 */
function TimeZoneList(): array {
	// Check if timezone_identifiers_list is available
	if (!function_exists('timezone_identifiers_list')) {
		throw new RuntimeException('Timezone identifiers list function is not available.');
	}

	$zones_array = [];
	$timestamp = time();
	$original_timezone = date_default_timezone_get(); // Store original timezone

	try {
		foreach (timezone_identifiers_list() as $key => $zone) {
			// Validate timezone identifier
			if (empty($zone) || !is_string($zone)) {
				continue; // Skip invalid timezone identifiers
			}

			// Attempt to set the timezone
			if (date_default_timezone_set($zone) === false) {
				continue; // Skip if timezone setting fails
			}

			// Store timezone data
			$zones_array[$key] = [
				'zone' => $zone,
				'diff_from_GMT' => '[UTC/GMT ' . date('P', $timestamp) . ']'
			];
		}
	} catch (Exception $e) {
		// Restore original timezone before throwing exception
		date_default_timezone_set($original_timezone);
		throw new RuntimeException('Error processing timezone list: ' . $e->getMessage());
	}

	// Restore original timezone
	date_default_timezone_set($original_timezone);

	return $zones_array;
}
