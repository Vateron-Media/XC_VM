<?php

use XcVm\Core\Http\Router;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Core\Module\ModuleInterface;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Events\EventDispatcher;
use PHPUnit\Framework\TestCase;

// Shared tracker — loaded by PHPUnit before any module PHP file is require_once'd by ModuleLoader.
// Generated module classes reference this class directly in their method bodies.
class TestBootCallTracker {
    public static array $calls = [];

    public static function record(string $event): void {
        self::$calls[] = $event;
    }

    public static function reset(): void {
        self::$calls = [];
    }

    public static function has(string $event): bool {
        return in_array($event, self::$calls, true);
    }
}

final class ModuleLoaderBootTest extends TestCase {

    private ServiceContainer $container;

    protected function setUp(): void {
        ServiceContainer::resetInstance();
        Router::resetInstance();
        NavbarRegistry::reset();
        EventDispatcher::resetInstance();
        TestBootCallTracker::reset();

        $this->container = ServiceContainer::getInstance();

        // Store a fresh EventDispatcher instance in the container and wire
        // it as the static singleton so both DI callers and static callers
        // share the same listener store within this test.
        $dispatcher = new EventDispatcher();
        EventDispatcher::setInstance($dispatcher);
        $this->container->set('events', $dispatcher);
    }

    protected function tearDown(): void {
        ServiceContainer::resetInstance();
        Router::resetInstance();
        NavbarRegistry::reset();
        EventDispatcher::resetInstance();
        TestBootCallTracker::reset();
    }

    // ── bootAll() lifecycle ───────────────────────────────────────────────

    public function testBootAllCallsBootOnModule(): void {
        $root = $this->createRoot();
        $this->createModule($root, 'boot-alpha', boot: "\\TestBootCallTracker::record('boot:boot-alpha');");

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll($this->container);

        $this->assertTrue(TestBootCallTracker::has('boot:boot-alpha'));
    }

    public function testBootAllCallsRegisterNavbarOnModule(): void {
        $root = $this->createRoot();
        $this->createModule($root, 'navbar-beta', navbar: "\\TestBootCallTracker::record('navbar:navbar-beta');");

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll($this->container);

        $this->assertTrue(TestBootCallTracker::has('navbar:navbar-beta'));
    }

    public function testBootAllCallsRegisterRoutesWhenRouterProvided(): void {
        $root = $this->createRoot();
        $this->createModule($root, 'routes-gamma', routes: "\\TestBootCallTracker::record('routes:routes-gamma');");

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll($this->container, Router::getInstance());

        $this->assertTrue(TestBootCallTracker::has('routes:routes-gamma'));
    }

    public function testBootAllSkipsRoutesWhenRouterIsNull(): void {
        $root = $this->createRoot();
        $this->createModule($root, 'noroutes-delta', routes: "\\TestBootCallTracker::record('routes:noroutes-delta');");

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll($this->container, null);

        $this->assertFalse(TestBootCallTracker::has('routes:noroutes-delta'));
    }

    public function testBootAllRegistersEventSubscribers(): void {
        $root = $this->createRoot();
        $this->createModule($root, 'events-epsilon', subscribers: "'test.event.epsilon' => function() {}");

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll($this->container);

        $this->assertTrue(EventDispatcher::hasListeners('test.event.epsilon'));
    }

    public function testBootAllWithMultipleModulesCallsAllBoots(): void {
        $root = $this->createRoot();
        $this->createModule($root, 'multi-one', boot: "\\TestBootCallTracker::record('boot:multi-one');");
        $this->createModule($root, 'multi-two', boot: "\\TestBootCallTracker::record('boot:multi-two');");

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll($this->container);

        $this->assertTrue(TestBootCallTracker::has('boot:multi-one'));
        $this->assertTrue(TestBootCallTracker::has('boot:multi-two'));
    }

    // ── BaseModule subclass integration ───────────────────────────────────

    public function testModuleExtendingBaseModuleBootsCorrectly(): void {
        $root = $this->createRoot();
        $this->createBaseModuleSubclass($root, 'base-eta');

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll($this->container);

        $this->assertTrue(TestBootCallTracker::has('boot:base-eta'));
    }

    // ── getModule() / getManifest() / isLoaded() ─────────────────────────

