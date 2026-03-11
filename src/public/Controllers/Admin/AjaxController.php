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
 */
class AjaxController extends BaseAdminController {

    public function index() {
        $adminDir = MAIN_HOME . 'public/Views/admin/';
        chdir($adminDir);
        require $adminDir . 'api.php';
    }
}
