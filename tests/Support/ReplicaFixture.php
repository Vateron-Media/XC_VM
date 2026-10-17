<?php

namespace XcVm\Tests\Support;

use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Domain\Cluster\ReplicaBuilder;

/**
 * A node replica as the agent stores it (ADR 0004, Phase 7): the node's keys
 * in `agent.json`, and per section the sealed, panel-signed record
 * (`replica/<name>.rep`) beside the data it wrote for PHP
 * (`replica/<name>.json`). Records are built by MAIN's own code
 * (ReplicaBuilder::record), signed by the extension's stand-in
 * (FakeClusterCrypto) under its panel key, the one the agent pinned.
 */
final class ReplicaFixture {
	public string $rUuid;

	public string $rBoxSk;

	/** MAIN's crypto: it signs the records. */
	public FakeClusterCrypto $rCrypto;

	/** Its panel key, pinned in agent.json. */
	public string $rSignPub;

	/**
	 * @param string $rClusterDir the agent's directory (config/cluster/), with `replica/` in it
	 */
	public function __construct(public string $rClusterDir) {
		$rHex = bin2hex(random_bytes(16));
		$this->rUuid = sprintf('%s-%s-4%s-a%s-%s', substr($rHex, 0, 8), substr($rHex, 8, 4), substr($rHex, 13, 3), substr($rHex, 17, 3), substr($rHex, 20, 12));
		$this->rBoxSk = random_bytes(32);
		$this->rCrypto = new FakeClusterCrypto();
		$this->rSignPub = ClusterReference::panelPub($this->rCrypto->rSeed);
		@mkdir($rClusterDir . 'replica/blocklist.d', 0777, true);
		$this->agent();
	}

	/** MAIN's crypto under another panel key: a panel root this node did not pin. */
	public static function otherPanel(): FakeClusterCrypto {
		$rCrypto = new FakeClusterCrypto();
		$rCrypto->rSeed = random_bytes(32);
		return $rCrypto;
	}

	/** Write the agent's state as Go does: the key bytes as standard base64. */
	public function agent(?string $rSignPub = null, ?string $rUuid = null): void {
		file_put_contents($this->rClusterDir . 'agent.json', json_encode([
			'node_uuid' => $rUuid ?? $this->rUuid, 'server_id' => 5, 'node_sign_seed' => base64_encode(random_bytes(32)),
			'node_box_sk' => base64_encode($this->rBoxSk), 'panel_sign_pub' => base64_encode($rSignPub ?? $this->rSignPub),
			'main_urls' => ['https://main.example:8443'], 'policy_ver' => 1, 'enrolled' => true,
		], JSON_UNESCAPED_SLASHES));
	}

	public function dir(): string {
		return $this->rClusterDir . 'replica/';
	}

