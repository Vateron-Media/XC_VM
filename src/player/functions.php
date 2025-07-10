<?php

if (isset($rSkipVerify) || php_sapi_name() != 'cli') {
	session_start();
	list($licensePath) = get_included_files();
	$licensePath = pathinfo($licensePath)['dirname'] . '/';

	if (file_exists('config.php')) {
		require_once 'config.php';
	}

	require_once 'libs/tmdb.php';

	if (!$argc) {
		define('HOST', trim(explode(':', $_SERVER['HTTP_HOST'])[0]));
	}

	if (isset($rConfig)) {
		define('PLATFORM', $rConfig['platform']);
		define('TMP_PATH', $rConfig['tmp_path']);
		define('CACHE_TMP_PATH', TMP_PATH);

		if (!TMP_PATH || file_exists(TMP_PATH)) {
		} else {
			mkdir(TMP_PATH);
		}
	} else {
		if (extension_loaded('xc_vm')) {
			define('MAIN_HOME', '/home/xc_vm/');
			define('BIN_PATH', MAIN_HOME . 'bin/');
			define('PLATFORM', 'xc_vm');
			define('TMP_PATH', MAIN_HOME . 'tmp/player/');
			define('CACHE_TMP_PATH', MAIN_HOME . 'tmp/cache/');
			define('EPG_PATH', MAIN_HOME . 'content/epg/');
		} else {
			echo 'No platform found.';

			exit();
		}
	}

	$_INFO = array();

	if (file_exists(MAIN_HOME . 'config')) {
		$_INFO = parse_ini_file(CONFIG_PATH . 'config.ini');
	} else {
		die('no config found');
	}

	$db = new Database($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);

	if (extension_loaded('xc_vm') && PLATFORM == 'xc_vm') {
		$db->db_connect();
		define('STREAMS_TMP_PATH', MAIN_HOME . 'tmp/cache/streams/');
		define('SERIES_TMP_PATH', MAIN_HOME . 'tmp/cache/series/');
		define('LINES_TMP_PATH', MAIN_HOME . 'tmp/cache/lines/');
		define('CONS_TMP_PATH', MAIN_HOME . 'tmp/opened_cons/');
		define('SIGNALS_TMP_PATH', MAIN_HOME . 'tmp/signals/');
		define('GEOLITE2_BIN', BIN_PATH . 'maxmind/GeoLite2.mmdb');
		define('GEOISP_BIN', BIN_PATH . 'maxmind/GeoIP2-ISP.mmdb');
		define('CONTENT_PATH', MAIN_HOME . 'content/');
	} else {
		if (PLATFORM) {
			$db->db_explicit_connect($rConfig['db_host'], $rConfig['db_port'], $rConfig['db_name'], $rConfig['db_user'], $rConfig['db_pass']);
			define('STREAMS_TMP_PATH', TMP_PATH);
			define('SERIES_TMP_PATH', TMP_PATH);
			define('LINES_TMP_PATH', TMP_PATH);
			define('CONS_TMP_PATH', TMP_PATH);
			define('CONTENT_PATH', TMP_PATH);
		}
	}

	CoreUtilities::$db = &$db;
	CoreUtilities::init();
	define('SERVER_ID', CoreUtilities::getMainID());

	if (PLATFORM == 'xc_vm') {
	} else {
		foreach (array('player_allow_bouquet', 'player_allow_playlist', 'player_opacity', 'player_blur', 'tmdb_language') as $rKey) {
			CoreUtilities::$rSettings[$rKey] = $rConfig[$rKey];
		}

		foreach (array('server_name', 'tmdb_api_key') as $rKey) {
			if (!empty($rConfig[$rKey])) {
				CoreUtilities::$rSettings[$rKey] = $rConfig[$rKey];
			}
		}
		CoreUtilities::$rSettings['player_hide_incompatible'] = false;
		CoreUtilities::$rSettings['disable_hls'] = false;
		CoreUtilities::$rSettings['cloudflare'] = true;
		CoreUtilities::$rSettings['custom_ip_header'] = null;
	}

	$_VERSION = '1.1.6';
	$_PAGE = CoreUtilities::getIncludedFileNameWithoutExtension();
	CoreUtilities::$rSettings['live_streaming_pass'] = md5(sha1(CoreUtilities::$rServers[SERVER_ID]['server_name'] . CoreUtilities::$rServers[SERVER_ID]['server_ip']) . '5f13a731fb85944e5c69ce863b0c990d');

	if (isset($rSkipVerify)) {
	} else {
		if (!(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || CoreUtilities::$rServers[SERVER_ID]['enable_https']) {
			if (isset($_SESSION['phash'])) {
				$rUserInfo = CoreUtilities::getUserInfo($_SESSION['phash'], null, null, true);

				if (!(!$rUserInfo || $_SESSION['pverify'] != md5($rUserInfo['username'] . '||' . $rUserInfo['password']) || !is_null($rUserInfo['exp_date']) && $rUserInfo['exp_date'] <= time() || $rUserInfo['admin_enabled'] == 0 || $rUserInfo['enabled'] == 0)) {
					sort($rUserInfo['bouquet']);
				} else {
					destroySession();
					header('Location: login.php');

					exit();
				}
			} else {
				header('Location: login.php');

				exit();
			}
		} else {
			header('Location: ' . CoreUtilities::$rServers[SERVER_ID]['http_url'] . ltrim($_SERVER['REQUEST_URI'], '/'));

			exit();
		}
	}
} else {
	exit();
}

class Database {
	public $result = null;
	public $dbh = null;
	public $connected = false;

	protected $dbuser = null;
	protected $dbpassword = null;
	protected $dbname = null;
	protected $dbhost = null;
	protected $dbport = null;


	/**
	 * Constructor - Initializes database connection
	 *
	 * @param string $db_user Database username
	 * @param string $db_pass Database password
	 * @param string $db_name Database name
	 * @param string $host Database host
	 * @param int $db_port Database port number
	 */
	public function __construct($db_user, $db_pass, $db_name, $host, $db_port = 3306) {
		$this->dbh = false;
		$this->dbuser = $db_user;
		$this->dbpassword = $db_pass;
		$this->dbname = $db_name;
		$this->dbhost = $host;
		$this->dbport = $db_port;
		$this->db_connect();
	}


	public function close_mysql() {
		if (!$this->connected) {
		} else {
			$this->connected = false;
			$this->dbh = null;
		}

		return true;
	}

	public function __destruct() {
		$this->close_mysql();
	}

	public function ping() {
		try {
			$this->dbh->query('SELECT 1');
		} catch (Exception $e) {
			return false;
		}

		return true;
	}

	public function db_connect() {
		try {
			$this->dbh = new PDO('mysql:host=' . $this->dbhost . ';port=' . $this->dbport . ';dbname=' . $this->dbname. ';charset=utf8mb4', $this->dbuser, $this->dbpassword);

			if (!$this->dbh) {
				exit(json_encode(array('error' => 'MySQL: Cannot connect to database! Please check credentials.')));
			}
		} catch (PDOException $e) {
			exit(json_encode(array('error' => 'MySQL: ' . $e->getMessage())));
		}
		$this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->connected = true;
		$this->dbh->exec('SET NAMES utf8;');

		return true;
	}

	public function db_explicit_connect($rHost, $rPort, $rDatabase, $rUsername, $rPassword) {
		try {
			$this->dbh = new PDO('mysql:host=' . $rHost . ';port=' . $rPort . ';dbname=' . $rDatabase, $rUsername, $rPassword);

			if (!$this->dbh) {
				return false;
			}
		} catch (PDOException $e) {
			return false;
		}
		$this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->connected = true;
		$this->dbh->exec('SET NAMES utf8;');

		return true;
	}

	public function query($query, $buffered = false) {
		if (!$this->dbh) {
			return false;
		}

		$numargs = func_num_args();
		$arg_list = func_get_args();
		$next_arg_list = array();
		$i = 1;

		while ($i < $numargs) {
			if (is_null($arg_list[$i]) || strtolower($arg_list[$i]) == 'null') {
				$next_arg_list[] = null;
			} else {
				$next_arg_list[] = $arg_list[$i];
			}

			$i++;
		}

		if ($buffered !== true) {
		} else {
			$this->dbh->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
		}

		try {
			$this->result = $this->dbh->prepare($query);
			$this->result->execute($next_arg_list);
		} catch (Exception $e) {
			return false;
		}

		return true;
	}

	public function simple_query($query) {
		try {
			$this->result = $this->dbh->query($query);
		} catch (Exception $e) {
			return false;
		}

		return true;
	}

	public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
		if (!($this->dbh && $this->result)) {
			return false;
		}

		$rows = array();

		if (0 >= $this->result->rowCount()) {
		} else {
			foreach ($this->result->fetchAll(PDO::FETCH_ASSOC) as $row) {
				if ($use_id && array_key_exists($column_as_id, $row)) {
					if (isset($rows[$row[$column_as_id]])) {
					} else {
						$rows[$row[$column_as_id]] = array();
					}

					if (!$unique_row) {
						if (!empty($sub_row_id) && array_key_exists($sub_row_id, $row)) {
							$rows[$row[$column_as_id]][$row[$sub_row_id]] = $row;
						} else {
							$rows[$row[$column_as_id]][] = $row;
						}
					} else {
						$rows[$row[$column_as_id]] = $row;
					}
				} else {
					$rows[] = $row;
				}
			}
		}

		$this->result = null;

		return $rows;
	}

	public function get_row() {
		if (!($this->dbh && $this->result)) {
			return false;
		}

		$row = array();

		if (0 >= $this->result->rowCount()) {
		} else {
			$row = $this->result->fetch(PDO::FETCH_ASSOC);
		}

		$this->result = null;

		return $row;
	}

	public function get_col() {
		if (!($this->dbh && $this->result)) {
			return false;
		}

		$row = false;

		if (0 >= $this->result->rowCount()) {
		} else {
			$row = $this->result->fetch();
			$row = $row[0];
		}

		$this->result = null;

		return $row;
	}

	public function escape($string) {
		return $this->dbh->quote($string);
	}

	public function num_fields() {
		$mysqli_num_fields = $this->result->columnCount();

		return (empty($mysqli_num_fields) ? 0 : $mysqli_num_fields);
	}

	public function last_insert_id() {
		$mysql_insert_id = $this->dbh->lastInsertId();

		return (empty($mysql_insert_id) ? 0 : $mysql_insert_id);
	}

	public function num_rows() {
		$mysqli_num_rows = $this->result->rowCount();

		return (empty($mysqli_num_rows) ? 0 : $mysqli_num_rows);
	}
}

