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
    /** @var string */
    private $modulesPath;

    /** @var string */
    private $overridesPath;

    /**
     * Initialize the module manager.
     *
     * @param string|null $modulesPath   Path to the modules directory.
     * @param string|null $overridesPath Path to the config/modules.php overrides file.
     */
    public function __construct(?string $modulesPath = null, ?string $overridesPath = null) {
        $this->modulesPath = $modulesPath ?: (defined('MAIN_HOME') ? MAIN_HOME . 'modules' : dirname(__DIR__, 2) . '/modules');
        $this->overridesPath = $overridesPath ?: (defined('CONFIG_PATH') ? CONFIG_PATH . 'modules.php' : dirname(__DIR__, 2) . '/config/modules.php');
    }

    /**
     * List all installed modules with their metadata and status.
     *
     * Scans the modules directory for module.json files, merges with
     * config/modules.php overrides, and returns sorted results.
     *
     * @return array<int, array{name: string, description: string, version: string, requires_core: string, enabled: bool, path: string}> Module list.
     */
    public function listModules(): array {
        $overrides = $this->readOverrides();
        $items = [];

        $jsonFiles = glob($this->modulesPath . '/*/module.json') ?: [];
        foreach ($jsonFiles as $jsonFile) {
            $name = basename(dirname($jsonFile));
            $meta = json_decode((string) @file_get_contents($jsonFile), true) ?: [];

            $items[] = [
                'name' => $name,
                'description' => $meta['description'] ?? '',
                'version' => $meta['version'] ?? '',
                'requires_core' => $meta['requires_core'] ?? '',
                'enabled' => !(isset($overrides[$name]['enabled']) && $overrides[$name]['enabled'] === false),
                'path' => dirname($jsonFile),
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
     * Loads the module instance, runs install(), applies core patches
     * if the module implements CoreCodePatchableModuleInterface, and enables it.
     *
     * @param string $name Module name (lowercase, alphanumeric + hyphens).
     * @return void
     * @throws RuntimeException If the module cannot be loaded.
     */
    public function installModule(string $name): void {
        $name = $this->sanitizeModuleName($name);
        $module = $this->loadModuleInstance($name);
        $module->install();
        if ($module instanceof CoreCodePatchableModuleInterface) {
            CoreCodePatcher::apply($name, $this->modulesPath . '/' . $name, $module->getCoreCodePatches());
        }
        $this->setEnabled($name, true);
    }

    /**
     * Uninstall a module by name.
     *
     * Reverts core patches if applicable, runs uninstall(), and disables the module.
     *
     * @param string $name Module name.
     * @return void
     * @throws RuntimeException If the module cannot be loaded.
     */
    public function uninstallModule(string $name): void {
        $name = $this->sanitizeModuleName($name);
        $module = $this->loadModuleInstance($name);
        if ($module instanceof CoreCodePatchableModuleInterface) {
            CoreCodePatcher::revert($name, $this->modulesPath . '/' . $name);
        }
        $module->uninstall();
        $this->setEnabled($name, false);
    }

    /**
     * Update a module by re-installing it.
     *
     * @param string $name Module name.
     * @return void
     */
    public function updateModule(string $name): void {
        $this->installModule($name);
    }

    /**
     * Enable or disable a module in config/modules.php.
     *
     * @param string $name    Module name.
     * @param bool   $enabled True to enable, false to disable.
     * @return void
     */
    public function setEnabled(string $name, bool $enabled): void {
        $name = $this->sanitizeModuleName($name);
        $overrides = $this->readOverrides();

        if ($enabled) {
            if (isset($overrides[$name]['enabled'])) {
                unset($overrides[$name]['enabled']);
            }
            if (isset($overrides[$name]) && count($overrides[$name]) === 0) {
                unset($overrides[$name]);
            }
        } else {
            if (!isset($overrides[$name]) || !is_array($overrides[$name])) {
                $overrides[$name] = [];
            }
            $overrides[$name]['enabled'] = false;
        }

        $this->writeOverrides($overrides);
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
            throw new RuntimeException('Cannot load module: ' . $name);
        }

        $module = $loader->getModule($name);
        if (!$module) {
            throw new RuntimeException('Module instance is not available: ' . $name);
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
            throw new InvalidArgumentException('Invalid module name.');
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
     * Write module overrides to config/modules.php.
     *
     * @param array $overrides Module overrides to persist.
     * @return void
     * @throws RuntimeException If the file cannot be written.
     */
    private function writeOverrides(array $overrides): void {
        ksort($overrides);

        $content = "<?php\n\nreturn " . var_export($overrides, true) . ";\n";

        if (@file_put_contents($this->overridesPath, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write config/modules.php');
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
