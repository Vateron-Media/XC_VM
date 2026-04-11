<?php

/**
 * Core Code Patcher — applies and reverts file-level patches for modules.
 *
 * When a module implements CoreCodePatchableModuleInterface, ModuleManager
 * uses this class to replace core files on install and restore originals
 * on uninstall.
 *
 * Backups are stored inside the module directory under .core_patch_backups/.
 * A manifest (.core_patch_manifest.json) tracks which files were patched.
 *
 * @temporary This is a stopgap until proper hook/integration points exist
 *            in core. Prefer native extension points over file patching.
 *
 * @package XC_VM_Core_Module
 * @author  obscuremind <https://github.com/obscuremind>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CoreCodePatcher {
    private const MANIFEST_FILE = '.core_patch_manifest.json';
    private const BACKUP_DIR = '.core_patch_backups';

    public static function apply(string $moduleName, string $modulePath, array $patches): void {
        self::revert($moduleName, $modulePath);

        if (empty($patches)) {
            return;
        }

        $manifest = [];
        foreach ($patches as $patch) {
            if (!is_array($patch)) {
                throw new InvalidArgumentException('Invalid core patch definition for module: ' . $moduleName);
            }

            $targetRelative = self::normalizeRelativePath((string) ($patch['target'] ?? ''));
            if ($targetRelative === '') {
                throw new InvalidArgumentException('Core patch target is required for module: ' . $moduleName);
            }

            $targetPath = self::resolveMainPath($targetRelative);
            if (!is_file($targetPath)) {
                throw new RuntimeException('Core patch target file not found: ' . $targetRelative);
            }

            $replacement = self::resolveReplacementContent($modulePath, $patch);

            $backupPath = self::backupPath($modulePath, $targetRelative);
            self::ensureDirectory(dirname($backupPath));
            if (!@copy($targetPath, $backupPath)) {
                throw new RuntimeException('Unable to backup core file: ' . $targetRelative);
            }

            if (@file_put_contents($targetPath, $replacement, LOCK_EX) === false) {
                throw new RuntimeException('Unable to patch core file: ' . $targetRelative);
            }

            $manifest[] = [
                'target' => $targetRelative,
                'backup' => ltrim(str_replace(rtrim($modulePath, '/') . '/', '', $backupPath), '/'),
            ];
        }

        $manifestPath = self::manifestPath($modulePath);
        if (@file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('Unable to write core patch manifest for module: ' . $moduleName);
        }
    }

    public static function revert(string $moduleName, string $modulePath): void {
        $manifestPath = self::manifestPath($modulePath);
        if (!is_file($manifestPath)) {
            return;
        }

        $raw = @file_get_contents($manifestPath);
        $manifest = json_decode((string) $raw, true);
        if (!is_array($manifest)) {
            throw new RuntimeException('Invalid core patch manifest for module: ' . $moduleName);
        }

        foreach ($manifest as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $targetRelative = self::normalizeRelativePath((string) ($entry['target'] ?? ''));
            $backupRelative = self::normalizeRelativePath((string) ($entry['backup'] ?? ''));
            if ($targetRelative === '' || $backupRelative === '') {
                continue;
            }

            $targetPath = self::resolveMainPath($targetRelative);
            $backupPath = rtrim($modulePath, '/') . '/' . $backupRelative;
            if (!is_file($backupPath)) {
                continue;
            }

            self::ensureDirectory(dirname($targetPath));
            if (!@copy($backupPath, $targetPath)) {
                throw new RuntimeException('Unable to restore core file: ' . $targetRelative);
            }
        }

        @unlink($manifestPath);
        self::deleteDirectory(rtrim($modulePath, '/') . '/' . self::BACKUP_DIR);
    }

    private static function resolveReplacementContent(string $modulePath, array $patch): string {
        $hasContent = array_key_exists('content', $patch);
        $hasSource = array_key_exists('source', $patch);

        if ($hasContent === $hasSource) {
            throw new InvalidArgumentException('Core patch must include exactly one of: content or source.');
        }

        if ($hasContent) {
            return (string) $patch['content'];
        }

        $sourceRelative = self::normalizeRelativePath((string) $patch['source']);
        if ($sourceRelative === '') {
            throw new InvalidArgumentException('Core patch source path is empty.');
        }

        $sourcePath = rtrim($modulePath, '/') . '/' . $sourceRelative;
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Core patch source file not found: ' . $sourceRelative);
        }

        $contents = @file_get_contents($sourcePath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read core patch source file: ' . $sourceRelative);
        }

        return $contents;
    }

    private static function manifestPath(string $modulePath): string {
        return rtrim($modulePath, '/') . '/' . self::MANIFEST_FILE;
    }

    private static function backupPath(string $modulePath, string $targetRelative): string {
        return rtrim($modulePath, '/') . '/' . self::BACKUP_DIR . '/' . $targetRelative;
    }

    private static function resolveMainPath(string $relative): string {
        $base = defined('MAIN_HOME') ? rtrim(MAIN_HOME, '/') : dirname(__DIR__, 2);
        $path = $base . '/' . $relative;

        $mainRoot = realpath($base);
        if ($mainRoot === false) {
            throw new RuntimeException('Unable to resolve MAIN_HOME path.');
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false) {
            $resolvedDir = realpath(dirname($path));
            if ($resolvedDir === false || strpos($resolvedDir, $mainRoot) !== 0) {
                throw new InvalidArgumentException('Invalid core patch target path: ' . $relative);
            }
            return $path;
        }

        if (strpos($resolvedPath, $mainRoot) !== 0) {
            throw new InvalidArgumentException('Core patch target is outside project root: ' . $relative);
        }

        return $resolvedPath;
    }

    private static function normalizeRelativePath(string $path): string {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || strpos($path, '../') !== false || strpos($path, '..\\') !== false || strpos($path, ':') !== false) {
            return '';
        }

        return $path;
    }

    private static function ensureDirectory(string $dir): void {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory: ' . $dir);
        }
    }

    private static function deleteDirectory(string $path): void {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if ($items) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                self::deleteDirectory($path . '/' . $item);
            }
        }

        @rmdir($path);
    }
}
