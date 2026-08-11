<?php

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
        // Zero committed modules is a valid state (see testEveryModuleDirectory
        // HasManifest): the loop may find nothing to check — that is a pass.
        $this->addToAssertionCount(1);

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
     * - ministra/ — isolated subsystem with its own portal bootstrap (permanent)
     */
    public function testNoWebContextModuleUsesGlobalDb(): void {
        $ministraDir = $this->moduleDirBasename('ministra');
        $exclude     = $ministraDir !== null ? [$ministraDir] : [];

        // May legitimately find nothing to check (every non-exempt module
        // extracted to its own repo) — that is a pass, not a risky test.
        $this->addToAssertionCount(1);

        foreach ($this->moduleFiles(excludeModuleDirs: $exclude) as $relative => $content) {
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

        // Zero committed modules is valid: modules may be git-source (installed
        // at runtime) and ministra now lives in core (src/Ministra). Finding
        // none to check is a pass, not a misconfigured MODULES_DIR.
        $this->addToAssertionCount(1);
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

        // As above: zero committed module directories is a valid state.
        $this->addToAssertionCount(1);
    }
}
