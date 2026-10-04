<?php

use XcVm\Core\Module\ModuleLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ModuleLoader::coreRequirementError(): which `requires_core` values let a
 * given core run a module, and that an unreadable one rules it out.
 */
final class ModuleCoreRequirementTest extends TestCase {

	/** @return array<string, array{string, string, bool}> requires_core, core version, compatible */
	public static function constraints(): array {
		return [
			'minimum met'              => ['>=2.0', '2.6.0', true],
			'minimum not met'          => ['>=2.7.0', '2.6.0', false],
			'range, inside'            => ['>=2.6 <3.0', '2.6.0', true],
			'range, comma separated'   => ['>=2.6,<3.0', '3.0.0', false],
			'exact, single equals'     => ['=2.6.0', '2.6.0', true],
			'exact, double equals'     => ['==2.6.0', '2.6.1', false],
			'not equal'                => ['!=2.6.0', '2.6.0', false],
			'bare version means >='    => ['2.6.1', '2.6.0', false],
			'empty means any core'     => ['', '1.0.0', true],
			'caret is unreadable'      => ['^2.6', '2.6.0', false],
			'nightly counts as release' => ['>=2.6.1', '2.6.1-dev.3', true],
			'nightly is not the next'  => ['>=2.6.2', '2.6.1-dev.3', false],
		];
	}

	#[DataProvider('constraints')]
	public function testConstraint(string $rRequires, string $rCore, bool $rCompatible): void {
		$this->assertSame($rCompatible, ModuleLoader::coreRequirementError($rRequires, $rCore) === null);
	}

	public function testTheReasonNamesTheConstraintAndTheCore(): void {
		$this->assertSame('needs core >=2.7.0; this panel runs 2.6.0', ModuleLoader::coreRequirementError('>=2.7.0', '2.6.0'));
		$this->assertSame("has an unreadable requires_core '~2.6'", ModuleLoader::coreRequirementError('~2.6', '2.6.0'));
	}

	public function testDefaultsToTheRunningCore(): void {
		class_exists(\XcVm\Core\Config\ConstantsInitializer::class);
		$this->assertNull(ModuleLoader::coreRequirementError('>=' . preg_replace('/-dev\.\d+$/', '', XC_VM_VERSION)));
		$this->assertNotNull(ModuleLoader::coreRequirementError('>=99.0'));
	}
}
