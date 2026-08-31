<?php

declare(strict_types=1);

namespace XcVm\Core\Enum;

/**
 * Client connection rejection reason ("filter") recorded per request.
 *
 * Replaces the legacy `$rClientFilters` global (key => label map) with a
 * string-backed enum. The backing value equals the legacy array key (the
 * `client_status` column persisted in the client request log).
 *
 * @package XC_VM_Core_Enum
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
enum ClientFilter: string {
    case LbTokenInvalid         = 'LB_TOKEN_INVALID';
    case NotInBouquet           = 'NOT_IN_BOUQUET';
    case BlockedAsn             = 'BLOCKED_ASN';
    case IspLockFailed          = 'ISP_LOCK_FAILED';
    case UserDisallowExt        = 'USER_DISALLOW_EXT';
    case AuthFailed             = 'AUTH_FAILED';
    case UserExpired            = 'USER_EXPIRED';
    case UserDisabled           = 'USER_DISABLED';
    case UserBan                = 'USER_BAN';
    case MagTokenInvalid        = 'MAG_TOKEN_INVALID';
    case StalkerChannelMismatch = 'STALKER_CHANNEL_MISMATCH';
    case StalkerIpMismatch      = 'STALKER_IP_MISMATCH';
    case StalkerKeyExpired      = 'STALKER_KEY_EXPIRED';
    case StalkerDecryptFailed   = 'STALKER_DECRYPT_FAILED';
    case EmptyUa                = 'EMPTY_UA';
    case IpBan                  = 'IP_BAN';
    case CountryDisallow        = 'COUNTRY_DISALLOW';
    case UserAgentBan           = 'USER_AGENT_BAN';
    case UserAlreadyConnected   = 'USER_ALREADY_CONNECTED';
    case RestreamDetect         = 'RESTREAM_DETECT';
    case ProxyDetect            = 'PROXY_DETECT';
    case HostingDetect          = 'HOSTING_DETECT';
    case LineCreateFail         = 'LINE_CREATE_FAIL';
    case ConnectionLoop         = 'CONNECTION_LOOP';
    case TokenExpired           = 'TOKEN_EXPIRED';
    case IpMismatch             = 'IP_MISMATCH';

    /**
     * Human-readable filter label (as shown in the client request log).
     */
    public function label(): string {
        return match ($this) {
            self::LbTokenInvalid         => 'Token Failure',
            self::NotInBouquet           => 'Not in Bouquet',
            self::BlockedAsn             => 'Blocked ASN',
            self::IspLockFailed          => 'ISP Lock Failed',
            self::UserDisallowExt        => 'Extension Disallowed',
            self::AuthFailed             => 'Authentication Failed',
            self::UserExpired            => 'User Expired',
            self::UserDisabled           => 'User Disabled',
            self::UserBan                => 'User Banned',
            self::MagTokenInvalid        => 'MAG Token Invalid',
            self::StalkerChannelMismatch => 'Stalker Channel Mismatch',
            self::StalkerIpMismatch      => 'Stalker IP Mismatch',
            self::StalkerKeyExpired      => 'Stalker Key Expired',
            self::StalkerDecryptFailed   => 'Stalker Decrypt Failed',
            self::EmptyUa                => 'Empty User-Agent',
            self::IpBan                  => 'IP Banned',
            self::CountryDisallow        => 'Country Disallowed',
            self::UserAgentBan           => 'User-Agent Disallowed',
            self::UserAlreadyConnected   => 'IP Limit Reached',
            self::RestreamDetect         => 'Restream Detected',
            self::ProxyDetect            => 'Proxy / VPN Detected',
            self::HostingDetect          => 'Hosting Server Detected',
            self::LineCreateFail         => 'Connection Failed',
            self::ConnectionLoop         => 'Connection Loop',
            self::TokenExpired           => 'Token Expired',
            self::IpMismatch             => 'IP Mismatch',
        };
    }

    /**
     * Resolve a raw status key to its label, falling back to the raw key
     * itself when it is unknown (tolerant lookup for log rendering).
     */
    public static function labelFor(string $key): string {
        return self::tryFrom($key)?->label() ?? $key;
    }

    /**
     * Ordered key => label map for building select/filter dropdowns,
     * preserving the legacy `$rClientFilters` ordering.
     *
     * @return array<string, string>
     */
    public static function options(): array {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }
}
