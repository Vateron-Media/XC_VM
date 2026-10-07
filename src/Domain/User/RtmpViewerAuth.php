<?php

namespace XcVm\Domain\User;

use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\GeoIP\GeoIPService;
use XcVm\Core\Logging\DatabaseLogger;
use XcVm\Core\Util\Encryption;
use XcVm\Domain\Cluster\ConnectionAdmission;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Streaming\Delivery\StreamRedirector;

/**
 * An RTMP viewer's line, checked on MAIN: by rtmp.php there, and by the
 * cluster API's `rtmp_auth` for a load balancer, which has no line lookup
 * (ADR 0004). The checks rtmp.php has always made, in its order, for the
 * server the viewer is connected to; a refusal is logged here as rtmp.php
 * logged it. The caller then records the viewer on that server.
 */
final class RtmpViewerAuth {
	use DatabaseAware;

	/** Credentials that name no line: the one refusal the caller's flood guard counts. */
	public const AUTH_FAILED = 'AUTH_FAILED';

	/** No server of the stream's may take the viewer here: not logged, as rtmp.php never logged it. */
	public const NO_SERVER = 'NO_SERVER';

	/**
	 * @param array<string, mixed> $rSettings
	 * @param array<mixed>|null $rBouquets The bouquet map, or null for the lookup to read it.
	 * @param array<int, array<string, mixed>>|null $rServers The servers, or null to read them once the line passed.
	 * @param array{token?: string, username?: string, password?: string} $rRequest The link's credentials, plain strings.
	 * @param int $rServerID The server the viewer is connected to.
	 * @return array{ok: true, user: array{id: int, max_connections: int, pair_id: int|null, con_isp_name: string, is_restreamer: int}, country_code: string, channel: array<string, mixed>}|array{ok: false, reason: string}
	 *         `channel` is the stream on the viewer's server as auth.php puts it in a token's
	 *         channel_info, for MAIN's mint (rtmp_auth); it never leaves MAIN.
	 */
	public static function check(array $rSettings, bool $rCached, ?array $rBouquets, ?array $rServers, int $rStreamID, string $rIP, array $rRequest, bool $rRestreamDetect, int $rServerID): array {
		if (isset($rRequest['token'])) {
			if (!ctype_xdigit($rRequest['token'])) {
				$rTokenData = explode('/', (string) Encryption::readToken($rRequest['token'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA, true));
				list($rUsername, $rPassword) = array_pad($rTokenData, 2, null);
				$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rUsername, $rPassword, true, false, $rIP);
			} else {
				$rAccessToken = $rRequest['token'];
				$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rAccessToken, null, true, false, $rIP);
			}
		} else {
			$rUsername = $rRequest['username'] ?? '';
			$rPassword = $rRequest['password'] ?? '';
			$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rUsername, $rPassword, true, false, $rIP);
		}

		if (!$rUserInfo) {
			if (isset($rUsername)) {
				BruteforceGuard::checkBruteforce($rIP, null, $rUsername, false, $rPassword ?? null);
			}

			DatabaseLogger::clientLog($rStreamID, 0, 'AUTH_FAILED', $rIP);

			return ['ok' => false, 'reason' => self::AUTH_FAILED];
		}

		$rUserID = (int) $rUserInfo['id'];
		$rRefuse = static function (string $rReason, string $rData = '', bool $rBypass = false) use ($rStreamID, $rUserID, $rIP): array {
			DatabaseLogger::clientLog($rStreamID, $rUserID, $rReason, $rIP, $rData, $rBypass);

			return ['ok' => false, 'reason' => $rReason];
		};

		if (!is_null($rUserInfo['exp_date']) && $rUserInfo['exp_date'] <= time()) {
			return $rRefuse('USER_EXPIRED');
		}
		if ($rUserInfo['admin_enabled'] == 0) {
			return $rRefuse('USER_BAN');
		}
		if ($rUserInfo['enabled'] == 0) {
			return $rRefuse('USER_DISABLED');
		}
		if (!empty($rUserInfo['allowed_ips']) && !in_array($rIP, array_map('gethostbyname', $rUserInfo['allowed_ips']))) {
			return $rRefuse('IP_BAN');
		}

		$rCountryCode = (string) (GeoIPService::getIPInfo($rIP)['country']['iso_code'] ?? '');
		if ($rCountryCode !== '') {
			$rForceCountry = !empty($rUserInfo['forced_country']);
			$rAllowCountries = (array) ($rSettings['allow_countries'] ?? []);
			if ($rForceCountry && $rUserInfo['forced_country'] != 'ALL' && $rCountryCode != $rUserInfo['forced_country']) {
				return $rRefuse('COUNTRY_DISALLOW');
			}
			if (!$rForceCountry && !in_array('ALL', $rAllowCountries) && !in_array($rCountryCode, $rAllowCountries)) {
				return $rRefuse('COUNTRY_DISALLOW');
			}
		}

		if (isset($rUserInfo['ip_limit_reached'])) {
			return $rRefuse('USER_ALREADY_CONNECTED');
		}
		if (!in_array('rtmp', $rUserInfo['output_formats'])) {
			return $rRefuse('USER_DISALLOW_EXT');
		}
		if (!in_array($rStreamID, $rUserInfo['channel_ids'])) {
			return $rRefuse('NOT_IN_BOUQUET');
		}
		if ($rUserInfo['isp_violate'] == 1) {
			return $rRefuse('ISP_LOCK_FAILED', (string) json_encode(['old' => $rUserInfo['isp_desc'], 'new' => $rUserInfo['con_isp_name']]));
		}
		if ($rUserInfo['isp_is_server'] == 1 && !$rUserInfo['is_restreamer']) {
			return $rRefuse('BLOCKED_ASN', (string) json_encode(['user_agent' => '', 'isp' => $rUserInfo['con_isp_name'], 'asn' => $rUserInfo['isp_asn']]), true);
		}
		if ($rRestreamDetect && !$rUserInfo['is_restreamer']) {
			if ($rSettings['detect_restream_block_user']) {
				self::db()->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rUserID);
			}

			return $rRefuse('RESTREAM_DETECT');
		}

		$rChannelInfo = StreamRedirector::redirectStream($rCached, $rSettings, $rServers ?? ServerRepository::getAll(), $rStreamID, 'rtmp', $rUserInfo, $rCountryCode, $rUserInfo['con_isp_name'], 'live', $rServerID);
		if (!$rChannelInfo || ($rChannelInfo['redirect_id'] && $rChannelInfo['redirect_id'] != $rServerID)) {
			return ['ok' => false, 'reason' => self::NO_SERVER];
		}

		return [
			'ok' => true,
			'user' => [
				'id' => $rUserID,
				'max_connections' => (int) $rUserInfo['max_connections'],
				'pair_id' => empty($rUserInfo['pair_id']) ? null : (int) $rUserInfo['pair_id'],
				'con_isp_name' => (string) $rUserInfo['con_isp_name'],
				'is_restreamer' => (int) $rUserInfo['is_restreamer'],
			],
			'country_code' => $rCountryCode,
			'channel' => [
				'stream_id' => $rStreamID, 'redirect_id' => $rServerID, 'originator_id' => null, 'pid' => $rChannelInfo['pid'] ?? null,
				'on_demand' => $rChannelInfo['on_demand'] ?? 0, 'llod' => $rChannelInfo['llod'] ?? 0, 'monitor_pid' => $rChannelInfo['monitor_pid'] ?? null, 'proxy' => $rChannelInfo['direct_proxy'] ?? 0,
			],
		];
	}

	/**
	 * MAIN's mint for a viewer that passed check() on a load balancer, under
	 * the uuid its node records it with, as auth.php mints an HTTP viewer's
	 * token (ConnectionAdmission::admitToken): its admission and its proof, so
	 * the node's record is MAIN's own under cluster_conn_binding = enforce.
	 * The channel stays on MAIN.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array{ok: true, user: array<string, mixed>, country_code: string, channel: array<string, mixed>} $rPassed check()'s answer
	 * @return array<string, mixed> The token's `uuid`, `adm`, `adm_uuid` and `prf`, those it has.
	 */
	public static function mint(array $rSettings, array $rPassed, int $rStreamID, string $rIP, string $rUUID): array {
		$rToken = ConnectionAdmission::admitToken($rSettings, ['stream_id' => $rStreamID, 'extension' => 'rtmp', 'channel_info' => $rPassed['channel'], 'user_info' => $rPassed['user'], 'country_code' => $rPassed['country_code'], 'uuid' => $rUUID], $rIP, '');
		return array_intersect_key($rToken, array_flip(['uuid', 'adm', 'adm_uuid', 'prf']));
	}
}
