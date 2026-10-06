<?php

use PHPUnit\Framework\TestCase;
use XcVm\Streaming\Auth\StreamAuthMiddleware;

/**
 * A viewer's token carries times in MAIN's clock, and a server whose clock is
 * off MAIN's (time_offset, node − MAIN) reads them through MAIN's clock as it
 * sees it. A node that ran 18 minutes ahead refused every viewer MAIN sent it
 * with TOKEN_EXPIRED: live.php, vod.php and timeshift.php took the offset off
 * the token's start instead of off their own clock (issue #273).
 */
final class StreamClockOffsetTest extends TestCase {
	private const NOW = 1800000000;

	/** @return array<int, array{time_offset: int}> this server's row, with its offset from MAIN */
	private static function servers(int $rOffset): array {
		return [SERVER_ID => ['time_offset' => $rOffset]];
	}

	public function testMainsClockIsThisServersLessItsOffset(): void {
		$this->assertSame(self::NOW, StreamAuthMiddleware::mainNow(self::servers(0), self::NOW));
		$this->assertSame(self::NOW, StreamAuthMiddleware::mainNow(self::servers(1079), self::NOW + 1079), 'a node 18 minutes ahead');
		$this->assertSame(self::NOW, StreamAuthMiddleware::mainNow(self::servers(-76), self::NOW - 76), 'a node behind');
	}

	/** The check every stream script makes of a token MAIN minted (activity_start + the seconds it is good for). */
	public function testATokenMintedOnMainIsGoodForItsSecondsOnAnyNode(): void {
		$rStart = self::NOW;
		foreach ([0, 1079, -1079] as $rOffset) {
			$rNodeNow = self::NOW + $rOffset;
			$this->assertTrue($rStart + 5 >= StreamAuthMiddleware::mainNow(self::servers($rOffset), $rNodeNow + 3), 'within 5 s, offset ' . $rOffset);
			$this->assertFalse($rStart + 5 >= StreamAuthMiddleware::mainNow(self::servers($rOffset), $rNodeNow + 6), 'past 5 s, offset ' . $rOffset);
		}
	}

	public function testEveryStreamScriptReadsTheTokensTimesInMainsClock(): void {
		foreach (['live', 'vod', 'timeshift', 'auth'] as $rScript) {
			$rSource = (string) file_get_contents(MAIN_HOME . 'Public/stream/' . $rScript . '.php');
			foreach (explode("\n", $rSource) as $i => $rLine) {
				// where the start is set or reckoned with (openRecord() takes the offset off its own clock)
				if (preg_match('/\$rActivityStart [=+]|\$rExpiresAt/', $rLine)) {
					$this->assertStringNotContainsString('time_offset', $rLine, $rScript . '.php:' . ($i + 1));
					$this->assertDoesNotMatchRegularExpression('/\btime\(\)/', $rLine, $rScript . '.php:' . ($i + 1) . ': this server\'s own clock');
				}
			}
		}
	}
}
