<?php

namespace XcVm\Domain\Alert;

use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Expiry reminders (cron:reminders, daily on MAIN; Settings → General →
 * Expiry Reminders): lines that expire within the reminder days, enabled and
 * not trials, each reminded once per expiry date and threshold.
 *
 * - E-mail: each line's owner (reseller or admin) with an address gets one
 *   digest, sent through the first enabled e-mail alert channel's server.
 * - MAG: the line's MAG device gets an on-screen message.
 * - Telegram: a line with a Telegram username whose subscriber wrote /start
 *   to the bot of the channel chosen gets the message there (TelegramLinks).
 * - Webhook: every enabled webhook channel gets one `lines.expiring` POST
 *   listing them, for a billing system.
 *
 * A line is reminded at the smallest of the days its time left fits: one 2
 * days from its end when 7,3,1 are set gets the 3-day reminder only. A
 * renewal (another expiry date) starts its reminders again.
 *
 * @package XC_VM_Domain_Alert
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ExpiryReminders {
	use DatabaseAware;

	/** The subscriber's message (MAG, Telegram): {date} its end, {line} the line's username. */
	public const DEFAULT_MESSAGE = 'Your subscription ends on {date}. Contact your provider to renew it.';

	/** Reminders kept after a line's expiry, to know what was sent. */
	private const KEEP = 30 * 86400;

	/** @return list<int> the reminder days of the setting, 1 to 60, largest first */
	public static function days(string $rSetting): array {
		$rDays = array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', $rSetting, -1, PREG_SPLIT_NO_EMPTY) ?: []), static fn(int $rDay): bool => $rDay >= 1 && $rDay <= 60));
		rsort($rDays);
		return $rDays;
	}

	/** The reminder a line is due: the smallest of the days its time left fits, or null. Pure. */
	public static function bucket(int $rExpires, int $rNow, array $rDays): ?int {
		$rLeft = $rExpires - $rNow;
		if ($rLeft <= 0 || $rDays === []) {
			return null;
		}
		$rFit = array_filter($rDays, static fn(int $rDay): bool => $rDay * 86400 >= $rLeft);
		return $rFit === [] ? null : min($rFit);
	}

	/**
	 * The lines due a reminder now, not yet reminded at that threshold.
	 *
	 * @param list<int> $rDays
	 * @return list<array{id: int, username: string, member_id: int, exp_date: int, is_mag: int, telegram: ?string, days: int}>
	 */
	public static function due(int $rNow, array $rDays): array {
		if ($rDays === []) {
			return [];
		}
		$db = self::db();
		$db->query('SELECT `id`, `username`, `member_id`, `exp_date`, `is_mag`, `telegram` FROM `lines` WHERE `exp_date` > ? AND `exp_date` <= ? AND `enabled` = 1 AND `admin_enabled` = 1 AND `is_trial` = 0;', $rNow, $rNow + max($rDays) * 86400);
		$rLines = $db->get_raw_rows();
		$rSent = self::sent($rNow);
		$rOut = [];
		foreach ($rLines as $rRow) {
			$rBucket = self::bucket((int) $rRow['exp_date'], $rNow, $rDays);
			if ($rBucket !== null && !isset($rSent[$rRow['id'] . ':' . $rRow['exp_date'] . ':' . $rBucket])) {
				$rOut[] = ['id' => (int) $rRow['id'], 'username' => (string) $rRow['username'], 'member_id' => (int) $rRow['member_id'], 'exp_date' => (int) $rRow['exp_date'], 'is_mag' => (int) $rRow['is_mag'], 'telegram' => TelegramLinks::username($rRow['telegram'] ?? null), 'days' => $rBucket];
			}
		}
		return $rOut;
	}

	/** @return array<string, true> "line:exp_date:days" of the reminders sent */
	private static function sent(int $rNow): array {
		$db = self::db();
		$db->query('SELECT `line_id`, `exp_date`, `days` FROM `line_reminders` WHERE `exp_date` > ?;', $rNow);
		$rOut = [];
		foreach ($db->get_raw_rows() as $rRow) {
			$rOut[$rRow['line_id'] . ':' . $rRow['exp_date'] . ':' . $rRow['days']] = true;
		}
		return $rOut;
	}

	/**
	 * Send today's reminders and record them.
	 *
	 * @return array{lines: int, emails: int, mag: int, telegram: int, webhooks: int}
	 */
	public static function run(int $rNow): array {
		$rDone = ['lines' => 0, 'emails' => 0, 'mag' => 0, 'telegram' => 0, 'webhooks' => 0];
		$rDue = self::due($rNow, self::days((string) SettingsManager::get('reminders_days')));
		if ($rDue !== []) {
			$rPanel = (string) (SettingsManager::get('server_name') ?: 'XC_VM');
			$rChannels = array_values(array_filter(AlertChannels::all(), static fn(array $rChannel): bool => (bool) $rChannel['enabled']));
			$rResults = [];
			if (!empty(SettingsManager::get('reminders_email'))) {
				$rDone['emails'] = self::emails($rDue, $rChannels, $rPanel, $rResults);
			}
			if (!empty(SettingsManager::get('reminders_mag'))) {
				$rDone['mag'] = self::mag($rDue, $rNow);
			}
			if (($rBot = self::bot()) !== null) {
				$rDone['telegram'] = self::telegram($rDue, $rBot, $rResults);
			}
			if (!empty(SettingsManager::get('reminders_webhook'))) {
				$rDone['webhooks'] = self::webhooks($rDue, $rChannels, $rPanel, $rNow, $rResults);
			}
			foreach ($rDue as $rLine) {
				self::db()->query('INSERT IGNORE INTO `line_reminders` (`line_id`, `exp_date`, `days`, `sent`) VALUES (?, ?, ?, ?);', $rLine['id'], $rLine['exp_date'], $rLine['days'], $rNow);
			}
			$rDone['lines'] = count($rDue);
			Alerts::log('reminders', 'sent', '[' . $rPanel . '] Expiry reminders: ' . count($rDue) . ' line(s)', 'E-mails: ' . $rDone['emails'] . ', MAG messages: ' . $rDone['mag'] . ', Telegram messages: ' . $rDone['telegram'] . ', webhooks: ' . $rDone['webhooks'], $rResults, $rNow);
		}
		self::db()->query('DELETE FROM `line_reminders` WHERE `exp_date` < ?;', $rNow - self::KEEP);
		return $rDone;
	}

	/** One digest per owner with an address, through the first enabled e-mail channel's server. */
	private static function emails(array $rDue, array $rChannels, string $rPanel, array &$rResults): int {
		$rServer = array_values(array_filter($rChannels, static fn(array $rChannel): bool => $rChannel['type'] === 'email'))[0] ?? null;
		if ($rServer === null) {
			$rResults['e-mail'] = 'no enabled e-mail channel';
			return 0;
		}
		$rByOwner = [];
		foreach ($rDue as $rLine) {
			$rByOwner[$rLine['member_id']][] = $rLine;
		}
		$db = self::db();
		$db->query('SELECT `id`, `email` FROM `users` WHERE `id` IN (' . implode(',', array_map('intval', array_keys($rByOwner))) . ');');
		$rSent = 0;
		foreach ($db->get_raw_rows() as $rOwner) {
			$rAddress = trim((string) $rOwner['email']);
			if (filter_var($rAddress, FILTER_VALIDATE_EMAIL) === false) {
				continue;
			}
			$rLines = array_map(static fn(array $rLine): string => '- ' . $rLine['username'] . ': ' . date('Y-m-d H:i', $rLine['exp_date']) . ' (' . $rLine['days'] . ' day' . ($rLine['days'] > 1 ? 's' : '') . ')', $rByOwner[(int) $rOwner['id']]);
			$rError = SmtpMailer::send($rServer['config'], [$rAddress], '[' . $rPanel . '] ' . count($rLines) . ' line(s) expire soon', "These lines expire soon:\n\n" . implode("\n", $rLines) . "\n");
			$rResults['e-mail ' . $rAddress] = $rError ?? 'ok';
			$rSent += $rError === null ? 1 : 0;
		}
		return $rSent;
	}

	/** An on-screen message to the MAG device of each MAG line. */
	private static function mag(array $rDue, int $rNow): int {
		$rLines = array_column(array_filter($rDue, static fn(array $rLine): bool => $rLine['is_mag'] === 1), 'exp_date', 'id');
		if ($rLines === []) {
			return 0;
		}
		$db = self::db();
		$db->query('SELECT `mag_id`, `user_id` FROM `mag_devices` WHERE `user_id` IN (' . implode(',', array_map('intval', array_keys($rLines))) . ');');
		$rNames = array_column($rDue, 'username', 'id');
		$rSent = 0;
		foreach ($db->get_raw_rows() as $rDevice) {
			$rMessage = self::text((int) $rLines[(int) $rDevice['user_id']], (string) $rNames[(int) $rDevice['user_id']]);
			$db->query('INSERT INTO `mag_events`(`status`, `mag_device_id`, `event`, `need_confirm`, `msg`, `reboot_after_ok`, `send_time`) VALUES (0, ?, ?, ?, ?, ?, ?);', (int) $rDevice['mag_id'], 'send_msg', 1, $rMessage, 0, $rNow);
			$rSent++;
		}
		return $rSent;
	}

	/** The subscriber's message for a line. */
	public static function text(int $rExpires, string $rLine): string {
		$rText = trim((string) SettingsManager::get('reminders_message')) ?: self::DEFAULT_MESSAGE;
		return strtr($rText, ['{date}' => date('Y-m-d', $rExpires), '{line}' => $rLine]);
	}

	/** The enabled Telegram channel whose bot writes to subscribers (reminders_telegram), or null. */
	public static function bot(): ?array {
		$rID = (int) SettingsManager::get('reminders_telegram');
		foreach ($rID > 0 ? AlertChannels::all() : [] as $rChannel) {
			if ($rChannel['id'] === $rID && $rChannel['type'] === 'telegram' && $rChannel['enabled']) {
				return $rChannel;
			}
		}
		return null;
	}

	/** Each line's subscriber who wrote /start to the bot, under the line's Telegram username. */
	private static function telegram(array $rDue, array $rBot, array &$rResults): int {
		$rChats = TelegramLinks::chats(array_column($rDue, 'telegram'));
		$rSent = $rFailed = 0;
		foreach ($rDue as $rLine) {
			$rChat = $rChats[$rLine['telegram'] ?? ''] ?? null;
			if ($rChat === null) {
				continue;
			}
			if (TelegramLinks::send($rBot, (string) $rChat, self::text($rLine['exp_date'], $rLine['username'])) === null) {
				$rSent++;
			} else {
				$rFailed++;
			}
		}
		$rResults[$rBot['name']] = $rFailed === 0 ? 'ok' : $rFailed . ' message(s) refused';
		return $rSent;
	}

	/** One `lines.expiring` POST to every enabled webhook channel. */
	private static function webhooks(array $rDue, array $rChannels, string $rPanel, int $rNow, array &$rResults): int {
		$rPayload = [
			'event' => 'lines.expiring',
			'lines' => array_map(static fn(array $rLine): array => ['id' => $rLine['id'], 'username' => $rLine['username'], 'owner_id' => $rLine['member_id'], 'exp_date' => $rLine['exp_date'], 'days' => $rLine['days']], $rDue),
			'panel' => $rPanel,
			'time' => $rNow,
		];
		$rSent = 0;
		foreach ($rChannels as $rChannel) {
			if ($rChannel['type'] === 'webhook') {
				$rError = AlertChannels::send($rChannel, 'lines.expiring', '', $rPayload);
				$rResults[$rChannel['name']] = $rError ?? 'ok';
				$rSent += $rError === null ? 1 : 0;
			}
		}
		return $rSent;
	}
}
