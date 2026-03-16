<?php

/**
 * Загрузчик модулей
 *
 * Автоматически обнаруживает модули из modules/star/module.json.
 * config/modules.php хранит только overrides (enabled => false).
 *
 * Источник истины — PHP-класс модуля (ModuleInterface), не JSON.
 * module.json содержит только метаданные: name, description, version, requires_core.
 *
 * ──────────────────────────────────────────────────────────────────
 * Использование (web):
 * ──────────────────────────────────────────────────────────────────
 *
 *   $loader = new ModuleLoader();
 *   $loader->loadAll();
 *   $loader->bootAll($container, $router);
 *
 * ──────────────────────────────────────────────────────────────────
 * Использование (CLI):
 * ──────────────────────────────────────────────────────────────────
 *
 *   $loader = new ModuleLoader();
 *   $loader->loadAll();
 *   $loader->registerAllCommands($registry);
 *
 * @see ModuleInterface
 */

class ModuleLoader {

    /** @var ModuleInterface[] Загруженные модули: name => instance */
    protected $modules = [];

    /** @var array Overrides из config/modules.php */
    protected $overrides = [];

    /**
     * Обнаружить и загрузить все модули из modules/star
     *
     * Сканирует modules/star/module.json, проверяет overrides в config/modules.php.
     * Создаёт экземпляры ModuleInterface, но НЕ вызывает boot/registerRoutes.
     *
     * @param string|null $modulesDir Путь к директории modules/
     * @return $this
     */
    public function loadAll($modulesDir = null) {
        if ($modulesDir === null) {
            $modulesDir = defined('MAIN_HOME') ? MAIN_HOME . 'modules' : dirname(__DIR__, 2) . '/modules';
        }

        // Overrides — только отключение модулей
        $overridesPath = defined('CONFIG_PATH') ? CONFIG_PATH . 'modules.php' : dirname(__DIR__, 2) . '/config/modules.php';
        if (file_exists($overridesPath)) {
            $this->overrides = require $overridesPath;
            if (!is_array($this->overrides)) {
                $this->overrides = [];
            }
        }

        // Auto-discover модулей
        $jsonFiles = glob($modulesDir . '/*/module.json');
        if (!$jsonFiles) {
            return $this;
        }

        foreach ($jsonFiles as $jsonFile) {
            $name = basename(dirname($jsonFile));

            // Проверяем override: disabled
            if (isset($this->overrides[$name]['enabled']) && !$this->overrides[$name]['enabled']) {
                continue;
            }

            $this->load($name, dirname($jsonFile));
        }

        return $this;
    }

    /**
     * Загрузить один модуль
     *
     * @param string $name Имя модуля (имя директории)
     * @param string|null $modulePath Абсолютный путь к директории модуля
     * @return bool
     */
    public function load($name, $modulePath = null) {
        if (isset($this->modules[$name])) {
            return true;
        }

        if ($modulePath === null) {
            $modulePath = $this->getModulePath($name);
        }

        // Определяем имя класса по соглашению: kebab-case → PascalCase + Module
        $className = $this->resolveClassName($name);

        // Пытаемся загрузить класс
        if (!class_exists($className)) {
            $classFile = $modulePath . '/' . $className . '.php';
            if (file_exists($classFile)) {
                require_once $classFile;
            }

            if (!class_exists($className)) {
                error_log("ModuleLoader: class '{$className}' not found for module '{$name}'");
                return false;
            }
        }

        $module = new $className();
        if (!($module instanceof ModuleInterface)) {
            error_log("ModuleLoader: class '{$className}' does not implement ModuleInterface");
            return false;
        }

        $this->modules[$name] = $module;
        return true;
    }

    /**
     * Выполнить boot() и registerRoutes() для всех загруженных модулей
     *
     * Используется в web-контексте (bootstrap.php).
     *
     * @param ServiceContainer $container DI-контейнер
     * @param Router|null $router HTTP-роутер (null для CLI)
     */
    public function bootAll(ServiceContainer $container, Router $router = null) {
        foreach ($this->modules as $name => $module) {
            $module->boot($container);

            if ($router !== null) {
                $module->registerRoutes($router);
            }

            $subscribers = $module->getEventSubscribers();
            if (!empty($subscribers) && $container->has('events')) {
                $dispatcher = $container->get('events');
                foreach ($subscribers as $event => $handler) {
                    $dispatcher->listen($event, $handler);
                }
            }
        }
    }

    /**
     * Зарегистрировать CLI-команды всех загруженных модулей
     *
     * Используется в console.php. Каждый модуль сам определяет
     * свои команды в registerCommands() — без filesystem scanning.
     *
     * @param CommandRegistry $registry Реестр CLI-команд
     */
    public function registerAllCommands(CommandRegistry $registry) {
        foreach ($this->modules as $module) {
            $module->registerCommands($registry);
        }
    }

    /**
     * Проверить, загружен ли модуль
     */
    public function isLoaded($name) {
        return isset($this->modules[$name]);
    }

    /**
     * Получить экземпляр загруженного модуля
     *
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
     */
    public function getModulePath($name) {
        $base = defined('MAIN_HOME') ? MAIN_HOME : dirname(__DIR__, 2) . '/';
        return $base . 'modules/' . $name;
    }

    /**
     * Преобразовать имя модуля в имя класса
     *
     * kebab-case → PascalCase + 'Module'
     * Примеры: 'plex' → 'PlexModule', 'theft-detection' → 'TheftDetectionModule'
     *
     * @param string $name
     * @return string
     */
    protected function resolveClassName($name) {
        // Override из config: 'class' => 'CustomModule'
        if (isset($this->overrides[$name]['class'])) {
            return $this->overrides[$name]['class'];
        }

        // Конвенция: kebab-case → PascalCase + Module
        $parts = explode('-', $name);
        return implode('', array_map('ucfirst', $parts)) . 'Module';
    }
}
