<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Bootstrap\Stage\StatusConstantsStage;
use XcVm\Core\Bootstrap\StageProfiles;
use XcVm\Core\Enum\BootContext;

/**
 * The STATUS_* constants the Domain services return (StreamService::process(),
 * MovieService::process(), …) must be defined in every boot context, not only
 * Admin. They used to be Admin-only, so a CLI/Stream path that reached such a
 * service fatally hit an undefined STATUS_SUCCESS — e.g. a console runner that
 * called StreamService::process() crashed with
 * "Undefined constant XcVm\Domain\Stream\STATUS_SUCCESS".
 */
final class StageProfilesStatusConstantsTest extends TestCase {

	/** @return list<class-string> */
	private function stageClasses(BootContext $rContext): array {
		return array_map('get_class', StageProfiles::for($rContext, ['replica' => null]));
	}

	public function testStatusConstantsStageRunsInCliContext(): void {
		$this->assertContains(StatusConstantsStage::class, $this->stageClasses(BootContext::Cli));
	}

	public function testStatusConstantsStageRunsInMinimalContext(): void {
		$this->assertContains(StatusConstantsStage::class, $this->stageClasses(BootContext::Minimal));
	}

	public function testStatusConstantsStageRunsInStreamContext(): void {
		$this->assertContains(StatusConstantsStage::class, $this->stageClasses(BootContext::Stream));
	}

	public function testStatusConstantsStageIsNotDuplicated(): void {
		foreach ([BootContext::Cli, BootContext::Minimal, BootContext::Stream, BootContext::Admin] as $rContext) {
			$rCount = count(array_filter($this->stageClasses($rContext), static fn($rClass) => $rClass === StatusConstantsStage::class));
			$this->assertSame(1, $rCount, $rContext->value . ' must define the status constants exactly once');
		}
	}
}
