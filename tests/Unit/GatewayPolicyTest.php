<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\AgentPaths;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\StreamSecret;
use XcVm\Core\Gateway\GatewayNginxConfig;
use XcVm\Core\Gateway\GatewayPolicy;

/**
 * The segment gateway's policy and nginx include (Phase 12.1): what PHP hands
 * xc_fanout's gateway, from the caches the stream endpoints read.
 */
final class GatewayPolicyTest extends TestCase {
	private const NOW = 1800000000;

	private string $rDir;

	public static function setUpBeforeClass(): void {
		$rBase = sys_get_temp_dir() . '/xcvm-gw-test/';
		foreach (['CACHE_TMP_PATH' => $rBase . 'cache/', 'TMP_PATH' => $rBase, 'FLOOD_TMP_PATH' => $rBase . 'flood/', 'SIGNALS_TMP_PATH' => $rBase . 'signals/', 'CONS_TMP_PATH' => $rBase . 'opened_cons/', 'STREAMS_PATH' => $rBase . 'streams/', 'ARCHIVE_PATH' => $rBase . 'archive/', 'SERVER_ID' => 1] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
		@mkdir(CACHE_TMP_PATH, 0700, true);
	}

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/gwpol-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($this->rDir, 0700, true);
		ViewerKey::useFile($this->rDir . '/viewer');
		StreamSecret::useFile($this->rDir . '/secret');
		OpensslExtra::usePrevFile($this->rDir . '/ctx');
		GatewayPolicy::useFile($this->rDir . '/gateway/policy.json');
		NodeFlows::usePath($this->rDir . '/flows.json');
	}

