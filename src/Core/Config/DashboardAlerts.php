<?php

declare(strict_types=1);

namespace XcVm\Core\Config;

/** Shared defaults and bounds for the dashboard and General Settings. */
final class DashboardAlerts {
	public const COUNTS = ['dashboard_alerts_count' => [4, 50], 'dashboard_alert_history_count' => [20, 200]];

	public static function count(string $rKey, mixed $rValue): int {
		[$rDefault, $rMax] = self::COUNTS[$rKey];
		return is_numeric($rValue) ? max(1, min($rMax, (int) $rValue)) : $rDefault;
	}

	public static function options(?array $rSettings = null): array {
		$rSettings ??= SettingsManager::getAll();
		return [
			'latest_enabled' => (bool) ($rSettings['dashboard_alerts'] ?? true),
			'latest_count' => self::count('dashboard_alerts_count', $rSettings['dashboard_alerts_count'] ?? null),
			'history_enabled' => (bool) ($rSettings['dashboard_alert_history'] ?? true),
			'history_count' => self::count('dashboard_alert_history_count', $rSettings['dashboard_alert_history_count'] ?? null),
		];
	}
}
