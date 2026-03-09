<?php

/**
 * BasePlayerController — базовый контроллер для player-страниц.
 *
 * Наследует BaseAdminController, переопределяя:
 *   - $scope = 'player' (для renderUnifiedLayout и путей views)
 *   - requirePermission() — пустой (player не использует RBAC)
 *   - render() — поддерживает player-специфичные глобальные переменные
 *
 * Player layout:
 *   1. renderUnifiedLayoutHeader('player') → player/header.php
 *   2. require Views/player/{view}.php — HTML-контент
 *   3. renderUnifiedLayoutFooter('player') → player/footer.php
 *
 * @see BaseAdminController
 */
class BasePlayerController extends BaseAdminController
{
    /** @var string Scope: 'player' */
    protected $scope = 'player';

    /**
     * Player не использует RBAC — проверка не нужна.
     */
    protected function requirePermission()
    {
        // Player авторизация обрабатывается bootstrap (player_session.php).
        // Если пользователь дошёл до контроллера — он уже аутентифицирован.
    }

    /**
     * Render: header → view → footer.
     *
     * Переопределяет admin render для player-specific глобалов.
     * Player footer.php содержит page-specific JS, поэтому
     * все глобалы должны быть доступны на момент его вызова.
     *
     * @param string $view Имя view-файла (без .php)
     * @param array  $data Данные для view (extract'd в scope)
     */
    protected function render($view, array $data = [])
    {
        require_once MAIN_HOME . 'public/Views/layouts/admin.php';
        require_once MAIN_HOME . 'public/Views/layouts/footer.php';

        // Player-специфичные глобалы (для header.php и footer.php)
        $viewGlobals = [
            // Core
            'db', 'rSettings', 'rUserInfo', '_TITLE', '_PAGE',
            // Player data
            'rStreamIDs', 'rFilterBy', 'rSortArray', 'rFilterArray',
            'rSearchBy', 'rURLs', 'rSubtitles', 'rLegacy', 'rSeries',
            'rYearStart', 'rYearEnd', 'rRatingStart', 'rRatingEnd',
        ];
        foreach ($viewGlobals as $_g) {
            if (array_key_exists($_g, $GLOBALS) && !array_key_exists($_g, $data)) {
                $data[$_g] = $GLOBALS[$_g];
            }
        }
        unset($_g);

        // Экспортируем $data в $GLOBALS чтобы footer.php мог их видеть
        foreach ($data as $key => $value) {
            $GLOBALS[$key] = $value;
        }

        extract($data);

        $__viewsDir = MAIN_HOME . 'public/Views/' . $this->scope . '/';

        // 1. Header
        renderUnifiedLayoutHeader($this->scope);

        // 2. View content
        $__viewFile = $__viewsDir . $view . '.php';
        if (file_exists($__viewFile)) {
            require $__viewFile;
        }

        // 3. Footer (player footer.php содержит page-specific JS)
        renderUnifiedLayoutFooter($this->scope);
    }

    /**
     * Редирект на player home.
     */
    protected function goPlayerHome()
    {
        header('Location: index');
        exit;
    }
}
