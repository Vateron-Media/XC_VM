<?php

namespace XcVm\Core\Cluster;

/**
 * What the node replica's whole sections hold (plan, section 9, "What an LB
 * receives"), shared by MAIN, which builds them (Domain\Cluster\ReplicaBuilder),
 * and the node, which applies them (ReplicaApply). Lives in Core: it ships to
 * LBs, where Domain\Cluster does not.
 *
 * ```text
 * servers  {servers: [SERVER_FIELDS of every server], nodes: [{sid, gen, state, ed_pub}],
 *           reseller_dns: [str]}   the active resellers' DNS, for verify_host
 * node     NODE_FIELDS of the node's own row, and NODE_SETTINGS
 * crontab  {jobs: [{filename, time}]}   enabled rows whose role fits the node's mode
 * cluster  {main_urls, urls_ver, policy_ver, transport, heartbeat_sec,
 *           panel_sign_pub, panel_box_pub, min_proto, off_air}
 * bouquets    {bouquets: [BOUQUET_FIELDS of every bouquet]}, in the order
 *             BouquetService::getAll reads them (bouquet_order, 0 last; id)
 * categories  {categories: [CATEGORY_FIELDS of every stream category]}, by
 *             cat_order, then id
 * secrets  {live_streaming_pass: SECRET, openssl_extra: SECRET, viewer_key: SECRET}
 *          (viewer_key: the node's own, ViewerKey; a node on older code ignores it)
 *          SECRET = {kid, current, previous, previous_valid_until}
 * ```
 *
 * `bouquets` and `categories` are the viewer catalogue that the node's
 * caches of those names hold, which cron:cache built from MAIN's database:
 * the viewer APIs a load balancer still serves read them (player_api, the
 * Ministra portal, the playlists). Every column of `bouquets` and
 * `streams_categories` is carried (ReplicaSectionsTest).
 *
 * Every column of `servers` is in exactly one of SERVER_FIELDS, NODE_FIELDS
 * and SERVER_LOCAL (ReplicaSectionsTest fails on a new column that is not):
 * a replica never carries liveness, telemetry, `watchdog_data`, `php_pids`,
 * or the `api_url*` a node builds itself from its own settings.
 *
 * `secrets` is the one section that carries secrets (SECRET_KEYS, nothing
 * else): the viewer-token secret and OPENSSL_EXTRA, which the `settings`
 * section withholds and a node needs to open the tokens MAIN mints. It is
 * not in WHOLE: MAIN serves it only to an active node in mode 1 or 2, and
 * never keeps it unsealed (ReplicaEtagCache).
 *
 * The R2 `streams` section is one `stream` record per stream the node holds,
 * sent by the `streams` op (Domain\Cluster\StreamReplica), never whole:
 *
 * ```text
 * stream  {children, options, profile, recordings, server, stream, tickets, type}
 *   stream      STREAM_FIELDS of the `streams` row
 *   type        STREAM_TYPE_FIELDS of its `streams_types` row, or null
 *   profile     PROFILE_FIELDS of its transcoding profile, or null
 *   options     [OPTION_FIELDS + ARGUMENT_FIELDS] its options with their
 *               argument's definition, by argument_id
 *   server      STREAM_SERVER_FIELDS of the node's own `streams_servers` row,
 *               or null (the node records its archive, thumbnails or a
 *               recording of it without running it)
 *   children    [sid] the servers that relay it from this node (parent_id)
 *   recordings  [RECORDING_FIELDS] its recordings scheduled on this node
 *   tickets     the relay and file tickets of Phase 8, for a node whose
 *               DATAPLANE flow is on (Domain\Cluster\TicketService), else
 *               null; filled after the ETag was taken with it null
 * ```
 *
 * A node holds a stream when it is assigned it (`streams_servers`), records
 * its TV archive or thumbnails (`tv_archive_server_id`, `vframes_server_id`),
 * or has a recording of it scheduled (`recordings.source_id`), and the
 * `streams` row exists. Every column of `streams` and `streams_servers` is in
 * exactly one of the *_FIELDS and *_LOCAL lists (ReplicaSectionsTest): a
 * record never carries what nodes write back (pids, status, codecs,
 * progress, `updated`), nor MAIN's catalogue metadata, so neither moves its
 * ETag.
 */
final class ReplicaSections {
	public const SETTINGS = 'settings';
	public const SERVERS = 'servers';
	public const NODE = 'node';
	public const CRONTAB = 'crontab';
	public const CLUSTER = 'cluster';
	public const SECRETS = 'secrets';
	public const BOUQUETS = 'bouquets';
	public const CATEGORIES = 'categories';

