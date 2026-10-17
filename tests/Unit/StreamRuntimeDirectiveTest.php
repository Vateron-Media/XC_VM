<?php

use XcVm\Core\Cluster\StreamRuntimeDirective;
use PHPUnit\Framework\TestCase;

/**
 * @covers \XcVm\Core\Cluster\StreamRuntimeDirective
 *
 * The runtime directive MAIN embeds in a stream's replica record so a
 * serve-only LB can hand the stream to its fanout without PHP (Phase 4a). It
 * is the cross-repo contract the Go agent applies verbatim: kind="proxy" with
 * a node-agnostic source for a direct-proxy restream, kind="other" otherwise.
 * The source is FanoutClient::buildSource WITHOUT the node-local ffmpeg path —
 * the agent fills that from its own config (spec D3).
 */
final class StreamRuntimeDirectiveTest extends TestCase {

	public function testBuildsProxyDirectiveForDirectProxyStream() {
		$row = [
			'direct_proxy' => 1,
			'stream_source' => json_encode(['http://a/1.ts', 'http://b/2.m3u8']),
		];
		$args = [
			'user_agent' => ['value' => 'MyPlayer/1.0', 'argument_default_value' => 'VLC/3.0'],
			'proxy' => ['value' => '1.2.3.4:8080'],
			'cookie' => ['value' => 'sid=xyz'],
		];

		$directive = StreamRuntimeDirective::build($row, $args);

		$want = json_decode(file_get_contents(__DIR__ . '/fixtures/runtime_directive_proxy.json'), true);
		$this->assertSame($want, $directive);
	}

	public function testProxySourceCarriesNoFfmpegPath() {
		$row = ['direct_proxy' => 1, 'stream_source' => json_encode(['http://a/1.ts'])];

		$directive = StreamRuntimeDirective::build($row, []);

		// The node-local ffmpeg path is the agent's to fill, never MAIN's (D3).
		$this->assertArrayNotHasKey('ffmpeg', $directive['source']);
	}

	public function testNonDirectProxyStreamIsOther() {
		$row = ['direct_proxy' => 0, 'stream_source' => json_encode(['http://a/1.ts'])];

		$directive = StreamRuntimeDirective::build($row, []);

		$this->assertSame('other', $directive['kind']);
		$this->assertNull($directive['source']);
	}

	public function testDirectProxyWithNoSourceStaysProxyWithEmptyUrls() {
		// A direct-proxy stream whose source is unset is still kind=proxy; the
		// agent skips it (empty urls) rather than MAIN guessing a different kind.
		$row = ['direct_proxy' => 1, 'stream_source' => ''];

		$directive = StreamRuntimeDirective::build($row, []);

		$this->assertSame('proxy', $directive['kind']);
		$this->assertSame([], $directive['source']['urls']);
	}

	public function testTranscodeDirectiveWhenPortableSpecGiven() {
		// A non-proxy stream the target node can supervise: the portable Spec the
		// caller built rides in `spec`, kind=transcode, source null (4b).
		$row = ['direct_proxy' => 0, 'stream_source' => json_encode(['http://a/1.ts'])];
		$spec = [
			'sources' => [['label' => 's', 'cmd' => '{ffmpeg_cpu} -i x {streams}7_.m3u8']],
			'pid_path' => '{streams}7_.pid', 'server_id' => '{server_id}', 'encrypt' => false,
		];

		$directive = StreamRuntimeDirective::build($row, [], $spec);

		$this->assertSame('transcode', $directive['kind']);
		$this->assertNull($directive['source']);
		$this->assertSame($spec, $directive['spec']);
	}

	public function testNonProxyWithoutSpecIsOther() {
		// null Spec (delay/created_live/resolver — buildSupervisorSpec returned null): "other", no spec key.
		$directive = StreamRuntimeDirective::build(['direct_proxy' => 0, 'stream_source' => json_encode(['http://a/1.ts'])], [], null);

		$this->assertSame('other', $directive['kind']);
		$this->assertArrayNotHasKey('spec', $directive);
	}

	public function testProxyNeverCarriesATranscodeSpec() {
		// direct_proxy wins even if a Spec is passed: a proxy is served by the
		// puller, never the supervisor — no spec key.
		$directive = StreamRuntimeDirective::build(['direct_proxy' => 1, 'stream_source' => json_encode(['http://a/1.ts'])], [], ['sources' => []]);

		$this->assertSame('proxy', $directive['kind']);
		$this->assertArrayNotHasKey('spec', $directive);
	}

	public function testUpgradeOtherWithSpecBecomesTranscode() {
		// A serve-only target composes a portable Spec after the directive was
		// first classified "other" (StreamReplica::page); upgrade attaches it.
		$spec = ['sources' => [['cmd' => '{ffmpeg_cpu} -i x']], 'encrypt' => false];

		$this->assertSame(
			['kind' => 'transcode', 'source' => null, 'spec' => $spec],
			StreamRuntimeDirective::upgrade(['kind' => 'other', 'source' => null], $spec)
		);
	}

	public function testUpgradeOtherWithNullSpecStaysOther() {
		// buildSupervisorSpec returned null (not supervisable) → directive unchanged.
		$directive = ['kind' => 'other', 'source' => null];

		$this->assertSame($directive, StreamRuntimeDirective::upgrade($directive, null));
	}

	public function testUpgradeLeavesProxyUntouched() {
		// A proxy is served by the puller; a Spec must never displace it.
		$directive = ['kind' => 'proxy', 'source' => ['urls' => ['http://x']]];

		$this->assertSame($directive, StreamRuntimeDirective::upgrade($directive, ['sources' => []]));
	}
}
