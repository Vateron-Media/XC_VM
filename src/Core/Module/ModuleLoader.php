<?php

namespace XcVm\Core\Module;
use XcVm\Core\Module\Contract\StreamMiddlewareProviderInterface;
use XcVm\Core\Module\Contract\ServiceProviderInterface;
use XcVm\Core\Module\Contract\RouteProviderInterface;
use XcVm\Core\Module\Contract\NavbarProviderInterface;
use XcVm\Core\Module\Contract\CronProviderInterface;
use XcVm\Core\Module\Contract\CommandProviderInterface;

use XcVm\Core\Http\Pipeline\StreamPipeline;
use XcVm\Core\Http\Pipeline\StreamMiddlewareInterface;
use XcVm\Core\Http\Router;
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
    private array $modules = [];

    /** @var array Module overrides from config/modules.php (enabled/disabled, class names) */
    private array $overrides = [];

    /** @var array Normalized manifest data from module.json files (name => manifest array) */
    private array $manifests = [];

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
     * @throws \RuntimeException If a module fails to load, dependency is missing, or manifest is invalid.
     */
    public function loadAll(?string $modulesDir = null): self {
        if ($modulesDir === null) {
            $modulesDir = defined('MAIN_HOME')
                ? MAIN_HOME . 'Modules'
                : dirname(__DIR__, 2) . '/Modules';
        }

        $this->modules = [];
        $this->manifests = [];

        $this->loadOverrides();

        $jsonFiles = glob($modulesDir . '/*/module.json') ?: [];

        // Also discover modules installed as Composer packages (type: xcvm-module).
        // vendor/ is expected to be a sibling of the modules/ directory.
        $vendorDir = $this->resolveVendorDir($modulesDir);
        if ($vendorDir !== null) {
            foreach ($this->collectComposerModuleManifests($vendorDir) as $manifest) {
                if (!in_array($manifest, $jsonFiles, true)) {
                    $jsonFiles[] = $manifest;
                }
            }
        }

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
                throw new \ModuleLoadException("ModuleLoader: failed to load module '{$name}'");
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

        // Resolve the class from the module's OWN manifest name, not the passed
        // $name. Platform installs use the marketplace slug (e.g. "watch-d2bho")
        // as the directory, but the module's class follows its canonical manifest
        // name (e.g. "watch" -> XcVm\Module\Watch\WatchModule). This mirrors
        // discoverModules(), which already keys class resolution on manifest name.
        $className = $this->resolveClassName($this->manifestName($modulePath, $name));

        // Register a PSR-4 autoloader for this module's namespace before loading,
        // so multi-file modules (including encrypted ones) can reference their own
        // classes. The base namespace is the module FQCN minus its short class name
        // (e.g. XcVm\Module\Watch), mapped onto $modulePath.
        $baseNamespace = ($nsPos = strrpos($className, '\\')) !== false ? substr($className, 0, $nsPos) : '';
        $this->registerModuleAutoloader($modulePath, $baseNamespace);

        // NB: class_exists() with autoload=false. The module's main class file is
        // at the deterministic path modulePath/{ShortName}.php and is require'd
        // below — we must NOT let class_exists() trigger the global autoloader,
        // whose miss-path runs a full recursive directory rescan
        // (Autoloader::warmCache) on EVERY request (workers are short-lived under
        // pm=ondemand), which pegged php-fpm at high CPU.
        if (!class_exists($className, false)) {
            // Strip namespace prefix — the file lives at modulePath/{ShortName}.php.
            // Guard the no-namespace case (strrpos → false) so the first char isn't dropped.
            $pos       = strrpos($className, '\\');
            $shortName = $pos === false ? $className : substr($className, $pos + 1);
            $classFile = $modulePath . '/' . $shortName . '.php';
            if (file_exists($classFile)) {
                if ($this->isFileEncrypted($classFile) && !extension_loaded('xcvm_core')) {
                    error_log("ModuleLoader: module '{$name}' is encrypted but xcvm_core is not loaded");
                    return false;
                }
                require_once $classFile;
            }

            if (!class_exists($className, false)) {
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
     * @param \ServiceContainer $container Service container for dependency injection.
     * @param Router|null $router Optional router for module route registration.
     * @param StreamPipeline|null $pipeline Optional stream pipeline for middleware registration.
     * @return void
     */
    public function bootAll(\ServiceContainer $container, ?Router $router = null, ?StreamPipeline $pipeline = null): void {
        $navbarRegistry = new NavbarRegistry();
        (new CoreNavbarProvider())->registerNavbar($navbarRegistry);

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
                $module->registerNavbar($navbarRegistry);
            }
        }
    }

    /**
     * Registers CLI commands for all loaded modules.
     *
     * Only calls registerCommands() on modules implementing CommandProviderInterface.
     * Used in CLI context (console.php).
     *
     * @param \CommandRegistry $registry Command registry for registering module commands.
     * @return void
     */
    public function registerAllCommands(\CommandRegistry $registry): void {
        foreach ($this->modules as $module) {
            if ($module instanceof CommandProviderInterface) {
                $module->registerCommands($registry);
            }
        }
    }

    /**
     * Collects system crontab entries declared by loaded modules.
     *
     * Iterates over all loaded modules implementing CronProviderInterface and
     * assembles ready-to-write crontab lines. Callers (StartupCommand,
     * StatusCommand) append these to the crontab without touching module code.
     *
     * @return string[] Complete crontab lines, each ending with "# XC_VM".
     */
    public function collectCronEntries(): array {
        if (!defined('PHP_BIN') || !defined('MAIN_HOME')) {
            return [];
        }

        $lines = [];
        foreach ($this->modules as $module) {
            if (!$module instanceof CronProviderInterface) {
                continue;
            }
            foreach ($module->getCronEntries() as $expression => $command) {
                $lines[] = $expression . ' ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php ' . $command . ' # XC_VM';
            }
        }
        return $lines;
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
        return $base . 'Modules/' . $name;
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
     * @param array           $jsonFiles          Array of full paths to module.json files.
     * @param \ServerEnvironment $currentEnvironment Current server environment.
     * @return array Associative array of discovered modules: name => [path, manifest].
     * @throws \RuntimeException If manifest has invalid environment value or JSON is malformed.
     */
    protected function discoverModules(array $jsonFiles, \ServerEnvironment $currentEnvironment): array {
        $discovered = [];

        foreach ($jsonFiles as $jsonFile) {
            // Derive a provisional name from directory — used only as fallback in readManifest().
            $dirName = basename(dirname($jsonFile));

            // Check overrides by directory name before spending I/O on the manifest.
            if (!$this->resolveState($dirName)->isLoadable()) {
                continue;
            }

            $manifest = $this->readManifest($jsonFile, $dirName);

            // Canonical name: always from the manifest (handles module-path subdirs in Composer packages).
            $name = $manifest['name'];

            // Re-check overrides by canonical name (in case it differs from directory name).
            if (!$this->resolveState($name)->isLoadable()) {
                continue;
            }

            if (!in_array($manifest['environment'], ['main', 'lb', 'any'], true)) {
                throw new \ModuleManifestException("ModuleLoader: invalid environment in module.json for module {$name}");
            }

            // Filter by environment: skip if module is for different environment (skip lb-only on main, etc)
            if ($manifest['environment'] !== 'any' && $manifest['environment'] !== $currentEnvironment->value) {
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
        if (isset($this->overrides[$name]['class'])) {
            $class = $this->overrides[$name]['class'];
            // Override must be an FQN. If it's a bare class name, build the FQN using convention.
            if (!str_contains($class, '\\')) {
                $pascal = implode('', array_map('ucfirst', explode('-', $name)));
                return 'XcVm\\Module\\' . $pascal . '\\' . $class;
            }
            return $class;
        }

        // Convert kebab-case to PascalCase: 'my-module' → 'MyModule'
        $parts  = explode('-', $name);
        $pascal = implode('', array_map('ucfirst', $parts));

        // Return fully-qualified class name: XcVm\Module\{Pascal}\{Pascal}Module
        return 'XcVm\\Module\\' . $pascal . '\\' . $pascal . 'Module';
    }

    /**
     * The module's canonical name as declared in its module.json ("name"), used
     * for class resolution. Falls back to $fallback (the directory/slug) when the
     * manifest is missing or has no usable name. This lets a module installed
     * under a marketplace slug still resolve to the class the developer authored.
     *
     * @param string|null $modulePath Module directory (contains module.json).
     * @param string      $fallback   Name to use when the manifest has none.
     * @return string Canonical module name (kebab-case).
     */
    private function manifestName(?string $modulePath, string $fallback): string {
        if ($modulePath === null) {
            return $fallback;
        }
        $jsonFile = $modulePath . '/module.json';
        if (is_file($jsonFile)) {
            $meta = json_decode((string) @file_get_contents($jsonFile), true);
            if (is_array($meta) && isset($meta['name']) && is_string($meta['name']) && $meta['name'] !== '') {
                return $meta['name'];
            }
        }
        return $fallback;
    }

    /**
     * Detects the current environment (main or load-balancer).
     *
     * Checks SERVER_TYPE constant. Returns LoadBalancer if set to 'lb' (case-insensitive), else Main.
     */
    protected function getCurrentEnvironment(): \ServerEnvironment {
        if (defined('SERVER_TYPE') && strtolower((string) constant('SERVER_TYPE')) === 'lb') {
            return \ServerEnvironment::LoadBalancer;
        }
        return \ServerEnvironment::Main;
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
     * @throws \RuntimeException If JSON is invalid, dependencies not array, or dependency names not strings.
     */
    protected function readManifest(string $jsonFile, string $name): array {
        $raw = @file_get_contents($jsonFile);
        $manifest = json_decode((string) $raw, true);

        if (!is_array($manifest)) {
            throw new \ModuleManifestException("ModuleLoader: invalid JSON in module manifest for module {$name}");
        }

        $normalizeDepArray = function (mixed $raw, string $field) use ($name): array {
            if (!is_array($raw)) {
                throw new \ModuleManifestException("ModuleLoader: {$field} must be array for module {$name}");
            }
            $result = [];
            foreach ($raw as $dep) {
                if (!is_string($dep) || trim($dep) === '') {
                    throw new \ModuleManifestException("ModuleLoader: {$field} names must be non-empty strings for module {$name}");
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
     * @throws \RuntimeException If a cyclic dependency is detected.
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
     * @throws \RuntimeException If cyclic dependency detected or dependency not found in discovered modules.
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
                throw new \ModuleCycleException('ModuleLoader: cyclic module dependency detected: ' . implode(' -> ', $cycle));
            }
        }

        if (!isset($discovered[$name])) {
            throw new \ModuleLoadException("ModuleLoader: unknown module in dependency graph: {$name}");
        }

        // Mark as currently visiting
        $state[$name] = 1;
        $stack[] = $name;

        // Required dependencies — throw if missing
        foreach ($discovered[$name]['manifest']['dependencies'] as $dependency) {
            if (!isset($discovered[$dependency])) {
                throw new \ModuleNotFoundException("ModuleLoader: module {$name} requires missing dependency {$dependency}");
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
     * Two complementary mechanisms are supported and both run on every boot:
     *
     *   1. getEventSubscribers() — legacy array API:
     *        ['EventClass' => callable]  or  ['EventClass' => [callable, $priority]]
     *
     *   2. #[\ListensTo] attribute — declarative PHP 8.1 attribute on public methods:
     *        #[\ListensTo(SomeEvent::class, priority: 10)]
     *        public function onSome(SomeEvent $e): void { ... }
     *
     * Both paths are additive — using one does not disable the other.
     *
     * If the event class named in a #[\ListensTo] attribute does not exist at
     * registration time the listener is silently skipped (graceful degradation).
     *
     * @param ServiceProviderInterface $module
     * @param \ServiceContainer $container
     * @return void
     */
    private function registerEventSubscribers(ServiceProviderInterface $module, \ServiceContainer $container): void {
        // Verify the container holds an actual \EventDispatcher instance (not just the class name).
        // Static calls below route to the same instance via \EventDispatcher::getInstance().
        if (!$container->has('events') || !$container->get('events') instanceof \EventDispatcher) {
            return;
        }

        // ── 1. Legacy array API via getEventSubscribers() ─────────────────
        $subscribers = $module->getEventSubscribers();
        foreach ($subscribers as $event => $handler) {
            if (is_array($handler) && isset($handler[0]) && is_callable($handler[0])) {
                // [callable, int $priority] tuple — new PSR-14 style
                \EventDispatcher::listen($event, $handler[0], $handler[1] ?? 0);
            } else {
                // Legacy: string event name or class-string, plain callable
                \EventDispatcher::listen($event, $handler);
            }
        }

        // ── 2. #[\ListensTo] attribute scan via Reflection ─────────────────
        $reflection = new \ReflectionClass($module);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(\ListensTo::class);
            if (empty($attributes)) {
                continue;
            }
            foreach ($attributes as $attribute) {
                /** @var \ListensTo $listensTo */
                $listensTo = $attribute->newInstance();

                // Graceful degradation: skip if the event class is not (yet) loadable.
                if (!class_exists($listensTo->eventClass)) {
                    continue;
                }

                \EventDispatcher::listen(
                    $listensTo->eventClass,
                    [$module, $method->getName()],
                    $listensTo->priority,
                );
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
     * Registers a PSR-4 autoloader for a single module's namespace (once per path).
     *
     * The module's base namespace ({$baseNamespace}, e.g. XcVm\Module\Watch) is
     * mapped onto its directory ({$modulePath}). The namespace remainder becomes
     * the sub-path, exactly per PSR-4:
     *
     *   XcVm\Module\Watch\WatchModule          → {modulePath}/WatchModule.php
     *   XcVm\Module\Watch\Service\WatchService → {modulePath}/Service/WatchService.php
     *
     * Classes outside the module's namespace (global legacy classes, other
     * modules, core) fall through to the next autoloader untouched — no short-name
     * glob, so two modules can declare same-named sub-classes without colliding.
     * Marketplace slug directories are unaffected: {modulePath} is the real path.
     *
     * Encrypted files are require'd as-is and transparently decrypted by the
     * XC_VM zend_compile_file hook.
     */
    private function registerModuleAutoloader(string $modulePath, string $baseNamespace): void {
        if ($baseNamespace === '' || isset(self::$autoloadedPaths[$modulePath])) {
            return;
        }
        self::$autoloadedPaths[$modulePath] = true;

        $prefix    = $baseNamespace . '\\';
        $prefixLen = strlen($prefix);

        spl_autoload_register(function (string $class) use ($modulePath, $prefix, $prefixLen): void {
            // Only resolve classes under this module's namespace; everything else
            // falls through to the next autoloader (Composer / legacy scanner).
            if (strncmp($class, $prefix, $prefixLen) !== 0) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, $prefixLen));
            $file     = $modulePath . '/' . $relative . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    /**
     * Resolve the effective \ModuleState for a module from its overrides entry.
     *
     * Reads both the new 'state' key and the legacy 'enabled' bool key so that
     * existing config/modules.php files continue to work without migration.
     *
     * @param string $name Module name or directory name.
     * @return \ModuleState
     */
    private function resolveState(string $name): \ModuleState {
        $entry = $this->overrides[$name] ?? null;
        if ($entry === null) {
            return \ModuleState::Enabled;
        }
        // New key takes precedence.
        if (isset($entry['state'])) {
            return \ModuleState::fromRaw($entry['state']);
        }
        // Legacy bool key.
        if (array_key_exists('enabled', $entry)) {
            return \ModuleState::fromRaw($entry['enabled']);
        }
        return \ModuleState::Enabled;
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

    /**
     * Resolves the vendor directory path relative to the modules directory.
     *
     * The vendor directory is expected to be a sibling of the modules/ directory
     * (i.e. at MAIN_HOME/vendor/). Returns null when vendor/composer/ does not exist
     * or Composer has not been run.
     *
     * @param string $modulesDir Absolute path to the modules/ directory.
     * @return string|null Absolute path to vendor/, or null if not found.
     */
    private function resolveVendorDir(string $modulesDir): ?string {
        $vendorDir = dirname($modulesDir) . '/vendor';
        return is_dir($vendorDir . '/composer') ? $vendorDir : null;
    }

    /**
     * Collects module.json paths from Composer-installed xcvm-module packages.
     *
     * Reads vendor/composer/installed.json and returns the module.json path for
     * every package whose "type" is "xcvm-module". Packages may optionally declare
     * a subdirectory for the module root via:
     *
     *   "extra": { "xcvm": { "module-path": "src/" } }
     *
     * When absent, module.json is expected at the package root.
     *
     * @param string $vendorDir Absolute path to the vendor/ directory.
     * @return string[] Absolute paths to discovered module.json files.
     */
    private function collectComposerModuleManifests(string $vendorDir): array {
        $installedJson = $vendorDir . '/composer/installed.json';
        $raw = @file_get_contents($installedJson);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        // Composer 2.x wraps packages in {"packages": [...], "dev": ...}
        // Composer 1.x uses a flat array
        $packages = isset($data['packages']) && is_array($data['packages'])
            ? $data['packages']
            : $data;

        $manifests = [];
        foreach ($packages as $package) {
            if (!is_array($package) || ($package['type'] ?? '') !== 'xcvm-module') {
                continue;
            }

            // install-path is relative to vendor/composer/
            $installPath = realpath($vendorDir . '/composer/' . ($package['install-path'] ?? ''));
            if ($installPath === false) {
                continue;
            }

            // Optional subdirectory override
            $subPath     = trim((string) ($package['extra']['xcvm']['module-path'] ?? ''), '/');
            $moduleDir   = $subPath !== '' ? $installPath . '/' . $subPath : $installPath;
            $manifestFile = $moduleDir . '/module.json';

            if (file_exists($manifestFile)) {
                $manifests[] = $manifestFile;
            }
        }

        return $manifests;
    }
}
