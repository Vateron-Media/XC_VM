<?php

if (!function_exists('bootstrapAdminSession')) {
	function bootstrapAdminSession() {
		if (session_status() == PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
			$rParams = (session_get_cookie_params() ?: array());
			$rParams['samesite'] = 'Strict';
			session_set_cookie_params($rParams);
			session_start();
		}
	}
}
