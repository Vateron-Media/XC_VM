<?php

declare(strict_types=1);

namespace XcVm\Streaming\Routing;

use XcVm\Core\Module\Contract\StreamRoutingProviderInterface;

final class PlaybackRouter {
	public const API_VERSION = 1;

	/** @var list<StreamRoutingProviderInterface> */
	private static array $providers = [];

	public static function register(StreamRoutingProviderInterface $provider): void {
		self::$providers[] = $provider;
	}

	public static function proxyFor(int $lineId): ?int {
		foreach (self::$providers as $provider) {
			$proxyId = $provider->proxyFor($lineId);
			if ($proxyId !== null) {
				return $proxyId;
			}
		}
		return null;
	}

	public static function active(): bool {
		return self::$providers !== [];
	}

	/** Choose a compatible online proxy, or native playback for module-managed lines. */
	public static function select(int $origin, int $line, bool $legacy, string $country, string $isp, ?string $failure = 'NO_SERVERS_AVAILABLE'): ?int {
		if (!self::active()) {
			if (!$legacy) {
				return null;
			}
			$proxy = \XcVm\Streaming\Balancer\ProxySelector::availableProxy(
				array_keys(\XcVm\Domain\Stream\ConnectionTracker::getProxies($origin)),
				$country,
				$isp
			);
			if (!$proxy && $failure !== null) {
				generateError($failure);
			}
			return $proxy ? (int) $proxy : null;
		}
		try {
			$proxy = self::proxyFor($line);
			$available = \XcVm\Domain\Stream\ConnectionTracker::getProxies($origin);
			if ($proxy === null || !isset($available[$proxy]) || empty($available[$proxy]['enabled'])) {
				return null;
			}
			$selected = \XcVm\Streaming\Balancer\ProxySelector::availableProxy([$proxy], $country, $isp);
			return $selected ? (int) $selected : null;
		} catch (\Throwable $error) {
			error_log('PlaybackRouter: native fallback: ' . $error->getMessage());
			return null;
		}
	}

	public static function reset(): void {
		self::$providers = [];
	}
}
