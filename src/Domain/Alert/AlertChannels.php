<?php

namespace XcVm\Domain\Alert;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Where alerts and reminder mails go (`alert_channels`): a Telegram bot and
 * chat, a webhook (a JSON POST, signed with HMAC-SHA256 when a secret is set),
 * or an SMTP server and recipients. Their secrets live in this MAIN-only
 * table, never in `settings`, which the load balancers' replica carries.
 *
 * @package XC_VM_Domain_Alert
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class AlertChannels {
	use DatabaseAware;

	public const TYPES = ['telegram', 'webhook', 'email'];

	/** Each type's fields; the secret ones are never sent back to the page. */
	public const FIELDS = [
		'telegram' => ['bot_token' => true, 'chat_id' => false],
		'webhook' => ['url' => false, 'secret' => true],
		'email' => ['host' => false, 'port' => false, 'security' => false, 'username' => false, 'password' => true, 'from' => false, 'to' => false],
	];

	private const TIMEOUT = 10;

	/** @return list<array{id: int, type: string, name: string, enabled: int, config: array<string, string>}> */
	public static function all(): array {
		$db = self::db();
		$db->query('SELECT `id`, `type`, `name`, `enabled`, `config` FROM `alert_channels` ORDER BY `id`;');
		return array_map(static fn(array $rRow): array => ['id' => (int) $rRow['id'], 'type' => (string) $rRow['type'], 'name' => (string) $rRow['name'], 'enabled' => (int) $rRow['enabled'], 'config' => json_decode((string) $rRow['config'], true) ?: []], $db->get_raw_rows());
	}

	/** A channel for the page: its secrets replaced by whether they are set. */
	public static function forPage(array $rChannel): array {
		foreach (self::FIELDS[$rChannel['type']] ?? [] as $rField => $rSecret) {
			if ($rSecret) {
				$rChannel['config'][$rField] = ($rChannel['config'][$rField] ?? '') !== '' ? '********' : '';
			}
		}
		return $rChannel;
	}

	/**
	 * Add or change a channel from the page. A secret field left empty, or
	 * sent back masked, keeps the stored one.
	 *
	 * @param array<string, mixed> $rData
	 * @return array{result: bool, error?: string, id?: int}
	 */
	public static function save(array $rData): array {
		$rType = (string) ($rData['type'] ?? '');
		$rName = trim((string) ($rData['name'] ?? ''));
		if (!isset(self::FIELDS[$rType])) {
			return ['result' => false, 'error' => 'type'];
		}
		if ($rName === '' || mb_strlen($rName) > 64) {
			return ['result' => false, 'error' => 'name'];
		}
		$rID = (int) ($rData['id'] ?? 0);
		$rStored = [];
		if ($rID > 0) {
			$rOld = array_values(array_filter(self::all(), static fn(array $rChannel): bool => $rChannel['id'] === $rID))[0] ?? null;
			if ($rOld === null || $rOld['type'] !== $rType) {
				return ['result' => false, 'error' => 'type'];
			}
			$rStored = $rOld['config'];
		}
		$rConfig = [];
		foreach (self::FIELDS[$rType] as $rField => $rSecret) {
			$rValue = trim((string) ($rData[$rField] ?? ''));
			$rConfig[$rField] = $rSecret && ($rValue === '' || $rValue === '********') ? (string) ($rStored[$rField] ?? '') : $rValue;
		}
		if (($rError = self::invalid($rType, $rConfig)) !== null) {
			return ['result' => false, 'error' => $rError];
		}
		$rJSON = json_encode($rConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$rEnabled = empty($rData['enabled']) ? 0 : 1;
		if ($rID > 0) {
			self::db()->query('UPDATE `alert_channels` SET `name` = ?, `enabled` = ?, `config` = ? WHERE `id` = ?;', $rName, $rEnabled, $rJSON, $rID);
			return ['result' => true, 'id' => $rID];
		}
		self::db()->query('INSERT INTO `alert_channels` (`type`, `name`, `enabled`, `config`, `created`) VALUES (?, ?, ?, ?, ?);', $rType, $rName, $rEnabled, $rJSON, time());
		return ['result' => true, 'id' => (int) self::db()->last_insert_id()];
	}

	public static function delete(int $rID): bool {
		self::db()->query('DELETE FROM `alert_channels` WHERE `id` = ?;', $rID);
		return self::db()->num_rows() === 1;
	}

	/** What is wrong with a channel's settings, or null. */
	public static function invalid(string $rType, array $rConfig): ?string {
		switch ($rType) {
			case 'telegram':
				if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $rConfig['bot_token'] ?? '')) {
					return 'bot_token';
				}
				return preg_match('/^(-?\d+|@[A-Za-z0-9_]{5,})$/', $rConfig['chat_id'] ?? '') ? null : 'chat_id';
			case 'webhook':
				$rURL = (string) ($rConfig['url'] ?? '');
				return filter_var($rURL, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $rURL) ? null : 'url';
			case 'email':
				if (!preg_match('/^[A-Za-z0-9.-]+$/', $rConfig['host'] ?? '') || (int) ($rConfig['port'] ?? 0) < 1 || (int) $rConfig['port'] > 65535) {
					return 'host';
				}
				if (!in_array($rConfig['security'] ?? '', SmtpMailer::SECURITY, true)) {
					return 'security';
				}
				if (filter_var($rConfig['from'] ?? '', FILTER_VALIDATE_EMAIL) === false) {
					return 'from';
				}
				return self::recipients((string) ($rConfig['to'] ?? '')) !== [] ? null : 'to';
		}
		return 'type';
	}

	/** @return list<string> the valid addresses of a comma- or space-separated list */
	public static function recipients(string $rList): array {
		return array_values(array_filter(preg_split('/[\s,;]+/', trim($rList), -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn(string $rAddress): bool => filter_var($rAddress, FILTER_VALIDATE_EMAIL) !== false));
	}

	/**
	 * Deliver one message on a channel. Null when it went, else why not.
	 *
	 * @param array{type: string, config: array<string, string>} $rChannel
	 * @param array<string, mixed> $rPayload the webhook's JSON body
	 */
	public static function send(array $rChannel, string $rTitle, string $rText, array $rPayload): ?string {
		$rConfig = $rChannel['config'];
		switch ($rChannel['type']) {
			case 'telegram':
				[$rCode, $rBody] = self::post(TelegramLinks::url($rConfig['bot_token'], 'sendMessage'), http_build_query(['chat_id' => $rConfig['chat_id'], 'text' => $rTitle . "\n" . $rText, 'disable_web_page_preview' => 'true']), ['Content-Type: application/x-www-form-urlencoded']);
				$rAnswer = json_decode((string) $rBody, true);
				return $rCode === 200 && !empty($rAnswer['ok']) ? null : 'Telegram answered ' . ($rAnswer['description'] ?? ('HTTP ' . $rCode));
			case 'webhook':
				$rJSON = (string) json_encode($rPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				$rHeaders = ['Content-Type: application/json', 'X-XCVM-Event: ' . ($rPayload['event'] ?? 'alert')];
				if (($rConfig['secret'] ?? '') !== '') {
					$rHeaders[] = 'X-XCVM-Signature: sha256=' . hash_hmac('sha256', $rJSON, $rConfig['secret']);
				}
				[$rCode] = self::post($rConfig['url'], $rJSON, $rHeaders);
				return $rCode >= 200 && $rCode < 300 ? null : 'the webhook answered HTTP ' . $rCode;
			case 'email':
				return SmtpMailer::send($rConfig, self::recipients((string) $rConfig['to']), $rTitle, $rText);
		}
		return 'unknown channel type';
	}

	/** @return array{0: int, 1: string|false} HTTP status (0: no answer) and body */
	private static function post(string $rURL, string $rBody, array $rHeaders): array {
		$rCurl = curl_init($rURL);
		curl_setopt_array($rCurl, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $rBody,
			CURLOPT_HTTPHEADER => $rHeaders,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
			CURLOPT_TIMEOUT => self::TIMEOUT,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		]);
		$rAnswer = curl_exec($rCurl);
		$rCode = (int) curl_getinfo($rCurl, CURLINFO_RESPONSE_CODE);
		curl_close($rCurl);
		return [$rAnswer === false ? 0 : $rCode, $rAnswer];
	}
}