    public function testGetModuleReturnsInstanceAfterLoad(): void {
        $root = $this->createRoot();
        $this->createModule($root, 'get-zeta');

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $module = $loader->getModule('get-zeta');
        $this->assertInstanceOf(ModuleInterface::class, $module);
        $this->assertSame('get-zeta', $module->getName());
    }

    public function testGetModuleReturnsNullForUnknown(): void {
        $loader = new ModuleLoader();
        $loader->loadAll($this->createRoot());

        $this->assertNull($loader->getModule('no-such-module'));
    }

    public function testIsLoadedReturnsFalseForUnknown(): void {
        $loader = new ModuleLoader();
        $loader->loadAll($this->createRoot());

        $this->assertFalse($loader->isLoaded('no-such-module'));
    }

    public function testGetManifestReturnsModuleMetadata(): void {
        $root = $this->createRoot();
        $this->createModule($root, 'manifest-theta');

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $manifest = $loader->getManifest('manifest-theta');
        $this->assertIsArray($manifest);
        $this->assertSame('manifest-theta', $manifest['name']);
        $this->assertSame('1.0.0', $manifest['version']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function createRoot(): string {
        $path = sys_get_temp_dir() . '/xc_vm_boot_' . bin2hex(random_bytes(6));
        mkdir($path, 0775, true);
        return $path;
    }

    private function className(string $name): string {
        return implode('', array_map('ucfirst', explode('-', $name))) . 'Module';
    }

    private function writeManifest(string $root, string $name): void {
        $manifest = [
            'name'         => $name,
            'description'  => 'boot test module',
            'version'      => '1.0.0',
            'requires_core' => '>=2.0',
            'environment'  => 'main',
            'dependencies' => [],
            'has_navbar'   => false,
            'has_settings' => false,
        ];
        file_put_contents(
            $root . '/' . $name . '/module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function createModule(
        string $root,
        string $name,
        string $boot = '',
        string $navbar = '',
        string $routes = '',
        string $subscribers = ''
    ): void {
        $dir = $root . '/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifest($root, $name);

        $cls    = $this->className($name);
        $ns     = $this->namespace($name);
        $subscriberReturn = $subscribers !== '' ? "return [{$subscribers}];" : 'return [];';

        $code = "<?php\n"
            . "namespace {$ns};\n"
            . "use XcVm\Core\Module\ModuleInterface;\n"
            . "use XcVm\Core\Container\ServiceContainer;\n"
            . "use XcVm\\Core\\Http\\Router;\n"
            . "use XcVm\Cli\CommandRegistry;\n"
            . "use XcVm\Core\Module\NavbarRegistry;\n"
            . "class {$cls} implements ModuleInterface {\n"
            . "\tpublic function getName(): string { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '1.0.0'; }\n"
            . "\tpublic function boot(ServiceContainer \$container): void { {$boot} }\n"
            . "\tpublic function registerRoutes(\\XcVm\\Core\\Http\\Router \$router): void { {$routes} }\n"
            . "\tpublic function registerCommands(CommandRegistry \$registry): void {}\n"
            . "\tpublic function getEventSubscribers(): array { {$subscriberReturn} }\n"
            . "\tpublic function install(): void {}\n"
            . "\tpublic function uninstall(): void {}\n"
            . "\tpublic function registerNavbar(NavbarRegistry \$registry): void { {$navbar} }\n"
            . "}\n";

        file_put_contents($dir . '/' . $cls . '.php', $code);
    }

    private function createBaseModuleSubclass(string $root, string $name): void {
        $dir = $root . '/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifest($root, $name);

        $cls = $this->className($name);
        $ns  = $this->namespace($name);

        $code = "<?php\n"
            . "namespace {$ns};\n"
            . "use XcVm\Core\Module\BaseModule;\n"
            . "use XcVm\Core\Container\ServiceContainer;\n"
            . "class {$cls} extends BaseModule {\n"
            . "\tpublic function getName(): string { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '1.0.0'; }\n"
            . "\tpublic function boot(ServiceContainer \$container): void {\n"
            . "\t\t\\TestBootCallTracker::record('boot:{$name}');\n"
            . "\t}\n"
            . "}\n";

        file_put_contents($dir . '/' . $cls . '.php', $code);
    }

    private function namespace(string $name): string {
        return 'XcVm\\Module\\' . $this->pascalName($name);
    }

    private function pascalName(string $name): string {
        return implode('', array_map('ucfirst', explode('-', $name)));
    }
}
