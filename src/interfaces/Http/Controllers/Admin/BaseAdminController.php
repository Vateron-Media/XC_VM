<?php

/**
 * BaseAdminController — базовый контроллер для admin-страниц (Phase 6.3).
 *
 * Инкапсулирует общий render-flow:
 *   1. renderUnifiedLayoutHeader('admin')
 *   2. require Views/admin/{view}.php — HTML-контент
 *   3. renderUnifiedLayoutFooter('admin')
 *   4. require Views/admin/{view}.scripts.php — page-specific JS
 *
 * Контроллер-наследник:
 *   - Вызывает requirePermission() для проверки доступа
 *   - Устанавливает setTitle() для $_TITLE
 *   - Вызывает render('view_name', $data) для отрисовки
 *
 * @see interfaces/Http/Views/layouts/admin.php  — renderUnifiedLayoutHeader()
 * @see interfaces/Http/Views/layouts/footer.php — renderUnifiedLayoutFooter()
 * @see core/Http/Router.php                     — callHandler() → new Controller()->method()
 */
class BaseAdminController
{
    /** @var string Scope: 'admin' или 'reseller' */
    protected $scope = 'admin';

    /** @var string Заголовок страницы */
    protected $title = '';

    /**
     * Установить заголовок страницы ($_TITLE для legacy header).
     */
    protected function setTitle($title)
    {
        $this->title = $title;
        $GLOBALS['_TITLE'] = $title;
    }

    /**
     * Проверка общих прав доступа (checkPermissions).
     * При отказе — goHome() + exit.
     */
    protected function requirePermission()
    {
        if (function_exists('checkPermissions') && !checkPermissions()) {
            if (function_exists('goHome')) {
                goHome();
            }
            exit;
        }
    }

    /**
     * Проверка расширенных прав (hasPermissions).
     * При отказе — goHome() + exit.
     *
     * @param string $type Тип прав ('adv' и т.д.)
     * @param string $key  Ключ прав ('edit_user' и т.д.)
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

    /**
     * Render: header → view → footer → scripts.
     *
     * @param string $view Имя view-файла (без .php), напр. 'ips'
     * @param array  $data Данные для view (extract'd в scope)
     */
    protected function render($view, array $data = [])
    {
        // Layout functions
        require_once MAIN_HOME . 'interfaces/Http/Views/layouts/admin.php';
        require_once MAIN_HOME . 'interfaces/Http/Views/layouts/footer.php';

        // Глобальные переменные, нужные view-шаблонам и legacy-файлам.
        // Полный набор, включая переменные из bootstrap, functions.php
        // и admin_constants.php, чтобы legacy body code мог их использовать.
        $viewGlobals = [
            // Core rendering
            'language', 'db', 'rSettings', 'rMobile', 'rUserInfo',
            'rPermissions', '_TITLE', '_STATUS', '_PAGE',
            // Theme/UI
            'rThemes', 'rHues',
            // Servers
            'rServers', 'allServers', 'rProxyServers',
            'rServerError', 'allServersHealthy', 'updateRequired',
            // Locale/Geo
            'allowedLangs', 'rTMDBLanguages', 'rGeoCountries',
            'rCountryCodes', 'rCountries',
            // Devices & constants
            'rMAGs', 'rTimezones',
            // Status arrays (admin_constants.php)
            'rStatusArray', 'rSearchStatusArray', 'rVODStatusArray',
            'rWatchStatusArray', 'rFailureStatusArray', 'rStreamLogsArray',
            'rResellerActions', 'rClientFilters',
            // Permissions
            'rPermissionKeys', 'rAdvPermissions',
            // Misc from bootstrap
            'rDetect', 'rTimeout', 'rProtocol',
        ];
        foreach ($viewGlobals as $_g) {
            if (array_key_exists($_g, $GLOBALS) && !array_key_exists($_g, $data)) {
                $data[$_g] = $GLOBALS[$_g];
            }
        }
        unset($_g);

        extract($data);

        $__viewsDir = MAIN_HOME . 'interfaces/Http/Views/' . $this->scope . '/';

        // 1. Header
        renderUnifiedLayoutHeader($this->scope);

        // 2. View content
        $__viewFile = $__viewsDir . $view . '.php';
        $__scriptsFile = $__viewsDir . $view . '.scripts.php';
        $__hasScriptsFile = file_exists($__scriptsFile);

        if (file_exists($__viewFile)) {
            require $__viewFile;
        }

        // Split mode: separate .scripts.php exists → footer + scripts handled here.
        // Unified mode: no .scripts.php → view includes footer + scripts itself.
        if ($__hasScriptsFile) {
            renderUnifiedLayoutFooter($this->scope);
            require $__scriptsFile;
        }
    }

    /**
     * JSON-ответ.
     */
    protected function json(array $data, $code = 200)
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Редирект.
     */
    protected function redirect($url)
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Получить параметр запроса (GET/POST/CoreUtilities::$rRequest).
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function input($key, $default = null)
    {
        // Приоритет: CoreUtilities::$rRequest → $_REQUEST
        if (class_exists('CoreUtilities', false) && isset(CoreUtilities::$rRequest[$key])) {
            return CoreUtilities::$rRequest[$key];
        }
        return isset($_REQUEST[$key]) ? $_REQUEST[$key] : $default;
    }

    /**
     * Получить текущий статус из запроса (?status=...).
     *
     * @return mixed|null
     */
    protected function getStatus()
    {
        return isset($GLOBALS['_STATUS']) ? $GLOBALS['_STATUS'] : $this->input('status');
    }
}
