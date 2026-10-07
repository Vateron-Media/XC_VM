<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Placement advice (Servers → Placement Advice): which servers are busy and
 * where their busiest streams could also run. Advice only: nothing is moved,
 * the admin adds the server to a stream (or not).
 *
 * A server's load is the highest of its viewers against its Max Clients,
 * its outgoing traffic against its network speed (as the load balancing's
 * own reading: ConnectionTracker::getCapacity()) and its CPU. One at BUSY or
 * above gets the least loaded server under IDLE as a helper, with up to
 * STREAMS of its streams by viewers that the helper does not run yet.
 *
 * @package XC_VM_Domain_Cluster
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class PlacementAdvice {
	use DatabaseAware;

	public const BUSY = 0.8;

	public const IDLE = 0.4;

	public const STREAMS = 5;

	/**
	 * Each serving server's load (MAIN and the load balancers, enabled and online). Pure.
	 *
	 * @param array<int|string, array<string, mixed>> $rServers ServerRepository::getAll()
	 * @param array<int, int> $rClients server id => viewers now
	 * @return array<int, array{load: float, by: string, clients: ?float, network: ?float, cpu: ?float}>
	 */
	public static function loads(array $rServers, array $rClients): array {
		$rOut = [];
		foreach ($rServers as $rServer) {
			if ((int) ($rServer['server_type'] ?? 0) !== 0 || empty($rServer['enabled']) || empty($rServer['server_online'])) {
				continue;
			}
			$rID = (int) $rServer['id'];
			$rWatch = is_array($rServer['watchdog'] ?? null) ? $rServer['watchdog'] : (json_decode((string) ($rServer['watchdog_data'] ?? ''), true) ?: []);
			$rHardware = json_decode((string) ($rServer['server_hardware'] ?? ''), true) ?: [];
			$rSpeed = (float) (!empty($rHardware['network_speed']) ? $rHardware['network_speed'] : ((int) ($rServer['network_guaranteed_speed'] ?? 0) ?: 1000));
			$rRatios = [
				'clients' => (int) ($rServer['total_clients'] ?? 0) > 0 ? ($rClients[$rID] ?? 0) / (int) $rServer['total_clients'] : null,
				'network' => isset($rWatch['bytes_sent']) ? ((float) $rWatch['bytes_sent'] / 125000) / $rSpeed : null,
				'cpu' => isset($rWatch['cpu']) && is_numeric($rWatch['cpu']) ? (float) $rWatch['cpu'] / 100 : null,
			];
			$rKnown = array_filter($rRatios, static fn(?float $rValue): bool => $rValue !== null);
			$rLoad = $rKnown === [] ? 0.0 : max($rKnown);
			$rOut[$rID] = ['load' => round($rLoad, 3), 'by' => $rKnown === [] ? '' : (string) array_search($rLoad, $rKnown, true)] + $rRatios;
		}
		return $rOut;
	}

	/**
	 * The advice from the loads. Pure.
	 *
	 * @param array<int, array{load: float, by: string}> $rLoads loads()
	 * @param array<int, array<int, int>> $rViewers server id => stream id => viewers
	 * @param array<int, list<int>> $rRunsOn stream id => the servers it is set to run on
	 * @return list<array{from: int, load: float, by: string, to: ?int, to_load: ?float, streams: list<array{id: int, viewers: int}>}>
	 */
	public static function advise(array $rLoads, array $rViewers, array $rRunsOn): array {
		$rIdle = array_filter($rLoads, static fn(array $rLoad): bool => $rLoad['load'] < self::IDLE);
		uasort($rIdle, static fn(array $a, array $b): int => $a['load'] <=> $b['load']);
		$rOut = [];
		$rBusy = array_filter($rLoads, static fn(array $rLoad): bool => $rLoad['load'] >= self::BUSY);
		uasort($rBusy, static fn(array $a, array $b): int => $b['load'] <=> $a['load']);
		foreach ($rBusy as $rFrom => $rLoad) {
			$rTo = array_key_first(array_diff_key($rIdle, [$rFrom => true]));
			$rStreams = [];
			if ($rTo !== null) {
				$rCounts = $rViewers[$rFrom] ?? [];
				arsort($rCounts);
				foreach ($rCounts as $rStream => $rCount) {
					if ($rCount > 0 && !in_array($rTo, $rRunsOn[$rStream] ?? [], true)) {
						$rStreams[] = ['id' => (int) $rStream, 'viewers' => (int) $rCount];
					}
					if (count($rStreams) === self::STREAMS) {
						break;
					}
				}
			}
			$rOut[] = ['from' => (int) $rFrom, 'load' => $rLoad['load'], 'by' => $rLoad['by'], 'to' => $rTo, 'to_load' => $rTo === null ? null : $rIdle[$rTo]['load'], 'streams' => $rStreams];
		}
		return $rOut;
	}

	/**
	 * The advice now, with names: what the page shows.
	 *
	 * @return array{loads: array<int, array<string, mixed>>, advice: list<array<string, mixed>>, names: array<int, string>, streams: array<int, string>}
	 */
	public static function current(): array {
		$rServers = ServerRepository::getAll(true);
		$rClients = array_map(static fn(array $rRow): int => (int) ($rRow['online_clients'] ?? 0), ConnectionTracker::getCapacity());
		$rLoads = self::loads($rServers, $rClients);
		$rViewers = [];
		$rRunsOn = [];
		$rBusy = array_keys(array_filter($rLoads, static fn(array $rLoad): bool => $rLoad['load'] >= self::BUSY));
		if ($rBusy !== []) {
			$db = self::db();
			$rIn = implode(',', array_fill(0, count($rBusy), '?'));
			$db->query('SELECT `stream_id`, `server_id` FROM `streams_servers` WHERE `server_id` IN (' . $rIn . ') AND `pid` > 0;', ...$rBusy);
			$rOnBusy = [];
			foreach ($db->get_rows() as $rRow) {
				$rOnBusy[(int) $rRow['server_id']][] = (int) $rRow['stream_id'];
			}
			$rAll = array_values(array_unique(array_merge([], ...array_values($rOnBusy))));
			if ($rAll !== []) {
				$db->query('SELECT `stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_fill(0, count($rAll), '?')) . ');', ...$rAll);
				foreach ($db->get_rows() as $rRow) {
					$rRunsOn[(int) $rRow['stream_id']][] = (int) $rRow['server_id'];
				}
			}
			foreach ($rOnBusy as $rServerID => $rStreamIDs) {
				$rViewers[$rServerID] = SettingsManager::get('redis_handler')
					? array_map(static fn($rByServer): int => is_array($rByServer) ? count($rByServer[$rServerID] ?? []) : 0, ConnectionTracker::getStreamConnections($rStreamIDs, false))
					: ConnectionTracker::onlineClientCounts($rStreamIDs, $rServerID);
			}
		}
		$rAdvice = self::advise($rLoads, $rViewers, $rRunsOn);
		$rNames = [];
		foreach ($rServers as $rServer) {
			$rNames[(int) $rServer['id']] = (string) $rServer['server_name'];
		}
		$rStreamNames = [];
		$rIDs = array_values(array_unique(array_merge([], ...array_map(static fn(array $rItem): array => array_column($rItem['streams'], 'id'), $rAdvice))));
		if ($rIDs !== []) {
			$db = self::db();
			$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_fill(0, count($rIDs), '?')) . ');', ...$rIDs);
			foreach ($db->get_rows() as $rRow) {
				$rStreamNames[(int) $rRow['id']] = (string) $rRow['stream_display_name'];
			}
		}
		return ['loads' => $rLoads, 'advice' => $rAdvice, 'names' => $rNames, 'streams' => $rStreamNames];
	}
}
