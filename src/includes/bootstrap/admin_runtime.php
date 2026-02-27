<?php

if (!function_exists('bootstrapAdminStatusConstants')) {
	function bootstrapAdminStatusConstants() {
		require_once MAIN_HOME . 'bootstrap.php';

		if (class_exists('XC_Bootstrap') && method_exists('XC_Bootstrap', 'defineStatusConstants')) {
			XC_Bootstrap::defineStatusConstants();
			return;
		}

		$rStatusMap = array(
			'STATUS_FAILURE' => 0,
			'STATUS_SUCCESS' => 1,
			'STATUS_SUCCESS_MULTI' => 2,
			'STATUS_CODE_LENGTH' => 3,
			'STATUS_NO_SOURCES' => 4,
			'STATUS_DISABLED' => 5,
			'STATUS_NOT_ADMIN' => 6,
			'STATUS_INVALID_EMAIL' => 7,
			'STATUS_INVALID_PASSWORD' => 8,
			'STATUS_INVALID_IP' => 9,
			'STATUS_INVALID_PLAYLIST' => 10,
			'STATUS_INVALID_NAME' => 11,
			'STATUS_INVALID_CAPTCHA' => 12,
			'STATUS_INVALID_CODE' => 13,
			'STATUS_INVALID_DATE' => 14,
			'STATUS_INVALID_FILE' => 15,
			'STATUS_INVALID_GROUP' => 16,
			'STATUS_INVALID_DATA' => 17,
			'STATUS_INVALID_DIR' => 18,
			'STATUS_INVALID_MAC' => 19,
			'STATUS_EXISTS_CODE' => 20,
			'STATUS_EXISTS_NAME' => 21,
			'STATUS_EXISTS_USERNAME' => 22,
			'STATUS_EXISTS_MAC' => 23,
			'STATUS_EXISTS_SOURCE' => 24,
			'STATUS_EXISTS_IP' => 25,
			'STATUS_EXISTS_DIR' => 26,
			'STATUS_SUCCESS_REPLACE' => 27,
			'STATUS_FLUSH' => 28,
			'STATUS_TOO_MANY_RESULTS' => 29,
			'STATUS_SPACE_ISSUE' => 30,
			'STATUS_INVALID_USER' => 31,
			'STATUS_CERTBOT' => 32,
			'STATUS_CERTBOT_INVALID' => 33,
			'STATUS_INVALID_INPUT' => 34,
			'STATUS_NOT_RESELLER' => 35,
			'STATUS_NO_TRIALS' => 36,
			'STATUS_INSUFFICIENT_CREDITS' => 37,
			'STATUS_INVALID_PACKAGE' => 38,
			'STATUS_INVALID_TYPE' => 39,
			'STATUS_INVALID_USERNAME' => 40,
			'STATUS_INVALID_SUBRESELLER' => 41,
			'STATUS_NO_DESCRIPTION' => 42,
			'STATUS_NO_KEY' => 43,
			'STATUS_EXISTS_HMAC' => 44,
			'STATUS_CERTBOT_RUNNING' => 45,
			'STATUS_RESERVED_CODE' => 46,
			'STATUS_NO_TITLE' => 47,
			'STATUS_NO_SOURCE' => 48
		);

		foreach ($rStatusMap as $rStatusConst => $rStatusValue) {
			if (!defined($rStatusConst)) {
				define($rStatusConst, $rStatusValue);
			}
		}
	}
}

if (!function_exists('bootstrapAdminRuntime')) {
	function bootstrapAdminRuntime() {
		global $_INFO;
		global $db;
		global $rDetect;
		global $rMobile;
		global $rTimeout;
		global $rSQLTimeout;
		global $rProtocol;
		global $allServers;
		global $rServers;
		global $rSettings;
		global $rProxyServers;
		global $rPermissions;
		global $language;
		global $allowedLangs;

		$rBootstrapped = false;
		require_once MAIN_HOME . 'bootstrap.php';
		if (class_exists('XC_Bootstrap') && defined('XC_Bootstrap::CONTEXT_ADMIN')) {
			try {
				XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
				$rBootstrapped = true;
			} catch (Throwable $e) {
				$rBootstrapped = false;
			}
		}

		if (!$rBootstrapped) {
			require_once MAIN_HOME . 'core/Database/DatabaseHandler.php';
			require_once INCLUDES_PATH . 'CoreUtilities.php';
			require_once INCLUDES_PATH . 'admin_api.php';
			require_once INCLUDES_PATH . 'reseller_api.php';
			require_once INCLUDES_PATH . 'libs/Translator.php';
			$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
			CoreUtilities::$db = &$db;
			CoreUtilities::init();
			API::$db = &$db;
			API::init();
			ResellerAPI::$db = &$db;
			ResellerAPI::init();
			CoreUtilities::connectRedis();
			register_shutdown_function(function () {
				global $db;
				if (is_object($db)) {
					$db->close_mysql();
				}
			});

			$language = Translator::class;
			$language::init(MAIN_HOME . 'includes/langs/');
		}

		if (defined('SERVER_ID') === false) {
			define('SERVER_ID', intval(CoreUtilities::$rConfig['server_id']));
		}

		require_once INCLUDES_PATH . 'libs/mobiledetect.php';
		$rDetect = new Mobile_Detect();
		$rMobile = $rDetect->isMobile();
		$rTimeout = 15;
		$rSQLTimeout = 10;
		set_time_limit($rTimeout);
		ini_set('mysql.connect_timeout', $rSQLTimeout);
		ini_set('max_execution_time', $rTimeout);
		ini_set('default_socket_timeout', $rTimeout);

		$rProtocol = getProtocol();
		$allServers = ServerRepository::getAllSimple();
		$rServers = ServerRepository::getStreamingSimple($rPermissions);
		$rSettings = CoreUtilities::$rSettings;
		$rProxyServers = ServerRepository::getProxySimple($rPermissions);

		$language = Translator::class;
		$allowedLangs = $language::available();
	}
}
