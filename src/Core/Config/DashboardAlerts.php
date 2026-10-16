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

	/** Normalized database keys, also used as the settings form's original snapshot. */
	public static function values(array $rSettings): array {
		return [
			'dashboard_alerts' => (int) (bool) ($rSettings['dashboard_alerts'] ?? true),
			'dashboard_alerts_count' => self::count('dashboard_alerts_count', $rSettings['dashboard_alerts_count'] ?? null),
			'dashboard_alert_history' => (int) (bool) ($rSettings['dashboard_alert_history'] ?? true),
			'dashboard_alert_history_count' => self::count('dashboard_alert_history_count', $rSettings['dashboard_alert_history_count'] ?? null),
		];
	}

	/** Only edited alert preferences are saved; an unchanged stale form cannot reset them. */
	public static function submitted(array $rData): array {
		$rKeys = array_keys(self::values([]));
		$rPosted = array_intersect_key($rData, array_flip($rKeys));
		if (isset($rData['submit_settings'])) {
			foreach (['dashboard_alerts', 'dashboard_alert_history'] as $rKey) {
				$rPosted[$rKey] = (int) !empty($rData[$rKey]);
			}
		}
		$rValues = array_intersect_key(self::values($rPosted), $rPosted);
		if (!array_key_exists('dashboard_alerts_original', $rData)) {
			return $rValues; // API or legacy form, with no rendered snapshot.
		}
		$rOriginal = is_string($rData['dashboard_alerts_original']) ? json_decode($rData['dashboard_alerts_original'], true) : null;
		if (!is_array($rOriginal) || count(array_intersect_key($rOriginal, array_flip($rKeys))) !== count($rKeys)) {
			return []; // An invalid snapshot never overwrites stored preferences.
		}
		return array_diff_assoc($rValues, self::values($rOriginal));
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
