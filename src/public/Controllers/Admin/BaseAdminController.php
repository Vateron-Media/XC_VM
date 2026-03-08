<?php

/**
 * BaseAdminController — базовый контроллер для admin-страниц.
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
 * @see public/Views/layouts/admin.php  — renderUnifiedLayoutHeader()
 * @see public/Views/layouts/footer.php — renderUnifiedLayoutFooter()
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
        require_once MAIN_HOME . 'public/Views/layouts/admin.php';
        require_once MAIN_HOME . 'public/Views/layouts/footer.php';

        // Глобальные переменные, нужные view-шаблонам и legacy-файлам.
        // Полный набор, включая переменные из bootstrap, functions.php
        // и admin_constants.php, чтобы legacy body code мог их использовать.
        $viewGlobals = [
            // Core rendering
            'language', 'db', 'rSettings', 'rMobile', 'rUserInfo',
            'rPermissions', '_TITLE', '_STATUS', '_PAGE', 'rRequest',
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
            // Reseller-specific
            'rGenTrials',
        ];
        foreach ($viewGlobals as $_g) {
            if (array_key_exists($_g, $GLOBALS) && !array_key_exists($_g, $data)) {
                $data[$_g] = $GLOBALS[$_g];
            }
        }
        unset($_g);

        extract($data);

        $__viewsDir = MAIN_HOME . 'public/Views/' . $this->scope . '/';

        // 1. Header
        renderUnifiedLayoutHeader($this->scope);

        // Header may define new globals (e.g. reseller header sets rGenTrials)
        foreach ($viewGlobals as $_g) {
            if (!isset($$_g) && array_key_exists($_g, $GLOBALS)) {
                $$_g = $GLOBALS[$_g];
            }
        }
        unset($_g);

        // 2. View content
        $__viewFile = $__viewsDir . $view . '.php';
        $__scriptsFile = $__viewsDir . $view . '.scripts.php';
        $__hasScriptsFile = file_exists($__scriptsFile);

        if (file_exists($__viewFile)) {
            require $__viewFile;
        }

        // Split mode: separate .scripts.php exists → footer + scripts handled here.
        // Clean mode: no .scripts.php + no $__viewMode → controller renders footer.
        // Unified (proxy) mode: no .scripts.php + $__viewMode set → proxy view handles footer itself.
        if ($__hasScriptsFile) {
            renderUnifiedLayoutFooter($this->scope);
            require $__scriptsFile;
        } elseif (!isset($__viewMode)) {
            renderUnifiedLayoutFooter($this->scope);
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
     * Получить параметр запроса (GET/POST/RequestManager::getAll()).
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function input($key, $default = null)
    {
        // Приоритет: RequestManager → $_REQUEST
        if (isset(RequestManager::getAll()[$key])) {
            return RequestManager::getAll()[$key];
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
