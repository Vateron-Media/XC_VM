<?php

/**
 * BaseResellerController — базовый контроллер для reseller-страниц (Phase 6.4).
 *
 * Наследует BaseAdminController, переопределяя:
 *   - $scope = 'reseller' (для renderUnifiedLayout и путей views)
 *   - requirePermission() → checkResellerPermissions()
 *
 * @see BaseAdminController
 */
class BaseResellerController extends BaseAdminController
{
    /** @var string Scope: 'reseller' */
    protected $scope = 'reseller';

    /**
     * Проверка прав доступа реселлера (checkResellerPermissions).
     * При отказе — goHome() + exit.
     */
    protected function requirePermission()
    {
        if (function_exists('checkResellerPermissions') && !checkResellerPermissions()) {
            if (function_exists('goHome')) {
                goHome();
            }
            exit;
        }
    }

    /**
     * Проверка расширенных прав реселлера (hasPermissions).
     * Reseller permissions загружены через getGroupPermissions().
     *
     * @param string $type Тип прав
     * @param string $key  Ключ прав
     */
    protected function requireAdvPermission($type, $key)
    {
        if (class_exists('Authorization') && !Authorization::check($type, $key)) {
            if (function_exists('goHome')) {
                goHome();
            }
            exit;
        }
    }
}
