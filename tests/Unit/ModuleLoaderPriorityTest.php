<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for features added in roadmap TASK-010 (priority) and TASK-011 (optional_dependencies).
 */
final class ModuleLoaderPriorityTest extends TestCase {

    // ── Priority system (TASK-010) ─────────────────────────────

    public function testHigherPriorityModuleLoadsFirst(): void {
        $root = $this->createModulesRoot();
        $this->createModule($root, 'low-prio',  [], ['priority' => 10]);
        $this->createModule($root, 'high-prio', [], ['priority' => 50]);
        $this->createModule($root, 'mid-prio',  [], ['priority' => 30]);

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $this->assertSame(
            ['high-prio', 'mid-prio', 'low-prio'],
            array_keys($loader->getModules())
        );
    }

    public function testEqualPriorityModulesLoadAlphabetically(): void {
        $root = $this->createModulesRoot();
        $this->createModule($root, 'zebra',  [], ['priority' => 0]);
        $this->createModule($root, 'apple',  [], ['priority' => 0]);
        $this->createModule($root, 'mango',  [], ['priority' => 0]);

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $this->assertSame(
            ['apple', 'mango', 'zebra'],
            array_keys($loader->getModules())
        );
    }

    public function testDefaultPriorityIsZero(): void {
        $root = $this->createModulesRoot();
        // No priority key in manifest at all
        $this->createModule($root, 'no-priority');

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $this->assertTrue($loader->isLoaded('no-priority'));
        $this->assertSame(0, $loader->getManifest('no-priority')['priority']);
    }

    // ── Optional dependencies (TASK-011) ──────────────────────

    public function testMissingOptionalDependencyDoesNotThrow(): void {
        $root = $this->createModulesRoot();
        $this->createModule($root, 'main-module', ['optional_dependencies' => ['ghost-module']]);

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $this->assertTrue($loader->isLoaded('main-module'));
    }

    public function testPresentOptionalDependencyLoadsBeforeDependent(): void {
        $root = $this->createModulesRoot();
        $this->createModule($root, 'opt-dep');
        $this->createModule($root, 'consumer', ['optional_dependencies' => ['opt-dep']]);

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $keys = array_keys($loader->getModules());
        $this->assertLessThan(
            array_search('consumer', $keys),
            array_search('opt-dep', $keys),
            "'opt-dep' must be loaded before 'consumer'"
        );
    }

    public function testRequiredDependencyStillThrowsWhenMissing(): void {
        $root = $this->createModulesRoot();
        $this->createModule($root, 'needs-ghost', ['dependencies' => ['ghost-module']]);

        $loader = new ModuleLoader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires missing dependency/');
        $loader->loadAll($root);
    }

    // ── Helpers ───────────────────────────────────────────────

    private function createModulesRoot(): string {
        $path = sys_get_temp_dir() . '/xc_vm_priority_test_' . bin2hex(random_bytes(6));
        mkdir($path, 0775, true);
        return $path;
    }

    private function createModule(string $root, string $name, array $manifestOverrides = [], array $extraManifest = []): void {
        $modulePath = $root . '/' . $name;
        mkdir($modulePath, 0775, true);

        $pascal    = implode('', array_map('ucfirst', explode('-', $name)));
        $className = $pascal . 'Module';

        $manifest = array_merge([
            'name'                  => $name,
            'description'           => 'test module',
            'version'               => '1.0.0',
            'requires_core'         => '>=2.0',
            'environment'           => 'main',
            'dependencies'          => [],
            'optional_dependencies' => [],
            'has_navbar'            => false,
            'has_settings'          => false,
            'priority'              => 0,
        ], $manifestOverrides, $extraManifest);

        file_put_contents(
            $modulePath . '/module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $namespace = 'XcVm\\Module\\' . $pascal;

        $php = '<?php' . "\n"
            . 'namespace ' . $namespace . ';' . "\n"
            . 'use ModuleInterface;' . "\n"
            . 'use ServiceContainer;' . "\n"
            . 'use Router;' . "\n"
            . 'use CommandRegistry;' . "\n"
            . 'use NavbarRegistry;' . "\n"
            . 'class ' . $className . ' implements ModuleInterface {' . "\n"
            . "\tpublic function getName(): string { return '{$name}'; }" . "\n"
            . "\tpublic function getVersion(): string { return '1.0.0'; }" . "\n"
            . "\tpublic function boot(ServiceContainer \$container): void {}" . "\n"
            . "\tpublic function registerRoutes(Router \$router): void {}" . "\n"
            . "\tpublic function registerCommands(CommandRegistry \$registry): void {}" . "\n"
            . "\tpublic function getEventSubscribers(): array { return []; }" . "\n"
            . "\tpublic function install(): void {}" . "\n"
            . "\tpublic function uninstall(): void {}" . "\n"
            . "\tpublic function registerNavbar(NavbarRegistry \$registry): void {}" . "\n"
            . '}' . "\n";

        file_put_contents($modulePath . '/' . $className . '.php', $php);
    }
}