	/**
	 * A record for this node as MAIN builds it: `u32(len) ‖ payload ‖ sig`,
	 * sealed to its box key ($rCrypto: signed under another panel key).
	 *
	 * @param array<string, mixed> $rDoc
	 */
	public function record(string $rTag, array $rDoc, ?FakeClusterCrypto $rCrypto = null): string {
		$rNode = ['node_uuid' => $this->rUuid, 'node_box_pub' => sodium_crypto_scalarmult_base($this->rBoxSk)];
		return ReplicaBuilder::record($rCrypto ?? $this->rCrypto, $rNode, $rTag, (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Store a whole section as the agent does: `<name>.rep` and `<name>.json`.
	 * $rJsonData: what `<name>.json` says, when it differs from the record.
	 *
	 * @param array<mixed> $rData
	 * @param array<mixed>|null $rJsonData
	 */
	public function whole(string $rName, array $rData, ?array $rJsonData = null, array $rDocOver = []): string {
		$rEtag = ReplicaBuilder::etag($rData);
		$rDoc = $rDocOver + ['v' => 1, 'section' => $rName, 'node' => $this->rUuid, 'gen' => 1, 'etag' => $rEtag, 'iat' => 1800000000, 'data' => $rData];
		file_put_contents($this->dir() . $rName . '.rep', $this->record('rep', $rDoc));
		file_put_contents($this->dir() . $rName . '.json', json_encode(['etag' => $rEtag, 'data' => $rJsonData ?? $rData], JSON_UNESCAPED_SLASHES));
		return $rEtag;
	}

	/**
	 * The blocklist's whole section and its materialised `blocklist.json`
	 * ($rJsonData: when it differs).
	 *
	 * @param array<mixed> $rData
	 * @param array<mixed>|null $rJsonData
	 */
	public function blocklist(array $rData, int $rSeq, ?array $rJsonData = null): void {
		$rEtag = ReplicaBuilder::etag($rData);
		$rDoc = ['v' => 1, 'section' => 'blocklist', 'node' => $this->rUuid, 'gen' => 1, 'etag' => $rEtag, 'seq' => $rSeq, 'iat' => 1800000000, 'data' => $rData];
		file_put_contents($this->dir() . 'blocklist.rep', $this->record('rep', $rDoc));
		file_put_contents($this->dir() . 'blocklist.json', json_encode(['seq' => $rSeq, 'etag' => $rEtag, 'data' => $rJsonData ?? $rData], JSON_UNESCAPED_SLASHES));
	}

	/**
	 * A blocklist delta, stored as `blocklist.d/<seq, 19 digits>.blk`.
	 *
	 * @param list<string> $rAdd
	 * @param list<string> $rRemove
	 */
	public function delta(int $rSeq, array $rAdd, array $rRemove = []): void {
		$rDoc = ['v' => 1, 'seq' => $rSeq, 'iat' => 1800000000, 'add' => $rAdd, 'remove' => $rRemove];
		file_put_contents($this->dir() . sprintf('blocklist.d/%019d.blk', $rSeq), $this->record('blk', $rDoc));
	}

	/**
	 * Store an R2 `stream` record as the agent does: `streams/<id>.rep` (the
	 * sealed record), then `streams/<id>.json` (`{etag, ver, data}`;
	 * $rJsonData: what it says, when it differs from the record).
	 *
	 * @param array<mixed> $rData
	 * @param array<mixed>|null $rJsonData
	 * @param array<string, mixed> $rDocOver fields of the signed payload to override
	 */
	public function stream(int $rID, array $rData, int $rVer = 1, ?array $rJsonData = null, array $rDocOver = []): string {
		@mkdir($this->dir() . 'streams', 0700, true);
		$rEtag = ReplicaBuilder::etag($rData);
		$rDoc = $rDocOver + ['v' => 1, 'section' => 'stream', 'node' => $this->rUuid, 'gen' => 1, 'stream_id' => $rID, 'ver' => $rVer, 'etag' => $rEtag, 'iat' => 1800000000, 'data' => ReplicaBuilder::canonical($rData)];
		file_put_contents($this->dir() . 'streams/' . $rID . '.rep', $this->record('rep', $rDoc));
		file_put_contents($this->dir() . 'streams/' . $rID . '.json', json_encode(['etag' => $rEtag, 'ver' => $rVer, 'data' => $rJsonData ?? ReplicaBuilder::canonical($rData)], JSON_UNESCAPED_SLASHES));
		return $rEtag;
	}

	/**
	 * A `stream` record's data as MAIN builds it (StreamRecords::data): a
	 * live stream assigned to $rServerID, on demand, with one option.
	 *
	 * @param array<string, mixed> $rStream `streams` columns to set
	 * @return array<string, mixed>
	 */
	public static function streamData(int $rID, int $rServerID, array $rStream = []): array {
		return ReplicaBuilder::canonical([
			'children' => [],
			'options' => [['argument_id' => 1, 'value' => 'curl/8', 'argument_cat' => 'fetch', 'argument_name' => 'User Agent', 'argument_wprotocol' => 'http', 'argument_key' => 'user_agent', 'argument_cmd' => '-user_agent "%s"', 'argument_type' => 'text', 'argument_default_value' => 'VLC']],
			'profile' => null,
			'recordings' => [],
			'server' => ['server_stream_id' => $rID, 'stream_id' => $rID, 'server_id' => $rServerID, 'parent_id' => null, 'on_demand' => 1],
			'stream' => ReplicaSections::typed($rStream + ['id' => $rID, 'type' => 1, 'stream_display_name' => 'S' . $rID, 'stream_source' => '["http://src.example/' . $rID . '"]', 'direct_source' => 0], ReplicaSections::STREAM_FIELDS),
			'tickets' => null,
			'type' => ['live' => 1, 'type_id' => 1, 'type_key' => 'live', 'type_name' => 'Live Streams', 'type_output' => 'live'],
		]);
	}

	/** The streams section's cursor (`streams.json`), as the agent writes it once a pass completed. */
	public function streamsSince(int $rSince): void {
		@mkdir($this->dir() . 'streams', 0700, true);
		file_put_contents($this->dir() . 'streams.json', json_encode(['since' => $rSince]));
	}

	/**
	 * A `servers` section row (SERVER_FIELDS) as MAIN sends it.
	 *
	 * @param array<string, int|string|null> $rOver
	 * @return array<string, int|string|null>
	 */
	public static function server(int $rID, int $rMain, string $rIP, array $rOver = []): array {
		return $rOver + [
			'id' => $rID, 'server_type' => 0, 'server_name' => 'S' . $rID, 'is_main' => $rMain, 'enabled' => 1, 'parent_id' => null,
			'server_ip' => $rIP, 'private_ip' => null, 'domain_name' => '', 'enable_https' => 0, 'http_broadcast_port' => 80,
			'https_broadcast_port' => 443, 'http_ports_add' => '', 'https_ports_add' => '', 'rtmp_port' => 8880, 'total_clients' => 1000,
			'network_guaranteed_speed' => 1000, 'enable_geoip' => 0, 'geoip_countries' => '[]', 'geoip_type' => 'low_priority',
			'enable_isp' => 0, 'isp_names' => '[]', 'isp_type' => 'low_priority', 'timeshift_only' => 0, 'random_ip' => 0,
			'enable_proxy' => 0, 'persistent_connections' => 0, 'enable_gzip' => 0, 'order' => $rID, 'whitelist_ips' => '[]', 'xc_vm_version' => '2.5.3',
		];
	}

	/** The `bouquets` and `categories` sections: one bouquet of stream 10, one live category. */
	public function catalog(): void {
		$this->whole('bouquets', ['bouquets' => [['bouquet_channels' => '[10]', 'bouquet_movies' => '[]', 'bouquet_name' => 'Sports', 'bouquet_order' => 1, 'bouquet_radios' => '[]', 'bouquet_series' => '[]', 'id' => 1]]]);
		$this->whole('categories', ['categories' => [['cat_order' => 1, 'category_name' => 'News', 'category_type' => 'live', 'id' => 4, 'is_adult' => 0, 'parent_id' => 0]]]);
	}

	/** Flip one byte of a stored record. */
	public function corrupt(string $rFile): void {
		$rBytes = (string) file_get_contents($this->dir() . $rFile);
		$rBytes[intdiv(strlen($rBytes), 2)] = chr(ord($rBytes[intdiv(strlen($rBytes), 2)]) ^ 1);
		file_put_contents($this->dir() . $rFile, $rBytes);
	}

	/**
	 * The sections a CONFIG node needs to boot from its replica, for server 5
	 * (MAIN is 1), with MAIN's settings row and secrets.
	 *
	 * @param array<string, string|null> $rSettings
	 */
	public function node(array $rSettings = []): void {
		$rServer = [self::class, 'server'];
		$this->whole('settings', $rSettings + ['server_name' => 'Panel', 'default_timezone' => 'UTC', 'on_demand_wait_time' => '20', 'ffmpeg_cpu' => '8.0', 'ffmpeg_gpu' => '', 'enable_cache' => '1']);
		$this->whole('secrets', [
			'live_streaming_pass' => ['current' => 'stream-pass', 'kid' => str_repeat('a', 16), 'previous' => null, 'previous_valid_until' => null],
			'openssl_extra' => ['current' => 'extra-from-main', 'kid' => str_repeat('b', 16), 'previous' => null, 'previous_valid_until' => null],
		]);
		$this->whole('servers', ['servers' => [$rServer(1, 1, '192.0.2.1'), $rServer(5, 0, '192.0.2.5')], 'nodes' => [['sid' => 5, 'gen' => 1, 'state' => 'active', 'ed_pub' => base64_encode(random_bytes(32))]]]);
		$this->whole('node', [
			'id' => 5, 'http_broadcast_port' => 8080, 'https_broadcast_port' => 8443, 'http_ports_add' => '', 'https_ports_add' => '', 'rtmp_port' => 8880,
			'limit_requests' => 0, 'limit_burst' => 0, 'total_services' => 4, 'use_disk' => 0, 'enable_https' => 0, 'domain_name' => '',
			'network_interface' => 'eth0', 'governor' => '', 'sysctl' => '', 'time_offset' => 0, 'serve_only' => 0, 'cloudflare' => 0, 'mag_legacy_redirect' => 0,
		]);
		$this->whole('crontab', ['jobs' => [['filename' => 'cache', 'time' => '* * * * *'], ['filename' => 'cleanup', 'time' => '0 * * * *']]]);
		$this->blocklist(['ip' => ['203.0.113.1'], 'asn' => [], 'ua' => [], 'isp' => [], 'rtmp' => []], 7);
	}
}
