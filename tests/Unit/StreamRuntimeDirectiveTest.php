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
}
