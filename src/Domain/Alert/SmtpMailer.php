<?php

namespace XcVm\Domain\Alert;

/**
 * A small SMTP client for the alert and reminder mails: one plain-text
 * message to a few recipients over SMTP with implicit TLS (465), STARTTLS
 * (587) or none, AUTH LOGIN when a username is set. No library: the panel's
 * PHP has no mailer and no local MTA.
 *
 * @package XC_VM_Domain_Alert
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class SmtpMailer {
	public const SECURITY = ['ssl', 'starttls', 'none'];

	/** @var resource|null */
	private $rSocket = null;

	/**
	 * Send one message. Null when the server took it, else what went wrong.
	 *
	 * @param array<string, string|bool> $rConfig an e-mail channel's settings: host, port, security, username, password, from
	 * @param list<string> $rTo
	 */
	public static function send(array $rConfig, array $rTo, string $rSubject, string $rBody, int $rTimeout = 15): ?string {
		return (new self())->deliver($rConfig, $rTo, $rSubject, $rBody, $rTimeout);
	}

	/** The message as it goes after DATA: headers, a blank line, the body with CRLF line ends and leading dots doubled. */
	public static function message(string $rFrom, array $rTo, string $rSubject, string $rBody, int $rNow): string {
		$rSubject = self::oneLine($rSubject);
		$rHeaders = [
			'From: ' . $rFrom,
			'To: ' . implode(', ', $rTo),
			'Subject: ' . (preg_match('/[^\x20-\x7e]/', $rSubject) ? '=?UTF-8?B?' . base64_encode($rSubject) . '?=' : $rSubject),
			'Date: ' . gmdate('D, d M Y H:i:s', $rNow) . ' +0000',
			'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::domainOf($rFrom) . '>',
			'MIME-Version: 1.0',
			'Content-Type: text/plain; charset=UTF-8',
			'Content-Transfer-Encoding: 8bit',
		];
		$rLines = preg_split('/\r\n|\r|\n/', $rBody) ?: [];
		$rLines = array_map(static fn(string $rLine): string => str_starts_with($rLine, '.') ? '.' . $rLine : $rLine, $rLines);
		return implode("\r\n", $rHeaders) . "\r\n\r\n" . implode("\r\n", $rLines) . "\r\n";
	}

	private function deliver(array $rConfig, array $rTo, string $rSubject, string $rBody, int $rTimeout): ?string {
		$rFrom = self::oneLine((string) ($rConfig['from'] ?? ''));
		$rTo = array_values(array_filter(array_map([self::class, 'oneLine'], $rTo), static fn(string $rAddress): bool => filter_var($rAddress, FILTER_VALIDATE_EMAIL) !== false));
		if (filter_var($rFrom, FILTER_VALIDATE_EMAIL) === false || $rTo === []) {
			return 'a sender and at least one recipient address are needed';
		}
		$rSecurity = in_array($rConfig['security'] ?? '', self::SECURITY, true) ? $rConfig['security'] : 'starttls';
		$rHost = (string) ($rConfig['host'] ?? '');
		$rPort = (int) ($rConfig['port'] ?? 0);
		$rContext = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $rHost]]);
		$rRemote = ($rSecurity === 'ssl' ? 'ssl://' : 'tcp://') . $rHost . ':' . $rPort;
		$rSocket = @stream_socket_client($rRemote, $rCode, $rMessage, $rTimeout, STREAM_CLIENT_CONNECT, $rContext);
		if (!is_resource($rSocket)) {
			return 'cannot connect to ' . $rHost . ':' . $rPort . ($rMessage !== '' ? ' (' . $rMessage . ')' : '');
		}
		$this->rSocket = $rSocket;
		stream_set_timeout($rSocket, $rTimeout);
		try {
			$this->expect(null, 220);
			$rHelo = 'EHLO ' . (gethostname() ?: 'xcvm');
			$this->expect($rHelo, 250);
			if ($rSecurity === 'starttls') {
				$this->expect('STARTTLS', 220);
				if (!@stream_socket_enable_crypto($rSocket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
					return 'STARTTLS failed';
				}
				$this->expect($rHelo, 250);
			}
			if ((string) ($rConfig['username'] ?? '') !== '') {
				$this->expect('AUTH LOGIN', 334);
				$this->expect(base64_encode((string) $rConfig['username']), 334, true);
				$this->expect(base64_encode((string) ($rConfig['password'] ?? '')), 235, true);
			}
			$this->expect('MAIL FROM:<' . $rFrom . '>', 250);
			foreach ($rTo as $rAddress) {
				$this->expect('RCPT TO:<' . $rAddress . '>', [250, 251]);
			}
			$this->expect('DATA', 354);
			$this->expect(self::message($rFrom, $rTo, $rSubject, $rBody, time()) . '.', 250);
			$this->expect('QUIT', 221);
			return null;
		} catch (\RuntimeException $e) {
			return $e->getMessage();
		} finally {
			@fclose($rSocket);
			$this->rSocket = null;
		}
	}

	/**
	 * Send a command (null: none, read the greeting) and read the reply; a
	 * secret command (a login) is not repeated in the error.
	 *
	 * @param int|list<int> $rWant
	 */
	private function expect(?string $rCommand, int|array $rWant, bool $rSecret = false): void {
		if ($rCommand !== null && @fwrite($this->rSocket, $rCommand . "\r\n") === false) {
			throw new \RuntimeException('the connection closed');
		}
		$rReply = '';
		while (($rLine = fgets($this->rSocket, 1024)) !== false) {
			$rReply .= $rLine;
			if (strlen($rLine) < 4 || $rLine[3] !== '-') {
				break;
			}
		}
		$rCode = (int) substr($rReply, 0, 3);
		if (!in_array($rCode, (array) $rWant, true)) {
			$rAsked = $rCommand === null ? 'the greeting' : ($rSecret ? 'the login' : strtok($rCommand, "\r\n"));
			throw new \RuntimeException(($rReply === '' ? 'no answer' : 'answer ' . trim($rReply)) . ' to ' . $rAsked);
		}
	}

	/** Strip line breaks: an address or subject cannot add headers or commands. */
	private static function oneLine(string $rText): string {
		return trim((string) preg_replace('/[\r\n]+/', ' ', $rText));
	}

	private static function domainOf(string $rAddress): string {
		$rAt = strrchr($rAddress, '@');
		return $rAt !== false && strlen($rAt) > 1 ? substr($rAt, 1) : 'xcvm.local';
	}
}
