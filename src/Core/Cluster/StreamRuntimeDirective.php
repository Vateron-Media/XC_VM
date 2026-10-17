<?php

namespace XcVm\Core\Cluster;

use XcVm\Streaming\Fanout\FanoutClient;

/**
 * The runtime directive MAIN embeds in a stream's replica record
 * ({@see StreamRecords::data()}), so a serve-only LB hands the stream to its
 * fanout with no PHP (Phase 4a; spec "LB as a single Go binary", decision D3).
 *
 * The LB's Go agent applies it verbatim and derives nothing: `kind` is MAIN's
 * restream-vs-transcode decision, `source` the node-agnostic pull config.
 */
final class StreamRuntimeDirective {
	/**
	 * Build the directive for one stream.
	 *
	 * `kind` is "proxy" when the stream is a direct-proxy restream
	 * (`streams.direct_proxy == 1`) — the viewer path the fanout puller serves
	 * and the same condition behind the live token's `channel_info.proxy`; it is
	 * "other" otherwise (a transcode/encode stream, carried by Phase 4b).
	 *
	 * For "proxy", `source` is {@see FanoutClient::buildSource()} WITHOUT the
	 * node-local `ffmpeg` path: that is the LB's to fill from its own config, so
	 * MAIN need not know each node's binary (D3). For "other", `source` is null.
	 *
	 * @param array $rStreamRow The `streams` row (reads `direct_proxy`, `stream_source`).
	 * @param array $rArgsByKey Stream arguments keyed by `argument_key` (user_agent/proxy/cookie).
	 * @return array{kind: string, source: array|null}
	 */
	public static function build(array $rStreamRow, array $rArgsByKey): array {
		if ((int) ($rStreamRow['direct_proxy'] ?? 0) !== 1) {
			return ['kind' => 'other', 'source' => null];
		}
		$rSource = FanoutClient::buildSource($rStreamRow, $rArgsByKey);
		unset($rSource['ffmpeg']); // node-local; the LB agent fills it
		return ['kind' => 'proxy', 'source' => $rSource];
	}
}
