<?php

use PHPUnit\Framework\TestCase;

/**
 * The rolling-update progress card on the Servers page must not reappear for a
 * run that finished before the page was opened: RollingUpdate leaves its state
 * file on disk after a run, so `showRolling` has to gate the card on a live (or
 * being-watched) run, not merely on a status existing. Guards the "rolling
 * update message never disappears" bug.
 */
final class RollingUpdateCardGateTest extends TestCase {

	public function testTheCardShowIsGatedByALiveRunNotJustAStatus(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/servers.php');

		$this->assertSame(1, preg_match('/function showRolling\(d\)\s*\{(.*?)\$\(\x27#rolling-update-card\x27\)\.removeClass\(\x27d-none\x27\)/s', $rView, $rPre), 'found showRolling up to the card show');
		$rBeforeShow = $rPre[1];

		// Between entering showRolling and showing the card there must be a guard
		// that keeps a non-live, not-being-watched (finished) run from showing.
		$this->assertMatchesRegularExpression('/\blive\b/', $rBeforeShow, 'a liveness check gates the card');
		$this->assertStringContainsString('rollingActive', $rBeforeShow, 'a per-view "seen a live run" flag gates the card');
		$this->assertSame(2, preg_match_all('/\breturn;/', $rBeforeShow), 'two early returns: no state, and not-live-and-not-active — the card is not shown unconditionally');
	}
}
