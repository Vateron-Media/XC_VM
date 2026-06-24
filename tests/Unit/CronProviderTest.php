<?php

use PHPUnit\Framework\TestCase;

/**
 * CronProviderInterface — ModuleLoader integration tests.
 *
 * Verifies that ModuleLoader::collectCronEntries() correctly aggregates
 * cron schedule entries from all loaded CronProviderInterface modules and
 * that BaseModule::getCronEntries() defaults to an empty array.
 */
final class CronProviderTest extends TestCase {

    // ── BaseModule default ────────────────────────────────────────

    public function testBaseModuleGetCronEntriesReturnsEmptyByDefault(): void {
        $module = new class extends BaseModule {
            public function getName(): string    { return 'cron-default'; }
            public function getVersion(): string { return '1.0.0'; }
        };

        $this->assertSame([], $module->getCronEntries());
    }

    public function testBaseModuleImplementsCronProviderInterface(): void {
        $module = new class extends BaseModule {
            public function getName(): string    { return 'cron-impl'; }
            public function getVersion(): string { return '1.0.0'; }
        };

        $this->assertInstanceOf(CronProviderInterface::class, $module);
    }

    // ── ModuleLoader::collectCronEntries() ────────────────────────

    public function testCollectCronEntriesSkipsModulesWithNoCronEntries(): void {
        $root = $this->createRoot();
        $this->createModuleWithCron($root, 'no-cron-alpha', []);

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $this->assertSame([], $loader->collectCronEntries());
    }

    public function testCollectCronEntriesReturnsSingleEntry(): void {
        $root = $this->createRoot();
        $this->createModuleWithCron($root, 'cron-beta', ['* * * * *' => 'cron:beta']);

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $entries = $loader->collectCronEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('cron:beta', $entries[0]);
        $this->assertStringContainsString('* * * * *', $entries[0]);
        $this->assertStringContainsString('# XC_VM', $entries[0]);
    }

    public function testCollectCronEntriesReturnsMultipleEntriesFromOneModule(): void {
        $root = $this->createRoot();
        $this->createModuleWithCron($root, 'cron-gamma', [
            '*/5 * * * *'  => 'cron:gamma-fast',
            '0 3 * * *'    => 'cron:gamma-nightly',
        ]);

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $entries = $loader->collectCronEntries();
        $this->assertCount(2, $entries);

        $combined = implode("\n", $entries);
        $this->assertStringContainsString('cron:gamma-fast', $combined);
        $this->assertStringContainsString('cron:gamma-nightly', $combined);
    }

    public function testCollectCronEntriesAggregatesAcrossModules(): void {
        $root = $this->createRoot();
        $this->createModuleWithCron($root, 'cron-delta', ['* * * * *' => 'cron:delta']);
        $this->createModuleWithCron($root, 'cron-epsilon', ['*/10 * * * *' => 'cron:epsilon']);

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $entries = $loader->collectCronEntries();
        $this->assertCount(2, $entries);

        $combined = implode("\n", $entries);
        $this->assertStringContainsString('cron:delta', $combined);
        $this->assertStringContainsString('cron:epsilon', $combined);
    }

    public function testCollectCronEntriesIgnoresModulesNotImplementingInterface(): void {
        $root = $this->createRoot();
        $this->createModuleWithoutCron($root, 'no-iface-zeta');

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $this->assertSame([], $loader->collectCronEntries());
    }

    public function testCollectCronEntriesLineFormat(): void {
        if (!defined('PHP_BIN'))   { define('PHP_BIN',   '/usr/bin/php'); }
        if (!defined('MAIN_HOME')) { define('MAIN_HOME', '/opt/xc_vm/'); }

        $root = $this->createRoot();
        $this->createModuleWithCron($root, 'cron-eta', ['* * * * *' => 'cron:eta']);

        $loader = new ModuleLoader();
        $loader->loadAll($root);

        $entries = $loader->collectCronEntries();
        $this->assertCount(1, $entries);
        $this->assertSame(
            '* * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:eta # XC_VM',
            $entries[0]
        );
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function createRoot(): string {
        $path = sys_get_temp_dir() . '/xc_vm_cron_' . bin2hex(random_bytes(6));
        mkdir($path, 0775, true);
        return $path;
    }

    private function pascalName(string $name): string {
        return implode('', array_map('ucfirst', explode('-', $name)));
    }

    private function namespace(string $name): string {
        return 'XcVm\\Module\\' . $this->pascalName($name);
    }

    private function writeManifest(string $root, string $name): void {
        $manifest = [
            'name'         => $name,
            'description'  => 'cron test module',
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

    /**
     * Creates a module that implements CronProviderInterface with the given entries.
     *
     * @param array<string, string> $cronEntries
     */
    private function createModuleWithCron(string $root, string $name, array $cronEntries): void {
        $dir = $root . '/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifest($root, $name);

        $pascal = $this->pascalName($name);
        $cls    = $pascal . 'Module';
        $ns     = $this->namespace($name);

        $entriesPhp = var_export($cronEntries, true);

        $code = "<?php\n"
            . "namespace {$ns};\n"
            . "use BaseModule;\n"
            . "use ServiceContainer;\n"
            . "class {$cls} extends BaseModule {\n"
            . "\tpublic function getName(): string    { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '1.0.0'; }\n"
            . "\tpublic function getCronEntries(): array { return {$entriesPhp}; }\n"
            . "}\n";

        file_put_contents($dir . '/' . $cls . '.php', $code);
    }

    /**
     * Creates a plain module that does NOT explicitly implement CronProviderInterface.
     * Uses ModuleInterface directly so we can test that the loader ignores it.
     */
    private function createModuleWithoutCron(string $root, string $name): void {
        $dir = $root . '/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifest($root, $name);

        $pascal = $this->pascalName($name);
        $cls    = $pascal . 'Module';
        $ns     = $this->namespace($name);

        $code = "<?php\n"
            . "namespace {$ns};\n"
            . "use ModuleInterface;\n"
            . "use ServiceContainer;\n"
            . "use XcVm\\Core\\Http\\Router;\n"
            . "use CommandRegistry;\n"
            . "use NavbarRegistry;\n"
            . "class {$cls} implements ModuleInterface {\n"
            . "\tpublic function getName(): string    { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '1.0.0'; }\n"
            . "\tpublic function boot(ServiceContainer \$c): void {}\n"
            . "\tpublic function getEventSubscribers(): array { return []; }\n"
            . "\tpublic function registerRoutes(\\XcVm\\Core\\Http\\Router \$r): void {}\n"
            . "\tpublic function registerCommands(CommandRegistry \$r): void {}\n"
            . "\tpublic function registerNavbar(NavbarRegistry \$registry): void {}\n"
            . "\tpublic function install(): void {}\n"
            . "\tpublic function uninstall(): void {}\n"
            . "}\n";

        file_put_contents($dir . '/' . $cls . '.php', $code);
    }
}
