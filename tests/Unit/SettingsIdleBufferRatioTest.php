<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Server\SettingsService;

/**
 * The fanout idle buffer ratio is a fraction (0.1-1). The settings form stripped
 * every non-digit from it, so 0.25 reached the server as 025; the save now takes
 * what the operator typed, with a comma or a point, and keeps it in range.
 */
final class SettingsIdleBufferRatioTest extends TestCase {
	public function testTakesTheFractionTheOperatorTyped(): void {
		$this->assertSame(0.25, SettingsService::normalizeIdleBufferRatio('0.25'));
		$this->assertSame(0.25, SettingsService::normalizeIdleBufferRatio('0,25'), 'a decimal comma');
		$this->assertSame(0.5, SettingsService::normalizeIdleBufferRatio(' 0.50 '));
		$this->assertSame(1.0, SettingsService::normalizeIdleBufferRatio('1'));
	}

	public function testKeepsItInTheDaemonsRange(): void {
		$this->assertSame(0.1, SettingsService::normalizeIdleBufferRatio('0.01'));
		$this->assertSame(1.0, SettingsService::normalizeIdleBufferRatio('25'));
		$this->assertSame(0.33, SettingsService::normalizeIdleBufferRatio('0.333'));
	}

	public function testSomethingThatIsNotANumberIsNotSaved(): void {
		$this->assertNull(SettingsService::normalizeIdleBufferRatio(''));
		$this->assertNull(SettingsService::normalizeIdleBufferRatio('abc'));
		$this->assertNull(SettingsService::normalizeIdleBufferRatio('0.2.5'));
		$this->assertNull(SettingsService::normalizeIdleBufferRatio(null));
	}
}
