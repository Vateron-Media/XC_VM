<?php

/**
 * LogoutController — Уничтожение сессии + редирект на login.
 */
class LogoutController extends BaseAdminController
{
	public function index()
	{
		if (function_exists('destroySession')) {
			destroySession();
		}
		$this->redirect('./login');
	}
}
