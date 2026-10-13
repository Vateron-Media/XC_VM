<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Exception\Module\ModuleException;
use XcVm\Core\Module\ModuleManager;
use XcVm\Core\Module\ModuleQuarantine;

final class ModuleQuarantineTest extends TestCase {

    private string $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/xc_vm_quarantine_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    // ── recordFatal(): which errors quarantine which module ──────────────

    public function testIoncubeLicenceFatalNamedInTheMessageQuarantinesTheModule(): void {
        $this->module('billing_8efdd');

        $dir = ModuleQuarantine::recordFatal($this->root, [
            'type'    => E_CORE_ERROR,
            'message' => "<br>The encoded file <b>{$this->root}/billing_8efdd/BillingModule.php</b> requires a license file <b>module.lic</b>.",
            'file'    => 'Unknown',
            'line'    => 0,
        ]);

        $this->assertSame('billing_8efdd', $dir);
        $this->assertStringContainsString('requires a license file module.lic', (string) ModuleQuarantine::reason($this->root, 'billing_8efdd'));
    }

    public function testCompileErrorInAModuleFileQuarantinesIt(): void {
        $this->module('broken_aaaaa');

        $dir = ModuleQuarantine::recordFatal($this->root, [
            'type'    => E_COMPILE_ERROR,
            'message' => 'Cannot use the final modifier on an abstract class',
            'file'    => "{$this->root}/broken_aaaaa/Service/Thing.php",
            'line'    => 3,
        ]);

        $this->assertSame('broken_aaaaa', $dir);
    }

    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public static function notQuarantining(): array {
        return [
            'a warning'                 => [E_WARNING, 'Undefined variable $x', '/M/mod_aaaaa/A.php'],
            'an uncaught exception'     => [E_ERROR, 'Uncaught RuntimeException: db down', '/M/mod_aaaaa/A.php'],
            'memory exhausted'          => [E_ERROR, 'Allowed memory size of 134217728 bytes exhausted', '/M/mod_aaaaa/A.php'],
            'execution time exceeded'   => [E_ERROR, 'Maximum execution time of 30 seconds exceeded', '/M/mod_aaaaa/A.php'],
            'a fatal outside a module'  => [E_COMPILE_ERROR, 'Cannot redeclare foo()', '/home/xc_vm/Core/Util/Foo.php'],
            'a directory that is gone'  => [E_COMPILE_ERROR, 'Cannot redeclare foo()', '/M/gone_bbbbb/A.php'],
        ];
    }

    /** @dataProvider notQuarantining */
    public function testErrorsThatAreNotModuleDefectsQuarantineNothing(int $type, string $message, string $file): void {
        $this->module('mod_aaaaa');

        $dir = ModuleQuarantine::recordFatal($this->root, [
            'type'    => $type,
            'message' => $message,
            'file'    => str_replace('/M/', $this->root . '/', $file),
            'line'    => 1,
        ]);

        $this->assertNull($dir);
        $this->assertFileDoesNotExist($this->root . '/' . ModuleQuarantine::FILE);
    }

    // ── reason(): the record lifts itself when the files change ──────────

    public function testChangedFilesLiftTheQuarantine(): void {
        $this->module('billing_8efdd');
        ModuleQuarantine::record($this->root, 'billing_8efdd', 'requires a license file module.lic');
        $this->assertNotNull(ModuleQuarantine::reason($this->root, 'billing_8efdd'));

        // The licence arrives next to the encoded files.
        file_put_contents($this->root . '/billing_8efdd/module.lic', 'lic');

        $this->assertNull(ModuleQuarantine::reason($this->root, 'billing_8efdd'));
        $this->assertFileDoesNotExist($this->root . '/' . ModuleQuarantine::FILE, 'the last record removes the file');
    }

    public function testAPlantedSymlinkIsReplacedNeverWrittenThrough(): void {
        $this->module('billing_8efdd');
        $victim = $this->root . '/victim.txt';
        file_put_contents($victim, 'untouched');
        chmod($victim, 0600);
        symlink($victim, $this->root . '/' . ModuleQuarantine::FILE);

        ModuleQuarantine::record($this->root, 'billing_8efdd', 'requires a license file module.lic');

        $this->assertSame('untouched', file_get_contents($victim));
        $this->assertSame(0600, fileperms($victim) & 0777);
        $this->assertFalse(is_link($this->root . '/' . ModuleQuarantine::FILE));
        $this->assertSame(0644, fileperms($this->root . '/' . ModuleQuarantine::FILE) & 0777);
        $this->assertNotNull(ModuleQuarantine::reason($this->root, 'billing_8efdd'));
    }

