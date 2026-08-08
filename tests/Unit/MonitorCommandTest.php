<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\MonitorCommand;

/**
 * Unit tests for pure helpers extracted from MonitorCommand's obfuscated
 * goto-based execute(). Each helper replaces a self-contained goto cluster; the
 * tests lock its behaviour so the control-flow untangling cannot silently drift.
 */
final class MonitorCommandTest extends TestCase {

	/** Invoke a private static MonitorCommand method via reflection. */
	private function call(string $method, ...$args) {
		$m = new ReflectionMethod(MonitorCommand::class, $method);
		$m->setAccessible(true);
		return $m->invoke(null, ...$args);
	}

	// ── parseFrameRate (label768/780/1047/1052/1057) ───────────

	public function testPlainInteger(): void {
		$this->assertSame(30.0, $this->call('parseFrameRate', '30'));
		$this->assertSame(25.0, $this->call('parseFrameRate', 25));
	}

	public function testRationalFrameRate(): void {
		$this->assertSame(25.0, $this->call('parseFrameRate', '25/1'));
		$this->assertEqualsWithDelta(29.97, $this->call('parseFrameRate', '30000/1001'), 0.001);
	}

	public function testZeroAndMalformedAreZero(): void {
		$this->assertSame(0.0, $this->call('parseFrameRate', ''));
		$this->assertSame(0.0, $this->call('parseFrameRate', '0'));
		$this->assertSame(0.0, $this->call('parseFrameRate', '0/0'), 'division-by-zero guarded');
		$this->assertSame(0.0, $this->call('parseFrameRate', '30/0'), 'zero denominator guarded');
	}
}
