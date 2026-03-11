<?php

/**
 * PostController — POST-обработчик форм admin-панели.
 *
 * Все формы отправляют данные через submitForm() → POST post.php?action=xxx.
 * Этот контроллер обрабатывает прямые POST-запросы к /post.
 *
 * ВАЖНО: post.php имеет dual-mode логику на основе get_included_files() count:
 *   - Когда $rICount > 1 (included from footer) → выдаёт JavaScript
 *   - Когда $rICount == 1 (direct access) → обрабатывает POST
 *
 * Через FC post.php попадает сюда после bootstrap, поэтому $rICount > 1.
 * Чтобы активировать POST-обработку, принудительно переопределяем условие.
 */
class PostController extends BaseAdminController
{
	public function index()
	{
		@chdir(MAIN_HOME . 'public/Views/admin/');

		// Принудительно установить $rICount = 1 чтобы post.php обработал POST,
		// а не выдал JavaScript. Include count уже > 1 из-за FC bootstrap.
		$GLOBALS['__forcePostMode'] = true;

		require MAIN_HOME . 'public/Views/admin/post.php';
	}
}
