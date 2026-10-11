<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * An on-demand stream stopped for having no viewers keeps its stream_info: an
 * adaptive master lists a variant by its resolution and bitrate, so an idle
 * variant that lost them could never be offered, nor started (issue #61). Any
 * other stop still clears it.
 */
final class IdleOnDemandStreamInfoTest extends TestCase {
	/** @var list<array<string, mixed>> */
	private array $rWrites = [];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);
		DatabaseFactory::set(new TestDb());
		StreamStateWriter::useSink(function (string $rWhere, array $rFields): bool {
			$this->rWrites[] = $rFields;
			return true;
		});
	}

	protected function tearDown(): void {
		StreamStateWriter::useSink(null);
		DatabaseFactory::reset();
	}

	/** @return array<string, mixed> the fields the stop writes */
	private function reset(bool $rKeepInfo): array {
		(new ReflectionMethod(StreamProcess::class, 'resetStreamServerRow'))->invoke(null, 42, true, $rKeepInfo);
		$this->assertCount(1, $this->rWrites);
		return $this->rWrites[0];
	}

	public function testAnIdleStopKeepsStreamInfo(): void {
		$rFields = $this->reset(true);
		$this->assertArrayNotHasKey('stream_info', $rFields);
		$this->assertArrayHasKey('pid', $rFields);
		$this->assertNull($rFields['pid']);
		$this->assertSame(0, $rFields['stream_status']);
	}

	public function testAnyOtherStopClearsStreamInfo(): void {
		$rFields = $this->reset(false);
		$this->assertArrayHasKey('stream_info', $rFields);
		$this->assertNull($rFields['stream_info']);
	}
}
