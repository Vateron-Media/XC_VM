<?php

/**
 * SetupController — Начальная настройка системы + MySQL admin.
 *
 * setup.php и database.php работают до полного bootstrap (возможно нет БД).
 * Оба файла содержат собственные HTML-документы.
 */
class SetupController extends BaseAdminController
{
	/**
	 * Страница первичной настройки.
	 */
	public function index()
	{
		@chdir(MAIN_HOME . 'public/Views/admin/');
		require MAIN_HOME . 'public/Views/admin/setup.php';
	}

	/**
	 * PHP Mini MySQL Admin — управление БД.
	 */
	public function database()
	{
		@chdir(MAIN_HOME . 'public/Views/admin/');
		require MAIN_HOME . 'public/Views/admin/database.php';
	}
}
