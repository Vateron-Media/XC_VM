<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\DaemonTrait;

/**
 * The signals daemon re-read `servers` (a SELECT * against MAIN) on every
 * pass of its loop once it had run a settings check — four times a second on
 * every node. Persistent loops now reload the servers list on a five-second
 * timer shared through DaemonTrait.
 */
final class SignalsLoopQueryRateTest extends TestCase {

	private function daemon(): object {
		return new class {
			use DaemonTrait;

			public function due(?int $rNow): bool {
				return $this->serversRefreshDue($rNow);
			}

			public function markRefreshed(int $rAt): void {
				$this->rServersRefreshedAt = $rAt;
			}
		};
	}

	public function testReloadAtMostEveryFiveSeconds(): void {
		$rDaemon = $this->daemon();
		$this->assertTrue($rDaemon->due(1000), 'first pass loads');
		$rDaemon->markRefreshed(1000);
		$this->assertFalse($rDaemon->due(1000));
		$this->assertFalse($rDaemon->due(1004));
		$this->assertTrue($rDaemon->due(1005));
	}

	public function testAQuarterSecondLoopReadsServersTwelveTimesAMinuteNotTwoHundredForty(): void {
		$rDaemon = $this->daemon();
		$rReads = 0;
		for ($rTick = 0; $rTick < 240; $rTick++) { // one minute at usleep(250000)
			$rNow = 1000 + intdiv($rTick, 4);
			if ($rDaemon->due($rNow)) {
				$rReads++;
				$rDaemon->markRefreshed($rNow);
			}
		}
		$this->assertSame(12, $rReads);
	}

	/** @dataProvider daemons */
	public function testDaemonsUseTheTimer(string $rFile): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/' . $rFile);
		$this->assertStringContainsString('$this->serversRefreshDue()', $rSource);
		$this->assertStringNotContainsString('ServerRepository::getAll(true)', $rSource);
	}

	/** @return array<string, array{string}> */
	public static function daemons(): array {
		return ['signals' => ['SignalsCommand.php'], 'watchdog' => ['WatchdogCommand.php'], 'cache handler' => ['CacheHandlerCommand.php']];
	}
}
