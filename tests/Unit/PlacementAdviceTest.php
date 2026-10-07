<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\PlacementAdvice;

/**
 * Placement advice (Domain\Cluster\PlacementAdvice): a server's load is the
 * highest of its viewers against Max Clients, its outgoing traffic against its
 * network speed and its CPU; a busy one is paired with the least loaded idle
 * one and its busiest streams that one does not run. Advice only.
 */
final class PlacementAdviceTest extends TestCase {
	/** @return array<string, mixed> a server row as ServerRepository::getAll() has it */
	private function server(int $rID, array $rExtra = []): array {
		return $rExtra + ['id' => $rID, 'server_type' => 0, 'enabled' => 1, 'server_online' => true, 'total_clients' => 100, 'network_guaranteed_speed' => 1000, 'server_hardware' => '', 'watchdog_data' => json_encode(['cpu' => 10, 'bytes_sent' => 0])];
	}

	public function testALoadIsItsHighestRatio(): void {
		$rLoads = PlacementAdvice::loads([
			1 => $this->server(1),
			2 => $this->server(2, ['watchdog_data' => json_encode(['cpu' => 20, 'bytes_sent' => 112500000])]), // 900 Mbit/s of 1000
			3 => $this->server(3, ['server_hardware' => json_encode(['network_speed' => 10000]), 'watchdog_data' => json_encode(['cpu' => 95, 'bytes_sent' => 112500000])]),
			4 => $this->server(4, ['server_type' => 1]),
			5 => $this->server(5, ['server_online' => false]),
		], [1 => 85, 2 => 10, 3 => 0]);
		$this->assertSame([1, 2, 3], array_keys($rLoads), 'not a proxy, not one offline');
		$this->assertSame([0.85, 'clients'], [$rLoads[1]['load'], $rLoads[1]['by']]);
		$this->assertSame([0.9, 'network'], [$rLoads[2]['load'], $rLoads[2]['by']]);
		$this->assertSame([0.95, 'cpu'], [$rLoads[3]['load'], $rLoads[3]['by']], 'its 10 Gbit/s link is far from full; its CPU is not');
	}

	public function testABusyServerGetsTheLeastLoadedHelperAndItsBusiestStreams(): void {
		$rLoads = [
			1 => ['load' => 0.92, 'by' => 'network'],
			2 => ['load' => 0.35, 'by' => 'clients'],
			3 => ['load' => 0.10, 'by' => 'cpu'],
			4 => ['load' => 0.60, 'by' => 'cpu'],
		];
		$rAdvice = PlacementAdvice::advise($rLoads, [1 => [10 => 50, 11 => 300, 12 => 120, 13 => 0]], [10 => [1], 11 => [1, 3], 12 => [1]]);
		$this->assertCount(1, $rAdvice, 'only the server at 80% or more');
		$this->assertSame([1, 3, 0.1], [$rAdvice[0]['from'], $rAdvice[0]['to'], $rAdvice[0]['to_load']]);
		$this->assertSame([['id' => 12, 'viewers' => 120], ['id' => 10, 'viewers' => 50]], $rAdvice[0]['streams'], 'by viewers; not one it runs already, not one nobody watches');
	}

	public function testWithNoIdleServerThereIsNoHelper(): void {
		$rAdvice = PlacementAdvice::advise([1 => ['load' => 0.9, 'by' => 'cpu'], 2 => ['load' => 0.7, 'by' => 'cpu']], [1 => [10 => 5]], []);
		$this->assertSame([null, []], [$rAdvice[0]['to'], $rAdvice[0]['streams']]);
		$this->assertSame([], PlacementAdvice::advise([1 => ['load' => 0.5, 'by' => 'cpu']], [], []), 'nothing busy, nothing to say');
	}

	public function testAtMostAFewStreams(): void {
		$rViewers = array_combine(range(100, 120), range(1, 21));
		$rAdvice = PlacementAdvice::advise([1 => ['load' => 0.9, 'by' => 'clients'], 2 => ['load' => 0.1, 'by' => 'clients']], [1 => $rViewers], []);
		$this->assertCount(PlacementAdvice::STREAMS, $rAdvice[0]['streams']);
		$this->assertSame(120, $rAdvice[0]['streams'][0]['id']);
	}
}
