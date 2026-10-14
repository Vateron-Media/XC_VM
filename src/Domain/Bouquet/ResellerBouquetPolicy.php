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
		return array_values(array_unique(array_map('intval', array_filter(
			$value,
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
	public static function apply(array $line, array $data, array $actor, array $permissions, object $db, array $previous = []): array {
		$line = self::preserveOrder($line, $previous);
		$bouquets = self::filter(self::ids($line['bouquet'] ?? []), self::forOwner($db, (int) ($line['member_id'] ?? 0)));
		$bouquets = self::filter($bouquets, self::allowed($actor));
		if (!empty($permissions['allow_change_bouquets'])) {
			if (in_array($data['bouquets_present'] ?? null, ['1', 1], true)) {
				$bouquets = self::filter(self::ids($data['bouquets_selected'] ?? []), $bouquets);
			}
			if (array_key_exists('bouquet_order', $data)) {
				$bouquets = self::order($bouquets, $data['bouquet_order']);
				$line['bouquet_order_custom'] = 1;
			}
		}
		$line['bouquet'] = json_encode($bouquets, JSON_THROW_ON_ERROR);
		return $line;
	}

	public static function constrainOwner(array $line, object $db, bool $preserveSavedOrder = true): array {
		if ($preserveSavedOrder && !empty($line['id']) && self::hasCustomOrder($line)) {
			if ($db->query('SELECT * FROM `lines` WHERE `id` = ?;', (int) $line['id']) === false) {
				throw new \RuntimeException('Cannot load saved bouquet order.');
			}
			$line = self::preserveOrder($line, $db->get_row() ?: []);
		}
		$allowed = self::forOwner($db, (int) ($line['member_id'] ?? 0));
		if ($allowed !== null) {
			$line['bouquet'] = json_encode(self::filter(self::ids($line['bouquet'] ?? []), $allowed), JSON_THROW_ON_ERROR);
		}
		return $line;
	}

	/** Keep a customer's order while allowing selected bouquets to change. */
	public static function preserveOrder(array $line, array $previous): array {
		if (self::hasCustomOrder($previous)) {
			$line['bouquet'] = json_encode(self::order(self::ids($line['bouquet'] ?? []), self::ids($previous['bouquet'] ?? [])), JSON_THROW_ON_ERROR);
			$line['bouquet_order_custom'] = 1;
		}
		return $line;
	}

	/** Snapshot used to reject an order submitted from an outdated customer form. */
	public static function orderRevision(array $line): string {
		return hash('sha256', json_encode([self::ids($line['bouquet'] ?? []), self::hasCustomOrder($line)], JSON_THROW_ON_ERROR));
	}

	/** A revision of the rendered allowance, independent of JSON formatting. */
	public static function allowanceRevision(array $reseller): string {
		return hash('sha256', json_encode(self::allowed($reseller), JSON_THROW_ON_ERROR));
	}

	public static function hasCustomOrder(mixed $custom): bool {
		if (is_string($custom)) {
			$custom = json_decode($custom, true);
		}
		if (!is_array($custom)) {
			return false;
		}
		if (!empty($custom['bouquet_order_custom']) || ($custom['bouquet_order'] ?? false) === true) {
			return true;
		}
		return isset($custom['custom_data']) && self::hasCustomOrder($custom['custom_data']);
	}
}
