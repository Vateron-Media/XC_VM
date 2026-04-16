<?php

/**
 * LoginController — Страница авторизации admin-панели.
 *
 * Login имеет собственный HTML-документ (не использует layout header/footer).
 * Файл admin/login.php содержит полный bootstrap через functions.php.
 * Контроллер делегирует напрямую в legacy-файл.
 *
 * @renders public/Views/admin/login.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LoginController extends BaseAdminController {
	public function index() {
		@chdir(MAIN_HOME . 'public/Views/admin/');
		require MAIN_HOME . 'public/Views/admin/login.php';
	}
}