class CoreUtilities {
	public static $db = null;
	public static $rRequest = array();
	public static $rSettings = array();
	public static $rServers = array();
	public static $rBlockedISP = array();
	public static $rBouquets = array();
	public static $rCategories = array();
	public static $rAllowedIPs = array();
	public static $rCached = null;

	public static function init() {
		if (empty($_GET)) {
		} else {
			self::cleanGlobals($_GET);
		}

		if (empty($_POST)) {
		} else {
			self::cleanGlobals($_POST);
		}

		if (empty($_SESSION)) {
		} else {
			self::cleanGlobals($_SESSION);
		}

		if (empty($_COOKIE)) {
		} else {
			self::cleanGlobals($_COOKIE);
		}

		$rInput = @self::parseIncomingRecursively($_GET, array());
		self::$rRequest = @self::parseIncomingRecursively($_POST, $rInput);

		if (!self::$db->connected) {
		} else {
			self::$rSettings = self::getSettings();
			self::$rBlockedISP = self::getBlockedISP();
			self::$rBouquets = self::getBouquets();
			self::$rCategories = self::getCategories();
			self::$rAllowedIPs = self::getAllowedIPs();

			if (PLATFORM == 'xc_vm') {
				self::$rCached = (self::$rSettings['enable_cache'] ?: false);
			} else {
				self::$rCached = false;
			}

			if (empty(self::$rSettings['default_timezone'])) {
			} else {
				date_default_timezone_set(self::$rSettings['default_timezone']);
			}

			self::$rServers = self::getServers();
		}
	}

	public static function serialize($rData) {
		if (function_exists('igbinary_serialize') || PLATFORM == 'xc_vm') {
			return igbinary_serialize($rData);
		}

		return serialize($rData);
	}

	public static function unserialize($rData) {
		if (function_exists('igbinary_unserialize') || PLATFORM == 'xc_vm') {
			return igbinary_unserialize($rData);
		}

		return unserialize($rData);
	}

	public static function setCache($rCache, $rData) {
		$rData = self::serialize($rData);
		file_put_contents(TMP_PATH . $rCache, $rData, LOCK_EX);
	}

	public static function getCache($rCache, $rSeconds) {
		if (!file_exists(TMP_PATH . $rCache)) {
		} else {
			if (time() - filemtime(TMP_PATH . $rCache) >= $rSeconds) {
			} else {
				$rData = file_get_contents(TMP_PATH . $rCache);

				return self::unserialize($rData);
			}
		}

		return false;
	}

	public static function getBouquets() {
		$rCache = self::getCache('bouquets', 60);

		if (empty($rCache)) {
			$rOutput = array();

			if (PLATFORM == 'xc_vm') {
			} else {
				$rStreamMap = array();
				self::$db->query('SELECT `id`, `type` FROM streams WHERE `type` IN (1,2,3,4);');

				foreach (self::$db->get_rows() as $rStream) {
					switch ($rStream['type']) {
						case '1':
						case '3':
							$rStreamMap[intval($rStream['id'])] = 'channels';

							break;

						case '2':
							$rStreamMap[intval($rStream['id'])] = 'movies';

							break;

						case '4':
							$rStreamMap[intval($rStream['id'])] = 'radios';

							break;
					}
				}
			}

			self::$db->query('SELECT *, IF(`bouquet_order` > 0, `bouquet_order`, 999) AS `order` FROM `bouquets` ORDER BY `order` ASC;');

			foreach (self::$db->get_rows(true, 'id') as $rID => $rChannels) {
				$rOutput[$rID]['id'] = $rID;
				$rOutput[$rID]['bouquet_name'] = $rChannels['bouquet_name'];
				$rOutput[$rID]['order'] = $rChannels['order'];

				if (PLATFORM == 'xc_vm') {
					$rOutput[$rID]['streams'] = array_merge(json_decode($rChannels['bouquet_channels'], true), json_decode($rChannels['bouquet_movies'], true), json_decode($rChannels['bouquet_radios'], true));
					$rOutput[$rID]['series'] = json_decode($rChannels['bouquet_series'], true);
					$rOutput[$rID]['channels'] = json_decode($rChannels['bouquet_channels'], true);
					$rOutput[$rID]['movies'] = json_decode($rChannels['bouquet_movies'], true);
					$rOutput[$rID]['radios'] = json_decode($rChannels['bouquet_radios'], true);
				} else {
					$rOutput[$rID]['streams'] = json_decode($rChannels['bouquet_channels'], true);
					$rOutput[$rID]['series'] = json_decode($rChannels['bouquet_series'], true);
					$rOutput[$rID]['channels'] = array();
					$rOutput[$rID]['movies'] = array();
					$rOutput[$rID]['radios'] = array();

					foreach ($rOutput[$rID]['streams'] as $rStreamID) {
						$rType = ($rStreamMap[intval($rStreamID)] ?: 'channels');
						$rOutput[$rID][$rType][] = intval($rStreamID);
					}
				}
			}
			self::setCache('bouquets', $rOutput);

			return $rOutput;
		} else {
			return $rCache;
		}
	}

	public static function getStream($rID) {
		if (PLATFORM == 'xc_vm') {
			self::$db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rID);
		} else {
			self::$db->query('SELECT * FROM `streams` LEFT JOIN `webplayer_data` ON `webplayer_data`.`stream_id` = `streams`.`id` WHERE `streams`.`id` = ?;', $rID);
		}