	/**
	 * The R2 section on the node: `replica/streams.json` (the agent's cursor)
	 * and `replica/streams/<id>.json`, one `stream` record each; also its
	 * name in the apply's report and in `replica_owned`.
	 */
	public const STREAMS = 'streams';

	/** Sections sent whole, by ETag, to an agent that names them in `have` (and `secrets`, on its own terms). */
	public const WHOLE = [self::SETTINGS, self::SERVERS, self::NODE, self::CRONTAB, self::CLUSTER, self::BOUQUETS, self::CATEGORIES];

	/**
	 * `secrets`: what it carries, and nothing else. `live_streaming_pass`
	 * keys the viewer tokens, OPENSSL_EXTRA (config/openssl_extra) their
	 * context; both are withheld from the `settings` section.
	 */
	public const SECRET_KEYS = ['live_streaming_pass', 'openssl_extra'];

	/** `servers`: the routing and relay fields of every server, with their types. */
	public const SERVER_FIELDS = [
		'id' => 'int', 'server_type' => 'int', 'server_name' => 'str', 'is_main' => 'int', 'enabled' => 'int',
		'parent_id' => 'str', 'server_ip' => 'str', 'private_ip' => 'str', 'domain_name' => 'str', 'enable_https' => 'int',
		'http_broadcast_port' => 'int', 'https_broadcast_port' => 'int', 'http_ports_add' => 'str', 'https_ports_add' => 'str',
		'rtmp_port' => 'int', 'total_clients' => 'int', 'network_guaranteed_speed' => 'int', 'enable_geoip' => 'int',
		'geoip_countries' => 'str', 'geoip_type' => 'str', 'enable_isp' => 'int', 'isp_names' => 'str', 'isp_type' => 'str',
		'timeshift_only' => 'int', 'random_ip' => 'int', 'enable_proxy' => 'int', 'persistent_connections' => 'int',
		'enable_gzip' => 'int', 'order' => 'int', 'whitelist_ips' => 'str', 'xc_vm_version' => 'str', 'relay_seal' => 'int', 'proxy_key_gen' => 'int',
	];

	/** `node`: the node's own configuration, from its row; only it gets these. */
	public const NODE_FIELDS = [
		'id' => 'int', 'http_broadcast_port' => 'int', 'https_broadcast_port' => 'int', 'http_ports_add' => 'str',
		'https_ports_add' => 'str', 'rtmp_port' => 'int', 'limit_requests' => 'int', 'limit_burst' => 'int',
		'total_services' => 'int', 'use_disk' => 'int', 'enable_https' => 'int', 'domain_name' => 'str',
		'network_interface' => 'str', 'governor' => 'str', 'sysctl' => 'str', 'time_offset' => 'int',
	];

	/** `node`: the settings that configure a node's nginx (realip, the Ministra redirect). */
	public const NODE_SETTINGS = ['cloudflare' => 'int', 'mag_legacy_redirect' => 'int'];

	/**
	 * `servers` columns no replica carries: liveness and telemetry (MAIN's
	 * view of each node), the node's own reports, and install bookkeeping.
	 * A node materialises them as null.
	 */
	public const SERVER_LOCAL = [
		'status', 'last_check_ago', 'last_status', 'remote_status', 'ping', 'watchdog_data', 'php_pids', 'connections',
		'users', 'requests_per_second', 'server_hardware', 'video_devices', 'audio_devices', 'gpu_info', 'interfaces',
		'governors', 'certbot_ssl', 'certbot_renew', 'uuid', 'ssh_hostkey_sha1', 'viewer_key_fp', 'proxy_signed',
	];

	/** R2: the record of one stream a node holds (the `streams` op). */
	public const STREAM = 'stream';

	/** `stream.stream`: the `streams` columns a node runs and serves a stream with. */
	public const STREAM_FIELDS = [
		'id' => 'int', 'type' => 'int', 'category_id' => 'str', 'stream_display_name' => 'str', 'stream_source' => 'str',
		'stream_icon' => 'str', 'enable_transcode' => 'int', 'transcode_attributes' => 'str', 'custom_ffmpeg' => 'str',
		'movie_properties' => 'str', 'movie_subtitles' => 'str', 'read_native' => 'int', 'target_container' => 'str',
		'stream_all' => 'int', 'remove_subtitles' => 'int', 'custom_sid' => 'str', 'epg_id' => 'int', 'channel_id' => 'str',
		'epg_lang' => 'str', 'auto_restart' => 'str', 'transcode_profile_id' => 'int', 'gen_timestamps' => 'int',
		'added' => 'int', 'series_no' => 'int', 'direct_source' => 'int', 'tv_archive_duration' => 'int',
		'tv_archive_server_id' => 'int', 'vframes_server_id' => 'int', 'movie_symlink' => 'int', 'rtmp_output' => 'int',
		'allow_record' => 'int', 'probesize_ondemand' => 'int', 'custom_map' => 'str', 'external_push' => 'str',
		'delay_minutes' => 'int', 'llod' => 'int', 'adaptive_link' => 'str', 'fps_restart' => 'int',
		'fps_threshold' => 'int', 'direct_proxy' => 'int', 'force_ffmpeg' => 'int',
	];

