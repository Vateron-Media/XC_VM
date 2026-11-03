<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Streaming\Delivery\TsProducerRecovery;

final class TsProducerRecoveryTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/xcvm-ts-recovery-' . bin2hex(random_bytes(5));
		mkdir($this->directory);
	}

	protected function tearDown(): void {
		foreach (glob($this->directory . '/*') ?: [] as $path) {
			unlink($path);
		}
		rmdir($this->directory);
	}

	private function replacement(int $pid = 200): void {
		file_put_contents($this->directory . '/42_.pid', (string) $pid);
		touch($this->directory . '/42_.pid', time() - 2);
		file_put_contents($this->directory . '/42_.m3u8', "#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:3\n#EXTINF:10,\n42_3.ts\n#EXTINF:10,\n42_4.ts\n");
		file_put_contents($this->directory . '/42_4.ts', 'new live segment');
	}

	public function testReplacementStartsAtLatestCompleteSegmentWithoutReplayingItsBacklog(): void {
		$this->replacement();
		$this->assertSame(['pid' => 200, 'current' => 3], TsProducerRecovery::wait($this->directory, 42, 100, 0, static fn(): bool => true));
	}

	public function testSameProducerIsNotAReplacement(): void {
		$this->replacement(100);
		$this->assertNull(TsProducerRecovery::wait($this->directory, 42, 100, 0, static fn(): bool => true));
	}

	public function testOldPlaylistOrAmbiguousSameSecondPlaylistIsNotReady(): void {
		$this->replacement();
		touch($this->directory . '/42_.m3u8', time() - 2);
		$this->assertNull(TsProducerRecovery::wait($this->directory, 42, 100, 0, static fn(): bool => true));
		touch($this->directory . '/42_.m3u8', time() - 5);
		$this->assertNull(TsProducerRecovery::wait($this->directory, 42, 100, 0, static fn(): bool => true));
	}

	public function testFreshPlaylistCannotMakeAnOldSegmentReady(): void {
		$this->replacement();
		touch($this->directory . '/42_4.ts', time() - 5);
		$this->assertNull(TsProducerRecovery::wait($this->directory, 42, 100, 0, static fn(): bool => true));
	}

	public function testMissingOrEmptySegmentIsNotReady(): void {
		$this->replacement();
		unlink($this->directory . '/42_4.ts');
		$this->assertNull(TsProducerRecovery::wait($this->directory, 42, 100, 0, static fn(): bool => true));
		file_put_contents($this->directory . '/42_4.ts', '');
		$this->assertNull(TsProducerRecovery::wait($this->directory, 42, 100, 0, static fn(): bool => true));
	}

	public function testSecondRestartWhileReadingSnapshotIsRejected(): void {
		$this->replacement();
		$calls = 0;
		$this->assertNull(TsProducerRecovery::wait($this->directory, 42, 100, 0, function () use (&$calls): bool {
			return ++$calls === 1;
		}));
	}

	public function testPidMarkerReplacementDuringSnapshotIsRejected(): void {
		$this->replacement();
		$this->assertNull(TsProducerRecovery::wait($this->directory, 42, 100, 0, function (): bool {
			file_put_contents($this->directory . '/42_.pid', '201');
			return true;
		}));
	}

	public function testDeadProducerDoesNotSpendTheWholeMissingSegmentBudget(): void {
		$start = hrtime(true);
		$this->assertFalse(TsProducerRecovery::awaitSegment($this->directory . '/42_999.ts', 42, 100, 20, static fn(): bool => false));
		$this->assertLessThan(1, (hrtime(true) - $start) / 1e9);
	}

	public function testADeadProducerCannotMakeAnExistingStaleSegmentReady(): void {
		$this->replacement();
		$this->assertFalse(TsProducerRecovery::awaitSegment($this->directory . '/42_4.ts', 42, 100, 0, static fn(): bool => false));
		$this->assertTrue(TsProducerRecovery::awaitSegment($this->directory . '/42_4.ts', 42, 200, 0, static fn(): bool => true));
	}

	public function testNoReplacementReturnsWithinItsBudget(): void {
		$start = hrtime(true);
		$this->assertNull(TsProducerRecovery::wait($this->directory, 42, 100, 0.15, static fn(): bool => true));
		$this->assertLessThan(1, (hrtime(true) - $start) / 1e9);
	}
}
