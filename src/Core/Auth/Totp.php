<?php

namespace XcVm\Core\Auth;

/**
 * Time-based one-time passwords (RFC 6238): six digits from an HMAC-SHA1 of
 * the 30-second time step, which is what authenticator apps compute from the
 * secret's otpauth:// link. Pure functions; TwoFactor keeps the secrets.
 *
 * @package XC_VM_Core_Auth
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class Totp {
	public const STEP = 30;

	public const DIGITS = 6;

	/** Codes one step either side are taken too: clocks drift and typing takes time. */
	public const WINDOW = 1;

	private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/** A new secret: 160 random bits, base32 as the apps take it. */
	public static function newSecret(): string {
		return self::base32Encode(random_bytes(20));
	}

	/** The code of one time step. */
	public static function code(string $rSecret, int $rStep): string {
		// HMAC-SHA1 is the algorithm every authenticator app computes (RFC 6238's default).
		$rMac = hash_hmac('sha1', pack('J', $rStep), self::base32Decode($rSecret), true);
		$rOffset = ord($rMac[19]) & 0x0f;
		$rValue = unpack('N', substr($rMac, $rOffset, 4))[1] & 0x7fffffff;
		return str_pad((string) ($rValue % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
	}

	/**
	 * The time step $rCode is the code of at $rNow (one step either side), if
	 * that step is later than $rAfter: a code is good once.
	 */
	public static function match(string $rSecret, string $rCode, int $rNow, int $rAfter = 0): ?int {
		if (!preg_match('/^\d{' . self::DIGITS . '}\z/', $rCode)) {
			return null;
		}
		$rStep = intdiv($rNow, self::STEP);
		for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
			if ($rStep + $i > $rAfter && hash_equals(self::code($rSecret, $rStep + $i), $rCode)) {
				return $rStep + $i;
			}
		}
		return null;
	}

	/** The otpauth:// link an authenticator app adds the account from. */
	public static function uri(string $rSecret, string $rAccount, string $rIssuer): string {
		return 'otpauth://totp/' . rawurlencode($rIssuer . ':' . $rAccount) . '?'
			. http_build_query(['secret' => $rSecret, 'issuer' => $rIssuer, 'digits' => self::DIGITS, 'period' => self::STEP], '', '&', PHP_QUERY_RFC3986);
	}

	public static function base32Encode(string $rBytes): string {
		$rBits = '';
		foreach (str_split($rBytes) as $rByte) {
			$rBits .= $rByte === '' ? '' : str_pad(decbin(ord($rByte)), 8, '0', STR_PAD_LEFT);
		}
		$rOut = '';
		foreach ($rBits === '' ? [] : str_split($rBits, 5) as $rChunk) {
			$rOut .= self::BASE32[bindec(str_pad($rChunk, 5, '0'))];
		}
		return $rOut;
	}

	/** Base32 to bytes; spaces, case and padding are ignored, as the apps do. */
	public static function base32Decode(string $rText): string {
		$rBits = '';
		foreach (str_split(strtoupper((string) preg_replace('/[\s=]/', '', $rText))) as $rChar) {
			$rValue = $rChar === '' ? false : strpos(self::BASE32, $rChar);
			if ($rValue !== false) {
				$rBits .= str_pad(decbin($rValue), 5, '0', STR_PAD_LEFT);
			}
		}
		$rOut = '';
		foreach ($rBits === '' ? [] : str_split($rBits, 8) as $rByte) {
			if (strlen($rByte) === 8) {
				$rOut .= chr(bindec($rByte));
			}
		}
		return $rOut;
	}
}
