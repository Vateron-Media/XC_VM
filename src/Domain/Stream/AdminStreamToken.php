<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Util\Encryption;
use XcVm\Core\Util\NetworkUtils;

/**
 * AdminStreamToken — decodes and validates the `uitoken` used by admin-UI
 * loopback requests (live / timeshift / thumb / vod) as an alternative to the
 * static `live_streaming_pass`.
 *
 * Pure and testable: explicit inputs only, no superglobals, no $db, never calls
 * exit()/header(). Replaces the auth block that was re-typed with small
 * variations across the four admin stream entry points.
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class AdminStreamToken {

    /**
     * @param int         $streamId  Target stream id.
     * @param string      $ip        Client IP the token was issued to.
     * @param int         $expires   Expiry as a unix timestamp.
     * @param string|null $container VOD container (vod.php only), else null.
     * @param mixed       $start     Timeshift start (raw — may be a date string), else null.
     * @param mixed       $duration  Timeshift duration (raw), else null.
     */
    private function __construct(
        public readonly int $streamId,
        public readonly string $ip,
        public readonly int $expires,
        public readonly ?string $container = null,
        public readonly mixed $start = null,
        public readonly mixed $duration = null,
    ) {
    }

    /**
     * Decrypt + decode a raw uitoken. Returns null on any decrypt/JSON failure
     * or a missing required field — callers treat null exactly like an invalid
     * token (404), matching the previous inline code where a missing field
     * failed the expiry/IP check and fell through to the same 404.
     *
     * `start`/`duration` are kept raw (NOT cast) because timeshift accepts a
     * non-numeric date string there.
     *
     * @param string $rawToken Encrypted token from the request.
     * @param string $key      Decryption key (live_streaming_pass).
     * @return self|null
     */
    public static function decode(string $rawToken, string $key): ?self {
        $rDecrypted = Encryption::decrypt($rawToken, $key, OPENSSL_EXTRA);

        if (!is_string($rDecrypted)) {
            return null;
        }

        $rData = json_decode($rDecrypted, true);

        if (!is_array($rData) || !isset($rData['stream_id'], $rData['ip'], $rData['expires'])) {
            return null;
        }

        return new self(
            (int) $rData['stream_id'],
            (string) $rData['ip'],
            (int) $rData['expires'],
            isset($rData['container']) ? (string) $rData['container'] : null,
            $rData['start'] ?? null,
            $rData['duration'] ?? null,
        );
    }

    /**
     * Not expired and the caller IP matches (subnet-aware per
     * {@see NetworkUtils::ipMatches()}).
     *
     * @param bool        $rSubnetMatch Compare only the leading three octets.
     * @param string|null $rClientIp    Current client IP.
     * @param int|null    $rNow         Reference time (defaults to time()).
     * @return bool
     */
    public function isValid(bool $rSubnetMatch, ?string $rClientIp, ?int $rNow = null): bool {
        return $this->expires >= ($rNow ?? time())
            && NetworkUtils::ipMatches($rSubnetMatch, $this->ip, $rClientIp);
    }
}
