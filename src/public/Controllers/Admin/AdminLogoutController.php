<?php

/**
 * AdminLogoutController — Уничтожение сессии + редирект на login.
 */
class AdminLogoutController extends BaseAdminController
{
	public function index()
	{
		if (function_exists('destroySession')) {
			destroySession();
		}
		$this->redirect('./login');
	}
}
