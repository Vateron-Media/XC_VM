<?php

use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * ModuleLoader — Composer package discovery tests.
 *
 * Verifies that ModuleLoader::loadAll() discovers modules installed as
 * Composer packages (type: xcvm-module) from vendor/composer/installed.json,
 * in addition to modules in the local modules/ directory.
 *
 * Directory layout mirrored in the temp root:
 *
 *   $root/
 *     modules/
 *       local-alpha/        — local module (classic)
 *     vendor/
 *       composer/
 *         installed.json    — Composer 2.x manifest
 *       vateron/
 *         composer-beta/    — Composer-installed xcvm-module package
 *           module.json
 *           ComposerBetaModule.php
 */
final class ComposerDiscoveryTest extends TestCase {

    private string $root;

    protected function setUp(): void {
        ServiceContainer::resetInstance();
        Router::resetInstance();
        NavbarRegistry::reset();
        EventDispatcher::resetInstance();

        $this->root = sys_get_temp_dir() . '/xc_vm_cdtest_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/modules', 0775, true);
        mkdir($this->root . '/vendor/composer', 0775, true);
    }

    protected function tearDown(): void {
        $this->removeTree($this->root);
        ServiceContainer::resetInstance();
        Router::resetInstance();
        NavbarRegistry::reset();
        EventDispatcher::resetInstance();
    }

    // ── Core behaviour ────────────────────────────────────────────

    public function testLoadAllIgnoresVendorWhenInstalledJsonMissing(): void {
        $this->createLocalModule('local-alpha');
        // No installed.json in vendor/composer/ — just the directory

        $loader = new ModuleLoader();
        $loader->loadAll($this->root . '/modules');

        $this->assertTrue($loader->isLoaded('local-alpha'));
        $this->assertCount(1, $loader->getModules());
    }

    public function testLoadAllSkipsVendorPackagesWithWrongType(): void {
        $this->createLocalModule('local-beta');
        $this->writeInstalledJson([
            ['name' => 'vendor/other-lib', 'type' => 'library', 'install-path' => '../vendor/other-lib'],
        ]);

        $loader = new ModuleLoader();
        $loader->loadAll($this->root . '/modules');

        $this->assertCount(1, $loader->getModules());
        $this->assertTrue($loader->isLoaded('local-beta'));
    }

    public function testLoadAllDiscoversComposerModule(): void {
        $this->createLocalModule('local-gamma');
        $this->createComposerModule('composer-delta');
        $this->writeInstalledJson([
            ['name' => 'vateron/composer-delta', 'type' => 'xcvm-module', 'install-path' => '../vateron/composer-delta'],
        ]);

        $loader = new ModuleLoader();
        $loader->loadAll($this->root . '/modules');

        $this->assertTrue($loader->isLoaded('local-gamma'),    'local module must be loaded');
        $this->assertTrue($loader->isLoaded('composer-delta'), 'Composer module must be loaded');
        $this->assertCount(2, $loader->getModules());
    }

    public function testLoadAllDeduplicatesIfManifestAlreadyInLocalDir(): void {
        // Even if someone manually symlinks a Composer package into modules/, it should not load twice.
        $this->createComposerModule('dedup-epsilon');
        $manifestPath = $this->root . '/vendor/vateron/dedup-epsilon/module.json';

        // Also put the same path into the modules/ scan by symlinking (simulate duplicate)
        $localLink = $this->root . '/modules/dedup-epsilon';
        @mkdir($localLink, 0775, true);
        copy($manifestPath, $localLink . '/module.json');

        $this->writeInstalledJson([
            ['name' => 'vateron/dedup-epsilon', 'type' => 'xcvm-module', 'install-path' => '../vateron/dedup-epsilon'],
        ]);

        // Both paths point to the same module name — loader should handle gracefully (isLoaded guard).
        $loader = new ModuleLoader();
        $loader->loadAll($this->root . '/modules');

        // The module should be loaded exactly once
        $this->assertTrue($loader->isLoaded('dedup-epsilon'));
        $this->assertCount(1, $loader->getModules());
    }

    public function testLoadAllSupportsComposer1xFlatInstalledJson(): void {
        $this->createComposerModule('legacy-zeta');

        // Composer 1.x uses a flat array (no 'packages' key)
        $installed = [
            ['name' => 'vateron/legacy-zeta', 'type' => 'xcvm-module', 'install-path' => '../vateron/legacy-zeta'],
        ];
        file_put_contents(
            $this->root . '/vendor/composer/installed.json',
            json_encode($installed)
        );

        $loader = new ModuleLoader();
        $loader->loadAll($this->root . '/modules');

        $this->assertTrue($loader->isLoaded('legacy-zeta'));
    }

    public function testLoadAllSupportsModulePathExtra(): void {
        // Package has module.json inside a subdirectory declared via extra.xcvm.module-path
        $pkgDir = $this->root . '/vendor/vateron/nested-eta';
        mkdir($pkgDir . '/src', 0775, true);

        $pascal = 'NestedEtaModule';
        $this->writeManifestAt($pkgDir . '/src/module.json', 'nested-eta');
        $this->writeModuleClass($pkgDir . '/src', 'nested-eta');

        $this->writeInstalledJson([
            [
                'name'         => 'vateron/nested-eta',
                'type'         => 'xcvm-module',
                'install-path' => '../vateron/nested-eta',
                'extra'        => ['xcvm' => ['module-path' => 'src']],
            ],
        ]);

        $loader = new ModuleLoader();
        $loader->loadAll($this->root . '/modules');

        $this->assertTrue($loader->isLoaded('nested-eta'));
    }

    public function testLoadAllIgnoresComposerModuleWithMissingManifest(): void {
        $this->createLocalModule('local-theta');

        // Package directory exists but has no module.json
        mkdir($this->root . '/vendor/vateron/no-manifest-iota', 0775, true);

        $this->writeInstalledJson([
            ['name' => 'vateron/no-manifest-iota', 'type' => 'xcvm-module', 'install-path' => '../vateron/no-manifest-iota'],
        ]);

        $loader = new ModuleLoader();
        $loader->loadAll($this->root . '/modules');

        $this->assertFalse($loader->isLoaded('no-manifest-iota'), 'package without module.json must be skipped');
        $this->assertCount(1, $loader->getModules());
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function pascal(string $name): string {
        return implode('', array_map('ucfirst', explode('-', $name)));
    }

    private function writeManifestAt(string $path, string $name): void {
        file_put_contents($path, json_encode([
            'name'          => $name,
            'description'   => 'composer discovery test',
            'version'       => '1.0.0',
            'requires_core' => '>=2.0',
            'environment'   => 'main',
            'dependencies'  => [],
            'has_navbar'    => false,
            'has_settings'  => false,
        ]));
    }

    private function writeModuleClass(string $dir, string $name): void {
        $pascal    = $this->pascal($name);
        $cls       = $pascal . 'Module';
        $namespace = 'XcVm\\Module\\' . $pascal;

        file_put_contents($dir . '/' . $cls . '.php', "<?php\n"
            . "namespace {$namespace};\n"
            . "use XcVm\Core\Module\BaseModule;\n"
            . "class {$cls} extends BaseModule {\n"
            . "\tpublic function getName(): string    { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '1.0.0'; }\n"
            . "}\n");
    }

    private function createLocalModule(string $name): void {
        $dir = $this->root . '/modules/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifestAt($dir . '/module.json', $name);
        $this->writeModuleClass($dir, $name);
    }

    private function createComposerModule(string $name): void {
        $dir = $this->root . '/vendor/vateron/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifestAt($dir . '/module.json', $name);
        $this->writeModuleClass($dir, $name);
    }

    /**
     * Write vendor/composer/installed.json in Composer 2.x format.
     *
     * @param array[] $packages
     */
    private function writeInstalledJson(array $packages): void {
        file_put_contents(
            $this->root . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages, 'dev-package-names' => []])
        );
    }

    private function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
