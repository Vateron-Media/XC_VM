<?php

use XcVm\Core\Module\ModuleManager;
use XcVm\Core\Module\BaseModule;
use PHPUnit\Framework\TestCase;

// Static tracker used by generated module migration callables.
class MigrationCallTracker {
    public static array $calls = [];

    public static function record(string $version): void {
        self::$calls[] = $version;
    }

    public static function reset(): void {
        self::$calls = [];
    }
}

final class ModuleManagerMigrationsTest extends TestCase {

    private string $modulesPath;
    private string $overridesPath;
    private string $workDir;

    protected function setUp(): void {
        $this->workDir      = sys_get_temp_dir() . '/xc_vm_mgr_' . bin2hex(random_bytes(6));
        $this->modulesPath  = $this->workDir . '/modules';
        $this->overridesPath = $this->workDir . '/modules.php';
        mkdir($this->modulesPath, 0775, true);
        MigrationCallTracker::reset();
    }

    protected function tearDown(): void {
        $this->deleteDir($this->workDir);
        MigrationCallTracker::reset();
    }

    // ── installModule() records installed_version ─────────────────────────

    public function testInstallRecordsInstalledVersion(): void {
        $this->createModule('install-record', '1.0.0');

        $manager = $this->manager();
        $manager->installModule('install-record');

        $overrides = $this->readOverrides();
        $this->assertSame('1.0.0', $overrides['install-record']['installed_version'] ?? null);
    }

    // ── uninstallModule() clears installed_version ────────────────────────

    public function testUninstallClearsInstalledVersion(): void {
        $this->createModule('uninstall-clear', '1.0.0');
        $this->writeOverrides(['uninstall-clear' => ['installed_version' => '1.0.0']]);

        $manager = $this->manager();
        $manager->uninstallModule('uninstall-clear');

        $overrides = $this->readOverrides();
        $this->assertArrayNotHasKey('installed_version', $overrides['uninstall-clear'] ?? []);
    }

    // ── updateModule() — fallback to installModule() ──────────────────────

    public function testUpdateWithNoInstalledVersionFallsBackToInstall(): void {
        $this->createModule('update-fallback', '2.0.0');

        $manager = $this->manager();
        $manager->updateModule('update-fallback');

        $overrides = $this->readOverrides();
        $this->assertSame('2.0.0', $overrides['update-fallback']['installed_version'] ?? null);
    }

    // ── updateModule() — already up-to-date ──────────────────────────────

    public function testUpdateWithSameVersionSkipsMigrations(): void {
        $this->createMigratableModule('update-same', '1.0.0', ['1.0.0']);
        $this->writeOverrides(['update-same' => ['installed_version' => '1.0.0']]);

        $manager = $this->manager();
        $manager->updateModule('update-same');

        // Migration for 1.0.0 must NOT run — fromVersion == toVersion
        $this->assertSame([], MigrationCallTracker::$calls);
    }

    // ── updateModule() — runs pending migrations in semver order ─────────

    public function testUpdateRunsPendingMigrationsInOrder(): void {
        $this->createMigratableModule('update-order', '1.3.0', ['0.9.0', '1.1.0', '1.3.0', '1.2.0']);
        $this->writeOverrides(['update-order' => ['installed_version' => '1.0.0']]);

        $manager = $this->manager();
        $manager->updateModule('update-order');

        // 0.9.0 <= 1.0.0 → excluded. 1.1.0, 1.2.0, 1.3.0 → ascending semver order.
        $this->assertSame(['1.1.0', '1.2.0', '1.3.0'], MigrationCallTracker::$calls);
    }

    public function testUpdateExcludesMigrationsAboveToVersion(): void {
        $this->createMigratableModule('update-cap', '1.1.0', ['1.1.0', '1.2.0', '2.0.0']);
        $this->writeOverrides(['update-cap' => ['installed_version' => '1.0.0']]);

        $manager = $this->manager();
        $manager->updateModule('update-cap');

        // Module version is 1.1.0 → migrations > 1.1.0 must be excluded
        $this->assertSame(['1.1.0'], MigrationCallTracker::$calls);
    }

    // ── updateModule() — records new version after migrations ─────────────

