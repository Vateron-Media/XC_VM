<?php

use XcVm\Streaming\Delivery\HlsSequence;
use PHPUnit\Framework\TestCase;

/**
 * @covers \XcVm\Streaming\Delivery\HlsSequence
 *
 * Exercises the pure re-anchoring step that keeps a stream's HLS MEDIA-SEQUENCE
 * monotonic across the off-air ↔ live transition. The off-air placeholder numbers
 * its loop floor(time()/10) (~1.7e8) while the fanout daemon restarts its own
 * counter from 0 per stream; reconcile() bridges the two so the published sequence
 * only ever advances.
 */
final class HlsSequenceTest extends TestCase {

	public function testFirstUseAnchorsToTheOffAirFloor() {
		// Cold start, no prior state: the live counter (daemonSeq) is small, so the
		// published value is pulled up to the off-air wall-clock floor.
		[$seq, $state] = HlsSequence::reconcile(0, 1000, null);
		$this->assertSame(1000, $seq);
		$this->assertSame(1000, $state['last']);
		$this->assertSame(1000, $state['base']); // base = floor - daemonSeq

		[$seq2] = HlsSequence::reconcile(5, 1000, null);
		$this->assertSame(1000, $seq2); // still floored up, not 5
	}

	public function testSteadyStateAdvancesByTheDaemonDelta() {
		// After anchoring (base=995, last=1000), each new daemon segment increments
		// the published sequence by exactly one — base stays fixed.
		$state = ['base' => 995, 'last' => 1000];
		[$seq, $state] = HlsSequence::reconcile(6, 1000, $state);
		$this->assertSame(1001, $seq);
		$this->assertSame(995, $state['base']);

		[$seq] = HlsSequence::reconcile(7, 1001, $state);
		$this->assertSame(1002, $seq);
	}

	public function testNeverDropsBelowTheOffAirFloor() {
		// The off-air floor jumped ahead (e.g. the stream idled): the live sequence
		// re-anchors up to it rather than emitting a lower value.
		[$seq, $state] = HlsSequence::reconcile(0, 2000, ['base' => 995, 'last' => 1000]);
		$this->assertSame(2000, $seq);
		$this->assertSame(2000, $state['base']);
	}

	public function testDaemonRestartNeverStepsBackward() {
		// Live reached 1500 (base 995 + daemonSeq 505). The daemon restarts the
		// stream and its counter resets to 0 — the published sequence must hold, not
		// fall back to ~995.
		$state = ['base' => 995, 'last' => 1500];
		[$seq, $state] = HlsSequence::reconcile(0, 1400, $state);
		$this->assertSame(1500, $seq);          // held at last, no backward step
		$this->assertSame(1500, $state['base']); // re-anchored

		[$seq] = HlsSequence::reconcile(1, 1400, $state);
		$this->assertSame(1501, $seq);           // and climbs again by the daemon delta
	}

	public function testOffAirToLiveTransitionIsForwardOnly() {
		// A player last saw the off-air sequence 1000; the live cold start (daemonSeq
		// 0) at a slightly later wall clock publishes >= 1000, never the raw 0/1.
		$offAirSeen = 1000;
		[$live] = HlsSequence::reconcile(0, 1001, null);
		$this->assertGreaterThanOrEqual($offAirSeen, $live);
	}

	public function testLongSegmentsAreNeverRenumberedWhilePlaying() {
		// 12 s segments against the 10 s off-air floor: the daemon counter falls
		// behind the wall clock. Re-anchoring to the floor on every publish shifted
		// every listed segment by one each time it caught up — players replayed or
		// skipped a segment. With a fresh, uninterrupted run base must stay fixed.
		$t0 = 1700000000;
		$state = null;
		$base = null;
		for ($t = $t0; $t < $t0 + 600; $t += 2) {
			$daemon = intdiv($t - $t0, 12);
			[$seq, $state] = HlsSequence::reconcile($daemon, intdiv($t, 10), $state, $t, 12);
			$base ??= $state['base'];
			$this->assertSame($base, $state['base'], "renumbered at t+" . ($t - $t0));
			$this->assertSame($daemon + $base, $seq);
		}
	}

	public function testStalePublishReappliesTheFloor() {
		// Nothing published for longer than the stale gap: a player may have been
		// shown the off-air loop meanwhile, so the next live value is floored again.
		$state = ['base' => 100, 'last' => 150, 'daemon' => 50, 'at' => 1000];
		[$seq] = HlsSequence::reconcile(51, 500, $state, 1000 + 31, 6);
		$this->assertSame(500, $seq);

		// Within the gap the run is continuous and the floor is not applied.
		[$seq] = HlsSequence::reconcile(51, 500, $state, 1000 + 20, 6);
		$this->assertSame(151, $seq);
	}

	public function testOffAirMarkReappliesTheFloor() {
		// markOffAir() zeroes `at`: even a daemon counter that ran on through a
		// brief outage is re-anchored above the off-air loop the player just saw.
		$state = ['base' => 100, 'last' => 150, 'daemon' => 50, 'at' => 0];
		[$seq] = HlsSequence::reconcile(51, 500, $state, 1000, 6);
		$this->assertSame(500, $seq);
	}

	public function testDaemonRestartWithFreshStateStillHolds() {
		// A fresh state with a daemon counter that went BACKWARDS is a restart: hold
		// at last (≥ floor), never step back.
		$state = ['base' => 1000, 'last' => 1600, 'daemon' => 600, 'at' => 1000];
		[$seq, $state] = HlsSequence::reconcile(0, 1200, $state, 1001, 6);
		$this->assertSame(1600, $seq);
		[$seq] = HlsSequence::reconcile(1, 1200, $state, 1007, 6);
		$this->assertSame(1601, $seq);
	}

	public function testPublishedSequenceIsAlwaysMonotonic() {
		// Property check: across an arbitrary run of daemon values, floors and a
		// restart, the published sequence never decreases.
		$state = null;
		$prev = -1;
		$daemon = 0;
		foreach ([100, 100, 101, 102, 102, 103, 0, 1, 2, 250, 251] as $i => $floor) {
			// Simulate a daemon restart at index 6 (the "0").
			$daemon = ($i === 6) ? 0 : $daemon + 1;
			[$seq, $state] = HlsSequence::reconcile($daemon, $floor, $state);
			$this->assertGreaterThanOrEqual($prev, $seq, "step $i regressed: $seq < $prev");
			$prev = $seq;
		}
	}
}
