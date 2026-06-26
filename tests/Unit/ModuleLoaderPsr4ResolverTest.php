<?php

use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * ModuleLoader — PSR-4 module autoloader tests (phase 2).
 *
 * Verifies that registerModuleAutoloader() resolves a module's classes by
 * mapping the namespace remainder onto a sub-path under the module directory
 * (true PSR-4), instead of the previous lossy short-name + glob lookup:
 *
 *   XcVm\Module\{Name}\Service\Foo → {modulePath}/Service/Foo.php
 *
 * The key regression guard is that two sub-classes sharing a short name but
 * living in different sub-namespaces resolve to their OWN files — the old glob
 * (modulePath/{*}/Foo.php) returned whichever matched first and silently loaded
 * the wrong one.
 */
final class ModuleLoaderPsr4ResolverTest extends TestCase {

    private string $root;

    protected function setUp(): void {
        ServiceContainer::resetInstance();
        Router::resetInstance();
        NavbarRegistry::reset();
        EventDispatcher::resetInstance();

        $this->root = sys_get_temp_dir() . '/xc_vm_psr4test_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/modules', 0775, true);
    }

    protected function tearDown(): void {
        $this->removeTree($this->root);
        ServiceContainer::resetInstance();
        Router::resetInstance();
        NavbarRegistry::reset();
        EventDispatcher::resetInstance();
    }

    public function testSubNamespaceClassResolvesViaPsr4(): void {
        // Module 'psr4alpha' with a sub-namespaced class at Service/AlphaService.php.
        $this->createModule('psr4alpha');
        $this->writeSubClass('psr4alpha', 'Service', 'AlphaService', 'alpha-service');

        $loader = new ModuleLoader();
        $loader->loadAll($this->root . '/modules');
        $this->assertTrue($loader->isLoaded('psr4alpha'));

        $fqcn = 'XcVm\\Module\\Psr4alpha\\Service\\AlphaService';
        // Autoload ON: proves the module's PSR-4 autoloader maps the FQCN onto
        // {modulePath}/Service/AlphaService.php.
        $this->assertTrue(class_exists($fqcn), 'Sub-namespace class must resolve via PSR-4');
        $this->assertSame('alpha-service', constant($fqcn . '::ORIGIN'));
    }

    public function testSameShortNameSubClassesDoNotCollide(): void {
        // One module, two classes both named "Widget" in different sub-namespaces.
        // The old short-name + glob would load only one file for both FQCNs.
        $this->createModule('psr4delta');
        $this->writeSubClass('psr4delta', 'Service', 'Widget', 'from-service');
        $this->writeSubClass('psr4delta', 'Repository', 'Widget', 'from-repository');

        $loader = new ModuleLoader();
        $loader->loadAll($this->root . '/modules');
        $this->assertTrue($loader->isLoaded('psr4delta'));

        $service    = 'XcVm\\Module\\Psr4delta\\Service\\Widget';
        $repository = 'XcVm\\Module\\Psr4delta\\Repository\\Widget';
        $this->assertTrue(class_exists($service), 'Service\\Widget must resolve');
        $this->assertTrue(class_exists($repository), 'Repository\\Widget must resolve');
        $this->assertSame('from-service', constant($service . '::ORIGIN'));
        $this->assertSame('from-repository', constant($repository . '::ORIGIN'));
    }

    public function testForeignNamespaceFallsThrough(): void {
        // A class outside the module namespace must NOT be claimed by the module
        // autoloader (it returns without requiring anything → no fatal).
        $this->createModule('psr4omega');
        $loader = new ModuleLoader();
        $loader->loadAll($this->root . '/modules');

        $this->assertFalse(
            class_exists('XcVm\\Module\\SomethingElse\\Nope', true),
            'Foreign namespace must fall through, not be force-resolved'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function createModule(string $name): void {
        $dir = $this->root . '/modules/' . $name;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', json_encode([
            'name'          => $name,
            'description'   => 'psr4 resolver test',
            'version'       => '1.0.0',
            'requires_core' => '>=2.0',
            'environment'   => 'main',
            'dependencies'  => [],
            'has_navbar'    => false,
            'has_settings'  => false,
        ]));

        $pascal    = ucfirst($name);
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

    private function writeSubClass(string $name, string $subDir, string $class, string $origin): void {
        $pascal    = ucfirst($name);
        $namespace = 'XcVm\\Module\\' . $pascal . '\\' . $subDir;
        $dir       = $this->root . '/modules/' . $name . '/' . $subDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $class . '.php', "<?php\n"
            . "namespace {$namespace};\n"
            . "class {$class} {\n"
            . "\tpublic const ORIGIN = '{$origin}';\n"
            . "}\n");
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
