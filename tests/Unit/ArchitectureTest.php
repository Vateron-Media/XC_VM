<?php

use XcVm\Module\Watch\WatchService;
use XcVm\Module\Watch\WatchItemCommand;
use XcVm\Module\Watch\WatchItem;
use XcVm\Module\Watch\WatchCron;
use XcVm\Module\Watch\RecordingService;
use XcVm\Module\Plex\PlexService;
use XcVm\Module\Plex\PlexRepository;
use XcVm\Module\Plex\PlexItemCommand;
use XcVm\Module\Plex\PlexItem;
use XcVm\Module\Plex\PlexCron;
use XcVm\Core\Boundary\BoundaryInterface;
use XcVm\Core\Container\ServiceContainer;
use PHPUnit\Framework\TestCase;

/**
 * Architecture guard — enforces structural rules for src/modules/.
 *
 * Rules checked here are invariants that must never regress:
 *   1. No module file may use ServiceContainer::getInstance() (Service Locator anti-pattern).
 *   2. No web-context module file may use `global $db` (DI boundary violation).
 *   3. Every module entry-point (*Module.php) must declare XcVm\Module\{Pascal} namespace.
 *
 * Explicit exemptions are listed with a comment explaining WHY and referencing
 * the roadmap item that will eventually remove the exemption.
 */
final class ArchitectureTest extends TestCase {

    private const MODULES_DIR = __DIR__ . '/../../src/Modules';

    /**
     * Files that still use `global $db` because they run under a separate CLI
     * bootstrap (admin.php) and are not part of the web DI context.
     * Will be removed after R4-3 (full global $db migration).
     */
    private const CLI_GLOBAL_DB_EXEMPT = [
        'PlexCron.php',
        'PlexItem.php',
        'PlexItemCommand.php',
        'WatchCron.php',
        'WatchItem.php',
        'WatchItemCommand.php',
    ];

