<?php
/**
 * PlayerResizeController — Image resize proxy for player panel.
 *
 * Migrated from player/resize.php.
 * Resizes remote/local images and caches the result as PNG.
 * Requires authenticated player session (bootstrap handles this).
 */
class PlayerResizeController extends BasePlayerController
{
	public function index()
	{
		session_write_close();

		if (!isset($GLOBALS['rUserInfo']) || !$GLOBALS['rUserInfo']['id']) {
			exit();
		}

		require MAIN_HOME . 'infrastructure/legacy/player_resize_body.php';
	}
}