    public function testAnUnrecordedModuleIsNotQuarantined(): void {
        $this->module('clean_ccccc');

        $this->assertNull(ModuleQuarantine::reason($this->root, 'clean_ccccc'));
    }

    // ── End to end: a real uncatchable fatal in a real process ───────────

    public function testAFatalModuleKillsOneProcessAndTheNextOneSkipsIt(): void {
        $this->loaderModule('healthy', 'class HealthyModule extends \\XcVm\\Core\\Module\\BaseModule { public function getName(): string { return \'healthy\'; } public function getVersion(): string { return \'1.0.0\'; } }');
        // A class missing an abstract method is a fatal at link time that
        // try/catch cannot stop, exactly like the ionCube licence fatal.
        $this->loaderModule('fatal', 'class FatalModule implements \\Countable {}');

        [$first, $out1] = $this->runLoader();
        $this->assertSame(255, $first, "the first process dies on the module's fatal: {$out1}");
        $this->assertNotNull(ModuleQuarantine::reason($this->root, 'fatal'));

        [$second, $out2] = $this->runLoader();
        $this->assertSame(0, $second, "the next process skips the quarantined module: {$out2}");
        $this->assertSame('loaded: healthy', trim($out2));

        // A fixed release replaces the files: the module loads again.
        $this->loaderModule('fatal', 'class FatalModule extends \\XcVm\\Core\\Module\\BaseModule { public function getName(): string { return \'fatal\'; } public function getVersion(): string { return \'1.0.0\'; } }');
        [$third, $out3] = $this->runLoader();
        $this->assertSame(0, $third, $out3);
        $this->assertSame('loaded: fatal,healthy', trim($out3));
    }

    // ── ModuleManager never runs a quarantined module's code ─────────────

    public function testUninstallingAQuarantinedModuleDropsItsSchemaWithoutLoadingIt(): void {
        $dir = $this->root . '/qbill_8efdd';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', json_encode(['name' => 'qbill', 'version' => '1.1.0', 'requires_core' => '>=2.0', 'environment' => 'main', 'dependencies' => []]));
        // Requiring this file is what killed the process; here it leaves a mark.
        file_put_contents($dir . '/QbillModule.php', "<?php\nfile_put_contents(__DIR__ . '/required', '1');\n");
        file_put_contents($dir . '/database_drop.sql', "DROP TABLE IF EXISTS `qbill_orders`;\n");
        ModuleQuarantine::record($this->root, 'qbill_8efdd', 'requires a license file module.lic');

        $db = new class {
            public array $statements = [];
            public function query(string $sql): bool { $this->statements[] = rtrim($sql, ';'); return true; }
        };
        $container = ServiceContainer::getInstance();
        $container->set('db', $db);
        try {
            $manager = new ModuleManager($this->root, $this->root . '/modules.php', $container);
            $manager->uninstallModule('qbill');

            $this->assertFileDoesNotExist($dir . '/required', 'the module code was never required');
            $this->assertSame(['DROP TABLE IF EXISTS `qbill_orders`'], $db->statements);

            $this->expectException(ModuleException::class);
            $this->expectExceptionMessage('quarantined');
            $manager->installModule('qbill');
        } finally {
            $container->remove('db');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function module(string $dir): void {
        mkdir($this->root . '/' . $dir, 0775, true);
        file_put_contents($this->root . '/' . $dir . '/module.json', '{}');
    }

    private function loaderModule(string $name, string $class): void {
        $dir = $this->root . '/' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/module.json', json_encode([
            'name' => $name, 'version' => '1.0.0', 'requires_core' => '>=2.0',
            'environment' => 'any', 'dependencies' => [],
        ]));
        $ns = 'XcVm\\Module\\' . ucfirst($name);
        file_put_contents($dir . '/' . ucfirst($name) . 'Module.php', "<?php\nnamespace {$ns};\n{$class}\n");
        clearstatcache();
    }

    /** @return array{0: int, 1: string} */
    private function runLoader(): array {
        $autoload = dirname(__DIR__, 2) . '/src/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . '$l = (new XcVm\Core\Module\ModuleLoader())->loadAll(' . var_export($this->root, true) . ');'
            . '$n = array_keys($l->getModules()); sort($n); echo "loaded: " . implode(",", $n);';
        $cmd = escapeshellarg(PHP_BINARY) . ' -d error_log=/dev/null -d display_errors=0 -r ' . escapeshellarg($code) . ' 2>&1';
        exec($cmd, $output, $status);
        return [$status, implode("\n", $output)];
    }
}
