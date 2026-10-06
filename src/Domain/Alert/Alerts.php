<?php

namespace XcVm\Domain\Alert;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Public\Controllers\Admin\DashboardController;

/**
 * The alert rules and their evaluation (cron:alerts, every minute on MAIN).
 *
 * Each rule watches one kind of subject (a server, a stream, a dashboard
 * check). A subject that has been in trouble for the rule's minutes fires
 * once; when it is fine again, a resolved message follows. A subject that
 * fired is not announced again for QUIET seconds, so one that flaps is
 * reported once. Each minute's fired and resolved subjects of a rule go out
 * as one message on the rule's channels (none chosen: every enabled channel),
 * and every message is kept in `alert_log`.
 *
 * @package XC_VM_Domain_Alert
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class Alerts {
	use DatabaseAware;

	/** rule => [title, threshold (null: none), minutes, on by default] */
	public const RULES = [
		'server_down' => ['A server is down', null, 2, 1],
		'stream_down' => ['A stream is down', null, 5, 0],
		'cpu' => ['CPU use is high', 90, 10, 1],
		'memory' => ['Memory use is high', 90, 10, 1],
		'disk' => ['Disk use is high', 90, 0, 1],
		'checks' => ['A Service Status check is failing', null, 0, 1],
	];

	/** Seconds a subject that fired is not announced again. */
	public const QUIET = 900;

	/** Subjects named in one message at most. */
	public const MAX_ITEMS = 20;

	/** Inactive state rows are kept this long after they last fired. */
	private const KEEP = 86400;

	/** @return array<string, array{enabled: int, threshold: int, minutes: int, channels: list<int>}> every rule, as stored or by default */
	public static function rules(): array {
		$rStored = [];
		$db = self::db();
		if ($db->query('SELECT `rule`, `enabled`, `threshold`, `minutes`, `channels` FROM `alert_rules`;')) {
			foreach ($db->get_raw_rows() as $rRow) {
				$rStored[$rRow['rule']] = $rRow;
			}
		}
		$rOut = [];
		foreach (self::RULES as $rRule => [, $rThreshold, $rMinutes, $rEnabled]) {
			$rRow = $rStored[$rRule] ?? null;
			$rOut[$rRule] = [
				'enabled' => (int) ($rRow['enabled'] ?? $rEnabled),
				'threshold' => (int) ($rRow['threshold'] ?? $rThreshold ?? 0),
				'minutes' => (int) ($rRow['minutes'] ?? $rMinutes),
				'channels' => array_values(array_map('intval', (array) json_decode((string) ($rRow['channels'] ?? '[]'), true))),
			];
		}
		return $rOut;
	}

	/**
	 * Save the rules from the page: per rule `enabled`, `threshold` (1-100),
	 * `minutes` (0-1440) and `channels` (ids).
	 *
	 * @param array<string, mixed> $rData
	 */
	public static function saveRules(array $rData): bool {
		foreach (self::RULES as $rRule => [, $rThreshold]) {
			$rIn = (array) ($rData['rules'][$rRule] ?? []);
			$rChannels = array_values(array_unique(array_filter(array_map('intval', (array) ($rIn['channels'] ?? [])))));
			self::db()->query(
				'REPLACE INTO `alert_rules` (`rule`, `enabled`, `threshold`, `minutes`, `channels`) VALUES (?, ?, ?, ?, ?);',
				$rRule,
				empty($rIn['enabled']) ? 0 : 1,
				$rThreshold === null ? 0 : max(1, min(100, (int) ($rIn['threshold'] ?? $rThreshold))),
				max(0, min(1440, (int) ($rIn['minutes'] ?? 0))),
				json_encode($rChannels)
			);
		}
		return true;
	}

	/**
	 * What is wrong now, by rule: subject => label.
	 *
	 * @param array<string, array{enabled: int, threshold: int}> $rRules
	 * @return array<string, array<string, string>>
	 */
	public static function conditions(array $rRules): array {
		$rOut = array_fill_keys(array_keys(self::RULES), []);
		$rServers = ServerRepository::getAll();
		foreach ($rServers as $rID => $rServer) {
			// A server installing (3) or updating (5) is neither up nor down, as on the dashboard.
			if (empty($rServer['enabled']) || in_array((int) ($rServer['status'] ?? 0), [3, 5], true)) {
				continue;
			}
			$rName = (string) $rServer['server_name'];
			if (empty($rServer['server_online'])) {
				$rOut['server_down'][(string) $rID] = $rName;
				continue;
			}
			$rWatch = json_decode((string) ($rServer['watchdog_data'] ?? ''), true) ?: [];
			$rUsed = ['cpu' => $rWatch['cpu'] ?? null, 'memory' => $rWatch['total_mem_used_percent'] ?? null, 'disk' => null];
			if (!empty($rWatch['total_disk_space']) && isset($rWatch['free_disk_space'])) {
				$rUsed['disk'] = 100 - (float) $rWatch['free_disk_space'] / (float) $rWatch['total_disk_space'] * 100;
			}
			foreach ($rUsed as $rRule => $rValue) {
				if (is_numeric($rValue) && (float) $rValue >= $rRules[$rRule]['threshold']) {
					$rOut[$rRule][(string) $rID] = $rName . ': ' . round((float) $rValue) . ' %';
				}
			}
		}
		if (!empty($rRules['stream_down']['enabled']) && self::db()->query('SELECT `streams_servers`.`stream_id`, `streams_servers`.`server_id`, `streams`.`stream_display_name` FROM `streams_servers` INNER JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams_servers`.`stream_status` = 1 LIMIT 5000;')) {
			foreach (self::db()->get_raw_rows() as $rRow) {
				$rServer = $rServers[(int) $rRow['server_id']] ?? null;
				if ($rServer !== null && !empty($rServer['enabled'])) {
					$rOut['stream_down'][$rRow['stream_id'] . ':' . $rRow['server_id']] = $rRow['stream_display_name'] . ' (' . $rServer['server_name'] . ')';
				}
			}
		}
		if (!empty($rRules['checks']['enabled'])) {
			foreach (DashboardController::statusChecks($rServers) as $rCheck) {
				// A server down is the server_down rule's.
				if ($rCheck['state'] === 'fail' && $rCheck['key'] !== 'servers') {
					$rOut['checks'][$rCheck['key']] = $rCheck['title'] . ': ' . strip_tags($rCheck['detail']);
				}
			}
		}
		return $rOut;
	}

	/**
	 * One minute of the state machine. Pure.
	 *
	 * @param array<string, array{rule: string, subject: string, label: string, active: int, since: int, notified: int, sent: int, fired_at: int}> $rState keyed "rule|subject"
	 * @param array<string, array<string, string>> $rConditions what is wrong now, by rule (conditions())
	 * @param array<string, array{enabled: int, minutes: int}> $rRules
	 * @return array{0: array<string, array<string, mixed>>, 1: list<array{rule: string, kind: string, subject: string, label: string}>} the new state, and the events: fired, resolved, or suppressed (fired within QUIET)
	 */
	public static function step(array $rState, array $rConditions, array $rRules, int $rNow): array {
		$rEvents = [];
		foreach ($rRules as $rRule => $rDef) {
			$rFiring = empty($rDef['enabled']) ? [] : ($rConditions[$rRule] ?? []);
			foreach ($rFiring as $rSubject => $rLabel) {
				$rKey = $rRule . '|' . $rSubject;
				$rRow = $rState[$rKey] ?? ['rule' => $rRule, 'subject' => (string) $rSubject, 'label' => '', 'active' => 0, 'since' => 0, 'notified' => 0, 'sent' => 0, 'fired_at' => 0];
				if (!$rRow['active']) {
					$rRow = array_merge($rRow, ['active' => 1, 'since' => $rNow, 'notified' => 0, 'sent' => 0]);
				}
				$rRow['label'] = $rLabel;
				if (!$rRow['notified'] && $rNow - $rRow['since'] >= $rDef['minutes'] * 60) {
					$rRow['notified'] = 1;
					if ($rNow - $rRow['fired_at'] >= self::QUIET) {
						$rRow = array_merge($rRow, ['sent' => 1, 'fired_at' => $rNow]);
						$rEvents[] = ['rule' => $rRule, 'kind' => 'fired', 'subject' => (string) $rSubject, 'label' => $rLabel];
					} else {
						$rEvents[] = ['rule' => $rRule, 'kind' => 'suppressed', 'subject' => (string) $rSubject, 'label' => $rLabel];
					}
				}
				$rState[$rKey] = $rRow;
			}
			foreach ($rState as $rKey => $rRow) {
				if ($rRow['rule'] !== $rRule || !$rRow['active'] || isset($rFiring[$rRow['subject']])) {
					continue;
				}
				// Turned off is not resolved: a rule switched off ends its troubles silently.
				if ($rRow['notified'] && $rRow['sent'] && !empty($rDef['enabled'])) {
					$rEvents[] = ['rule' => $rRule, 'kind' => 'resolved', 'subject' => $rRow['subject'], 'label' => $rRow['label']];
				}
				$rState[$rKey] = array_merge($rRow, ['active' => 0, 'notified' => 0, 'sent' => 0]);
			}
		}
		foreach ($rState as $rKey => $rRow) {
			if (!$rRow['active'] && $rNow - $rRow['fired_at'] > self::KEEP) {
				unset($rState[$rKey]);
			}
		}
		return [$rState, $rEvents];
	}

	/**
	 * The messages one minute's events make: per rule, one for what fired and
	 * one for what was resolved.
	 *
	 * @param list<array{rule: string, kind: string, subject: string, label: string}> $rEvents
	 * @return list<array{rule: string, kind: string, title: string, text: string, items: list<array{subject: string, label: string}>}>
	 */
	public static function messages(array $rEvents, string $rPanel): array {
		$rGroups = [];
		foreach ($rEvents as $rEvent) {
			if ($rEvent['kind'] !== 'suppressed') {
				$rGroups[$rEvent['rule'] . '|' . $rEvent['kind']][] = $rEvent;
			}
		}
		$rOut = [];
		foreach ($rGroups as $rGroup) {
			[$rRule, $rKind] = [$rGroup[0]['rule'], $rGroup[0]['kind']];
			$rLines = array_map(static fn(array $rEvent): string => '- ' . $rEvent['label'], array_slice($rGroup, 0, self::MAX_ITEMS));
			if (count($rGroup) > self::MAX_ITEMS) {
				$rLines[] = '... and ' . (count($rGroup) - self::MAX_ITEMS) . ' more';
			}
			$rOut[] = [
				'rule' => $rRule,
				'kind' => $rKind,
				'title' => '[' . $rPanel . '] ' . ($rKind === 'fired' ? '' : 'Resolved: ') . self::RULES[$rRule][0] . ' (' . count($rGroup) . ')',
				'text' => implode("\n", $rLines),
				'items' => array_map(static fn(array $rEvent): array => ['subject' => $rEvent['subject'], 'label' => $rEvent['label']], $rGroup),
			];
		}
		return $rOut;
	}

	/** One minute: evaluate, keep the state, send and log the messages. Returns how many messages went out. */
	public static function run(int $rNow): int {
		$db = self::db();
		$rRules = self::rules();
		$rState = [];
		if ($db->query('SELECT * FROM `alert_state`;')) {
			foreach ($db->get_raw_rows() as $rRow) {
				$rState[$rRow['rule'] . '|' . $rRow['subject']] = ['rule' => $rRow['rule'], 'subject' => $rRow['subject'], 'label' => (string) $rRow['label'], 'active' => (int) $rRow['active'], 'since' => (int) $rRow['since'], 'notified' => (int) $rRow['notified'], 'sent' => (int) $rRow['sent'], 'fired_at' => (int) $rRow['fired_at']];
			}
		}
		[$rNew, $rEvents] = self::step($rState, self::conditions($rRules), $rRules, $rNow);
		foreach (array_diff_key($rState, $rNew) as $rRow) {
			$db->query('DELETE FROM `alert_state` WHERE `rule` = ? AND `subject` = ?;', $rRow['rule'], $rRow['subject']);
		}
		foreach ($rNew as $rRow) {
			if (($rState[$rRow['rule'] . '|' . $rRow['subject']] ?? null) !== $rRow) {
				$db->query('REPLACE INTO `alert_state` (`rule`, `subject`, `label`, `active`, `since`, `notified`, `sent`, `fired_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?);', $rRow['rule'], $rRow['subject'], mb_substr($rRow['label'], 0, 255), $rRow['active'], $rRow['since'], $rRow['notified'], $rRow['sent'], $rRow['fired_at']);
			}
		}
		$rSent = 0;
		$rChannels = array_values(array_filter(AlertChannels::all(), static fn(array $rChannel): bool => (bool) $rChannel['enabled']));
		foreach (self::messages($rEvents, (string) (SettingsManager::get('server_name') ?: 'XC_VM')) as $rMessage) {
			$rWanted = $rRules[$rMessage['rule']]['channels'];
			$rResults = self::deliver(array_values(array_filter($rChannels, static fn(array $rChannel): bool => $rWanted === [] || in_array($rChannel['id'], $rWanted, true))), $rMessage, $rNow);
			self::log($rMessage['rule'], $rMessage['kind'], $rMessage['title'], $rMessage['text'], $rResults, $rNow);
			$rSent++;
		}
		return $rSent;
	}

	/**
	 * Send a message on channels.
	 *
	 * @param list<array<string, mixed>> $rChannels
	 * @param array{rule: string, kind: string, title: string, text: string, items?: list<array>} $rMessage
	 * @return array<string, string> channel name => "ok" or the error
	 */
	public static function deliver(array $rChannels, array $rMessage, int $rNow): array {
		$rPayload = ['event' => 'alert', 'state' => $rMessage['kind'], 'rule' => $rMessage['rule'], 'title' => $rMessage['title'], 'text' => $rMessage['text'], 'items' => $rMessage['items'] ?? [], 'panel' => (string) (SettingsManager::get('server_name') ?: 'XC_VM'), 'time' => $rNow];
		$rResults = [];
		foreach ($rChannels as $rChannel) {
			$rResults[$rChannel['name']] = AlertChannels::send($rChannel, $rMessage['title'], $rMessage['text'], $rPayload) ?? 'ok';
		}
		return $rResults;
	}

	/** @param array<string, string> $rResults */
	public static function log(string $rRule, string $rKind, string $rTitle, string $rText, array $rResults, int $rNow): void {
		self::db()->query('INSERT INTO `alert_log` (`date`, `rule`, `kind`, `title`, `text`, `results`) VALUES (?, ?, ?, ?, ?, ?);', $rNow, $rRule, $rKind, mb_substr($rTitle, 0, 255), $rText, json_encode($rResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/** @return list<array<string, mixed>> the latest log rows */
	public static function history(int $rLimit = 50): array {
		self::db()->query('SELECT `date`, `rule`, `kind`, `title`, `text`, `results` FROM `alert_log` ORDER BY `id` DESC LIMIT ' . max(1, min(500, $rLimit)) . ';');
		return self::db()->get_raw_rows();
	}

	/** A test message on one channel, from the page. Null when it went. */
	public static function test(int $rChannelID): ?string {
		$rChannel = array_values(array_filter(AlertChannels::all(), static fn(array $rChannel): bool => $rChannel['id'] === $rChannelID))[0] ?? null;
		if ($rChannel === null) {
			return 'no such channel';
		}
		$rPanel = (string) (SettingsManager::get('server_name') ?: 'XC_VM');
		$rMessage = ['rule' => 'test', 'kind' => 'test', 'title' => '[' . $rPanel . '] Test alert', 'text' => 'This channel works: the panel\'s alerts will arrive here.', 'items' => []];
		$rResults = self::deliver([$rChannel], $rMessage, time());
		self::log('test', 'test', $rMessage['title'], $rMessage['text'], $rResults, time());
		return $rResults[$rChannel['name']] === 'ok' ? null : $rResults[$rChannel['name']];
	}
}
