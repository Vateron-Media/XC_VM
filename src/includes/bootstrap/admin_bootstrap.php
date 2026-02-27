<?php

if (!function_exists('bootstrapAdminInclude')) {
	function bootstrapAdminInclude() {
		require_once __DIR__ . '/admin_session.php';
		require_once __DIR__ . '/admin_runtime.php';

		bootstrapAdminSession();
		bootstrapAdminStatusConstants();
		bootstrapAdminRuntime();

		require_once MAIN_HOME . 'resources/data/admin_constants.php';
	}
}
