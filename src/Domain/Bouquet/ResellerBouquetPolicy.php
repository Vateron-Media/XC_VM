<?php

declare(strict_types=1);

namespace XcVm\Domain\Bouquet;

/** Per-reseller limits apply when saving customers, never retroactively at playback. */
final class ResellerBouquetPolicy {
	/** @return list<int> */
	public static function ids(mixed $value): array {
		if (is_string($value)) {
			$value = json_decode($value, true);
		}
		if (!is_array($value)) {
			return [];
		}
		return array_values(array_unique(array_map('intval', array_filter($value,
			static fn($id): bool => (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0
		))));
	}

	/** null means unrestricted; an empty list allows no bouquets. */
	public static function allowed(array $reseller): ?array {
		$value = $reseller['allowed_bouquets'] ?? null;
		return $value === null ? null : self::ids($value);
	}

	public static function filter(array $bouquets, ?array $allowed): array {
		$bouquets = self::ids($bouquets);
		return $allowed === null ? $bouquets : array_values(array_intersect($bouquets, $allowed));
	}

	public static function forOwner(object $db, int $owner): ?array {
		if ($owner <= 0) {
			return null;
		}
		if ($db->query('SELECT * FROM `users` WHERE `id` = ?;', $owner) === false) {
			throw new \RuntimeException('Cannot load reseller bouquet permissions.');
		}
		$row = $db->get_row();
		return $row ? self::allowed($row) : null;
	}

	/** Keep the requested order, append valid ids omitted from the order payload. */
	public static function order(array $bouquets, mixed $order): array {
		return array_values(array_unique(array_merge(self::filter(self::ids($order), $bouquets), $bouquets)));
	}

	/** Preserve package defaults unless the customer form explicitly sends a selection. */
	public static function apply(array $line, array $data, array $actor, array $permissions, object $db): array {
		$bouquets = self::filter(self::ids($line['bouquet'] ?? []), self::forOwner($db, (int) ($line['member_id'] ?? 0)));
		$bouquets = self::filter($bouquets, self::allowed($actor));
		if (!empty($permissions['allow_change_bouquets'])) {
			if (($data['bouquets_present'] ?? '') === '1') {
				$bouquets = self::filter(self::ids($data['bouquets_selected'] ?? []), $bouquets);
			}
			if (array_key_exists('bouquet_order', $data)) {
				$bouquets = self::order($bouquets, $data['bouquet_order']);
				$custom = is_array($line['custom_data'] ?? null) ? $line['custom_data'] : json_decode((string) ($line['custom_data'] ?? ''), true);
				$custom = is_array($custom) ? $custom : [];
				$custom['bouquet_order'] = true;
				$line['custom_data'] = json_encode($custom, JSON_THROW_ON_ERROR);
			}
		}
		$line['bouquet'] = json_encode($bouquets, JSON_THROW_ON_ERROR);
		return $line;
	}

	public static function constrainOwner(array $line, object $db): array {
		$allowed = self::forOwner($db, (int) ($line['member_id'] ?? 0));
		if ($allowed !== null) {
			$line['bouquet'] = json_encode(self::filter(self::ids($line['bouquet'] ?? []), $allowed), JSON_THROW_ON_ERROR);
		}
		return $line;
	}

	public static function hasCustomOrder(mixed $custom): bool {
		if (is_string($custom)) {
			$custom = json_decode($custom, true);
		}
		return is_array($custom) && ($custom['bouquet_order'] ?? false) === true;
	}
}
