<?php

namespace XcVm\Domain\Line;

use XcVm\Core\Reference\GeoReference;

final class ForcedCountryPolicy {
	/** Resolve the country a reseller may store for a package-backed resource. */
	public static function resolve(array $rPackage, array $rReseller, array $rData): ?string {
		$rPackageCountry = self::country($rPackage['forced_country'] ?? null);
		if ($rPackageCountry !== null) {
			return $rPackageCountry;
		}

		if (!array_key_exists('forced_country', $rData)) {
			return null;
		}
		if (empty($rReseller['can_set_forced_country'])) {
			throw new \InvalidArgumentException('Forced country selection is not delegated.');
		}

		$rCountry = strtoupper(trim((string) $rData['forced_country']));
		if ($rCountry === '') {
			return null;
		}
		if (!array_key_exists($rCountry, GeoReference::countryCodes())) {
			throw new \InvalidArgumentException('Invalid forced country.');
		}

		return $rCountry;
	}

	private static function country(mixed $rCountry): ?string {
		$rCountry = strtoupper(trim((string) $rCountry));
		return $rCountry !== '' && in_array($rCountry, array_column(GeoReference::countries(), 'id'), true) ? $rCountry : null;
	}
}
