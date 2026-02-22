<?php

/**
 * XC_VM — Загрузчик модулей
 *
 * Сканирует config/modules.php, проверяет зависимости,
 * инициализирует модули и регистрирует их маршруты/кроны/события.
 *
 * ──────────────────────────────────────────────────────────────────
 * Использование:
 * ──────────────────────────────────────────────────────────────────
 *
 *   // В bootstrap.php:
 *   $loader = new ModuleLoader($container, $router);
 *   $loader->loadAll();
 *
 *   // Или загрузить конкретный модуль:
 *   $loader->load('watch');
 *
 *   // Проверить загруженность:
 *   $loader->isLoaded('plex'); // true/false
 *
 *   // Получить загруженный модуль:
 *   $module = $loader->getModule('watch');
 *
 * ──────────────────────────────────────────────────────────────────
 * config/modules.php:
 * ──────────────────────────────────────────────────────────────────
 *
 *   return [
 *       'plex'  => ['enabled' => true,  'class' => 'PlexModule'],
 *       'watch' => ['enabled' => true,  'class' => 'WatchModule'],
 *       'tmdb'  => ['enabled' => false, 'class' => 'TmdbModule'],
 *   ];
 *
 * @see ModuleInterface
 * @see Router
 * @see ServiceContainer
 */

class ModuleLoader {

    /** @var ServiceContainer */
    protected $container;

    /** @var Router */
    protected $router;

    /** @var ModuleInterface[] Загруженные модули: name => instance */
    protected $modules = [];

    /** @var array Конфигурация модулей (из modules.php) */
    protected $config = [];

    /**
     * @param ServiceContainer $container DI-контейнер
     * @param Router $router HTTP-роутер
     */
    public function __construct(ServiceContainer $container, Router $router) {
        $this->container = $container;
        $this->router    = $router;
    }

    /**
     * Загрузить все включённые модули из config/modules.php
     *
     * @param string|null $configPath Путь к modules.php. По умолчанию — CONFIG_PATH . 'modules.php'
     * @return $this
     */
    public function loadAll($configPath = null) {
        if ($configPath === null) {
            $configPath = defined('CONFIG_PATH') ? CONFIG_PATH . 'modules.php' : __DIR__ . '/../../config/modules.php';
        }

        if (file_exists($configPath)) {
            $this->config = require $configPath;
        }

        if (!is_array($this->config)) {
            $this->config = [];
        }

        foreach ($this->config as $name => $settings) {
            if (!empty($settings['enabled'])) {
                $this->load($name, $settings);
            }
        }

        return $this;
    }

    /**
     * Загрузить один модуль по имени
     *
     * @param string $name Имя модуля
     * @param array|null $settings Настройки модуля (из config). Если null — читает из загруженного config
     * @return bool true если модуль загружен, false если ошибка
     */
    public function load($name, array $settings = null) {
        // Уже загружен
        if (isset($this->modules[$name])) {
            return true;
        }

        if ($settings === null) {
            $settings = $this->config[$name] ?? [];
        }

        // Определяем класс модуля
        $class = $settings['class'] ?? null;
        if (!$class) {
            // Fallback: пробуем имя модуля с заглавной + 'Module'
            $class = ucfirst($name) . 'Module';
        }

        // Проверяем существование класса
        if (!class_exists($class)) {
            // Попытка загрузить по пути модуля
            $modulePath = $this->getModulePath($name);
            $moduleFile = $modulePath . '/' . $class . '.php';
            if (file_exists($moduleFile)) {
                require_once $moduleFile;
            }

            if (!class_exists($class)) {
                error_log("ModuleLoader: class '{$class}' not found for module '{$name}'");
                return false;
            }
        }

        // Проверяем контракт
        $module = new $class();
        if (!($module instanceof ModuleInterface)) {
            error_log("ModuleLoader: class '{$class}' does not implement ModuleInterface");
            return false;
        }

        // Проверяем зависимости (module.json)
        if (!$this->checkDependencies($name)) {
            error_log("ModuleLoader: dependencies not met for module '{$name}'");
            return false;
        }

        // 1. Boot — регистрация сервисов
        $module->boot($this->container);

        // 2. Маршруты
        $module->registerRoutes($this->router);

        // 3. Кроны — сохраняем в контейнере для CronRunner
        $crons = $module->registerCrons();
        if (!empty($crons)) {
            $existing = $this->container->has('module.crons') ? $this->container->get('module.crons') : [];
            $existing[$name] = $crons;
            $this->container->set('module.crons', $existing);
        }

        // 4. События
        $subscribers = $module->getEventSubscribers();
        if (!empty($subscribers) && $this->container->has('events')) {
            $dispatcher = $this->container->get('events');
            foreach ($subscribers as $event => $handler) {
                $dispatcher->listen($event, $handler);
            }
        }

        // Сохраняем модуль
        $this->modules[$name] = $module;

        return true;
    }

    /**
     * Проверить, загружен ли модуль
     *
     * @param string $name Имя модуля
     * @return bool
     */
    public function isLoaded($name) {
        return isset($this->modules[$name]);
    }

    /**
     * Получить экземпляр загруженного модуля
     *
     * @param string $name Имя модуля
     * @return ModuleInterface|null
     */
    public function getModule($name) {
        return $this->modules[$name] ?? null;
    }

    /**
     * Получить все загруженные модули
     *
     * @return ModuleInterface[]
     */
    public function getModules() {
        return $this->modules;
    }

    /**
     * Получить путь к директории модуля
     *
     * @param string $name Имя модуля
     * @return string
     */
    public function getModulePath($name) {
        $base = defined('MAIN_HOME') ? MAIN_HOME : dirname(__DIR__, 2) . '/';
        return $base . 'modules/' . $name;
    }

    /**
     * Проверить зависимости модуля (из module.json)
     *
     * @param string $name Имя модуля
     * @return bool
     */
    protected function checkDependencies($name) {
        $jsonPath = $this->getModulePath($name) . '/module.json';
        if (!file_exists($jsonPath)) {
            return true; // нет module.json — нет зависимостей
        }

        $manifest = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($manifest)) {
            return true;
        }

        $deps = $manifest['dependencies'] ?? [];
        foreach ($deps as $dep) {
            if (!$this->isLoaded($dep)) {
                // Попробуем загрузить зависимость
                if (!$this->load($dep)) {
                    return false;
                }
            }
        }

        return true;
    }
}
