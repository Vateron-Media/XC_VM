<?php

use PHPUnit\Framework\TestCase;

final class ModuleStateEnumTest extends TestCase {

    // ── Basic enum structure ──────────────────────────────────────

    public function testAllFourCasesExist(): void {
        $this->assertSame('enabled',    ModuleState::Enabled->value);
        $this->assertSame('disabled',   ModuleState::Disabled->value);
        $this->assertSame('installing', ModuleState::Installing->value);
        $this->assertSame('failed',     ModuleState::Failed->value);
    }

    // ── isLoadable() ─────────────────────────────────────────────

    public function testOnlyEnabledIsLoadable(): void {
        $this->assertTrue(ModuleState::Enabled->isLoadable());
        $this->assertFalse(ModuleState::Disabled->isLoadable());
        $this->assertFalse(ModuleState::Installing->isLoadable());
        $this->assertFalse(ModuleState::Failed->isLoadable());
    }

    // ── fromRaw() backward-compat ─────────────────────────────────

    public function testFromRawTrueReturnsEnabled(): void {
        $this->assertSame(ModuleState::Enabled, ModuleState::fromRaw(true));
    }

    public function testFromRawNullReturnsEnabled(): void {
        $this->assertSame(ModuleState::Enabled, ModuleState::fromRaw(null));
    }

    public function testFromRawFalseReturnsDisabled(): void {
        $this->assertSame(ModuleState::Disabled, ModuleState::fromRaw(false));
    }

    public function testFromRawStringEnabled(): void {
        $this->assertSame(ModuleState::Enabled, ModuleState::fromRaw('enabled'));
    }

    public function testFromRawStringDisabled(): void {
        $this->assertSame(ModuleState::Disabled, ModuleState::fromRaw('disabled'));
    }

    public function testFromRawStringInstalling(): void {
        $this->assertSame(ModuleState::Installing, ModuleState::fromRaw('installing'));
    }

    public function testFromRawStringFailed(): void {
        $this->assertSame(ModuleState::Failed, ModuleState::fromRaw('failed'));
    }

    public function testFromRawUnknownStringFallsBackToEnabled(): void {
        $this->assertSame(ModuleState::Enabled, ModuleState::fromRaw('unknown_garbage'));
    }

    // ── ModuleManager integration ─────────────────────────────────

    public function testSetStateWritesAndReadsBack(): void {
        $workDir       = sys_get_temp_dir() . '/xc_vm_state_' . bin2hex(random_bytes(6));
        $modulesPath   = $workDir . '/modules';
        $overridesPath = $workDir . '/modules.php';
        mkdir($modulesPath, 0775, true);
        file_put_contents($overridesPath, "<?php\nreturn [];\n");

        $manager = new ModuleManager($modulesPath, $overridesPath);

        $manager->setState('test-module', ModuleState::Disabled);
        $overrides = require $overridesPath;
        $this->assertSame('disabled', $overrides['test-module']['state']);
        $this->assertArrayNotHasKey('enabled', $overrides['test-module'] ?? []);

        $manager->setState('test-module', ModuleState::Enabled);
        // opcache/require cache: reload from disk
        $content = file_get_contents($overridesPath);
        $overrides = eval('?>' . $content);
        $overrides = require $overridesPath;
        $this->assertArrayNotHasKey('test-module', $overrides);

        $manager->setState('test-module', ModuleState::Installing);
        $overrides = require $overridesPath;
        $this->assertSame('installing', $overrides['test-module']['state']);

        $manager->setState('test-module', ModuleState::Failed);
        $overrides = require $overridesPath;
        $this->assertSame('failed', $overrides['test-module']['state']);

        unlink($overridesPath);
        rmdir($modulesPath);
        rmdir($workDir);
    }

    public function testSetEnabledShimBackwardCompat(): void {
        $workDir       = sys_get_temp_dir() . '/xc_vm_shim_' . bin2hex(random_bytes(6));
        $modulesPath   = $workDir . '/modules';
        $overridesPath = $workDir . '/modules.php';
        mkdir($modulesPath, 0775, true);
        file_put_contents($overridesPath, "<?php\nreturn [];\n");

        $manager = new ModuleManager($modulesPath, $overridesPath);

        $manager->setEnabled('my-module', false);
        $overrides = require $overridesPath;
        $this->assertSame('disabled', $overrides['my-module']['state']);

        $manager->setEnabled('my-module', true);
        $overrides = require $overridesPath;
        $this->assertArrayNotHasKey('my-module', $overrides);

        unlink($overridesPath);
        rmdir($modulesPath);
        rmdir($workDir);
    }

    public function testLegacyBoolInOverridesReadCorrectly(): void {
        $workDir       = sys_get_temp_dir() . '/xc_vm_legacy_' . bin2hex(random_bytes(6));
        $modulesPath   = $workDir . '/modules';
        $overridesPath = $workDir . '/modules.php';
        mkdir($modulesPath, 0775, true);
        file_put_contents($overridesPath, "<?php\nreturn ['my-module' => ['enabled' => false]];\n");

        $manager = new ModuleManager($modulesPath, $overridesPath);

        mkdir($modulesPath . '/my-module', 0775, true);
        file_put_contents($modulesPath . '/my-module/module.json', json_encode([
            'name'          => 'my-module',
            'version'       => '1.0.0',
            'description'   => 'Test',
            'requires_core' => '1.0.0',
            'environment'   => 'any',
            'dependencies'  => [],
        ]));

        $modules = $manager->listModules();
        $this->assertCount(1, $modules);
        $this->assertFalse($modules[0]['enabled']);
        $this->assertSame(ModuleState::Disabled, $modules[0]['state']);

        unlink($modulesPath . '/my-module/module.json');
        rmdir($modulesPath . '/my-module');
        unlink($overridesPath);
        rmdir($modulesPath);
        rmdir($workDir);
    }
}
