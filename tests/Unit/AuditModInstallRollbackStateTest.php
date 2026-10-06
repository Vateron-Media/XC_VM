<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\ModuleState;
use XcVm\Core\Module\ModuleManager;

/**
 * An update that fails puts the previous files back and leaves the module as
 * the administrator had it: a module that was switched off stays off.
 */
final class AuditModInstallRollbackStateTest extends TestCase {
	private string $rWork;
	private string $rModules;
	private string $rOverrides;

	protected function setUp(): void {
		$this->rWork      = sys_get_temp_dir() . '/xc_vm_rollstate_' . bin2hex(random_bytes(6));
		$this->rModules   = $this->rWork . '/modules';
		$this->rOverrides = $this->rWork . '/modules.php';
		mkdir($this->rModules, 0775, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rWork));
	}

	/** @return array<string, array{0: array<string, string|bool>, 1: string}> */
	public static function statesBeforeTheUpdate(): array {
		return [
			'a module that was switched off'             => [['state' => 'disabled'], 'disabled'],
			'a module switched off with the earlier key' => [['enabled' => false], 'disabled'],
			'a module whose install had not completed'   => [['state' => 'failed'], 'failed'],
			'a module that was on'                       => [[], 'enabled'],
			'a module switched on with the earlier key'  => [['enabled' => true], 'enabled'],
		];
	}

	#[DataProvider('statesBeforeTheUpdate')]
	public function testAStoreUpdateThatFailsLeavesTheStateAsItWas(array $rEntry, string $rExpected): void {
		if (class_exists('XC_VM', false)) {
			$this->markTestSkipped('With xcvm_core loaded the update would reach the platform.');
		}
		$rDir = $this->rModules . '/ars-store';
		mkdir($rDir, 0775, true);
		file_put_contents($rDir . '/module.json', json_encode(['name' => 'ars-store', 'version' => '1.0.0']));
		file_put_contents($this->rOverrides, "<?php\n\nreturn " . var_export(['ars-store' => $rEntry + ['installed_version' => '1.0.0']], true) . ";\n");

		// Without the extension the files cannot be pulled: the update fails
		// once the installed copy has been set aside.
		try {
			(new ModuleManager($this->rModules, $this->rOverrides, ServiceContainer::getInstance()))
				->downloadFromPlatform('ars-store', '', 'key');
			$this->fail('The update cannot succeed without the extension.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('rolled back', $e->getMessage());
		}

		$this->assertFileExists($rDir . '/module.json');
		// Read as the loader reads it: the state, else the earlier on/off key.
		$rAfter = (require $this->rOverrides)['ars-store'] ?? [];
		$this->assertSame($rExpected, ModuleState::fromRaw($rAfter['state'] ?? ($rAfter['enabled'] ?? null))->value);
	}

	public function testAGitOrUrlUpdateHandsTheStateItFoundToTheRollback(): void {
		// The fetch needs the network, so this path is read, not run.
		$rMethod = new ReflectionMethod(ModuleManager::class, 'updateModuleFromSource');
		$rBody   = implode('', array_slice(
			file($rMethod->getFileName()),
			$rMethod->getStartLine() - 1,
			$rMethod->getEndLine() - $rMethod->getStartLine() + 1
		));

		$this->assertMatchesRegularExpression(
			'/(\$\w+)\s*=\s*ModuleState::fromRaw\([^;]*\[\'state\'\][^;]*\[\'enabled\'\][^;]*\);.*\$this->backupModuleDir\(.*\$this->restoreModuleBackup\([^;]*,\s*\1\);/s',
			$rBody
		);
	}
}
