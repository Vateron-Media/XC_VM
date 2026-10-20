<?php

namespace XcVm\Domain\Device;

/** Exact, opt-in User-Agent allowlist for MAG portal requests. */
final class DeviceUserAgentPolicy {
	public static function normalize(mixed $rValues): array {
		if (!is_array($rValues) || count($rValues) > 50) {
			throw new \InvalidArgumentException('Invalid User-Agent allowlist.');
		}
		$rResult = [];
		foreach ($rValues as $rValue) {
			if (!is_string($rValue) || trim($rValue) === '' || strlen($rValue) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $rValue)) {
				throw new \InvalidArgumentException('Invalid User-Agent.');
			}
			$rResult[] = $rValue;
		}
		return array_values(array_unique($rResult));
	}

	public static function allows(array $rDevice, string $rAgent): bool {
		if (empty($rDevice['lock_user_agent'])) {
			return true;
		}
		try {
			$rValues = $rDevice['allowed_user_agents'] ?? '[]';
			$rValues = is_string($rValues) ? json_decode($rValues, true, 32, JSON_THROW_ON_ERROR) : $rValues;
			return in_array($rAgent, self::normalize($rValues), true);
		} catch (\Throwable $rError) {
			return false;
		}
	}

	public static function observed(): string {
		$rAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		return is_string($rAgent) && strlen($rAgent) <= 1024 && !preg_match('/[\x00-\x1f\x7f]/', $rAgent) ? $rAgent : '';
	}
}
