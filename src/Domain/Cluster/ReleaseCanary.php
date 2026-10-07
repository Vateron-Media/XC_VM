<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The fleet canary for the binaries every server takes from GitHub itself
 * (xc_fanout and xc_agent, one release: FanoutBinaryCommand). With
 * `lb_binary_canary_server` set, that load balancer takes each release first,
 * and every other server, MAIN included, takes none newer than
 * `lb_release_pin`. MAIN raises the pin to the canary's release once the
 * canary has run it for `lb_binary_canary_hours`, active and heard
 * throughout (cron:cluster, every minute). The canary's agent version
 * (`cluster_nodes.agent_version`) is the release it runs: run.sh puts the
 * previous agent back when a new one keeps failing, and the count starts
 * again. Off (0, the default): no pin, every server takes the newest.
 */
final class ReleaseCanary {
	use DatabaseAware;

	/** cluster_meta: the canary's release and since when MAIN has heard it run it (`<version> <unix time>`). */
	public const SEEN = 'canary_release';

	/**
	 * One pass.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return string|null The release the pin was raised to, or null.
	 */
	public static function tick(array $rSettings, ?int $rNow = null): ?string {
		$rNow ??= ClusterClock::now();
		$rCanary = ClusterSettings::int('lb_binary_canary_server', $rSettings['lb_binary_canary_server'] ?? null);
		$rPin = (string) ($rSettings['lb_release_pin'] ?? '');
		if ($rCanary <= 0) {
			self::restart();
			if ($rPin !== '') {
				self::pin('', $rPin, null);
			}
			return null;
		}
		$rNode = NodeRegistry::byServer($rCanary);
		$rVersion = ltrim(trim((string) ($rNode['agent_version'] ?? '')), 'vV');
		$rLastSeen = $rNode === null ? null : HeartbeatService::freshest($rNode['last_seen_at'] ?? null, HeartbeatService::lastSeen()[$rCanary] ?? null);
		$rOfflineMs = 1000 * ClusterSettings::int('cluster_offline_after_sec', $rSettings['cluster_offline_after_sec'] ?? null);
		if ($rNode === null || $rNode['state'] !== 'active' || !preg_match('/^\d+(\.\d+){1,3}\z/', $rVersion)
			|| $rLastSeen === null || $rNow * 1000 - $rLastSeen > $rOfflineMs
		) {
			// Nothing MAIN can vouch for runs there: the count starts again.
			self::restart();
			return null;
		}
		[$rSeen, $rSince] = self::trial() ?? ['', 0];
		if ($rSeen !== $rVersion) {
			ClusterMeta::set(self::SEEN, $rVersion . ' ' . $rNow);
			return null;
		}
		$rHours = ClusterSettings::int('lb_binary_canary_hours', $rSettings['lb_binary_canary_hours'] ?? null);
		if ($rNow - (int) $rSince < $rHours * 3600 || ($rPin !== '' && version_compare($rVersion, $rPin, '<='))) {
			return null;
		}
		self::pin($rVersion, $rPin, $rCanary);
		return $rVersion;
	}

	/**
	 * The release the canary is on trial with, and since when MAIN has heard
	 * it run it (the Settings page's Cluster tab shows it beside the pin).
	 *
	 * @return array{0: string, 1: int}|null
	 */
	public static function trial(): ?array {
		$rSeen = ClusterMeta::get(self::SEEN);
		if ($rSeen === null) {
			return null;
		}
		[$rVersion, $rSince] = array_pad(explode(' ', $rSeen, 2), 2, '');
		return [$rVersion, (int) $rSince];
	}

	private static function restart(): void {
		if (ClusterMeta::get(self::SEEN) !== null) {
			ClusterMeta::delete(self::SEEN);
		}
	}

	private static function pin(string $rPin, string $rWas, ?int $rCanary): void {
		self::db()->query('UPDATE `settings` SET `lb_release_pin` = ?;', $rPin);
		ClusterAudit::log('release.pin', $rCanary, ['pin' => $rPin, 'was' => $rWas], 'cron');
	}
}
