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

	// ── isAutoRestartDue (label195 schedule chain) ─────────────

	public function testAutoRestartDueWhenDayHourMinuteMatch(): void {
		$now = mktime(14, 30, 0, 8, 8, 2026);
		// derive the expected components from the same $now -> timezone-agnostic.
		$day = date('l', $now);
		$at  = date('H', $now) . ':' . date('i', $now);
		$this->assertTrue($this->call('isAutoRestartDue', ['days' => [$day], 'at' => $at], $now));
	}

	public function testAutoRestartNotDueOnMismatch(): void {
		$now = mktime(14, 30, 0, 8, 8, 2026);
		$day = date('l', $now);
		$at  = date('H', $now) . ':' . date('i', $now);
		$this->assertFalse($this->call('isAutoRestartDue', ['days' => ['Nonesuch'], 'at' => $at], $now), 'wrong day');
		$this->assertFalse($this->call('isAutoRestartDue', ['days' => [$day], 'at' => ((intval(date('H', $now)) + 1) % 24) . ':' . date('i', $now)], $now), 'wrong hour');
		$this->assertFalse($this->call('isAutoRestartDue', ['days' => [$day], 'at' => date('H', $now) . ':' . (((intval(date('i', $now)) + 1) % 60)) ], $now), 'wrong minute');
	}

	public function testAutoRestartNotDueWhenUnconfigured(): void {
		$now = mktime(14, 30, 0, 8, 8, 2026);
		$this->assertFalse($this->call('isAutoRestartDue', [], $now));
		$this->assertFalse($this->call('isAutoRestartDue', ['days' => [], 'at' => '14:30'], $now), 'empty days');
		$this->assertFalse($this->call('isAutoRestartDue', ['days' => ['Saturday']], $now), 'no time');
	}

	// ── resolveStreamCodecMeta (label562 codec block) ──────────

	public function testCodecMetaEmptyOrNonArrayJson(): void {
		$this->assertSame([0, null, null, null], $this->call('resolveStreamCodecMeta', '', false));
		$this->assertSame([0, null, null, null], $this->call('resolveStreamCodecMeta', null, false));
		$this->assertSame([0, null, null, null], $this->call('resolveStreamCodecMeta', '"not-an-array"', false));
	}

	public function testCodecMetaCompatibleH264Aac(): void {
		$json = json_encode(['codecs' => [
			'video' => ['codec_name' => 'h264', 'height' => 1080],
			'audio' => ['codec_name' => 'aac'],
		]]);
		[$compat, $audio, $video, $res] = $this->call('resolveStreamCodecMeta', $json, false);
		$this->assertSame(1, $compat);
		$this->assertSame('aac', $audio);
		$this->assertSame('h264', $video);
		$this->assertSame(1080, $res);
	}

	public function testCodecMetaResolutionSnapsAndHevcGated(): void {
		$json = json_encode(['codecs' => [
			'video' => ['codec_name' => 'h264', 'height' => 700],
			'audio' => ['codec_name' => 'aac'],
		]]);
		$this->assertSame(720, $this->call('resolveStreamCodecMeta', $json, false)[3], '700 -> nearest 720');

		$hevc = json_encode(['codecs' => ['video' => ['codec_name' => 'hevc', 'height' => 2160], 'audio' => ['codec_name' => 'ac3']]]);
		$this->assertSame(0, $this->call('resolveStreamCodecMeta', $hevc, false)[0], 'hevc not allowed');
		$this->assertSame(1, $this->call('resolveStreamCodecMeta', $hevc, true)[0], 'hevc allowed');
	}
}
