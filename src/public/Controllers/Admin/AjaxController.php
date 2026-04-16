<?php

/**
 * AjaxController — прокси для admin/api.php
 *
 * Тонкий контроллер: делегирует всю логику в legacy admin/api.php.
 * api.php содержит ~90 action-секций (~4500 строк) — его рефакторинг
 * в отдельные методы запланирован в следующих фазах.
 *
 * @see admin/api.php
 * @since Phase 10.4
 *
 * @renders public/Views/admin/api.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AjaxController extends BaseAdminController {

    public function index() {
        $adminDir = MAIN_HOME . 'public/Views/admin/';
        chdir($adminDir);
        require $adminDir . 'api.php';
    }
}
