<?php

/**
 * XC_VM — Encryption Utilities
 *
 * Centralized encryption/decryption, base64url encoding,
 * and random string generation.
 *
 * ---------------------------------------------------------------
 * What it replaces:
 * ---------------------------------------------------------------
 *
 *   CoreUtilities::encryptData()     → Encryption::encrypt()
 *   CoreUtilities::decryptData()     → Encryption::decrypt()
 *   CoreUtilities::base64url_encode() → Encryption::base64urlEncode()
 *   CoreUtilities::base64url_decode() → Encryption::base64urlDecode()
 *   CoreUtilities::generateString()  → Encryption::randomString()
 *
 * @see CoreUtilities::encryptData()
 * @see StreamingUtilities::mc_decrypt()
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
}
