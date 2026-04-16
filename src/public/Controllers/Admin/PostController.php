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
 *
 * @renders public/Views/admin/post.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PostController extends BaseAdminController {
	public function index() {
		@chdir(MAIN_HOME . 'public/Views/admin/');

		// Принудительно установить $rICount = 1 чтобы post.php обработал POST,
		// а не выдал JavaScript. Include count уже > 1 из-за FC bootstrap.
		$GLOBALS['__forcePostMode'] = true;

		require MAIN_HOME . 'public/Views/admin/post.php';
	}
}