    /**
     * Files using the setDb()+db() DI migration pattern: they receive $db via
     * boot() in web context but retain `global $db` as a fallback for the CLI
     * cron path that bypasses the DI container.
     * Will be removed after R4-3 completes the full migration.
     */
    private const MIGRATION_FALLBACK_EXEMPT = [
        'PlexService.php',
        'PlexRepository.php',
        'WatchService.php',
        'RecordingService.php',
    ];

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Yields [relative-path => content] for every .php file under src/modules/,
     * optionally skipping top-level module subdirectories by name.
     *
     * @param string[] $excludeModuleDirs  Top-level dir names to skip (e.g. ['ministra']).
     * @return iterable<string, string>
     */
    private function moduleFiles(array $excludeModuleDirs = []): iterable {
        $baseReal = realpath(self::MODULES_DIR);

        $excludeReal = [];
        foreach ($excludeModuleDirs as $dir) {
            $p = realpath(self::MODULES_DIR . '/' . $dir);
            if ($p !== false) {
                $excludeReal[] = $p;
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::MODULES_DIR, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $realPath = (string) $file->getRealPath();

            foreach ($excludeReal as $excluded) {
                if (str_starts_with($realPath, $excluded . DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }

            $relative = substr($realPath, strlen($baseReal) + 1);
            yield $relative => (string) file_get_contents($realPath);
        }
    }

    /**
     * Resolve a module's on-disk directory basename by its canonical manifest name.
     *
     * Module directories use the `{name}_{hash5}` convention, so the basename is not
     * the canonical name. Tests that need a specific module must resolve it via its
     * module.json `name`, never by assuming the directory is named after the module.
     */
    private function moduleDirBasename(string $canonicalName): ?string {
        $dirs = new FilesystemIterator(self::MODULES_DIR, FilesystemIterator::SKIP_DOTS);
        foreach ($dirs as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isDir()) {
                continue;
            }
            $manifest = $entry->getRealPath() . '/module.json';
            if (!is_file($manifest)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($manifest), true);
            if (is_array($data) && ($data['name'] ?? null) === $canonicalName) {
                return $entry->getBasename();
            }
        }
        return null;
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    /**
     * No module file may resolve the container via the static singleton.
     * Modules receive ServiceContainer through boot(ServiceContainer $c).
     */
    public function testNoModuleUsesServiceLocator(): void {
        foreach ($this->moduleFiles() as $relative => $content) {
            $this->assertStringNotContainsString(
                'ServiceContainer::getInstance()',
                $content,
                "modules/{$relative} must not call ServiceContainer::getInstance() — use boot(ServiceContainer \$c) instead"
            );
        }
    }

    /**
     * Web-context module files must not use `global $db`.
     *
     * Exemptions:
     * - ministra/ — BoundaryInterface subsystem with its own portal bootstrap (permanent)
     * - CLI_GLOBAL_DB_EXEMPT — cron/CLI classes using admin.php bootstrap (temporary, see R4-3)
     */
    public function testNoWebContextModuleUsesGlobalDb(): void {
        $ministraDir = $this->moduleDirBasename('ministra');
        $exclude     = $ministraDir !== null ? [$ministraDir] : [];

        // May legitimately find nothing to check (every non-exempt module
        // extracted to its own repo) — that is a pass, not a risky test.
        $this->addToAssertionCount(1);

        foreach ($this->moduleFiles(excludeModuleDirs: $exclude) as $relative => $content) {
            $basename = basename($relative);

            if (in_array($basename, self::CLI_GLOBAL_DB_EXEMPT, true)) {
                continue;
            }

            if (in_array($basename, self::MIGRATION_FALLBACK_EXEMPT, true)) {
                continue;
            }

            $this->assertStringNotContainsString(
                'global $db',
                $content,
                "modules/{$relative} must not use global \$db — inject via boot(ServiceContainer \$c)"
            );
        }
    }

    /**
     * Every module entry-point (*Module.php) must declare the canonical namespace.
     *
     * Convention: XcVm\Module\{Pascal} where Pascal = PascalCase of the canonical
     * manifest name (module.json `name`), NOT the `{name}_{hash5}` directory basename.
     * Non-entry-point files (controllers, services, cron) are not yet required — see R4-3.
     */
    public function testModuleEntryPointsHaveCorrectNamespace(): void {
        $modulesDir = new FilesystemIterator(self::MODULES_DIR, FilesystemIterator::SKIP_DOTS);

        $checked = 0;
        foreach ($modulesDir as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isDir()) {
                continue;
            }

            $manifest = $entry->getRealPath() . '/module.json';
            if (!is_file($manifest)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($manifest), true);
            $name = is_array($data) ? (string) ($data['name'] ?? '') : '';
            if ($name === '') {
                continue;
            }

            $pascal     = implode('', array_map('ucfirst', explode('-', $name)));
            $moduleFile = $entry->getRealPath() . '/' . $pascal . 'Module.php';

            if (!file_exists($moduleFile)) {
                continue;
            }

            $expected = "namespace XcVm\\Module\\{$pascal};";
            $content  = (string) file_get_contents($moduleFile);

            $this->assertStringContainsString(
                $expected,
                $content,
                "{$pascal}Module.php must declare `{$expected}`"
            );

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'No *Module.php files found — check MODULES_DIR path');
    }

    /**
     * Sanity: every module directory must contain a module.json manifest.
     *
     * Catches accidental directory clutter in src/modules/.
     */
    public function testEveryModuleDirectoryHasManifest(): void {
        $modulesDir = new FilesystemIterator(self::MODULES_DIR, FilesystemIterator::SKIP_DOTS);

        $checked = 0;
        foreach ($modulesDir as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isDir()) {
                continue;
            }

            $manifest = $entry->getRealPath() . '/module.json';
            $this->assertFileExists(
                $manifest,
                "modules/{$entry->getBasename()}/ must contain a module.json manifest"
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'No module directories found');
    }
}
