<?php

namespace XcVm\Domain\Alert;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Subscribers' Telegram chats, for the expiry reminders.
 *
 * A Telegram bot cannot write to a username: only to a chat, once the person
 * has written to the bot. So a line (and its MAG or Enigma2 device) carries
 * the subscriber's Telegram username (`lines.telegram`), the subscriber sends
 * /start to the bot of the channel set in Settings → Expiry Reminders, and
 * cron:alerts reads the bot's messages each minute (getUpdates) and keeps
 * username → chat (`telegram_chats`). /stop forgets it. The bot answers both
 * the same whether or not a line has that username, so nobody can learn which
 * usernames are registered.
 *
 * @package XC_VM_Domain_Alert
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class TelegramLinks {
	use DatabaseAware;

	public const START_REPLY = 'Thank you. If your provider has added your Telegram username to your subscription, you will get a message here before it ends. Send /stop to stop them.';

	public const STOP_REPLY = 'You will not get messages here any more. Send /start to get them again.';

	/** The Bot API's address (a test points it at its own server). */
	private static string $rApi = 'https://api.telegram.org';

	public static function useApi(?string $rBase): void {
		self::$rApi = $rBase ?? 'https://api.telegram.org';
	}

	/** A Bot API method's URL for a bot. */
	public static function url(string $rToken, string $rMethod): string {
		return self::$rApi . '/bot' . $rToken . '/' . $rMethod;
	}

	/** A Telegram username as kept: without "@", lowercase, 5 to 32 letters, digits or "_"; null when it is not one. */
	public static function username(?string $rText): ?string {
		$rName = strtolower(ltrim(trim((string) $rText), '@'));
		return preg_match('/^[a-z][a-z0-9_]{4,31}$/', $rName) ? $rName : null;
	}

	/** @return array<string, string> username => chat id, of the usernames asked */
	public static function chats(array $rUsernames): array {
		$rUsernames = array_values(array_unique(array_filter($rUsernames)));
		if ($rUsernames === []) {
			return [];
		}
		$db = self::db();
		$db->query('SELECT `username`, `chat_id` FROM `telegram_chats` WHERE `username` IN (' . implode(',', array_fill(0, count($rUsernames), '?')) . ');', ...$rUsernames);
		return array_column($db->get_raw_rows(), 'chat_id', 'username');
	}

	/**
	 * Take what was written to the bot since the last read: /start keeps the
	 * sender's chat under their username, /stop forgets it. Private chats only.
	 *
	 * @param array{id: int, config: array<string, string>} $rChannel a Telegram alert channel
	 * @return int messages read
	 */
	public static function poll(array $rChannel, int $rNow): int {
		$rToken = (string) ($rChannel['config']['bot_token'] ?? '');
		$rOffsetFile = CACHE_TMP_PATH . 'telegram_offset_' . (int) $rChannel['id'];
		$rOffset = (int) @file_get_contents($rOffsetFile);
		$rUpdates = self::api($rToken, 'getUpdates', ['offset' => $rOffset, 'timeout' => 0, 'allowed_updates' => '["message"]']);
		if (!is_array($rUpdates)) {
			return 0;
		}
		foreach ($rUpdates as $rUpdate) {
			$rOffset = max($rOffset, (int) ($rUpdate['update_id'] ?? 0) + 1);
			$rMessage = $rUpdate['message'] ?? null;
			if (!is_array($rMessage) || ($rMessage['chat']['type'] ?? '') !== 'private') {
				continue;
			}
			$rChat = (string) ($rMessage['chat']['id'] ?? '');
			$rName = self::username($rMessage['from']['username'] ?? null);
			$rCommand = strtolower((string) strtok(trim((string) ($rMessage['text'] ?? '')), " @\n"));
			if ($rCommand === '/stop') {
				self::db()->query('DELETE FROM `telegram_chats` WHERE `chat_id` = ?;', $rChat);
				self::api($rToken, 'sendMessage', ['chat_id' => $rChat, 'text' => self::STOP_REPLY]);
			} elseif ($rCommand === '/start' && $rName !== null) {
				self::db()->query('REPLACE INTO `telegram_chats` (`username`, `chat_id`, `updated`) VALUES (?, ?, ?);', $rName, $rChat, $rNow);
				self::api($rToken, 'sendMessage', ['chat_id' => $rChat, 'text' => self::START_REPLY]);
			}
		}
		@file_put_contents($rOffsetFile, (string) $rOffset, LOCK_EX);
		return count($rUpdates);
	}

	/** Send to a chat through a channel's bot. Null when it went. */
	public static function send(array $rChannel, string $rChat, string $rText): ?string {
		$rAnswer = self::api((string) ($rChannel['config']['bot_token'] ?? ''), 'sendMessage', ['chat_id' => $rChat, 'text' => $rText, 'disable_web_page_preview' => 'true']);
		return $rAnswer !== null ? null : 'Telegram refused the message';
	}

	/** A Bot API call's `result`, or null. */
	private static function api(string $rToken, string $rMethod, array $rFields): mixed {
		if ($rToken === '') {
			return null;
		}
		$rCurl = curl_init(self::url($rToken, $rMethod));
		curl_setopt_array($rCurl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($rFields), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 10]);
		$rBody = curl_exec($rCurl);
		curl_close($rCurl);
		$rJSON = json_decode((string) $rBody, true);
		return is_array($rJSON) && !empty($rJSON['ok']) ? ($rJSON['result'] ?? true) : null;
	}
}
