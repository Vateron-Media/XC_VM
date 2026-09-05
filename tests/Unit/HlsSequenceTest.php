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
