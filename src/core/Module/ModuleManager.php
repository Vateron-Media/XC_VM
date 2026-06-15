<?php

/**
 * ModuleManager — administrative operations with modules.
 *
 * Provides:
 * - listing module metadata and status
 * - install / uninstall
 * - enable / disable via config/modules.php
 * - upload zip + install
 *
 * @package XC_VM_Core_Module
 * @author  obscuremind <https://github.com/obscuremind>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleManager {
    private string $modulesPath;
    private string $overridesPath;
    private ?ServiceContainer $container;

    /**
     * Initialize the module manager.
     *
     * @param string|null $modulesPath   Path to the modules directory.
     * @param string|null $overridesPath Path to the config/modules.php overrides file.
     * @param ServiceContainer|null $container Service container for DB access and DI.
     */
    public function __construct(
        ?string $modulesPath = null,
        ?string $overridesPath = null,
        ?ServiceContainer $container = null
    ) {
        $this->modulesPath   = $modulesPath   ?: (defined('MAIN_HOME')   ? MAIN_HOME   . 'modules'      : dirname(__DIR__, 2) . '/modules');
        $this->overridesPath = $overridesPath ?: (defined('CONFIG_PATH') ? CONFIG_PATH . 'modules.php'  : dirname(__DIR__, 2) . '/config/modules.php');
        $this->container     = $container;
    }

    /** @return object|null Database instance from the container, or null if unavailable. */
    private function getDb(): ?object {
        if ($this->container !== null && $this->container->has('db')) {
            return $this->container->get('db');
        }
        return null;
    }

    /**
     * List all installed modules with their metadata and status.
     *
     * Scans the modules directory for module.json files, merges with
     * config/modules.php overrides, and returns sorted results.
     *
     * @return array<int, array{name: string, description: string, version: string, requires_core: string, environment: string, priority: int, dependencies: array, optional_dependencies: array, has_navbar: bool, has_settings: bool, enabled: bool, state: ModuleState, path: string}> Module list.
     */
    public function listModules(): array {
        $overrides = $this->readOverrides();
        $items = [];

        $jsonFiles = glob($this->modulesPath . '/*/module.json') ?: [];
        foreach ($jsonFiles as $jsonFile) {
            $name  = basename(dirname($jsonFile));
            $meta  = json_decode((string) @file_get_contents($jsonFile), true) ?: [];
            $state = ModuleState::fromRaw($overrides[$name]['state'] ?? ($overrides[$name]['enabled'] ?? null));

            $items[] = [
                'name'                  => $name,
                'description'           => $meta['description'] ?? '',
                'version'               => $meta['version'] ?? '',
                'requires_core'         => $meta['requires_core'] ?? '',
                'environment'           => $meta['environment'] ?? 'main',
                'priority'              => (int) ($meta['priority'] ?? 0),
                'dependencies'          => is_array($meta['dependencies'] ?? null) ? $meta['dependencies'] : [],
                'optional_dependencies' => is_array($meta['optional_dependencies'] ?? null) ? $meta['optional_dependencies'] : [],
                'has_navbar'            => (bool) ($meta['has_navbar'] ?? false),
                'has_settings'          => (bool) ($meta['has_settings'] ?? false),
                'enabled'               => $state->isLoadable(),
                'state'                 => $state,
                'path'                  => dirname($jsonFile),
            ];
        }

        usort($items, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $items;
    }

    /**
     * Install a module by name.
     *
     * Loads the module instance, runs install(), and enables it.
     *
     * @param string $name Module name (lowercase, alphanumeric + hyphens).
     * @return void
     * @throws RuntimeException If the module cannot be loaded.
     */
    public function installModule(string $name): void {
        $name = $this->sanitizeModuleName($name);
        $module = $this->loadModuleInstance($name);

        $this->setState($name, ModuleState::Installing);

        try {
            $db = $this->getDb();
            if ($db !== null && method_exists($db, 'transactional')) {
                // Wrap install() in a DB transaction so partial migrations are rolled back.
                $db->transactional(function () use ($module) {
                    $module->install();
                });
            } else {
                $module->install();
            }
        } catch (\Throwable $e) {
            $this->setState($name, ModuleState::Failed);
            throw $e;
        }

        $this->setState($name, ModuleState::Enabled);
        $this->recordInstalledVersion($name, $module->getVersion());
    }

    /**
     * Uninstall a module by name.
     *
     * Runs uninstall() and disables the module.
     *
     * @param string $name Module name.
     * @return void
     * @throws RuntimeException If the module cannot be loaded.
     */
    public function uninstallModule(string $name): void {
        $name = $this->sanitizeModuleName($name);
        $module = $this->loadModuleInstance($name);
        $module->uninstall();
        $this->clearInstalledVersion($name);
        $this->setState($name, ModuleState::Disabled);
    }

    /**
     * Update a module, running only the incremental migrations needed.
     *
     * Reads the recorded installed_version from config/modules.php.
     * If no version is recorded (legacy install), falls back to full installModule().
     * If already at the current version, does nothing.
     * Otherwise runs all getMigrations() entries with version > installedVersion
     * and version <= module->getVersion(), in ascending semver order.
     *
     * @param string $name Module name.
     * @return void
     */
    public function updateModule(string $name): void {
        $name        = $this->sanitizeModuleName($name);
        $overrides   = $this->readOverrides();
        $fromVersion = $overrides[$name]['installed_version'] ?? null;

        if ($fromVersion === null) {
            $this->installModule($name);
            return;
        }

        $module    = $this->loadModuleInstance($name);
        $toVersion = $module->getVersion();

        if (version_compare($fromVersion, $toVersion, '>=')) {
            return;
        }

        if ($module instanceof MigratableInterface) {
            $this->runPendingMigrations($module->getMigrations(), $fromVersion, $toVersion);
        }

        $this->recordInstalledVersion($name, $toVersion);
    }

    /**
     * Set the lifecycle state of a module in config/modules.php.
     *
     * When state is Enabled the 'state' key is removed entirely (clean default).
     * When state is anything else the string value is persisted as 'state'.
     *
     * @param string      $name  Module name.
     * @param ModuleState $state Target lifecycle state.
     * @return void
     */
    public function setState(string $name, ModuleState $state): void {
        $name      = $this->sanitizeModuleName($name);
        $overrides = $this->readOverrides();

        if (!isset($overrides[$name]) || !is_array($overrides[$name])) {
            $overrides[$name] = [];
        }

        // Remove any legacy bool 'enabled' key — we use 'state' now.
        unset($overrides[$name]['enabled']);

        if ($state === ModuleState::Enabled) {
            // Enabled is the default: clean up the key so the file stays minimal.
            unset($overrides[$name]['state']);
            if (empty($overrides[$name])) {
                unset($overrides[$name]);
            }
        } else {
            $overrides[$name]['state'] = $state->value;
        }

        $this->writeOverrides($overrides);
    }

    /**
     * Enable or disable a module in config/modules.php.
     *
     * @deprecated Use setState(name, ModuleState::Enabled / ModuleState::Disabled) instead.
     *
     * @param string $name    Module name.
     * @param bool   $enabled True to enable, false to disable.
     * @return void
     */
    public function setEnabled(string $name, bool $enabled): void {
        $this->setState($name, $enabled ? ModuleState::Enabled : ModuleState::Disabled);
    }

    /**
     * Upload a zip archive and install the module from it.
     *
     * Extracts the archive to a temp directory, validates structure,
     * copies to the modules path, and runs installModule().
     *
     * @param string $zipFilePath Path to the uploaded zip file.
     * @return string Installed module name.
     * @throws RuntimeException If extraction or installation fails.
     * @throws InvalidArgumentException If the zip file is not found.
     */
    public function uploadAndInstall(string $zipFilePath): string {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension is not available.');
        }

        if (!is_file($zipFilePath)) {
            throw new InvalidArgumentException('Uploaded zip file not found.');
        }

        $tempBase = rtrim(sys_get_temp_dir(), '/') . '/xc_module_' . bin2hex(random_bytes(8));
        if (!@mkdir($tempBase, 0755, true) && !is_dir($tempBase)) {
            throw new RuntimeException('Unable to create temporary directory.');
        }

        try {
            $this->extractZipSafely($zipFilePath, $tempBase);

            $moduleDir = $this->resolveExtractedModuleDir($tempBase);
            $moduleName = basename($moduleDir);
            $moduleName = $this->sanitizeModuleName($moduleName);

            $targetDir = $this->modulesPath . '/' . $moduleName;
            if (is_dir($targetDir)) {
                $this->deleteDirectory($targetDir);
            }

            $this->copyDirectory($moduleDir, $targetDir);

            $this->installModule($moduleName);

            return $moduleName;
        } finally {
            $this->deleteDirectory($tempBase);
        }
    }

    /**
     * Download a module from the SaaS platform and install it.
     *
     * Delegates the full download → key-unwrap → extract flow to the
     * XC_VM C extension, then runs installModule() to register it.
     * Fires PackageInstalledEvent and hot-reloads the module into the
     * current ServiceContainer without requiring a PHP-FPM restart.
     *
     * @param string      $slug    Module slug as listed on the platform.
     * @param string      $version Exact version string (e.g. "1.2.0").
     * @param string|null $apiKey  API key for the SaaS platform.
     * @return void
     * @throws RuntimeException If the C extension is missing, download fails, or install fails.
     */
    public function downloadFromPlatform(string $slug, string $version, ?string $apiKey = null): void {
        if (!class_exists('XC_VM')) {
            throw new RuntimeException('XC_VM extension is not loaded. Install xcvm_core.so and enable it in php.ini.');
        }

        $result = \XC_VM::module_install($slug, $version, $apiKey ?? '');

        if (!is_array($result) || empty($result['ok'])) {
            $reason = $result['error'] ?? 'unknown';
            throw new RuntimeException("Platform download failed for module '{$slug}': {$reason}");
        }

        $modulePath = $result['path'];

        try {
            $this->installModule($slug);
        } catch (Throwable $e) {
            // Install failed (DB rolled back) — remove the partially unpacked directory
            // only if it sits inside our modules directory (prevent accidental deletion).
            $realModules = realpath($this->modulesPath);
            $realModule  = realpath($modulePath) ?: $modulePath;
            if ($realModules && str_starts_with($realModule, $realModules . '/')) {
                $this->deleteDirectory($modulePath);
            }
            throw new RuntimeException(
                "Module '{$slug}' install failed and was rolled back: " . $e->getMessage(),
                0,
                $e
            );
        }

        EventDispatcher::dispatch(new PackageInstalledEvent(
            slug:        $result['module'],
            version:     $result['version'],
            path:        $modulePath,
            installedAt: time(),
        ));

        $this->hotReload($slug, $modulePath);
    }

    /**
     * Hot-reload a newly installed module into the running ServiceContainer.
     *
     * Loads and boots the module within the current request so it becomes
     * immediately usable without a PHP-FPM restart.
     *
     * @param string $slug       Module name.
     * @param string $modulePath Absolute path to the module directory.
     */
    private function hotReload(string $slug, string $modulePath): void {
        $container = ServiceContainer::getInstance();

        $loader = new ModuleLoader();
        if (!$loader->load($slug, $modulePath)) {
            return;
        }

        $router = $container->getOrDefault('router');
        $loader->bootAll($container, $router instanceof Router ? $router : null);
    }

    /**
     * Run migrations whose target version falls in (fromVersion, toVersion].
     *
     * @param array<string, callable> $migrations
     * @param string $fromVersion Currently installed version (exclusive lower bound).
     * @param string $toVersion   New version (inclusive upper bound).
     */
    private function runPendingMigrations(array $migrations, string $fromVersion, string $toVersion): void {
        $pending = [];
        foreach ($migrations as $version => $callable) {
            if (
                version_compare($version, $fromVersion, '>') &&
                version_compare($version, $toVersion, '<=')
            ) {
                $pending[$version] = $callable;
            }
        }

        uksort($pending, 'version_compare');

        $db = $this->getDb();
        foreach ($pending as $callable) {
            if ($db !== null && method_exists($db, 'transactional')) {
                $db->transactional(fn() => $callable($this->container));
            } else {
                $callable($this->container);
            }
        }
    }

    /**
     * Persist the installed version for a module in config/modules.php.
     *
     * @param string $name    Module name.
     * @param string $version Installed version string.
     */
    private function recordInstalledVersion(string $name, string $version): void {
        $overrides = $this->readOverrides();
        if (!isset($overrides[$name]) || !is_array($overrides[$name])) {
            $overrides[$name] = [];
        }
        $overrides[$name]['installed_version'] = $version;
        $this->writeOverrides($overrides);
    }

    /**
     * Remove the recorded installed version for a module from config/modules.php.
     *
     * @param string $name Module name.
     */
    private function clearInstalledVersion(string $name): void {
        $overrides = $this->readOverrides();
        if (!isset($overrides[$name]['installed_version'])) {
            return;
        }
        unset($overrides[$name]['installed_version']);
        if (empty($overrides[$name])) {
            unset($overrides[$name]);
        }
        $this->writeOverrides($overrides);
    }

    /**
     * Load and return a module instance by name.
     *
     * @param string $name Module name.
     * @return object Module instance implementing ModuleInterface.
     * @throws RuntimeException If the module cannot be loaded or instantiated.
     */
    private function loadModuleInstance(string $name) {
        $name = $this->sanitizeModuleName($name);
        $loader = new ModuleLoader();
        $ok = $loader->load($name, $this->modulesPath . '/' . $name);
        if (!$ok) {
            throw new ModuleNotFoundException('Cannot load module: ' . $name);
        }

        $module = $loader->getModule($name);
        if (!$module) {
            throw new ModuleNotFoundException('Module instance is not available: ' . $name);
        }

        return $module;
    }

    /**
     * Validate and sanitize a module name.
     *
     * @param string $name Raw module name.
     * @return string Sanitized module name.
     * @throws InvalidArgumentException If the name is invalid.
     */
    private function sanitizeModuleName(string $name): string {
        $name = trim((string) $name);
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $name)) {
            throw new ModuleException('Invalid module name.');
        }
        return $name;
    }

    /**
     * Read module overrides from config/modules.php.
     *
     * @return array<string, array> Module overrides keyed by module name.
     */
    private function readOverrides(): array {
        if (!file_exists($this->overridesPath)) {
            return [];
        }

        $data = require $this->overridesPath;
        return is_array($data) ? $data : [];
    }

    /**
     * Write module overrides to config/modules.php atomically.
     *
     * Writes to a sibling temp file then renames into place, so concurrent
     * requests can never read a partially-written file.
     *
     * @param array $overrides Module overrides to persist.
     * @return void
     * @throws RuntimeException If the file cannot be written or renamed.
     */
    private function writeOverrides(array $overrides): void {
        ksort($overrides);

        $content = "<?php\n\nreturn " . var_export($overrides, true) . ";\n";

        $dir  = dirname($this->overridesPath);
        $tmp  = @tempnam($dir, '.modules_tmp_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create temporary file for config/modules.php');
        }

        try {
            if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write config/modules.php (temp stage)');
            }

            @chmod($tmp, 0644);

            if (!@rename($tmp, $this->overridesPath)) {
                throw new RuntimeException('Unable to atomically replace config/modules.php');
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }

    /**
     * Safely extract a zip archive to the destination directory.
     *
     * Validates each entry for path traversal attacks before extracting.
     *
     * @param string $zipFilePath  Path to the zip file.
     * @param string $destination  Extraction target directory.
     * @return void
     * @throws RuntimeException If extraction fails or unsafe entries are detected.
     */
    private function extractZipSafely(string $zipFilePath, string $destination): void {
        $zip = new ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            throw new RuntimeException('Unable to open zip archive.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false || $entry === '') {
                    continue;
                }

                $entry = str_replace('\\', '/', $entry);
                if (strpos($entry, '../') !== false || strpos($entry, '..\\') !== false || strpos($entry, ':') !== false) {
                    throw new RuntimeException('Unsafe zip entry detected.');
                }

                $targetPath = rtrim($destination, '/') . '/' . ltrim($entry, '/');

                if (substr($entry, -1) === '/') {
                    if (!is_dir($targetPath) && !@mkdir($targetPath, 0755, true)) {
                        throw new RuntimeException('Unable to create directory while extracting zip.');
                    }
                    continue;
                }

                $dir = dirname($targetPath);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                    throw new RuntimeException('Unable to create directory while extracting zip.');
                }

                $in = $zip->getStream($entry);
                if (!$in) {
                    throw new RuntimeException('Unable to read zip entry stream.');
                }

                $out = @fopen($targetPath, 'wb');
                if (!$out) {
                    fclose($in);
                    throw new RuntimeException('Unable to write extracted file.');
                }

                while (!feof($in)) {
                    $chunk = fread($in, 8192);
                    if ($chunk === false) {
                        break;
                    }
                    fwrite($out, $chunk);
                }

                fclose($in);
                fclose($out);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Resolve the module root directory from the extracted temp path.
     *
     * Handles both flat and nested zip layouts.
     *
     * @param string $tempBase Temporary extraction directory.
     * @return string Path to the directory containing module.json.
     * @throws RuntimeException If module.json is not found or ambiguous.
     */
    private function resolveExtractedModuleDir(string $tempBase): string {
        $rootJson = $tempBase . '/module.json';
        if (is_file($rootJson)) {
            return $tempBase;
        }

        $jsonFiles = glob($tempBase . '/*/module.json') ?: [];
        if (count($jsonFiles) !== 1) {
            throw new RuntimeException('Zip must contain exactly one module with module.json.');
        }

        return dirname($jsonFiles[0]);
    }

    /**
     * Recursively delete a directory and its contents.
     *
     * @param string $path Path to delete.
     * @return void
     */
    private function deleteDirectory(string $path): void {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if (!$items) {
            @rmdir($path);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteDirectory($path . '/' . $item);
        }

        @rmdir($path);
    }

    /**
     * Recursively copy a directory.
     *
     * @param string $source      Source directory path.
     * @param string $destination Destination directory path.
     * @return void
     * @throws RuntimeException If copying fails.
     */
    private function copyDirectory(string $source, string $destination): void {
        if (!is_dir($source)) {
            throw new RuntimeException('Source directory not found: ' . $source);
        }

        if (!is_dir($destination) && !@mkdir($destination, 0755, true)) {
            throw new RuntimeException('Unable to create module directory.');
        }

        $items = scandir($source);
        if (!$items) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $src = $source . '/' . $item;
            $dst = $destination . '/' . $item;

            if (is_dir($src)) {
                $this->copyDirectory($src, $dst);
            } else {
                if (!@copy($src, $dst)) {
                    throw new RuntimeException('Unable to copy file: ' . $item);
                }
            }
        }
    }
}
