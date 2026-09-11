<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\FanoutSyncCommand;

/**
 * @covers \XcVm\Cli\Commands\FanoutSyncCommand::dropOrphans
 *
 * The reverse of fanout_sync's reconcile: a daemon viewer whose connection row is
 * gone (kicked, reaped, expired line) is dropped once it has stayed orphaned for
 * the grace period — and never one that still has an open row.
 */
final class FanoutSyncOrphanTest extends TestCase {

	public function testOrphanIsDroppedOnlyAfterTheGrace(): void {
		$rSync = new FanoutSyncCommand();
		$rDropped = [];
		$rDrop = function (string $rUUID) use (&$rDropped) {
			$rDropped[] = $rUUID;
		};
		$rRows = [['uuid' => 'kept', 'hls_end' => 0]];

		$this->assertSame([], $rSync->dropOrphans(['kept', 'orphan'], $rRows, 1000, $rDrop), 'first sighting: grace starts');
		$this->assertSame([], $rSync->dropOrphans(['kept', 'orphan'], $rRows, 1010, $rDrop), 'still within the grace');
		$this->assertSame(['orphan'], $rSync->dropOrphans(['kept', 'orphan'], $rRows, 1020, $rDrop));
		$this->assertSame(['orphan'], $rDropped, 'the viewer with a row is never dropped');
	}

	public function testAViewerWhoseRowReturnsIsForgiven(): void {
		$rSync = new FanoutSyncCommand();
		$rDrop = function () {
		};
		$rSync->dropOrphans(['u'], [], 1000, $rDrop);
		// The row reappears (a read racing its write): the grace resets.
		$this->assertSame([], $rSync->dropOrphans(['u'], [['uuid' => 'u', 'hls_end' => 0]], 1015, $rDrop));
		$this->assertSame([], $rSync->dropOrphans(['u'], [], 1025, $rDrop), 'orphaned again: a fresh grace');
	}

	public function testAClosedRowCountsAsGone(): void {
		$rSync = new FanoutSyncCommand();
		$rDrop = function () {
		};
		$rClosed = [['uuid' => 'k', 'hls_end' => 1]]; // kicked: closed, not yet reaped
		$rSync->dropOrphans(['k'], $rClosed, 1000, $rDrop);
		$this->assertSame(['k'], $rSync->dropOrphans(['k'], $rClosed, 1020, $rDrop));
	}
}
