<?php

/**
 * SetupController — Начальная настройка системы + MySQL admin.
 *
 * setup.php и database.php работают до полного bootstrap (возможно нет БД).
 * Оба файла содержат собственные HTML-документы.
 *
 * @renders public/Views/admin/setup.php (index), public/Views/admin/database.php (database)
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SetupController extends BaseAdminController {
	/**
	 * Страница первичной настройки.
	 */
	public function index() {
		@chdir(MAIN_HOME . 'public/Views/admin/');
		require MAIN_HOME . 'public/Views/admin/setup.php';
	}

	/**
	 * PHP Mini MySQL Admin — управление БД.
	 */
	public function database() {
		@chdir(MAIN_HOME . 'public/Views/admin/');
		require MAIN_HOME . 'public/Views/admin/database.php';
	}
}
