<?php

namespace XcVm\Core\Util;

/**
 * Encryption Utilities
 *
 * Centralized encryption/decryption, base64url encoding,
 * and random string generation.
 *
 * @see StreamingUtilities::mc_decrypt()
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class Encryption {

    /**
     * Encrypt data using AES-256-CBC
     *
     * @param string $data Data to encrypt
     * @param string $key Encryption key (e.g., live_streaming_pass)
     * @param string $deviceId Device/context identifier
     * @return string Base64url-encoded encrypted data
     */
    public static function encrypt($data, $key, $deviceId) {
        $derivedKey = md5(sha1($deviceId) . $key);
        $iv = substr(md5(sha1($key)), 0, 16);

        $encrypted = openssl_encrypt(
            $data,
            'aes-256-cbc',
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        return self::base64urlEncode($encrypted);
    }

    /**
     * Decrypt data using AES-256-CBC
     *
     * @param string $data Base64url-encoded encrypted data
     * @param string $key Decryption key
     * @param string $deviceId Device/context identifier
     * @return string|false Decrypted data or false on failure
     */
    public static function decrypt($data, $key, $deviceId) {
        $derivedKey = md5(sha1($deviceId) . $key);
        $iv = substr(md5(sha1($key)), 0, 16);

        return openssl_decrypt(
            self::base64urlDecode($data),
            'aes-256-cbc',
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv
        );
    }

    /**
     * Seal $data as a token that cannot be read or altered without the key.
     *
     * AES-256-GCM under a key derived from $key and $deviceId, with a fresh
     * random nonce: base64url(nonce ‖ ciphertext ‖ tag). Same URL-safe alphabet
     * as encrypt(), so no route or pattern that carries a token changes.
     *
     * encrypt() is AES-CBC with a fixed IV and no MAC. A modified token decrypts
     * to modified bytes, and whether its padding holds shows in the response —
     * with patience, enough to read someone's token or to write one. Stream links
     * that the server trusts as they stand (live/vod token data, HLS segment and
     * key tokens, the web player's proxy URL) must be sealed.
     *
     * @param string $data     Plaintext.
     * @param string $key      Secret (live_streaming_pass).
     * @param string $deviceId Context string, as for encrypt().
     * @return string
     */
    public static function seal($data, $key, $deviceId) {
        $rNonce = random_bytes(self::SEAL_NONCE);
        $rTag = '';
        $rCipher = openssl_encrypt((string) $data, 'aes-256-gcm', self::sealKey($key, $deviceId), OPENSSL_RAW_DATA, $rNonce, $rTag, '', self::SEAL_TAG);
        return self::base64urlEncode($rNonce . $rCipher . $rTag);
    }

    /**
     * Open a token made by seal().
     *
     * @return string|false The plaintext, or false for anything that is not a
     *                      token sealed with this key and context — an altered
     *                      one, a legacy encrypt() token, garbage, a non-string.
     */
    public static function open($token, $key, $deviceId) {
        if (!is_string($token) || $token === '') {
            return false;
        }
        $rRaw = self::base64urlDecode($token);
        if (!is_string($rRaw) || strlen($rRaw) < self::SEAL_NONCE + self::SEAL_TAG) {
            return false;
        }
        return openssl_decrypt(
            substr($rRaw, self::SEAL_NONCE, -self::SEAL_TAG),
            'aes-256-gcm',
            self::sealKey($key, $deviceId),
            OPENSSL_RAW_DATA,
            substr($rRaw, 0, self::SEAL_NONCE),
            substr($rRaw, -self::SEAL_TAG)
        );
    }

    /**
     * Make a stream-link token: sealed when $rSealed (the secure_stream_tokens
     * setting), otherwise the legacy format servers on an older version read.
     */
    public static function mintToken($data, $key, $deviceId, bool $rSealed) {
        return $rSealed ? self::seal($data, $key, $deviceId) : self::encrypt($data, $key, $deviceId);
    }

    /**
     * Read a stream-link token: a sealed one always; a legacy one only where
     * $rAcceptLegacy — the secure_stream_tokens setting is off, or the token
     * only carries credentials the caller checks against the database again.
     *
     * @return string|false
     */
    public static function readToken($token, $key, $deviceId, bool $rAcceptLegacy) {
        $rPlain = self::open($token, $key, $deviceId);
        if ($rPlain !== false || !$rAcceptLegacy || !is_string($token)) {
            return $rPlain;
        }
        return self::decrypt($token, $key, $deviceId);
    }

    private const SEAL_NONCE = 12;
    private const SEAL_TAG = 16;

    /** The sealing key: an HMAC of the context under the secret, so an empty secret never throws. */
    private static function sealKey($key, $deviceId) {
        return hash_hmac('sha256', 'xc_vm stream token v2|' . $deviceId, (string) $key, true);
    }

    /**
     * Base64url encode (URL-safe base64 without padding)
     *
     * @param string $data Raw data
     * @return string Encoded string
     */
    public static function base64urlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url decode
     *
     * @param string $data Encoded string
     * @return string|false Decoded data
     */
    public static function base64urlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Generate a random alphanumeric string
     *
     * @param int $length String length (default: 10)
     * @return string
     */
    public static function randomString($length = 10) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($chars) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * Generate a secure random token (hex-encoded)
     *
     * @param int $bytes Number of random bytes (default: 32 = 64 hex chars)
     * @return string Hex-encoded token
     */
    public static function randomToken($bytes = 32) {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Generate a random AES key
     *
     * @param int $bits Key size in bits (128, 192, or 256)
     * @return string Raw key bytes
     */
    public static function generateKey($bits = 128) {
        return openssl_random_pseudo_bytes($bits / 8);
    }

    /**
     * Generate a random IV for AES-128-CBC
     *
     * @param string $cipher OpenSSL cipher (default: AES-128-CBC)
     * @return string Raw IV bytes
     */
    public static function generateIV($cipher = 'AES-128-CBC') {
        $ivSize = openssl_cipher_iv_length($cipher);
        return openssl_random_pseudo_bytes($ivSize);
    }

    /**
     * Генерирует уникальный код панели на основе пароля.
     *
     * @param string $pass  Пароль (live_streaming_pass)
     * @return string 15-символьный хеш
     */
    public static function generateUniqueCode($pass) {
        return substr(md5($pass ?? ''), 0, 15);
    }
}
