<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Bootstrap\BootKernel;
use XcVm\Core\Bootstrap\BootPipeline;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\StageProfiles;
use XcVm\Core\Bootstrap\Stage\AdminApiStage;
use XcVm\Core\Bootstrap\Stage\AttributionVerificationStage;
use XcVm\Core\Bootstrap\Stage\AdminGlobalsStage;
use XcVm\Core\Bootstrap\Stage\AdminShutdownStage;
use XcVm\Core\Bootstrap\Stage\ConfigStage;
use XcVm\Core\Bootstrap\Stage\ConstantsStage;
use XcVm\Core\Bootstrap\Stage\ContainerPopulateStage;
use XcVm\Core\Bootstrap\Stage\DatabaseStage;
use XcVm\Core\Bootstrap\Stage\FloodProtectionStage;
use XcVm\Core\Bootstrap\Stage\HealthCheckStage;
use XcVm\Core\Bootstrap\Stage\HostVerificationStage;
use XcVm\Core\Bootstrap\Stage\LegacyCoreStage;
use XcVm\Core\Bootstrap\Stage\ProcessTitleStage;
use XcVm\Core\Bootstrap\Stage\RedisStage;
use XcVm\Core\Bootstrap\Stage\SessionStage;
use XcVm\Core\Bootstrap\Stage\StatusConstantsStage;
use XcVm\Core\Bootstrap\Stage\TranslatorStage;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\BootContext;

/**
 * Pipeline mechanics and per-context profile composition. A full boot cannot run
 * in-process (ConfigStage calls the \XC_VM config extension, absent in tests), so
 * this covers ordering and composition with fakes/pure inspection.
 */
final class BootPipelineTest extends TestCase {
	protected function tearDown(): void {
		ServiceContainer::resetInstance();
	}

	private function state(BootContext $ctx = BootContext::Minimal): BootState {
		return new BootState($ctx, [], ServiceContainer::getInstance());
	}

	private function recordingStage(string $name, ArrayObject $log): BootStageInterface {
		return new class ($name, $log) implements BootStageInterface {
			public function __construct(private string $name, private ArrayObject $log) {
			}

			public function run(BootState $state): void {
				$this->log->append($this->name);
			}
		};
	}

	public function testPipelineRunsStagesInOrder(): void {
		$log = new ArrayObject();

		(new BootPipeline([
			$this->recordingStage('a', $log),
			$this->recordingStage('b', $log),
			$this->recordingStage('c', $log),
		]))->run($this->state());

		$this->assertSame(['a', 'b', 'c'], $log->getArrayCopy());
	}

	public function testPipelineAbortsOnThrow(): void {
		$log = new ArrayObject();
		$boom = new class implements BootStageInterface {
			public function run(BootState $state): void {
				throw new RuntimeException('boom');
			}
		};

		$this->expectException(RuntimeException::class);
		try {
			(new BootPipeline([$boom, $this->recordingStage('after', $log)]))->run($this->state());
		} finally {
			$this->assertNotContains('after', $log->getArrayCopy(), 'stage after a throwing stage must not run');
		}
	}

	/**
	 * @param array<string,mixed> $options
	 * @return list<class-string>
	 */
	private function classesFor(BootContext $ctx, array $options = []): array {
		$opts = array_merge(BootKernel::defaults($ctx), $options);
		return array_map('get_class', StageProfiles::for($ctx, $opts));
	}

	public function testMinimalProfile(): void {
		$this->assertSame([
			ConstantsStage::class,
			StatusConstantsStage::class,
			ConfigStage::class,
			FloodProtectionStage::class,
			HostVerificationStage::class,
			ContainerPopulateStage::class,
		], $this->classesFor(BootContext::Minimal));
	}

	public function testCliProfileDefaultsOmitRedisAndProcessTitle(): void {
		$this->assertSame([
			ConstantsStage::class,
			StatusConstantsStage::class,
			ConfigStage::class,
			FloodProtectionStage::class,
			HostVerificationStage::class,
			DatabaseStage::class,
			LegacyCoreStage::class,
			ContainerPopulateStage::class,
			HealthCheckStage::class,
		], $this->classesFor(BootContext::Cli));
	}

	public function testCliProfileAddsRedisAndProcessTitleWhenRequested(): void {
		$classes = $this->classesFor(BootContext::Cli, ['redis' => true, 'process' => 'worker']);

		$this->assertContains(RedisStage::class, $classes);
		$this->assertContains(ProcessTitleStage::class, $classes);
	}

	public function testStreamProfileIsDatabaseOnly(): void {
		$this->assertSame([
			ConstantsStage::class,
			StatusConstantsStage::class,
			ConfigStage::class,
			FloodProtectionStage::class,
			HostVerificationStage::class,
			DatabaseStage::class,
			ContainerPopulateStage::class,
			HealthCheckStage::class,
		], $this->classesFor(BootContext::Stream));
	}

	public function testAdminProfileFullSequence(): void {
		$this->assertSame([
			ConstantsStage::class,
			StatusConstantsStage::class,
			ConfigStage::class,
			FloodProtectionStage::class,
			HostVerificationStage::class,
			AttributionVerificationStage::class,
			SessionStage::class,
			DatabaseStage::class,
			LegacyCoreStage::class,
			RedisStage::class,
			AdminApiStage::class,
			TranslatorStage::class,
			AdminShutdownStage::class,
			AdminGlobalsStage::class,
			ContainerPopulateStage::class,
			HealthCheckStage::class,
		], $this->classesFor(BootContext::Admin));
	}

	public function testMinimalHasNoHealthCheck(): void {
		$this->assertNotContains(HealthCheckStage::class, $this->classesFor(BootContext::Minimal));
	}

	/** WebApi has no kernel profile — it boots via WebApiBootstrap, so misuse must fail loudly. */
	public function testWebApiContextHasNoKernelProfile(): void {
		$this->expectException(\LogicException::class);
		StageProfiles::for(BootContext::WebApi, []);
	}

	public function testDefaultsPerContext(): void {
		$this->assertTrue(BootKernel::defaults(BootContext::Admin)['redis']);
		$this->assertFalse(BootKernel::defaults(BootContext::Cli)['redis']);
		$this->assertTrue(BootKernel::defaults(BootContext::Stream)['cached']);
		$this->assertFalse(BootKernel::defaults(BootContext::Admin)['cached']);
	}
}