		if (self::$db->num_rows() != 1) {
		} else {
			$rRow = self::$db->get_row();

			if (!(PLATFORM != 'xc_vm' && $rRow['title'])) {
			} else {
				$rRow['stream_display_name'] = $rRow['title'];
			}

			return $rRow;
		}
	}

	public static function getCategories($rType = null) {
		if (is_string($rType)) {
			if (PLATFORM == 'xc_vm') {
				self::$db->query('SELECT t1.* FROM `streams_categories` t1 WHERE t1.category_type = ? GROUP BY t1.id ORDER BY t1.cat_order ASC', $rType);
			} else {
				self::$db->query('SELECT t1.* FROM `stream_categories` t1 WHERE t1.category_type = ? GROUP BY t1.id ORDER BY t1.cat_order ASC', $rType);
			}

			return (0 < self::$db->num_rows() ? self::$db->get_rows(true, 'id') : array());
		}

		$rCache = self::getCache('categories', 20);

		if (empty($rCache)) {
			if (PLATFORM == 'xc_vm') {
				self::$db->query('SELECT t1.* FROM `streams_categories` t1 ORDER BY t1.cat_order ASC');
			} else {
				self::$db->query('SELECT t1.* FROM `stream_categories` t1 ORDER BY t1.cat_order ASC');
			}

			$rCategories = (0 < self::$db->num_rows() ? self::$db->get_rows(true, 'id') : array());
			self::setCache('categories', $rCategories);

			return $rCategories;
		}

		return $rCache;
	}

	public static function getAllowedIPs() {
		if (empty($rAllowedIPs)) {
			$rIPs = array('127.0.0.1', $_SERVER['SERVER_ADDR']);

			foreach (self::$rServers as $rServerID => $rServerInfo) {
				if (empty($rServerInfo['whitelist_ips'])) {
				} else {
					$rIPs = array_merge($rIPs, json_decode($rServerInfo['whitelist_ips'], true));
				}

				$rIPs[] = $rServerInfo['server_ip'];

				if (!$rServerInfo['private_ip']) {
				} else {
					$rIPs[] = $rServerInfo['private_ip'];
				}
			}

			if (empty(self::$rSettings['allowed_ips_admin'])) {
			} else {
				$rIPs = array_merge($rIPs, explode(',', self::$rSettings['allowed_ips_admin']));
			}

			return array_unique($rIPs);
		} else {
			return self::$rAllowedIPs;
		}
	}

	public static function mergeRecursive($rArray) {
		if (is_array($rArray)) {
			$rArrayValues = array();

			foreach ($rArray as $rValue) {
				if (is_scalar($rValue) || is_resource($rValue)) {
					$rArrayValues[] = $rValue;
				} else {
					if (!is_array($rValue)) {
					} else {
						$rArrayValues = array_merge($rArrayValues, self::mergeRecursive($rValue));
					}
				}
			}

			return $rArrayValues;
		} else {
			return $rArray;
		}
	}

	public static function getBlockedISP() {
		$rCache = self::getCache('blocked_isp', 20);

		if ($rCache === false) {
			if (PLATFORM == 'xc_vm') {
				self::$db->query('SELECT id, isp, blocked FROM `blocked_isps`');
			} else {
				self::$db->query('SELECT id, isp, blocked FROM `isp_addon`');
			}

			$rOutput = self::$db->get_rows();
			self::setCache('blocked_isp', $rOutput);

			return $rOutput;
		}

		return $rCache;
	}

	public static function getServers() {
		$rCache = self::getCache('servers', 10);

		if (empty($rCache)) {
			if (!empty($_SERVER['REQUEST_SCHEME'])) {
			} else {
				$_SERVER['REQUEST_SCHEME'] = 'http';
			}

			if (PLATFORM == 'xc_vm') {
				self::$db->query('SELECT * FROM `servers`');
			} else {
				self::$db->query('SELECT * FROM `streaming_servers`');
				$httpsEnabledServers = (json_decode(self::$rSettings['use_https'], true) ?: array());
			}

			$rServers = array();
			$rOnlineStatus = array(1);

			foreach (self::$db->get_rows() as $rRow) {
				if (empty($rRow['domain_name'])) {
					$rURL = escapeshellcmd($rRow['server_ip']);
				} else {
					$rURL = str_replace(array('http://', '/', 'https://'), '', escapeshellcmd(explode(',', $rRow['domain_name'])[0]));
				}

				if (PLATFORM == 'xc_vm') {
					if ($rRow['enable_https'] == 1) {
						$rProtocol = 'https';
					} else {
						$rProtocol = 'http';
					}
				} else {
					if (in_array($rRow['id'], $httpsEnabledServers)) {
						$rProtocol = 'https';
					} else {
						$rProtocol = 'http';
					}

					$rRow['enable_https'] = in_array($rRow['id'], $httpsEnabledServers);
				}

				$rPort = ($rProtocol == 'http' ? intval($rRow['http_broadcast_port']) : intval($rRow['https_broadcast_port']));
				$rRow['server_protocol'] = $rProtocol;
				$rRow['request_port'] = $rPort;
				$rRow['site_url'] = $rProtocol . '://' . $rURL . ':' . $rPort . '/';
				$rRow['http_url'] = 'http://' . $rURL . ':' . intval($rRow['http_broadcast_port']) . '/';
				$rRow['https_url'] = 'https://' . $rURL . ':' . intval($rRow['https_broadcast_port']) . '/';
				$rRow['domains'] = array('protocol' => $rProtocol, 'port' => $rPort, 'urls' => array_filter(array_map('escapeshellcmd', explode(',', $rRow['domain_name']))));

				if (is_numeric($rRow['parent_id'])) {
					$rRow['parent_id'] = array(intval($rRow['parent_id']));
				} else {
					$rRow['parent_id'] = array_map('intval', json_decode($rRow['parent_id'], true));
				}

				$rServers[intval($rRow['id'])] = $rRow;
			}
			self::setCache('servers', $rServers);

			return $rServers;
		} else {
			return $rCache;
		}
	}

	public static function getSettings() {
		$rCache = self::getCache('settings', 20);

		if (empty($rCache)) {
			$rOutput = array();
			self::$db->query('SELECT * FROM `settings`');
			$rRows = self::$db->get_row();

			foreach ($rRows as $rKey => $rValue) {
				$rOutput[$rKey] = $rValue;
			}
			$rOutput['allow_countries'] = json_decode($rOutput['allow_countries'], true);
			self::setCache('settings', $rOutput);

			return $rOutput;
		} else {
			return $rCache;
		}
	}

	public static function cleanGlobals(&$rData, $rIteration = 0) {
		if (10 > $rIteration) {
			foreach ($rData as $rKey => $rValue) {
				if (is_array($rValue)) {
					self::cleanGlobals($rData[$rKey], ++$rIteration);
				} else {
					$rValue = str_replace(chr('0'), '', $rValue);
					$rValue = str_replace('', '', $rValue);
					$rValue = str_replace('', '', $rValue);
					$rValue = str_replace('../', '&#46;&#46;/', $rValue);
					$rValue = str_replace('&#8238;', '', $rValue);
					$rData[$rKey] = $rValue;
				}
			}
		} else {
			return null;
		}
	}

	public static function parseIncomingRecursively(&$rData, $rInput = array(), $rIteration = 0) {
		if (20 > $rIteration) {
			if (is_array($rData)) {
				foreach ($rData as $rKey => $rValue) {
					if (is_array($rValue)) {
						$rInput[$rKey] = self::parseIncomingRecursively($rData[$rKey], array(), $rIteration + 1);
					} else {
						$rKey = self::parseCleanKey($rKey);
						$rValue = self::parseCleanValue($rValue);
						$rInput[$rKey] = $rValue;
					}
				}

				return $rInput;
			} else {
				return $rInput;
			}
		} else {
			return $rInput;
		}
	}

	public static function parseCleanKey($rKey) {
		if ($rKey !== '') {
			$rKey = htmlspecialchars(urldecode($rKey));
			$rKey = str_replace('..', '', $rKey);
			$rKey = preg_replace('/\\_\\_(.+?)\\_\\_/', '', $rKey);

			return preg_replace('/^([\\w\\.\\-\\_]+)$/', '$1', $rKey);
		}

		return '';
	}

	public static function parseCleanValue($rValue) {
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

	public static function getIncludedFileNameWithoutExtension() {
		return strtolower(basename(get_included_files()[0], '.php'));
	}

	public static function getProxyFor($rServerID) {
		return (array_rand(array_keys(self::getProxies($rServerID, false))) ?: null);
	}

	public static function getProxies($rServerID, $rOnline = true) {
		$rReturn = array();

		foreach (self::$rServers as $rProxyID => $rServerInfo) {
			if (!($rServerInfo['server_type'] == 1 && in_array($rServerID, $rServerInfo['parent_id']) && ($rServerInfo['server_online'] || !$rOnline))) {
			} else {
				$rReturn[$rProxyID] = $rServerInfo;
			}
		}

		return $rReturn;
	}

	public static function getDomainName($rForceSSL = false) {
		$rDomainName = null;
		$rKey = ($rForceSSL ? 'https_url' : 'site_url');

		if (self::$rSettings['use_mdomain_in_lists'] == 1) {
			if (PLATFORM == 'xc_vm' && self::$rServers[SERVER_ID]['enable_proxy']) {
				$rProxyID = self::getProxyFor(SERVER_ID);

				if (!$rProxyID) {
				} else {
					$rDomainName = self::$rServers[$rProxyID][$rKey];
				}
			} else {
				$rDomainName = self::$rServers[SERVER_ID][$rKey];
			}
		} else {
			list($serverIPAddress, $serverPort) = explode(':', $_SERVER['HTTP_HOST']);

			if (PLATFORM == 'xc_vm' && $serverIPAddress == self::$rServers[SERVER_ID]['server_ip'] && self::$rServers[SERVER_ID]['enable_proxy']) {
				$rProxyID = self::getProxyFor(SERVER_ID);

				if (!$rProxyID) {
				} else {
					$rDomainName = self::$rServers[$rProxyID][$rKey];
				}
			} else {
				if ($rForceSSL) {
					$rDomainName = 'https://' . $serverIPAddress . ':' . self::$rServers[SERVER_ID]['https_broadcast_port'] . '/';
				} else {
					$rDomainName = self::$rServers[SERVER_ID]['server_protocol'] . '://' . $serverIPAddress . ':' . self::$rServers[SERVER_ID]['request_port'] . '/';
				}
			}
		}

		return $rDomainName;
	}

	public static function getSubtitles($rStreamID, $rSubtitles) {
		if (PLATFORM == 'xc_vm') {
			global $rUserInfo;
			$rDomainName = self::getDomainName(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443);
			$rReturn = array();

			if (!is_array($rSubtitles)) {
			} else {
				$i = 0;

				foreach ($rSubtitles as $rSubtitle) {
					$rLanguage = null;

					foreach (array_keys($rSubtitle['tags']) as $rKey) {
						if (!in_array(strtoupper(explode('-', $rKey)[0]), array('BPS', 'DURATION', 'NUMBER_OF_FRAMES', 'NUMBER_OF_BYTES'))) {
							if ($rKey != 'language') {
							} else {
								$rLanguage = $rSubtitle['tags'][$rKey];

								break;
							}
						} else {
							list(, $rLanguage) = explode('-', $rKey, 2);

							break;
						}
					}

					if ($rLanguage) {
					} else {
						$rLanguage = 'Subtitle #' . ($i + 1);
					}

					$rReturn[] = array('label' => $rLanguage, 'file' => $rDomainName . 'subtitle/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rStreamID . '?sub_id=' . $i . '&webvtt=1', 'kind' => 'subtitles');
					$i++;
				}
			}

			return $rReturn;
		}

		return array();
	}

	public static function mapContentTypesToNumbers($rTypes) {
		$rReturn = array();
		$rTypeInt = array('live' => 1, 'movie' => 2, 'created_live' => 3, 'radio_streams' => 4, 'series' => 5);

		foreach ($rTypes as $rType) {
			$rReturn[] = $rTypeInt[$rType];
		}

		return $rReturn;
	}

	public static function getOrderedCategories($rCategories, $rType = 'movie') {
		$rReturn = array();

		foreach (self::getCategories($rType) as $rCategory) {
			if (!in_array($rCategory['id'], $rCategories)) {
			} else {
				$rReturn[] = array('title' => $rCategory['category_name'], 'id' => $rCategory['id'], 'cat_order' => $rCategory['cat_order']);
			}
		}
		$rTitle = array_column($rReturn, 'cat_order');
		array_multisort($rTitle, SORT_ASC, $rReturn);

		if ($rType != 'live') {
			array_unshift($rReturn, array('id' => '0', 'cat_order' => 0, 'title' => 'All Genres'));
		} else {
			array_unshift($rReturn, array('id' => '0', 'cat_order' => 0, 'title' => 'Most Popular'));
		}

		return $rReturn;
	}

	public static function sortChannels($rChannels) {
		if (PLATFORM == 'xc_vm') {
			if (!(0 < count($rChannels) && file_exists(CACHE_TMP_PATH . 'channel_order') && self::$rSettings['channel_number_type'] != 'bouquet')) {
			} else {
				$rOrder = self::unserialize(file_get_contents(CACHE_TMP_PATH . 'channel_order'));
				$rChannels = array_flip($rChannels);
				$rNewOrder = array();

				foreach ($rOrder as $rID) {
					if (!isset($rChannels[$rID])) {
					} else {
						$rNewOrder[] = $rID;
					}
				}

				if (0 >= count($rNewOrder)) {
				} else {
					return $rNewOrder;
				}
			}

			return $rChannels;
		}

		return $rChannels;
	}

	public static function confirmIDs($rIDs) {
		$rReturn = array();

		foreach ($rIDs as $rID) {
			if (0 >= intval($rID)) {
			} else {
				$rReturn[] = $rID;
			}
		}

		return $rReturn;
	}

	public static function getUserStreams($rUserInfo, $rTypes = array(), $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array(), $rStart = 0, $rLimit = 10, $rIDs = false) {
		global $db;
		$rAdded = false;
		$rChannels = array();

		foreach ($rTypes as $rType) {
			switch ($rType) {
				case 'live':
				case 'created_live':
					if (!$rAdded) {
						$rChannels = array_merge($rChannels, $rUserInfo['live_ids']);
						$rAdded = true;

						break;
					}

					break;

				case 'movie':
					$rChannels = array_merge($rChannels, $rUserInfo['vod_ids']);

					break;

				case 'radio_streams':
					$rChannels = array_merge($rChannels, $rUserInfo['radio_ids']);

					break;

				case 'series':
					$rChannels = array_merge($rChannels, $rUserInfo['episode_ids']);

					break;
			}
		}
		$rStreams = array('count' => 0, 'streams' => array());
		$rKey = $rStart + 1;
		$rWhereV = $rWhere = array();

		if (!self::$rSettings['player_hide_incompatible']) {
		} else {
			$rWhere[] = '(SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1';
		}

		if (0 >= count($rTypes)) {
		} else {
			$rWhere[] = '`type` IN (' . implode(',', self::mapContentTypesToNumbers($rTypes)) . ')';
		}

		if (!empty($rCategoryID)) {
			if (PLATFORM == 'xc_vm') {
				$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
			} else {
				$rWhere[] = '`category_id` = ?';
			}

			$rWhereV[] = $rCategoryID;
		} else {
			if (!(in_array('live', $rTypes) && empty($rSearchBy))) {
			} else {
				$rStart = 0;
				$rLimit = 200;
				$rLiveIDs = self::unserialize(file_get_contents(CONTENT_PATH . 'live_popular'));

				if (!($rLiveIDs && 0 < count($rLiveIDs))) {
				} else {
					$rWhere[] = '`id` IN (' . implode(',', array_map('intval', $rLiveIDs)) . ')';
				}
			}
		}

		if (!$rPicking['filter']) {
		} else {
			switch ($rPicking['filter']) {
				case 'all':
					break;

				case 'timeshift':
					$rWhere[] = '`tv_archive_duration` > 0 AND `tv_archive_server_id` > 0';

					break;
			}
		}

		$rChannels = self::sortChannels($rChannels);

		if (empty($rFav)) {
		} else {
			$favoriteChannelIds = array();

			foreach ($rTypes as $rType) {
				foreach ($rUserInfo['fav_channels'][$rType] as $rStreamID) {
					$favoriteChannelIds[] = intval($rStreamID);
				}
			}
			$rChannels = array_intersect($favoriteChannelIds, $rChannels);
		}

		if (empty($rSearchBy)) {
		} else {
			$rWhere[] = '`stream_display_name` LIKE ?';
			$rWhereV[] = '%' . $rSearchBy . '%';
		}

		if (!is_array($rPicking['year_range'])) {
		} else {
			$rWhere[] = '(`year` >= ? AND `year` <= ?)';
			$rWhereV[] = $rPicking['year_range'][0];
			$rWhereV[] = $rPicking['year_range'][1];
		}

		if (!is_array($rPicking['rating_range'])) {
		} else {
			$rWhere[] = '(`rating` >= ? AND `rating` <= ?)';
			$rWhereV[] = $rPicking['rating_range'][0];
			$rWhereV[] = $rPicking['rating_range'][1];
		}

		$rChannels = self::confirmIDs($rChannels);

		if (count($rChannels) != 0) {
			$rWhere[] = '`id` IN (' . implode(',', array_map('intval', $rChannels)) . ')';
			$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);

			switch ($rOrderBy) {
				case 'name':
					uasort($rStreams['streams'], 'sortArrayStreamName');
					$rOrder = '`stream_display_name` ASC';

					break;

				case 'top':
				case 'rating':
					$rOrder = '`rating` DESC';

					break;

				case 'added':
					$rOrder = '`added` DESC';

					break;

				case 'release':
					$rOrder = '`year` DESC, `stream_display_name` ASC';

					break;

				case 'number':
				default:
					if (self::$rSettings['channel_number_type'] != 'manual' && 0 < count($rChannels)) {
						$rOrder = 'FIELD(id,' . implode(',', $rChannels) . ')';
					} else {
						$rOrder = '`order` ASC';
					}

					break;
			}

			if (0 < count($rChannels)) {
				if (PLATFORM == 'xc_vm') {
					$db->query('SELECT COUNT(`id`) AS `count` FROM `streams` ' . $rWhereString . ';', ...$rWhereV);
				} else {
					$db->query('SELECT COUNT(`id`) AS `count` FROM `streams` LEFT JOIN `webplayer_data` ON `webplayer_data`.`stream_id` = `streams`.`id` ' . $rWhereString . ';', ...$rWhereV);
				}

				$rStreams['count'] = $db->get_row()['count'];

				if ($rLimit) {
					if ($rIDs) {
						if (PLATFORM == 'xc_vm') {
							$rQuery = 'SELECT `id` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrder . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
						} else {
							$rQuery = 'SELECT `id` FROM `streams` LEFT JOIN `webplayer_data` ON `webplayer_data`.`stream_id` = `streams`.`id` ' . $rWhereString . ' ORDER BY ' . $rOrder . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
						}
					} else {
						if (PLATFORM == 'xc_vm') {
							$rQuery = 'SELECT (SELECT `stream_info` FROM `streams_servers` WHERE `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) AS `stream_info`, `id`, `stream_display_name`, `movie_properties`, `target_container`, `added`, `year`, `category_id`, `channel_id`, `epg_id`, `tv_archive_duration`, `stream_icon`, `allow_record`, `type` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrder . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
						} else {
							$rQuery = 'SELECT (SELECT `stream_info` FROM `streams_sys` WHERE `streams_sys`.`pid` IS NOT NULL AND `streams_sys`.`stream_id` = `streams`.`id` LIMIT 1) AS `stream_info`, `id`, `stream_display_name`, `title`, `movie_propeties`, `target_container`, `added`, `year`, `category_id`, `channel_id`, `epg_id`, `tv_archive_duration`, `stream_icon`, `allow_record`, `type` FROM `streams` LEFT JOIN `webplayer_data` ON `webplayer_data`.`stream_id` = `streams`.`id` ' . $rWhereString . ' ORDER BY ' . $rOrder . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
						}
					}
				} else {
					if ($rIDs) {
						if (PLATFORM == 'xc_vm') {
							$rQuery = 'SELECT `id` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrder . ';';
						} else {
							$rQuery = 'SELECT `id` FROM `streams` LEFT JOIN `webplayer_data` ON `webplayer_data`.`stream_id` = `streams`.`id` ' . $rWhereString . ' ORDER BY ' . $rOrder . ';';
						}
					} else {
						if (PLATFORM == 'xc_vm') {
							$rQuery = 'SELECT (SELECT `stream_info` FROM `streams_servers` WHERE `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) AS `stream_info`, `id`, `stream_display_name`, `movie_properties`, `target_container`, `added`, `year`, `category_id`, `channel_id`, `epg_id`, `tv_archive_duration`, `stream_icon`, `allow_record`, `type` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrder . ';';
						} else {
							$rQuery = 'SELECT (SELECT `stream_info` FROM `streams_sys` WHERE `streams_sys`.`pid` IS NOT NULL AND `streams_sys`.`stream_id` = `streams`.`id` LIMIT 1) AS `stream_info`, `id`, `stream_display_name`, `title`, `movie_propeties`, `target_container`, `added`, `year`, `category_id`, `channel_id`, `epg_id`, `tv_archive_duration`, `stream_icon`, `allow_record`, `type` FROM `streams` LEFT JOIN `webplayer_data` ON `webplayer_data`.`stream_id` = `streams`.`id` ' . $rWhereString . ' ORDER BY ' . $rOrder . ';';
						}
					}
				}

				$db->query($rQuery, ...$rWhereV);
				$rRows = $db->get_rows();
			} else {
				$rRows = array();
			}

			if ($rIDs) {
				return $rRows;
			}

			foreach ($rRows as $rStream) {
				$rStream['number'] = $rKey;

				if (PLATFORM != 'xc_vm') {
				} else {
					if (in_array($rCategoryID, json_decode($rStream['category_id'], true))) {
						$rStream['category_id'] = $rCategoryID;
					} else {
						list($rStream['category_id']) = json_decode($rStream['category_id'], true);
					}
				}

				$rStream['stream_info'] = json_decode($rStream['stream_info'], true);
				$rStreams['streams'][$rStream['id']] = $rStream;
				$rKey++;
			}

			return $rStreams;
		} else {
			return $rStreams;
		}
	}

	public static function getUserSeries($rUserInfo, $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array(), $rStart = 0, $rLimit = 10, $additionalOptions = null) {
		global $db;
		$rSeries = $rUserInfo['series_ids'];
		$rStreams = array('count' => 0, 'streams' => array());
		$rKey = $rStart + 1;
		$rWhereV = $rWhere = array();

		if (!self::$rSettings['player_hide_incompatible']) {
		} else {
			$rWhere[] = '(SELECT MAX(`compatible`) FROM `streams_servers` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams_servers`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) = 1';
		}

		if (empty($rCategoryID)) {
		} else {
			if (PLATFORM == 'xc_vm') {
				$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
			} else {
				$rWhere[] = '`category_id` = ?';
			}

			$rWhereV[] = $rCategoryID;
		}

		if (!is_array($rPicking['year_range'])) {
		} else {
			if (PLATFORM == 'xc_vm') {
				$rWhere[] = '(`year` >= ? AND `year` <= ?)';
			} else {
				$rWhere[] = '(LEFT(`releaseDate`, 4) >= ? AND LEFT(`releaseDate`, 4) <= ?)';
			}

			$rWhereV[] = $rPicking['year_range'][0];
			$rWhereV[] = $rPicking['year_range'][1];
		}

		if (!is_array($rPicking['rating_range'])) {
		} else {
			$rWhere[] = '(`rating` >= ? AND `rating` <= ?)';
			$rWhereV[] = $rPicking['rating_range'][0];
			$rWhereV[] = $rPicking['rating_range'][1];
		}

		if (empty($rSearchBy)) {
		} else {
			$rWhere[] = '`title` LIKE ?';
			$rWhereV[] = '%' . $rSearchBy . '%';
		}

		$rSeries = self::confirmIDs($rSeries);

		if (count($rSeries) != 0) {
			$rWhere[] = '`id` IN (' . implode(',', array_map('intval', $rSeries)) . ')';
			$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);

			switch ($rOrderBy) {
				case 'name':
					uasort($rStreams['streams'], 'sortArrayStreamName');
					$rOrder = '`title` ASC';

					break;

				case 'top':
				case 'rating':
					$rOrder = '`rating` DESC';

					break;

				case 'added':
					if (PLATFORM == 'xc_vm') {
						$rOrder = '`last_modified` DESC';
					} else {
						$rOrder = '`id` DESC';
					}

					break;

				case 'release':
					if (PLATFORM == 'xc_vm') {
						$rOrder = '`release_date` DESC';
					} else {
						$rOrder = '`releaseDate` DESC';
					}

					break;

				case 'number':
				default:
					if (PLATFORM == 'xc_vm' && CoreUtilities::$rSettings['vod_sort_newest']) {
						$rOrder = '`last_modified` DESC';
					} else {
						$rOrder = 'FIELD(id,' . implode(',', $rSeries) . ')';
					}

					break;
			}

			if (0 < count($rSeries)) {
				if (PLATFORM == 'xc_vm') {
					$db->query('SELECT COUNT(`id`) AS `count` FROM `streams_series` ' . $rWhereString . ';', ...$rWhereV);
				} else {
					$db->query('SELECT COUNT(`id`) AS `count` FROM `series` ' . $rWhereString . ';', ...$rWhereV);
				}

				$rStreams['count'] = $db->get_row()['count'];

				if ($rLimit) {
					if (PLATFORM == 'xc_vm') {
						$rQuery = 'SELECT `id`, `title`, `category_id`, `cover`, `rating`, `release_date`, `last_modified`, `tmdb_id`, `seasons`, `backdrop_path`, `year` FROM `streams_series` ' . $rWhereString . ' ORDER BY ' . $rOrder . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
					} else {
						$rQuery = 'SELECT `id`, `title`, `category_id`, `cover`, `rating`, `releaseDate`, `tmdb_id`, `seasons`, `backdrop_path` FROM `series` ' . $rWhereString . ' ORDER BY ' . $rOrder . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
					}
				} else {
					if (PLATFORM == 'xc_vm') {
						$rQuery = 'SELECT `id`, `title`, `category_id`, `cover`, `rating`, `release_date`, `last_modified`, `tmdb_id`, `seasons`, `backdrop_path`, `year` FROM `streams_series` ' . $rWhereString . ' ORDER BY ' . $rOrder . ';';
					} else {
						$rQuery = 'SELECT `id`, `title`, `category_id`, `cover`, `rating`, `releaseDate`, `tmdb_id`, `seasons`, `backdrop_path` FROM `series` ' . $rWhereString . ' ORDER BY ' . $rOrder . ';';
					}
				}

				$db->query($rQuery, ...$rWhereV);
				$rRows = $db->get_rows();
			} else {
				if ($additionalOptions) {
					return null;
				}

				$rRows = array();
			}

			foreach ($rRows as $rStream) {
				$rStream['number'] = $rKey;

				if (PLATFORM != 'xc_vm') {
				} else {
					if (in_array($rCategoryID, json_decode($rStream['category_id'], true))) {
						$rStream['category_id'] = $rCategoryID;
					} else {
						list($rStream['category_id']) = json_decode($rStream['category_id'], true);
					}
				}

				$rStreams['streams'][$rStream['id']] = $rStream;
				$rKey++;
			}

			return $rStreams;
		} else {
			return $rStreams;
		}
	}

	public static function getSerie($rID) {
		if (PLATFORM == 'xc_vm') {
			self::$db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rID);
		} else {
			self::$db->query('SELECT `series`.*, `webplayer_data`.`similar` FROM `series` LEFT JOIN `webplayer_data` ON `webplayer_data`.`series_id` = `series`.`id` WHERE `id` = ?;', $rID);
		}

		if (self::$db->num_rows() != 1) {
		} else {
			return self::$db->get_row();
		}
	}

	public static function getIPInfo($rIP) {
		if (PLATFORM == 'xc_vm') {
			if (!empty($rIP)) {
				if (!file_exists(CONS_TMP_PATH . md5($rIP) . '_geo2')) {
					$rGeoIP = new MaxMind\Db\Reader(GEOLITE2_BIN);
					$rResponse = $rGeoIP->get($rIP);
					$rGeoIP->close();

					if (!$rResponse) {
					} else {
						file_put_contents(CONS_TMP_PATH . md5($rIP) . '_geo2', json_encode($rResponse));
					}

					return $rResponse;
				}

				return json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_geo2'), true);
			}

			return false;
		}

		return false;
	}

	public static function getISP($rIP) {
		if (PLATFORM == 'xc_vm') {
			if (!empty($rIP)) {
				if (!file_exists(CONS_TMP_PATH . md5($rIP) . '_isp')) {
					$rGeoIP = new MaxMind\Db\Reader(GEOISP_BIN);
					$rResponse = $rGeoIP->get($rIP);
					$rGeoIP->close();

					if (!$rResponse) {
					} else {
						file_put_contents(CONS_TMP_PATH . md5($rIP) . '_isp', json_encode($rResponse));
					}

					return $rResponse;
				}

				return json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_isp'), true);
			}

			return false;
		}

		return false;
	}

	public static function checkISP($rConISP) {
		if (PLATFORM == 'xc_vm') {
			foreach (self::$rBlockedISP as $rISP) {
				if (strtolower($rConISP) != strtolower($rISP['isp'])) {
				} else {
					return intval($rISP['blocked']);
				}
			}

			return 0;
		} else {
			return false;
		}
	}

	public static function getUserInfo($rUserID = null, $rUsername = null, $rPassword = null, $rGetChannelIDs = false, $rGetConnections = false, $rIP = '') {
		$rUserInfo = null;

		if (self::$rCached) {
			if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
				if (self::$rSettings['case_sensitive_line']) {
					$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_t_' . $rUsername));
				} else {
					$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_t_' . strtolower($rUsername)));
				}
			} else {
				if (!empty($rUsername) && !empty($rPassword)) {
					if (self::$rSettings['case_sensitive_line']) {
						$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_c_' . $rUsername . '_' . $rPassword));
					} else {
						$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_c_' . strtolower($rUsername) . '_' . strtolower($rPassword)));
					}
				} else {
					if (!empty($rUserID)) {
					} else {
						return false;
					}
				}
			}

			if (!$rUserID) {
			} else {
				$rUserInfo = self::unserialize(file_get_contents(LINES_TMP_PATH . 'line_i_' . $rUserID));
			}
		} else {
			if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
				if (PLATFORM == 'xc_vm') {
					self::$db->query('SELECT * FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 0 AND `access_token` = ? AND LENGTH(`access_token`) = 32', $rUsername);
				} else {
					return false;
				}
			} else {
				if (!empty($rUsername) && !empty($rPassword)) {
					if (PLATFORM == 'xc_vm') {
						self::$db->query('SELECT * FROM `lines` WHERE `username` = ? AND `password` = ? LIMIT 1', $rUsername, $rPassword);
					} else {
						self::$db->query('SELECT * FROM `users` WHERE `username` = ? AND `password` = ? LIMIT 1', $rUsername, $rPassword);
					}
				} else {
					if (!empty($rUserID)) {
						if (PLATFORM == 'xc_vm') {
							self::$db->query('SELECT * FROM `lines` WHERE `id` = ?', $rUserID);
						} else {
							self::$db->query('SELECT * FROM `users` WHERE `id` = ?', $rUserID);
						}
					} else {
						return false;
					}
				}
			}

			if (0 >= self::$db->num_rows()) {
			} else {
				$rUserInfo = self::$db->get_row();
			}
		}

		if (!$rUserInfo) {
			return false;
		}

		if (!(PLATFORM == 'xc_vm' && self::$rSettings['county_override_1st'] == 1 && empty($rUserInfo['forced_country']) && !empty($rIP) && $rUserInfo['max_connections'] == 1)) {
		} else {
			$rUserInfo['forced_country'] = self::getIPInfo($rIP)['registered_country']['iso_code'];

			if (self::$rCached) {
				self::setSignal('forced_country/' . $rUserInfo['id'], $rUserInfo['forced_country']);
			} else {
				if (PLATFORM == 'xc_vm') {
					self::$db->query('UPDATE `lines` SET `forced_country` = ? WHERE `id` = ?', $rUserInfo['forced_country'], $rUserInfo['id']);
				} else {
					self::$db->query('UPDATE `users` SET `forced_country` = ? WHERE `id` = ?', $rUserInfo['forced_country'], $rUserInfo['id']);
				}
			}
		}

		$rUserInfo['bouquet'] = json_decode($rUserInfo['bouquet'], true);
		$rUserInfo['allowed_ips'] = @array_filter(@array_map('trim', @json_decode($rUserInfo['allowed_ips'], true)));
		$rUserInfo['allowed_ua'] = @array_filter(@array_map('trim', @json_decode($rUserInfo['allowed_ua'], true)));

		if (PLATFORM == 'xc_vm') {
			$rUserInfo['allowed_outputs'] = array_map('intval', json_decode($rUserInfo['allowed_outputs'], true));
		} else {
			$rUserInfo['allowed_outputs'] = array();
		}

		$rUserInfo['output_formats'] = array();

		if (self::$rCached) {
			foreach (self::unserialize(file_get_contents(CACHE_TMP_PATH . 'output_formats')) as $rRow) {
				if (!in_array(intval($rRow['access_output_id']), $rUserInfo['allowed_outputs'])) {
				} else {
					$rUserInfo['output_formats'][] = $rRow['output_key'];
				}
			}
		} else {
			if (PLATFORM == 'xc_vm') {
				self::$db->query('SELECT `access_output_id`, `output_key` FROM `output_formats`;');

				foreach (self::$db->get_rows() as $rRow) {
					if (!in_array(intval($rRow['access_output_id']), $rUserInfo['allowed_outputs'])) {
					} else {
						$rUserInfo['output_formats'][] = $rRow['output_key'];
					}
				}
			} else {
				self::$db->query('SELECT `user_output`.`access_output_id`, `access_output`.`output_key` FROM `user_output` LEFT JOIN `access_output` ON `user_output`.`access_output_id` = `access_output`.`access_output_id` WHERE `user_output`.`user_id` = ?;', $rUserInfo['id']);

				foreach (self::$db->get_rows() as $rRow) {
					$rUserInfo['allowed_outputs'][] = $rRow['access_output_id'];
					$rUserInfo['output_formats'][] = $rRow['output_key'];
				}
			}
		}

		$rUserInfo['con_isp_name'] = null;
		$rUserInfo['isp_violate'] = 0;
		$rUserInfo['isp_is_server'] = 0;

		if (!(PLATFORM == 'xc_vm' && self::$rSettings['show_isps'] == 1) || empty($rIP)) {
		} else {
			$rISPLock = self::getISP($rIP);

			if (!is_array($rISPLock)) {
			} else {
				if (empty($rISPLock['isp'])) {
				} else {
					$rUserInfo['con_isp_name'] = $rISPLock['isp'];
					$rUserInfo['isp_asn'] = $rISPLock['autonomous_system_number'];
					$rUserInfo['isp_violate'] = self::checkISP($rUserInfo['con_isp_name']);
				}
			}

			if (!(!empty($rUserInfo['con_isp_name']) && self::$rSettings['enable_isp_lock'] == 1 && $rUserInfo['is_stalker'] == 0 && $rUserInfo['is_isplock'] == 1 && !empty($rUserInfo['isp_desc']) && strtolower($rUserInfo['con_isp_name']) != strtolower($rUserInfo['isp_desc']))) {
			} else {
				$rUserInfo['isp_violate'] = 1;
			}

			if (!($rUserInfo['isp_violate'] == 0 && strtolower($rUserInfo['con_isp_name']) != strtolower($rUserInfo['isp_desc']))) {
			} else {
				if (self::$rCached) {
					self::setSignal('isp/' . $rUserInfo['id'], json_encode(array($rUserInfo['con_isp_name'], $rUserInfo['isp_asn'])));
				} else {
					if (PLATFORM == 'xc_vm') {
						self::$db->query('UPDATE `lines` SET `isp_desc` = ?, `as_number` = ? WHERE `id` = ?', $rUserInfo['con_isp_name'], $rUserInfo['isp_asn'], $rUserInfo['id']);
					} else {
						self::$db->query('UPDATE `users` SET `isp_desc` = ? WHERE `id` = ?', $rUserInfo['con_isp_name'], $rUserInfo['id']);
					}
				}
			}
		}

		if (!$rGetChannelIDs) {
		} else {
			$rLiveIDs = $rVODIDs = $rRadioIDs = $rCategoryIDs = $rChannelIDs = $rSeriesIDs = array();

			foreach ($rUserInfo['bouquet'] as $rID) {
				if (!isset(self::$rBouquets[$rID]['streams'])) {
				} else {
					$rChannelIDs = array_merge($rChannelIDs, self::$rBouquets[$rID]['streams']);
				}

				if (!isset(self::$rBouquets[$rID]['series'])) {
				} else {
					$rSeriesIDs = array_merge($rSeriesIDs, self::$rBouquets[$rID]['series']);
				}

				if (!isset(self::$rBouquets[$rID]['channels'])) {
				} else {
					$rLiveIDs = array_merge($rLiveIDs, self::$rBouquets[$rID]['channels']);
				}

				if (!isset(self::$rBouquets[$rID]['movies'])) {
				} else {
					$rVODIDs = array_merge($rVODIDs, self::$rBouquets[$rID]['movies']);
				}

				if (!isset(self::$rBouquets[$rID]['radios'])) {
				} else {
					$rRadioIDs = array_merge($rRadioIDs, self::$rBouquets[$rID]['radios']);
				}
			}
			$rUserInfo['channel_ids'] = array_map('intval', array_unique($rChannelIDs));
			$rUserInfo['series_ids'] = array_map('intval', array_unique($rSeriesIDs));
			$rUserInfo['vod_ids'] = array_map('intval', array_unique($rVODIDs));
			$rUserInfo['live_ids'] = array_map('intval', array_unique($rLiveIDs));
			$rUserInfo['radio_ids'] = array_map('intval', array_unique($rRadioIDs));

			if (self::$rCached) {
				$channelCategoryMap = self::unserialize(file_get_contents(STREAMS_TMP_PATH . 'channels_categories'));
				$seriesCategoryMap = self::unserialize(file_get_contents(SERIES_TMP_PATH . 'series_categories'));

				if (0 >= count($rUserInfo['channel_ids'])) {
				} else {
					foreach ($rUserInfo['channel_ids'] as $rStreamID) {
						if (!isset($channelCategoryMap[$rStreamID])) {
						} else {
							foreach (array_values($channelCategoryMap[$rStreamID]) as $rCategoryID) {
								if (!$rCategoryID || in_array($rCategoryID, $rCategoryIDs)) {
								} else {
									$rCategoryIDs[] = $rCategoryID;
								}
							}
						}
					}
				}

				if (0 >= count($rUserInfo['series_ids'])) {
				} else {
					foreach ($rUserInfo['series_ids'] as $rSeriesID) {
						if (!isset($seriesCategoryMap[$rSeriesID])) {
						} else {
							foreach (array_values($seriesCategoryMap[$rSeriesID]) as $rCategoryID) {
								if (!$rCategoryID || in_array($rCategoryID, $rCategoryIDs)) {
								} else {
									$rCategoryIDs[] = $rCategoryID;
								}
							}
						}
					}
				}
			} else {
				if (0 >= count($rUserInfo['channel_ids'])) {
				} else {
					self::$db->query('SELECT DISTINCT(`category_id`) FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rUserInfo['channel_ids'])) . ');');

					foreach (self::$db->get_rows(true, 'category_id') as $rGroup) {
						if (PLATFORM == 'xc_vm') {
							foreach (json_decode($rGroup['category_id'], true) as $rCategoryID) {
								if (in_array($rCategoryID, $rCategoryIDs)) {
								} else {
									$rCategoryIDs[] = $rCategoryID;
								}
							}
						} else {
							$rCategoryIDs[] = $rGroup['category_id'];
						}
					}
				}

				if (0 >= count($rUserInfo['series_ids'])) {
				} else {
					if (PLATFORM == 'xc_vm') {
						self::$db->query('SELECT DISTINCT(`category_id`) FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rUserInfo['series_ids'])) . ');');
					} else {
						self::$db->query('SELECT DISTINCT(`category_id`) FROM `series` WHERE `id` IN (' . implode(',', array_map('intval', $rUserInfo['series_ids'])) . ');');
					}

					foreach (self::$db->get_rows(true, 'category_id') as $rGroup) {
						if (PLATFORM == 'xc_vm') {
							foreach (json_decode($rGroup['category_id'], true) as $rCategoryID) {
								if (in_array($rCategoryID, $rCategoryIDs)) {
								} else {
									$rCategoryIDs[] = $rCategoryID;
								}
							}
						} else {
							$rCategoryIDs[] = $rGroup['category_id'];
						}
					}
				}
			}

			$rUserInfo['category_ids'] = array_map('intval', array_unique($rCategoryIDs));
		}

		return $rUserInfo;
	}

	public static function setSignal($rKey, $rData) {
		if (PLATFORM == 'xc_vm') {
			file_put_contents(SIGNALS_TMP_PATH . 'cache_' . md5($rKey), json_encode(array($rKey, $rData)));
		} else {
			return false;
		}
	}

	public static function getMainID() {
		foreach (self::$rServers as $rServerID => $rServer) {
			if (!(isset($rServer['is_main']) && $rServer['is_main'] == 1 || isset($rServer['can_delete']) && $rServer['can_delete'] == 0)) {
			} else {
				return $rServerID;
			}
		}
	}

	public static function getUserIP() {
		return $_SERVER['REMOTE_ADDR'];
	}

	public static function checkFlood($rIP = null) {
		if (self::$rSettings['flood_limit'] != 0) {
			if ($rIP) {
			} else {
				$rIP = self::getUserIP();
			}

			if (!(empty($rIP) || in_array($rIP, self::$rAllowedIPs))) {
				$rFloodExclude = array_filter(array_unique(explode(',', self::$rSettings['flood_ips_exclude'])));

				if (!in_array($rIP, $rFloodExclude)) {
					$rIPFile = TMP_PATH . $rIP;

					if (file_exists($rIPFile)) {
						$rFloodRow = json_decode(file_get_contents($rIPFile), true);
						$rFloodSeconds = self::$rSettings['flood_seconds'];
						$rFloodLimit = self::$rSettings['flood_limit'];

						if (time() - $rFloodRow['last_request'] <= $rFloodSeconds) {
							$rFloodRow['requests']++;
							$rFloodRow['last_request'] = time();
							file_put_contents($rIPFile, json_encode($rFloodRow), LOCK_EX);

							if ($rFloodLimit > $rFloodRow['requests']) {
							} else {
								sleep(10);

								exit();
							}
						} else {
							$rFloodRow['requests'] = 0;
							$rFloodRow['last_request'] = time();
							file_put_contents($rIPFile, json_encode($rFloodRow), LOCK_EX);
						}
					} else {
						file_put_contents($rIPFile, json_encode(array('requests' => 0, 'last_request' => time())), LOCK_EX);
					}
				} else {
					return null;
				}
			} else {
				return null;
			}
		} else {
			return null;
		}
	}

	public static function validateImage($rURL, $rForceProtocol = null) {
		if (PLATFORM == 'xc_vm') {
			if (substr($rURL, 0, 2) == 's:') {
				$rSplit = explode(':', $rURL, 3);
				$rServerURL = self::getPublicURL(intval($rSplit[1]), $rForceProtocol);

				if ($rServerURL) {
					return $rServerURL . 'images/' . basename($rURL);
				}

				return '';
			}

			return $rURL;
		}

		return $rURL;
	}

	public static function getPublicURL($rServerID = null, $rForceProtocol = null) {
		$rOriginatorID = null;

		if (isset($rServerID)) {
		} else {
			$rServerID = SERVER_ID;
		}

		if ($rForceProtocol) {
			$rProtocol = $rForceProtocol;
		} else {
			if (isset($_SERVER['SERVER_PORT']) && self::$rSettings['keep_protocol']) {
				$rProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http');
			} else {
				$rProtocol = self::$rServers[$rServerID]['server_protocol'];
			}
		}

		if (!self::$rServers[$rServerID]) {
		} else {
			if (!self::$rServers[$rServerID]['enable_proxy']) {
			} else {
				$rProxyIDs = array_keys(self::getProxies($rServerID));

				if (count($rProxyIDs) != 0) {
				} else {
					$rProxyIDs = array_keys(self::getProxies($rServerID, false));
				}

				if (count($rProxyIDs) != 0) {
					$rOriginatorID = $rServerID;
					$rServerID = $rProxyIDs[array_rand($rProxyIDs)];
				} else {
					return '';
				}
			}

			$rHost = (defined('host') ? HOST : null);

			if ($rHost && in_array(strtolower($rHost), array_map('strtolower', self::$rServers[$rServerID]['domains']['urls']))) {
				$rDomain = $rHost;
			} else {
				$rDomain = (empty(self::$rServers[$rServerID]['domain_name']) ? self::$rServers[$rServerID]['server_ip'] : explode(',', self::$rServers[$rServerID]['domain_name'])[0]);
			}

			$rServerURL = $rProtocol . '://' . $rDomain . ':' . self::$rServers[$rServerID][$rProtocol . '_broadcast_port'] . '/';

			if (!(self::$rServers[$rServerID]['server_type'] == 1 && $rOriginatorID && self::$rServers[$rOriginatorID]['is_main'] == 0)) {
			} else {
				$rServerURL .= md5($rServerID . '_' . $rOriginatorID . '_' . OPENSSL_EXTRA) . '/';
			}

			return $rServerURL;
		}
	}

	public static function getMovieTMDB($rID) {
		if (0 < strlen(self::$rSettings['tmdb_language'])) {
			$rTMDB = new TMDB(self::$rSettings['tmdb_api_key'], self::$rSettings['tmdb_language']);
		} else {
			$rTMDB = new TMDB(self::$rSettings['tmdb_api_key']);
		}

		return ($rTMDB->getMovie($rID) ?: null);
	}

	public static function getSeriesTMDB($rID) {
		if (0 < strlen(self::$rSettings['tmdb_language'])) {
			$rTMDB = new TMDB(self::$rSettings['tmdb_api_key'], self::$rSettings['tmdb_language']);
		} else {
			$rTMDB = new TMDB(self::$rSettings['tmdb_api_key']);
		}

		return (json_decode($rTMDB->getTVShow($rID)->getJSON(), true) ?: null);
	}

	public static function getSeasonTMDB($rID, $rSeason) {
		if (0 < strlen(self::$rSettings['tmdb_language'])) {
			$rTMDB = new TMDB(self::$rSettings['tmdb_api_key'], self::$rSettings['tmdb_language']);
		} else {
			$rTMDB = new TMDB(self::$rSettings['tmdb_api_key']);
		}

		return json_decode($rTMDB->getSeason($rID, intval($rSeason))->getJSON(), true);
	}

	public static function getSimilarMovies($rID, $rPage = 1) {
		if (0 < strlen(self::$rSettings['tmdb_language'])) {
			$rTMDB = new TMDB(self::$rSettings['tmdb_api_key'], self::$rSettings['tmdb_language']);
		} else {
			$rTMDB = new TMDB(self::$rSettings['tmdb_api_key']);
		}

		return json_decode(json_encode($rTMDB->getSimilarMovies($rID, $rPage)), true);
	}

	public static function getSimilarSeries($rID, $rPage = 1) {
		if (0 < strlen(self::$rSettings['tmdb_language'])) {
			$rTMDB = new TMDB(self::$rSettings['tmdb_api_key'], self::$rSettings['tmdb_language']);
		} else {
			$rTMDB = new TMDB(self::$rSettings['tmdb_api_key']);
		}

		return json_decode(json_encode($rTMDB->getSimilarSeries($rID, $rPage)), true);
	}

	public static function getYear($rTitle, $rProperties) {
		$rYear = null;

		if (!isset($rProperties['release_date'])) {
		} else {
			$rYear = substr($rProperties['release_date'], 0, 4);
		}

		if (!isset($rProperties['releaseDate'])) {
		} else {
			$rYear = substr($rProperties['releaseDate'], 0, 4);
		}

		$rRegex = '/\\(([0-9)]+)\\)/';
		preg_match($rRegex, $rTitle, $rMatches, PREG_OFFSET_CAPTURE, 0);
		$rTitleYear = null;
		$rMatchType = 0;

		if (count($rMatches) == 2) {
			$rTitleYear = intval($rMatches[1][0]);
			$rMatchType = 1;
		} else {
			$rSplit = explode('-', $rTitle);

			if (!(1 < count($rSplit) && is_numeric(trim(end($rSplit))))) {
			} else {
				$rTitleYear = intval(trim(end($rSplit)));
				$rMatchType = 2;
			}
		}

		if (0 >= $rMatchType) {
		} else {
			if (!(1900 <= $rTitleYear && $rTitleYear <= intval(date('Y') + 1))) {
			} else {
				if (!empty($rYear)) {
				} else {
					$rYear = $rTitleYear;
				}

				if ($rMatchType == 1) {
					$rTitle = trim(preg_replace('!\\s+!', ' ', str_replace($rMatches[0][0], '', $rTitle)));
				} else {
					$rTitle = trim(implode('-', array_slice($rSplit, 0, -1)));
				}
			}
		}

		return array('title' => $rTitle, 'year' => $rYear);
	}

	public static function encrypt($rData, $decryptionKey, $rDeviceID) {
		return self::base64url_encode(openssl_encrypt($rData, 'aes-256-cbc', md5(sha1($rDeviceID) . $decryptionKey), OPENSSL_RAW_DATA, substr(md5(sha1($decryptionKey)), 0, 16)));
	}

	public static function decrypt($rData, $decryptionKey, $rDeviceID) {
		return openssl_decrypt(self::base64url_decode($rData), 'aes-256-cbc', md5(sha1($rDeviceID) . $decryptionKey), OPENSSL_RAW_DATA, substr(md5(sha1($decryptionKey)), 0, 16));
	}

	private static function base64url_encode($rData) {
		return rtrim(strtr(base64_encode($rData), '+/', '-_'), '=');
	}

	private static function base64url_decode($rData) {
		return base64_decode(strtr($rData, '-_', '+/'));
	}

	public static function getMAC() {
		exec('ip --json address list', $rOutput);
		$rAddresses = json_decode(implode('', $rOutput), true);
		$rValidMAC = null;

		foreach ($rAddresses as $rAddress) {
			foreach ($rAddress['addr_info'] as $rInterface) {
				if ($rInterface['label'] == 'lo' || empty($rInterface['local'])) {
				} else {
					if (!(filter_var($rAddress['address'], FILTER_VALIDATE_MAC) && $rAddress['address'] != '00:00:00:00:00:00')) {
					} else {
						$rValidMAC = $rAddress['address'];

						break;
					}
				}
			}
		}

		return $rValidMAC;
	}

	public static function getEPG($rStreamID, $rStartDate = null, $rFinishDate = null, $rByID = false) {
		$rReturn = array();
		$rData = (file_exists(EPG_PATH . 'stream_' . $rStreamID) ? igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStreamID)) : array());

		foreach ($rData as $rItem) {
			if ($rStartDate && !($rStartDate < $rItem['end'] && $rItem['start'] < $rFinishDate)) {
			} else {
				if ($rByID) {
					$rReturn[$rItem['id']] = $rItem;
				} else {
					$rReturn[] = $rItem;
				}
			}
		}

		return $rReturn;
	}

	public static function getEPGs($rStreamIDs, $rStartDate = null, $rFinishDate = null) {
		$rReturn = array();

		foreach ($rStreamIDs as $rStreamID) {
			$rReturn[$rStreamID] = self::getEPG($rStreamID, $rStartDate, $rFinishDate);
		}

		return $rReturn;
	}

	public static function getProgramme($rStreamID, $rProgrammeID) {
		$rData = self::getEPG($rStreamID, null, null, true);

		if (!isset($rData[$rProgrammeID])) {
		} else {
			return $rData[$rProgrammeID];
		}
	}
}

function sortArrayStreamName($a, $b) {
	$rColumn = (isset($a['stream_display_name']) ? 'stream_display_name' : 'title');

	return strcmp($a[$rColumn], $b[$rColumn]);
}

function destroySession() {
	global $_SESSION;

	foreach (array('phash', 'pverify') as $rKey) {
		if (!isset($_SESSION[$rKey])) {
		} else {
			unset($_SESSION[$rKey]);
		}
	}
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
