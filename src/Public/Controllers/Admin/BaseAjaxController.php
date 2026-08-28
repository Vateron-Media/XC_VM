<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\Authorization;

/**
 * Базовый класс для admin-ajax контроллеров, вынесенных из legacy `admin/api.php`.
 *
 * `api.php` — плоская цепочка из ~90 блоков `if (action == 'x') { … exit(); }`,
 * каждый из которых повторяет один и тот же обвес: permission-гейт, `echo
 * json_encode(...)`, `exit()`. Этот класс собирает обвес в несколько методов,
 * чтобы вынесенные экшены читались как «гейт → сервис → ответ».
 *
 * Отдаёт только JSON (POPO, как {@see TmdbController}) — layout/шаблоны не нужны,
 * поэтому НЕ наследует {@see BaseAdminController}.
 *
 * Экшены достигают контроллера через `Router::dispatchApi()` (см.
 * `Public/index.php`), который срабатывает ДО fallback на `AjaxController`
 * → `api.php`. Админ-аутентификация уже обеспечена `AdminScopeBootstrap::boot()`
 * до диспетчеризации, поэтому здесь остаётся только per-action permission-гейт
 * и (для паритета с api.php) XHR-guard.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
abstract class BaseAjaxController {

    /**
     * JSON-ответ и завершение запроса — как `echo json_encode(...); exit();`
     * в api.php, но с корректным Content-Type (как {@see BaseAdminController::json()}).
     *
     * @param array<string, mixed> $rData
     */
    protected function json(array $rData): never {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }

        echo json_encode($rData);

        exit();
    }

    /**
     * Успех: `{"result":true}` плюс опциональные дополнительные ключи.
     *
     * @param array<string, mixed> $rExtra
     */
    protected function ok(array $rExtra = array()): never {
        $this->json(array('result' => true) + $rExtra);
    }

    /**
     * Отказ: `{"result":false}` плюс опциональные дополнительные ключи.
     * Каноничный «хвост» почти каждого блока api.php.
     *
     * @param array<string, mixed> $rExtra
     */
    protected function fail(array $rExtra = array()): never {
        $this->json(array('result' => false) + $rExtra);
    }

    /**
     * Permission-гейт: аналог per-action `Authorization::check($type, $key)` в
     * api.php. При отказе отдаёт `{"result":false}` и завершает запрос — ровно
     * как `else { echo json_encode(['result'=>false]); exit(); }`.
     */
    protected function gate(string $rType, string $rKey): void {
        if (!Authorization::check($rType, $rKey)) {
            $this->fail();
        }
    }

    /**
     * XHR-guard из api.php (там строки `if (!PHP_ERRORS) { … X-Requested-With … }`):
     * не-AJAX запросы отклоняются, пока не включён режим отладки (`PHP_ERRORS`).
     * Повторяет поведение api.php для экшенов, вынесенных в контроллеры.
     */
    protected function requireXhr(): void {
        if (defined('PHP_ERRORS') && PHP_ERRORS) {
            return;
        }

        $rRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        if (strtolower($rRequestedWith) !== 'xmlhttprequest') {
            exit();
        }
    }
}
