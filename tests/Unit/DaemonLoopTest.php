<?php

use PHPUnit\Framework\TestCase;

/**
 * A daemon's loop must not end in an unconditional `break`.
 *
 * Four of them did (`queue`, `scanner`, `signals`, `watchdog`): the whole pass
 * sat in a `while` whose last statement was `break`, so every pass fell out of
 * the loop and `restartDaemon()` re-executed `console.php` — a fresh bootstrap,
 * settings read and database connect per pass, per node, four times a second in
 * the signals daemon's case. The plan's 24 h RSS and queries/s acceptance
 * cannot be measured on a process that never lives that long.
 *
 * A `break` inside an `if` is how a daemon is meant to stop (a code change, an
 * nginx restart, a dead database): only the unconditional one at the end of the
 * body is refused.
 */
final class DaemonLoopTest extends TestCase {

	public function testNoDaemonLoopEndsWithAnUnconditionalBreak(): void {
		$rChecked = 0;

		foreach (glob(MAIN_HOME . 'Cli/Commands/*Command.php') ?: [] as $rFile) {
			$rLines = file($rFile) ?: [];
			if (!str_contains(implode('', $rLines), 'restartDaemon(')) {
				continue;
			}
			foreach ($rLines as $rNo => $rLine) {
				if (!preg_match('/^(\t+)(?:\} )?while \(/', $rLine, $rM)) {
					continue;
				}
				$rChecked++;
				$rEnd = self::closes($rLines, $rNo, strlen($rM[1]));
				$rLast = $rEnd === null ? '' : trim($rLines[$rEnd - 1]);
				$this->assertNotSame(
					'break;',
					$rLast,
					basename($rFile) . ':' . $rEnd . ": the loop's last statement is an unconditional break, so the daemon re-executes itself every pass"
				);
			}
		}

		$this->assertGreaterThan(4, $rChecked, 'the daemon loops were not found at all');
	}

	/** The line (0-based) of the `}` that closes a block opened at $rNo, indented with $rTabs tabs. */
	private static function closes(array $rLines, int $rNo, int $rTabs): ?int {
		$rClose = str_repeat("\t", $rTabs) . '}';
		for ($i = $rNo + 1; $i < count($rLines); $i++) {
			if (str_starts_with($rLines[$i], $rClose)) {
				return $i;
			}
		}
		return null;
	}
}