	/**
	 * `streams` columns no record carries: the archive and thumbnail workers'
	 * pids (the node's, as `stream.worker` events), the row's timestamp, and
	 * MAIN's catalogue metadata (ordering, notes, release year, ratings,
	 * similar titles, TMDb, Plex, EPG offset, title sync, import uuid).
	 */
	public const STREAM_LOCAL = [
		'tv_archive_pid', 'vframes_pid', 'updated', 'order', 'notes', 'year', 'rating', 'similar', 'tmdb_id',
		'tmdb_language', 'plex_uuid', 'uuid', 'epg_offset', 'title_sync',
	];

	/** `stream.server`: the node's own `streams_servers` row, what MAIN decides of it. */
	public const STREAM_SERVER_FIELDS = ['server_stream_id' => 'int', 'stream_id' => 'int', 'server_id' => 'int', 'parent_id' => 'int', 'on_demand' => 'int'];

	/**
	 * `streams_servers` columns no record carries: the node's runtime state,
	 * which it reports as `stream.state` events, and the created channel's
	 * build state it keeps itself (`pids_create_channel`, `cchannel_rsources`).
	 */
	public const STREAM_SERVER_LOCAL = [
		'pid', 'to_analyze', 'stream_status', 'stream_started', 'stream_info', 'monitor_pid', 'aes_pid', 'current_source',
		'bitrate', 'progress_info', 'cc_info', 'delay_pid', 'delay_available_at', 'pids_create_channel', 'cchannel_rsources',
		'updated', 'compatible', 'audio_codec', 'video_codec', 'resolution', 'ondemand_check',
	];

	/** `stream.type`: the stream's `streams_types` row. */
	public const STREAM_TYPE_FIELDS = ['type_id' => 'int', 'type_name' => 'str', 'type_key' => 'str', 'type_output' => 'str', 'live' => 'int'];

	/** `stream.profile`: its transcoding profile. */
	public const PROFILE_FIELDS = ['profile_id' => 'int', 'profile_name' => 'str', 'profile_options' => 'str'];

	/** `stream.options[]`: a `streams_options` row (not its own id or stream_id)... */
	public const OPTION_FIELDS = ['argument_id' => 'int', 'value' => 'str'];

	/** ...with its argument's definition (`streams_arguments`, not its id or description). */
	public const ARGUMENT_FIELDS = [
		'argument_cat' => 'str', 'argument_name' => 'str', 'argument_wprotocol' => 'str', 'argument_key' => 'str',
		'argument_cmd' => 'str', 'argument_type' => 'str', 'argument_default_value' => 'str',
	];

	/**
	 * `stream.recordings[]`: a recording of the stream scheduled on the node,
	 * `status` as MAIN last heard it (the node's own `recording.state` wins).
	 */
	public const RECORDING_FIELDS = [
		'id' => 'int', 'stream_id' => 'int', 'created_id' => 'int', 'category_id' => 'str', 'bouquets' => 'str',
		'title' => 'str', 'description' => 'str', 'stream_icon' => 'str', 'start' => 'int', 'end' => 'int',
		'source_id' => 'int', 'archive' => 'int', 'status' => 'int',
	];

	/**
	 * `bouquets`: every column of a `bouquets` row. The lists stay the JSON
	 * text MAIN's row holds, so a node decodes them as BouquetService does.
	 */
	public const BOUQUET_FIELDS = [
		'id' => 'int', 'bouquet_name' => 'str', 'bouquet_channels' => 'str', 'bouquet_movies' => 'str', 'bouquet_radios' => 'str',
		'bouquet_series' => 'str', 'bouquet_order' => 'int',
	];

	/** `categories`: every column of a `streams_categories` row. */
	public const CATEGORY_FIELDS = ['id' => 'int', 'category_type' => 'str', 'category_name' => 'str', 'parent_id' => 'int', 'cat_order' => 'int', 'is_adult' => 'int'];

