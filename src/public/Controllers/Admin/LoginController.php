<?php

/**
 * LoginController — Страница авторизации admin-панели.
 *
 * Login имеет собственный HTML-документ (не использует layout header/footer).
 * Файл admin/login.php содержит полный bootstrap через functions.php.
 * Контроллер делегирует напрямую в legacy-файл.
 */
class LoginController extends BaseAdminController
{
	public function index()
	{
		@chdir(MAIN_HOME . 'public/Views/admin/');
		require MAIN_HOME . 'public/Views/admin/login.php';
	}
}