	protected function tearDown(): void {
		ViewerKey::useFile(null);
		StreamSecret::useFile(null);
		OpensslExtra::usePrevFile(null);
		GatewayPolicy::useFile(null);
		NodeFlows::usePath(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return array<string, mixed> */
	private function settings(array $rOver = []): array {
		return $rOver + ['live_streaming_pass' => 'secret-now', 'secure_stream_tokens' => 1, 'restrict_same_ip' => 1, 'ip_subnet_match' => 0, 'encrypt_hls' => 1, 'send_server_header' => 'XC', 'send_protection_headers' => 1, 'send_altsvc_header' => 0, 'gateway_mode' => 'shadow', 'fanout_enabled' => 1];
	}

	/** @return array<int, array<string, mixed>> */
	private function servers(): array {
		return [
			1 => ['site_url' => 'http://main.example:8080/', 'https_broadcast_port' => 8443, 'time_offset' => 7],
			2 => ['site_url' => 'http://lb2.example:8080/', 'random_ip' => 0],
			5 => ['site_url' => 'http://lb5.example/', 'random_ip' => 1, 'domains' => ['protocol' => 'https', 'urls' => ['a.example', 'b.example'], 'port' => 8443]],
		];
	}

	public function testTheKeysAreReadTokensInItsOrderWithTheirWindows(): void {
		file_put_contents($this->rDir . '/viewer', json_encode(['current' => 'vk-now', 'previous' => 'vk-old', 'previous_valid_until' => self::NOW + 60]));
		file_put_contents($this->rDir . '/secret', json_encode(['value' => 'secret-old', 'valid_until' => self::NOW + 30]));
		file_put_contents($this->rDir . '/ctx', json_encode(['value' => 'ctx-old', 'valid_until' => self::NOW + 90]));
		$rKeys = GatewayPolicy::build($this->settings(), $this->servers(), 1, self::NOW)['keys'];
		$this->assertSame([['hex' => bin2hex('vk-now'), 'until' => null], ['hex' => bin2hex('vk-old'), 'until' => self::NOW + 60]], $rKeys['viewer']);
		$this->assertSame([['hex' => bin2hex('secret-now'), 'until' => null], ['hex' => bin2hex('secret-old'), 'until' => self::NOW + 30]], $rKeys['shared']);
		$this->assertSame([['hex' => bin2hex(OPENSSL_EXTRA), 'until' => null], ['hex' => bin2hex('ctx-old'), 'until' => self::NOW + 90]], $rKeys['context']);
		$this->assertFalse($rKeys['accept_legacy_cbc'], 'secure_stream_tokens on');

		// Past their windows, the replaced values are not handed over.
		$rLater = GatewayPolicy::build($this->settings(['secure_stream_tokens' => 0]), $this->servers(), 1, self::NOW + 120)['keys'];
		$this->assertCount(1, $rLater['viewer']);
		$this->assertCount(1, $rLater['shared']);
		$this->assertCount(1, $rLater['context']);
		$this->assertTrue($rLater['accept_legacy_cbc']);

		// No stream secret: readToken opens nothing but the viewer keys, so no shared key at all (not the replaced one in its place).
		$this->assertSame([], GatewayPolicy::build($this->settings(['live_streaming_pass' => '']), $this->servers(), 1, self::NOW)['keys']['shared']);
	}

	public function testTheRestIsWhatSegmentPhpReads(): void {
		$rPolicy = GatewayPolicy::build($this->settings(['send_altsvc_header' => 1]), $this->servers(), 1, self::NOW);
		$this->assertSame(['v', 'server_id', 'written_at', 'mode', 'keys', 'restrict_same_ip', 'ip_subnet_match', 'encrypt_hls', 'headers', 'serve_until', 'paths', 'live', 'verify_host', 'allowed_domains', 'conn_store', 'time_offset', 'redirect'], array_keys($rPolicy), 'nothing else, no other secret');
		$this->assertSame([1, self::NOW, 'shadow'], [$rPolicy['server_id'], $rPolicy['written_at'], $rPolicy['mode']]);
		$this->assertSame([true, false, true], [$rPolicy['restrict_same_ip'], $rPolicy['ip_subnet_match'], $rPolicy['encrypt_hls']]);
		$this->assertSame(['server' => 'XC', 'protection' => true, 'altsvc_port' => 8443], $rPolicy['headers']);
		$this->assertSame(0, $rPolicy['serve_until'], 'no lease limits a node without one');
		$this->assertSame(['cons' => CONS_TMP_PATH, 'streams' => STREAMS_PATH, 'archive' => ARCHIVE_PATH, 'flood' => FLOOD_TMP_PATH, 'signals' => SIGNALS_TMP_PATH, 'agent_sock' => AgentPaths::file(AgentPaths::SOCKET), 'spool' => AgentPaths::file(AgentPaths::DIR . 'spool/'), 'flows' => AgentPaths::file(AgentPaths::DIR . 'flows.json')], $rPolicy['paths']);
		$this->assertSame(['use_buffer' => false, 'on_demand_instant_off' => false, 'disallow_2nd_ip_con' => false, 'disallow_2nd_ip_max' => 0, 'unique_header' => false], $rPolicy['live'], 'use_buffer unset is live.php\'s "== 0"');
		$rLive = GatewayPolicy::build($this->settings(['use_buffer' => '1', 'on_demand_instant_off' => 1, 'disallow_2nd_ip_con' => 1, 'disallow_2nd_ip_max' => '3', 'send_unique_header' => 'xc']), $this->servers(), 1, self::NOW)['live'];
		$this->assertSame(['use_buffer' => true, 'on_demand_instant_off' => true, 'disallow_2nd_ip_con' => true, 'disallow_2nd_ip_max' => 3, 'unique_header' => true], $rLive);
		$this->assertSame([false, []], [$rPolicy['verify_host'], $rPolicy['allowed_domains']]);
		$rHost = GatewayPolicy::build($this->settings(['verify_host' => 1]), $this->servers(), 1, self::NOW, ['tv.example', 7, 'lb.example']);
		$this->assertSame([true, ['tv.example', 'lb.example']], [$rHost['verify_host'], $rHost['allowed_domains']], 'the host check and its list, names only');
		$this->assertSame(7, $rPolicy['time_offset']);
		$this->assertSame('php', $rPolicy['conn_store'], 'no agent: viewers are in MAIN\'s store');
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 2, 'flows' => 255, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
		$this->assertSame('agent', GatewayPolicy::build($this->settings(), $this->servers(), 1, self::NOW)['conn_store'], 'the CONNECTIONS flow on');
		$this->assertSame(['2' => ['http://lb2.example:8080'], '5' => ['https://a.example:8443', 'https://b.example:8443']], $rPolicy['redirect'], 'segment.php\'s Location base for each other server');
	}

	public function testTheModeIsOffWithoutFanoutOrAKnownValue(): void {
		$this->assertSame('shadow', GatewayPolicy::mode($this->settings()));
		$this->assertSame('off', GatewayPolicy::mode($this->settings(['fanout_enabled' => 0])), 'the gateway serves from fanout');
		$this->assertSame('segments', GatewayPolicy::mode($this->settings(['gateway_mode' => 'segments'])));
		$this->assertSame('segments+playlist', GatewayPolicy::mode($this->settings(['gateway_mode' => 'segments+playlist'])));
		$this->assertSame('off', GatewayPolicy::mode($this->settings(['gateway_mode' => 'playlist'])), 'not a mode');
		$this->assertSame('off', GatewayPolicy::mode([]));
	}

	public function testWriteIsWholeAndPrivate(): void {
		FileCache::setCache('settings', $this->settings());
		FileCache::setCache('servers', $this->servers());
		$this->assertTrue(GatewayPolicy::write(self::NOW));
		$rFile = GatewayPolicy::file();
		$this->assertSame(0600, fileperms($rFile) & 0777);
		$this->assertSame(self::NOW, json_decode((string) file_get_contents($rFile), true)['written_at']);
		$this->assertSame(['policy.json'], array_values(array_diff(scandir(dirname($rFile)), ['.', '..'])), 'no temporary file left');
	}

	public function testTheNginxIncludeIsAMirrorInShadowOnly(): void {
		$this->assertStringStartsWith('#', GatewayNginxConfig::render('off'));
		$this->assertStringNotContainsString('location', GatewayNginxConfig::render('off'));
		$rShadow = GatewayNginxConfig::render('shadow');
		$this->assertSame(GatewayNginxConfig::render('off'), GatewayNginxConfig::render('shadow', false), 'no gateway socket: nothing to send to');
		$this->assertSame(GatewayNginxConfig::render('off'), GatewayNginxConfig::render('segments', false));
		foreach (['segment', 'key', 'live'] as $rStream) {
			$this->assertStringContainsString("location = /stream/{$rStream} {\n    mirror /xc_gw_shadow;", $rShadow);
			$this->assertStringContainsString("fastcgi_param XC_STREAM {$rStream};", $rShadow);
		}
		$this->assertStringContainsString('proxy_pass http://' . GatewayNginxConfig::UPSTREAM . '/shadow;', $rShadow);
		$this->assertStringContainsString("fastcgi_param XC_REQUEST_ID \$request_id;\n    fastcgi_param XC_GW_SHADOW 1;", $rShadow, 'PHP reports what it answered under nginx\'s request id');
		$this->assertStringContainsString('proxy_set_header X-XC-Request-ID $request_id;', $rShadow, 'and the gateway judged the same one');
		$this->assertStringContainsString('proxy_set_header X-XC-Original-URI $request_uri;', $rShadow);
		$this->assertStringContainsString('proxy_set_header X-XC-Host $http_host;', $rShadow, 'the host the bootstrap checks');
		$this->assertStringNotContainsString('error_page', $rShadow, 'shadow passes nothing to the gateway but the mirror');

		$rServe = GatewayNginxConfig::render('segments');
		$this->assertStringNotContainsString('mirror', $rServe);
		foreach (['segment', 'key'] as $rStream) {
			$this->assertStringContainsString("location = /stream/{$rStream} {", $rServe);
			$this->assertStringContainsString("error_page 502 504 = @gw_{$rStream}_php;", $rServe, 'the gateway down: PHP answers');
			$this->assertStringContainsString("location @gw_{$rStream}_php {", $rServe, 'and what the gateway hands back');
			$this->assertStringContainsString("fastcgi_param XC_STREAM {$rStream};", $rServe);
		}
		// No URI part: a URI rewritten at server level is passed whole anyway.
		$this->assertStringContainsString('proxy_pass http://' . GatewayNginxConfig::UPSTREAM . ';', $rServe);
		$this->assertStringNotContainsString('XC_GW_SHADOW', $rServe, 'serving reports nothing');
		$this->assertStringContainsString('proxy_pass_header Server;', $rServe, 'send_server_header reaches the viewer');
		$this->assertStringNotContainsString('/stream/live', $rServe, 'segments: playlists stay PHP\'s');
		$rPlaylist = GatewayNginxConfig::render('segments+playlist');
		$this->assertStringContainsString("location = /stream/live {", $rPlaylist);
		$this->assertStringContainsString('error_page 502 504 = @gw_live_php;', $rPlaylist);
		$this->assertStringContainsString("location @gw_live_php {", $rPlaylist);
		$this->assertStringContainsString('fastcgi_param XC_STREAM live;', $rPlaylist);
		// The shipped default is the render for off, so a fresh node does not reload nginx for nothing.
		$this->assertSame(GatewayNginxConfig::render('off'), trim((string) file_get_contents(MAIN_HOME . 'bin/nginx/conf/gateway.conf')));
		foreach ([MAIN_HOME . 'bin/nginx/conf/nginx.conf', dirname(MAIN_HOME) . '/lb_configs/nginx.conf'] as $rConf) {
			$rText = (string) file_get_contents($rConf);
			$this->assertMatchesRegularExpression('/upstream ' . GatewayNginxConfig::UPSTREAM . ' \\{\\s*server unix:' . preg_quote(GatewayNginxConfig::SOCKET, '/') . ';\\s*keepalive \\d+;/', $rText, $rConf . ': the gateway\'s connections kept open');
			// The extensionless stream location (regex locations are tried in order, the first match wins).
			$this->assertSame(1, preg_match('/location ~ \\^\\/stream\\/\\([a-z|]+\\)\\$ \\{/', $rText, $rM, PREG_OFFSET_CAPTURE), $rConf);
			$this->assertLessThan($rM[0][1], strpos($rText, 'include gateway.conf;'), $rConf . ': before the stream location');
		}
	}
}