    public function testUpdateRecordsNewVersionAfterMigrations(): void {
        $this->createMigratableModule('update-record', '2.5.0', ['2.0.0', '2.5.0']);
        $this->writeOverrides(['update-record' => ['installed_version' => '1.9.0']]);

        $manager = $this->manager();
        $manager->updateModule('update-record');

        $overrides = $this->readOverrides();
        $this->assertSame('2.5.0', $overrides['update-record']['installed_version'] ?? null);
    }

    public function testUpdateWithNoMigrationsStillRecordsNewVersion(): void {
        $this->createMigratableModule('update-nomir', '3.0.0', []);
        $this->writeOverrides(['update-nomir' => ['installed_version' => '2.0.0']]);

        $manager = $this->manager();
        $manager->updateModule('update-nomir');

        $overrides = $this->readOverrides();
        $this->assertSame('3.0.0', $overrides['update-nomir']['installed_version'] ?? null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function manager(): ModuleManager {
        return new ModuleManager($this->modulesPath, $this->overridesPath, ServiceContainer::getInstance());
    }

    /** Create a plain module (no migrations) at the given version. */
    private function createModule(string $name, string $version): void {
        $dir       = $this->modulesPath . '/' . $name;
        $pascal    = $this->pascal($name);
        $className = $pascal . 'Module';
        $namespace = 'XcVm\\Module\\' . $pascal;
        mkdir($dir, 0775, true);

        file_put_contents(
            $dir . '/module.json',
            json_encode($this->manifest($name, $version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        file_put_contents($dir . '/' . $className . '.php',
            "<?php\n"
            . "namespace {$namespace};\n"
            . "use XcVm\Core\Module\BaseModule;\n"
            . "class {$className} extends BaseModule {\n"
            . "\tpublic function getName(): string { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '{$version}'; }\n"
            . "}\n"
        );
    }

    /**
     * Create a module that tracks migration calls via MigrationCallTracker.
     *
     * @param string   $name     Module name (kebab-case).
     * @param string   $version  Module version (reported by getVersion()).
     * @param string[] $migrationVersions Version strings to include as migration keys.
     */
    private function createMigratableModule(string $name, string $version, array $migrationVersions): void {
        $dir       = $this->modulesPath . '/' . $name;
        $pascal    = $this->pascal($name);
        $className = $pascal . 'Module';
        $namespace = 'XcVm\\Module\\' . $pascal;
        mkdir($dir, 0775, true);

        file_put_contents(
            $dir . '/module.json',
            json_encode($this->manifest($name, $version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $migLines = '';
        foreach ($migrationVersions as $ver) {
            $migLines .= "\t\t\t'{$ver}' => function(\$c) { \\MigrationCallTracker::record('{$ver}'); },\n";
        }

        file_put_contents($dir . '/' . $className . '.php',
            "<?php\n"
            . "namespace {$namespace};\n"
            . "use XcVm\Core\Module\BaseModule;\n"
            . "class {$className} extends BaseModule {\n"
            . "\tpublic function getName(): string { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '{$version}'; }\n"
            . "\tpublic function getMigrations(): array {\n"
            . "\t\treturn [\n"
            . $migLines
            . "\t\t];\n"
            . "\t}\n"
            . "}\n"
        );
    }

    private function manifest(string $name, string $version): array {
        return [
            'name'                  => $name,
            'description'           => 'migration test module',
            'version'               => $version,
            'requires_core'         => '>=2.0',
            'environment'           => 'main',
            'dependencies'          => [],
            'optional_dependencies' => [],
            'has_navbar'            => false,
            'has_settings'          => false,
            'priority'              => 0,
        ];
    }

    private function writeOverrides(array $overrides): void {
        $content = "<?php\n\nreturn " . var_export($overrides, true) . ";\n";
        file_put_contents($this->overridesPath, $content);
    }

    private function readOverrides(): array {
        if (!file_exists($this->overridesPath)) {
            return [];
        }
        $data = require $this->overridesPath;
        return is_array($data) ? $data : [];
    }

    private function pascal(string $name): string {
        return implode('', array_map('ucfirst', explode('-', $name)));
    }

    private function deleteDir(string $path): void {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteDir($path . '/' . $item);
        }
        @rmdir($path);
    }
}
