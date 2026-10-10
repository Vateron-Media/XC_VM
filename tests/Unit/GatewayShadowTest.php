<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Gateway\GatewayShadow;
use XcVm\Domain\Cluster\NodeAudit;

/**
 * The segment gateway's shadow comparison and its report (ADR 0005): what
 * PHP tells the gateway it answered, what the node reports with its audit,
 * what MAIN keeps of it, and when a node is ready to serve.
 */
final class GatewayShadowTest extends TestCase {
	private const NOW = 1800000000;

	protected function tearDown(): void {
		GatewayShadow::useCall(null);
	}

	public function testWhatPHPAnsweredInTheGatewaysWords(): void {
		$this->assertSame('serve', GatewayShadow::outcome(['Content-Type: video/mp2t', 'X-Accel-Redirect: /xc_fanout_hls/12_5?c=x'], 200), 'handed to the daemon');
		$this->assertSame('serve', GatewayShadow::outcome(['Content-Type: application/x-mpegurl'], 200), 'a playlist or a key');
		$this->assertSame('redirect', GatewayShadow::outcome(['location: http://lb5.example/hls/x'], 302));
		$this->assertSame('deny', GatewayShadow::outcome(['Access-Control-Allow-Origin: *'], 404));
		$this->assertSame('blocked', GatewayShadow::outcome([], 403), 'the bootstrap\'s flood block');
		$this->assertSame('status-500', GatewayShadow::outcome([], 500));
	}

	public function testReadiness(): void {
		$rWeek = GatewayShadow::READY_DAYS * 86400;
		$this->assertSame('none', GatewayShadow::readiness([], self::NOW));
		$this->assertSame('comparing', GatewayShadow::readiness(['since' => self::NOW - 3600, 'agree' => 5000], self::NOW), 'not a week yet');
		$this->assertSame('comparing', GatewayShadow::readiness(['since' => self::NOW - $rWeek, 'agree' => 99], self::NOW), 'too few');
		$this->assertSame('ready', GatewayShadow::readiness(['since' => self::NOW - $rWeek, 'agree' => 100], self::NOW));
		$this->assertSame('disagreed', GatewayShadow::readiness(['since' => self::NOW - 30 * 86400, 'agree' => 9999, 'disagree' => 1, 'last_disagree' => self::NOW - 86400], self::NOW));
		$this->assertSame('ready', GatewayShadow::readiness(['since' => self::NOW - 30 * 86400, 'agree' => 9999, 'disagree' => 1, 'last_disagree' => self::NOW - $rWeek - 1], self::NOW), 'a week clean since');
	}

	/** @return array<string, mixed> */
	private function stats(): array {
		$rCounts = ['segment serve daemon' => 900, 'key serve key' => 300, 'segment deny ip' => 4];
		for ($i = 0; $i < 60; $i++) {
			$rCounts['live php reason' . $i] = $i;
		}
		$rSamples = [];
		for ($i = 0; $i < 8; $i++) {
			$rSamples[] = ['at' => self::NOW - $i, 'kind' => 'segment', 'gateway' => 'deny connection', 'php' => 'serve', 'stream' => $i];
		}
		return ['counts' => $rCounts, 'judge_us_le' => [], 'shadow' => ['since' => self::NOW - 100, 'agree' => 7, 'disagree' => 8, 'deferred' => 3, 'unmatched' => 1, 'last_disagree' => self::NOW, 'samples' => $rSamples]];
	}

	public function testTheNodesReportIsTheBusiestCountsAndTheNewestSamples(): void {
		$rAsked = [];
		GatewayShadow::useCall(function (string $rMethod, string $rPath, array $rHeaders) use (&$rAsked): ?string {
			$rAsked[] = $rMethod . ' ' . $rPath;
			return (string) json_encode($this->stats());
		});
		$rReport = GatewayShadow::report()['gateway'];
		$this->assertSame(['GET /stats'], $rAsked);
		$rCounts = (array) $rReport['counts'];
		$this->assertCount(GatewayShadow::MAX_COUNTS, $rCounts);
		$this->assertSame(900, array_values($rCounts)[0], 'the busiest first');
		$this->assertSame([7, 8, 3, 1], [$rReport['shadow']['agree'], $rReport['shadow']['disagree'], $rReport['shadow']['deferred'], $rReport['shadow']['unmatched']]);
		$this->assertCount(GatewayShadow::MAX_SAMPLES, $rReport['shadow']['samples']);

		GatewayShadow::useCall(static fn(): ?string => null);
		$this->assertSame([], GatewayShadow::report(), 'no gateway running: nothing');
	}

	public function testMAINKeepsAWellFormedReportOnly(): void {
		$rStats = $this->stats();
		$rStats['counts']['segment serve daemon; DROP'] = 5;
		$rStats['counts']['live php not-on-air'] = -1;
		$rStats['shadow']['samples'][] = ['at' => self::NOW, 'kind' => 'segment', 'gateway' => 'serve daemon', 'php' => '<script>', 'stream' => 1];
		$rAudit = ['settings_misses' => [], 'gateway' => ['mode' => 'shadow', 'counts' => $rStats['counts'], 'shadow' => $rStats['shadow']]];
		$rKept = NodeAudit::normalise($rAudit);
		$rGateway = $rKept['gateway'];
		$this->assertSame('shadow', $rGateway['mode']);
		$this->assertArrayNotHasKey('segment serve daemon; DROP', $rGateway['counts'], 'not a verdict key');
		$this->assertArrayNotHasKey('live php not-on-air', $rGateway['counts'], 'not a count');
		$this->assertLessThanOrEqual(GatewayShadow::MAX_COUNTS, count($rGateway['counts']));
		$this->assertNotContains('<script>', array_column($rGateway['shadow']['samples'], 'php'), 'a sample in no known form');
		$this->assertCount(GatewayShadow::MAX_SAMPLES - 1, $rGateway['shadow']['samples'], 'the newest five, the bad one dropped');

		// The stored form reads back the same, and a report without the gateway has none.
		$rStored = json_decode(NodeAudit::encode($rKept), true);
		$this->assertSame($rGateway, NodeAudit::gatewayOf($rStored));
		$this->assertNull(NodeAudit::gatewayOf(['settings_misses' => []]));
		$this->assertArrayNotHasKey('gateway', NodeAudit::normalise(['settings_misses' => [], 'gateway' => ['mode' => 'turbo']]), 'not a mode');
	}
}