	/** `servers.nodes[].state`: cluster_nodes states. */
	public const NODE_STATES = ['enrolling', 'active', 'quarantined', 'revoked'];

	/** `cluster.off_air`: the off-air videos, by the settings key of their path. */
	public const OFF_AIR = [
		'connected' => 'connected_video_path', 'not_on_air' => 'not_on_air_video_path', 'banned' => 'banned_video_path',
		'expired' => 'expired_video_path', 'expiring' => 'expiring_video_path',
	];

	/**
	 * The crontab roles that fit a node in this mode: every node runs `all`,
	 * a node that still has MAIN's database (mode 0 or 1) also runs `legacy`,
	 * and `main` never leaves MAIN.
	 *
	 * @return list<string>
	 */
	public static function cronRoles(int $rMode): array {
		return $rMode >= 2 ? ['all'] : ['all', 'legacy'];
	}

	/**
	 * A crontab job as a node writes it into its crontab: a cron: command name
	 * and a five-field schedule, nothing a shell or cron would read more into
	 * (no trailing newline either: `\z`, not `$`).
	 *
	 * @return array{filename: string, time: string}|null
	 */
	public static function cronJob(mixed $rJob): ?array {
		if (!is_array($rJob) || !is_string($rJob['filename'] ?? null) || !is_string($rJob['time'] ?? null)) {
			return null;
		}
		if (!preg_match('/^[a-z0-9_]{1,64}\z/', $rJob['filename']) || !preg_match('/^[0-9*\/,-]+( [0-9*\/,-]+){4}\z/', $rJob['time'])) {
			return null;
		}
		return ['filename' => $rJob['filename'], 'time' => $rJob['time']];
	}

	/**
	 * A secret's key id: an HMAC keyed by the value, cut to 64 bits, which
	 * names the value without revealing it. For OPENSSL_EXTRA it is the
	 * fingerprint every node publishes (OpensslExtra::fingerprint).
	 */
	public static function kid(string $rName, string $rValue): string {
		return substr(hash_hmac('sha256', 'xc_vm ' . $rName . ' fingerprint v1', $rValue), 0, 16);
	}

	/**
	 * One entry of the `secrets` section as a node takes it: `current` and
	 * `kid` non-empty strings, and `previous` (a non-empty string) with
	 * `previous_valid_until` (unix seconds), or both null.
	 *
	 * @return array{current: string, kid: string, previous: ?string, previous_valid_until: ?int}|null
	 */
	public static function secret(mixed $rEntry): ?array {
		if (!is_array($rEntry) || !is_string($rEntry['current'] ?? null) || $rEntry['current'] === '' || !is_string($rEntry['kid'] ?? null) || $rEntry['kid'] === '') {
			return null;
		}
		$rPrevious = $rEntry['previous'] ?? null;
		$rUntil = $rEntry['previous_valid_until'] ?? null;
		if (!($rPrevious === null && $rUntil === null) && !(is_string($rPrevious) && $rPrevious !== '' && is_int($rUntil))) {
			return null;
		}
		return ['current' => $rEntry['current'], 'kid' => $rEntry['kid'], 'previous' => $rPrevious, 'previous_valid_until' => $rUntil];
	}

	/**
	 * A section's canonical form, which its ETag hashes: keys sorted at every
	 * level; lists keep their order. MAIN signs every section in this form
	 * (ReplicaBuilder::canonical).
	 */
	public static function canonical(mixed $rValue): mixed {
		if (!is_array($rValue)) {
			return $rValue;
		}
		if (!array_is_list($rValue)) {
			ksort($rValue, SORT_STRING);
		}
		return array_map([self::class, 'canonical'], $rValue);
	}

	/**
	 * A row's fields, typed the same whichever driver read them: integers
	 * as int, text as string, a missing column or NULL as null.
	 *
	 * @param array<string, mixed> $rRow
	 * @param array<string, string> $rFields name => 'int'|'str'
	 * @return array<string, int|string|null>
	 */
	public static function typed(array $rRow, array $rFields): array {
		$rOut = [];
		foreach ($rFields as $rKey => $rType) {
			$rValue = $rRow[$rKey] ?? null;
			if ($rValue === null || is_array($rValue)) {
				$rOut[$rKey] = null;
			} elseif ($rType === 'int') {
				$rOut[$rKey] = (int) $rValue;
			} else {
				$rOut[$rKey] = (string) $rValue;
			}
		}
		return $rOut;
	}
}
