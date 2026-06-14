<?php

/**
 * ModuleLoader — automatic system module loader and dependency resolver.
 *
 * Modules are discovered by presence of modules/{name}/module.json manifest.
 * Single source of truth is the PHP class implementing ModuleInterface.
 * The module.json file is used exclusively for metadata and dependency declarations.
 *
 * Override configuration (including module disabling) is stored in config/modules.php.
 *
 * Features:
 * - Automatic discovery of modules in modules/ directory
 * - Environment filtering (main / lb environments with 'any' as universal)
 * - Topological dependency resolution with cycle detection
 * - Fail-fast validation of manifests
 * - Module booting and command registration
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ModuleLoader {
    /** @var ModuleInterface[] Loaded module instances, keyed by module name */
    protected array $modules = [];

    /** @var array Module overrides from config/modules.php (enabled/disabled, class names) */
    protected array $overrides = [];

    /** @var array Normalized manifest data from module.json files (name => manifest array) */
    protected array $manifests = [];

    /** @var array<string, true> Module paths that already have an autoloader registered */
    private static array $autoloadedPaths = [];

    /**
     * Loads all discovered modules from modules/ directory.
     *
     * Performs automatic discovery, dependency validation, load order resolution,
     * and environment filtering (main / lb / any). Modules are loaded in topological
     * order to satisfy dependencies.
     *
     * @param string|null $modulesDir Path to modules directory. If null, auto-detected via MAIN_HOME or src/modules.
     * @return self Fluent interface for chaining.
     * @throws RuntimeException If a module fails to load, dependency is missing, or manifest is invalid.
     */
    public function loadAll(?string $modulesDir = null): self {
        if ($modulesDir === null) {
            $modulesDir = defined('MAIN_HOME')
                ? MAIN_HOME . 'modules'
                : dirname(__DIR__, 2) . '/modules';
        }

        $this->modules = [];
        $this->manifests = [];

        $this->loadOverrides();

        $jsonFiles = glob($modulesDir . '/*/module.json');
        if (empty($jsonFiles)) {
            return $this;
        }

        sort($jsonFiles, SORT_STRING);

        $currentEnvironment = $this->getCurrentEnvironment();
        $discovered = $this->discoverModules($jsonFiles, $currentEnvironment);

        $loadOrder = $this->resolveLoadOrder($discovered);

        foreach ($loadOrder as $name) {
            $modulePath = $discovered[$name]['path'];

            if (!$this->load($name, $modulePath)) {
                throw new RuntimeException("ModuleLoader: failed to load module '{$name}'");
            }

            $this->manifests[$name] = $discovered[$name]['manifest'];
        }

        return $this;
    }

    /**
     * Loads a single module by name.
     *
     * Resolves the class name from module name (or override config), includes the class file,
     * and instantiates the module. Verifies that the module implements ModuleInterface.
     *
     * @param string $name Module name (directory name in modules/).
     * @param string|null $modulePath Full path to module directory. If null, auto-detected via getModulePath().
     * @return bool True if successfully loaded, false if class not found or doesn't implement ModuleInterface.
     */
    public function load(string $name, ?string $modulePath = null): bool {
        if (isset($this->modules[$name])) {
            return true;
        }

        if ($modulePath === null) {
            $modulePath = $this->getModulePath($name);
        }

        // Register autoloader before loading so multi-file modules (including
        // encrypted ones) can reference their own classes via require/include.
        $this->registerModuleAutoloader($modulePath);

        $className = $this->resolveClassName($name);

        if (!class_exists($className)) {
            $classFile = $modulePath . '/' . $className . '.php';
            if (file_exists($classFile)) {
                if ($this->isFileEncrypted($classFile) && !extension_loaded('license_ext')) {
                    error_log("ModuleLoader: module '{$name}' is encrypted but license_ext is not loaded");
                    return false;
                }
                require_once $classFile;
            }

            if (!class_exists($className)) {
                error_log("ModuleLoader: class '{$className}' not found for module '{$name}'");
                return false;
            }
        }

        $module = new $className();

        if (!$module instanceof ModuleInterface) {
            error_log("ModuleLoader: class '{$className}' does not implement ModuleInterface");
            return false;
        }

        $this->modules[$name] = $module;
        return true;
    }

    /**
     * Boots all loaded modules in web context.
     *
     * Checks each sub-interface via instanceof so modules can implement
     * only the contracts they need. Core navbar is registered first.
     *
     * @param ServiceContainer $container Service container for dependency injection.
     * @param Router|null $router Optional router for module route registration.
     * @param StreamPipeline|null $pipeline Optional stream pipeline for middleware registration.
     * @return void
     */
    public function bootAll(ServiceContainer $container, ?Router $router = null, ?StreamPipeline $pipeline = null): void {
        (new CoreNavbarProvider())->registerNavbar();

        foreach ($this->modules as $module) {
            if ($module instanceof ServiceProviderInterface) {
                $module->boot($container);
                $this->registerEventSubscribers($module, $container);
            }

            if ($pipeline !== null) {
                $this->registerStreamMiddleware($module, $pipeline);
            }

            if ($module instanceof RouteProviderInterface && $router !== null) {
                $module->registerRoutes($router);
            }

            if ($module instanceof NavbarProviderInterface) {
                $module->registerNavbar();
            }
        }
    }

    /**
     * Registers CLI commands for all loaded modules.
     *
     * Only calls registerCommands() on modules implementing CommandProviderInterface.
     * Used in CLI context (console.php).
     *
     * @param CommandRegistry $registry Command registry for registering module commands.
     * @return void
     */
    public function registerAllCommands(CommandRegistry $registry): void {
        foreach ($this->modules as $module) {
            if ($module instanceof CommandProviderInterface) {
                $module->registerCommands($registry);
            }
        }
    }

    /**
     * Checks whether a module is loaded.
     *
     * @param string $name Module name to check.
     * @return bool True if module is loaded and instantiated, false otherwise.
     */
    public function isLoaded(string $name): bool {
        return isset($this->modules[$name]);
    }

    /**
     * Retrieves a loaded module instance by name.
     *
     * @param string $name Module name.
     * @return ModuleInterface|null Module instance if loaded, null otherwise.
     */
    public function getModule(string $name): ?ModuleInterface {
        return $this->modules[$name] ?? null;
    }

    /**
     * Retrieves all loaded module instances.
     *
     * @return ModuleInterface[] Associative array of loaded modules (name => instance).
     */
    public function getModules(): array {
        return $this->modules;
    }

    /**
     * Retrieves the normalized manifest for a loaded module.
     *
     * @param string $name Module name.
     * @return array|null Manifest array (name, description, version, requires_core, environment, dependencies, has_navbar, has_settings) if loaded, null otherwise.
     */
    public function getManifest(string $name): ?array {
        return $this->manifests[$name] ?? null;
    }

    /**
     * Retrieves all manifests for loaded modules.
     *
     * @return array Associative array of normalized manifests (name => manifest array).
     */
    public function getManifests(): array {
        return $this->manifests;
    }

    /**
     * Constructs the full path to a module directory.
     *
     * @param string $name Module name.
     * @return string Full path to module directory (modules/{name}).
     */
    public function getModulePath(string $name): string {
        $base = defined('MAIN_HOME') ? MAIN_HOME : dirname(__DIR__, 2) . '/';
        return $base . 'modules/' . $name;
    }

    /**
     * Loads module override configuration from config/modules.php.
     *
     * Overrides can disable modules, override class names, or provide other module-specific settings.
     *
     * @return void
     */
    protected function loadOverrides(): void {
        $overridesPath = defined('CONFIG_PATH')
            ? CONFIG_PATH . 'modules.php'
            : dirname(__DIR__, 2) . '/config/modules.php';

        if (file_exists($overridesPath)) {
            $this->overrides = require $overridesPath;
            if (!is_array($this->overrides)) {
                $this->overrides = [];
            }
        }
    }

    /**
     * Discovers and filters modules based on manifest files and environment.
     *
     * Reads all module.json manifests, normalizes manifest data, checks override disabling,
     * and filters modules to current environment (main/lb). Returns discovered modules
     * with their paths and normalized manifest data.
     *
     * @param array $jsonFiles Array of full paths to module.json files.
     * @param string $currentEnvironment Current environment ('main' or 'lb').
     * @return array Associative array of discovered modules: name => [path, manifest].
     * @throws RuntimeException If manifest has invalid environment value or JSON is malformed.
     */
    protected function discoverModules(array $jsonFiles, string $currentEnvironment): array {
        $discovered = [];

        foreach ($jsonFiles as $jsonFile) {
            $name = basename(dirname($jsonFile));

            // Check if module is disabled via overrides
            if (isset($this->overrides[$name]['enabled']) && !$this->overrides[$name]['enabled']) {
                continue;
            }

            $manifest = $this->readManifest($jsonFile, $name);

            if (!in_array($manifest['environment'], ['main', 'lb', 'any'], true)) {
                throw new RuntimeException("ModuleLoader: invalid environment in module.json for module {$name}");
            }

            // Filter by environment: skip if module is for different environment (skip lb-only on main, etc)
            if ($manifest['environment'] !== 'any' && $manifest['environment'] !== $currentEnvironment) {
                continue;
            }

            $discovered[$name] = [
                'path'     => dirname($jsonFile),
                'manifest' => $manifest,
            ];
        }

        return $discovered;
    }

    /**
     * Resolves the class name for a module from its name.
     *
     * Checks for override in config first. If not overridden, converts kebab-case name to PascalCase
     * and appends 'Module' suffix.
     *
     * Example: 'watch-manager' -> 'WatchManagerModule'
     *
     * @param string $name Module name (kebab-case).
     * @return string Resolved class name (PascalCase).
     */
    protected function resolveClassName(string $name): string {
        // Check for class name override in config
        if (isset($this->overrides[$name]['class'])) {
            return $this->overrides[$name]['class'];
        }

        // Convert kebab-case to PascalCase and append 'Module' suffix
        $parts = explode('-', $name);
        return implode('', array_map('ucfirst', $parts)) . 'Module';
    }

    /**
     * Detects the current environment (main or load-balancer).
     *
     * Checks SERVER_TYPE constant. Returns 'lb' if set to 'lb' (case-insensitive), otherwise 'main'.
     *
     * @return string Current environment: 'main' or 'lb'.
     */
    protected function getCurrentEnvironment(): string {
        if (defined('SERVER_TYPE') && strtolower((string) constant('SERVER_TYPE')) === 'lb') {
            return 'lb';
        }
        return 'main';
    }

    /**
     * Reads and normalizes a module manifest from JSON file.
     *
     * Parses module.json, validates all fields, normalizes dependency arrays (trim, dedup, sort),
     * and fills in default values. Performs strict type checking to catch configuration errors early.
     *
     * Supported fields:
     *   dependencies          — required; missing dep causes load failure
     *   optional_dependencies — optional; missing dep is silently skipped
     *   priority              — load order weight (higher = earlier, default 0)
     *
     * @param string $jsonFile Full path to module.json file.
     * @param string $name Module name (used for error messages).
     * @return array Normalized manifest.
     * @throws RuntimeException If JSON is invalid, dependencies not array, or dependency names not strings.
     */
    protected function readManifest(string $jsonFile, string $name): array {
        $raw = @file_get_contents($jsonFile);
        $manifest = json_decode((string) $raw, true);

        if (!is_array($manifest)) {
            throw new RuntimeException("ModuleLoader: invalid JSON in module manifest for module {$name}");
        }

        $normalizeDepArray = function (mixed $raw, string $field) use ($name): array {
            if (!is_array($raw)) {
                throw new RuntimeException("ModuleLoader: {$field} must be array for module {$name}");
            }
            $result = [];
            foreach ($raw as $dep) {
                if (!is_string($dep) || trim($dep) === '') {
                    throw new RuntimeException("ModuleLoader: {$field} names must be non-empty strings for module {$name}");
                }
                $result[] = trim($dep);
            }
            sort($result, SORT_STRING);
            return array_values(array_unique($result));
        };

        return [
            'name'                  => $manifest['name'] ?? $name,
            'description'           => $manifest['description'] ?? '',
            'version'               => $manifest['version'] ?? '',
            'requires_core'         => $manifest['requires_core'] ?? '',
            'environment'           => strtolower((string) ($manifest['environment'] ?? 'main')),
            'dependencies'          => $normalizeDepArray($manifest['dependencies'] ?? [], 'dependencies'),
            'optional_dependencies' => $normalizeDepArray($manifest['optional_dependencies'] ?? [], 'optional_dependencies'),
            'has_navbar'            => (bool) ($manifest['has_navbar'] ?? false),
            'has_settings'          => (bool) ($manifest['has_settings'] ?? false),
            'priority'              => (int) ($manifest['priority'] ?? 0),
        ];
    }

    /**
     * Resolves deterministic load order of modules based on dependencies.
     *
     * Uses depth-first search (DFS) with topological sort to order modules such that
     * dependencies are loaded before their dependents. Modules are visited in alphabetical order
     * for determinism. Detects cyclic dependencies and throws exception.
     *
     * @param array $discovered Discovered modules (name => [path, manifest]).
     * @return array Ordered module names: modules are in dependency-order (dependencies first).
     * @throws RuntimeException If a cyclic dependency is detected.
     */
    protected function resolveLoadOrder(array $discovered): array {
        $order = [];
        $state = [];  // 1 = visiting, 2 = visited
        $names = array_keys($discovered);

        // Sort by priority desc, then alphabetically for determinism within same priority
        usort($names, function (string $a, string $b) use ($discovered): int {
            $pa = $discovered[$a]['manifest']['priority'] ?? 0;
            $pb = $discovered[$b]['manifest']['priority'] ?? 0;
            if ($pa !== $pb) {
                return $pb <=> $pa; // higher priority first
            }
            return strcmp($a, $b);
        });

        foreach ($names as $name) {
            $this->visitDependencyNode($name, $discovered, $state, $order, []);
        }

        return $order;
    }

    /**
     * DFS traversal to visit a module and its dependencies in dependency order.
     *
     * Part of topological sort algorithm. Maintains state machine:
     * - 1 = currently visiting (used to detect cycles)
     * - 2 = finished visiting
     *
     * Recursively visits all dependencies before adding module to load order.
     *
     * @param string $name Module name to visit.
     * @param array $discovered Discovered modules (name => [path, manifest]).
     * @param array &$state Visit state for each module (1=visiting, 2=visited).
     * @param array &$order Load order being built (appends module names).
     * @param array $stack Call stack trace (used for cycle error message).
     * @return void
     * @throws RuntimeException If cyclic dependency detected or dependency not found in discovered modules.
     */
    protected function visitDependencyNode(
        string $name,
        array $discovered,
        array &$state,
        array &$order,
        array $stack
    ): void {
        if (isset($state[$name])) {
            if ($state[$name] === 2) {
                // Already fully visited, skip
                return;
            }
            if ($state[$name] === 1) {
                // Currently visiting = cycle detected
                $cycle = array_slice($stack, array_search($name, $stack, true) ?: 0);
                $cycle[] = $name;
                throw new RuntimeException('ModuleLoader: cyclic module dependency detected: ' . implode(' -> ', $cycle));
            }
        }

        if (!isset($discovered[$name])) {
            throw new RuntimeException("ModuleLoader: unknown module in dependency graph: {$name}");
        }

        // Mark as currently visiting
        $state[$name] = 1;
        $stack[] = $name;

        // Required dependencies — throw if missing
        foreach ($discovered[$name]['manifest']['dependencies'] as $dependency) {
            if (!isset($discovered[$dependency])) {
                throw new RuntimeException("ModuleLoader: module {$name} requires missing dependency {$dependency}");
            }
            $this->visitDependencyNode($dependency, $discovered, $state, $order, $stack);
        }

        // Optional dependencies — visit only when present, skip silently otherwise
        foreach ($discovered[$name]['manifest']['optional_dependencies'] ?? [] as $dependency) {
            if (isset($discovered[$dependency])) {
                $this->visitDependencyNode($dependency, $discovered, $state, $order, $stack);
            }
        }

        // Mark as fully visited and add to load order
        $state[$name] = 2;
        $order[] = $name;
    }

    /**
     * Registers event subscribers declared by a module.
     *
     * Supports both legacy string-keyed handlers and new class-based
     * [callable, int $priority] tuples from ServiceProviderInterface.
     *
     * @param ServiceProviderInterface $module
     * @param ServiceContainer $container
     * @return void
     */
    private function registerEventSubscribers(ServiceProviderInterface $module, ServiceContainer $container): void {
        $subscribers = $module->getEventSubscribers();
        if (empty($subscribers) || !$container->has('events')) {
            return;
        }

        foreach ($subscribers as $event => $handler) {
            if (is_array($handler) && isset($handler[0]) && is_callable($handler[0])) {
                // [callable, int $priority] tuple — new PSR-14 style
                EventDispatcher::listen($event, $handler[0], $handler[1] ?? 0);
            } else {
                // Legacy: string event name or class-string, plain callable
                EventDispatcher::listen($event, $handler);
            }
        }
    }

    /**
     * Registers stream middleware declared by a module into the pipeline.
     *
     * Only called for modules implementing StreamMiddlewareProviderInterface.
     *
     * @param ModuleInterface $module
     * @param StreamPipeline $pipeline
     * @return void
     */
    private function registerStreamMiddleware(ModuleInterface $module, StreamPipeline $pipeline): void {
        if (!$module instanceof StreamMiddlewareProviderInterface) {
            return;
        }
        foreach ($module->getStreamMiddleware() as $middleware) {
            if ($middleware instanceof StreamMiddlewareInterface) {
                $pipeline->pipe($middleware);
            }
        }
    }

    /**
     * Registers a PSR-style autoloader for a module directory (once per path).
     *
     * Handles two layouts:
     *   - modules/{name}/MyClass.php          (flat)
     *   - modules/{name}/SubDir/MyClass.php   (one level deep)
     *
     * Encrypted files are loaded with require_once and transparently decrypted
     * by the XC_VM zend_compile_file hook.
     */
    private function registerModuleAutoloader(string $modulePath): void {
        if (isset(self::$autoloadedPaths[$modulePath])) {
            return;
        }
        self::$autoloadedPaths[$modulePath] = true;

        spl_autoload_register(function (string $class) use ($modulePath): void {
            // Strip namespace prefix — use only the short class name for file lookup
            $short = substr($class, (int) strrpos($class, '\\') + 1);

            // Flat: modules/{name}/MyClass.php
            $flat = $modulePath . '/' . $short . '.php';
            if (file_exists($flat)) {
                require_once $flat;
                return;
            }

            // One level deep: modules/{name}/*/MyClass.php
            foreach (glob($modulePath . '/*/' . $short . '.php') ?: [] as $found) {
                require_once $found;
                return;
            }
        });
    }

    /**
     * Returns true if the file starts with the XCVM encrypted-file magic bytes.
     */
    private function isFileEncrypted(string $path): bool {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $magic = fread($fh, 4);
        fclose($fh);
        return $magic === "\x58\x43\x56\x4D";
    }
}
