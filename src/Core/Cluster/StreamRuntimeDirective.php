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
	 * and the same condition behind the live token's `channel_info.proxy`;
	 * "transcode" when a portable supervisor Spec is supplied (Phase 4b: the
	 * stream the target LB encodes), with that Spec in `spec`; "other" otherwise.
	 *
	 * For "proxy", `source` is {@see FanoutClient::buildSource()} WITHOUT the
	 * node-local `ffmpeg` path: that is the LB's to fill from its own config, so
	 * MAIN need not know each node's binary (D3). For "transcode", `source` is
	 * null and `spec` the portable Spec ({@see StreamProcess::buildSupervisorSpec}
	 * in portable mode — node-local values as tokens). For "other", both are null.
	 *
	 * The Spec is built by the caller (DB context) and passed in, so this stays a
	 * pure classifier: the proxy/other decision is `direct_proxy` alone, and a
	 * non-proxy stream is "transcode" iff its portable Spec is non-null (null for
	 * delay/created_live/resolver streams buildSupervisorSpec cannot supervise).
	 *
	 * @param array $rStreamRow The `streams` row (reads `direct_proxy`, `stream_source`).
	 * @param array $rArgsByKey Stream arguments keyed by `argument_key` (user_agent/proxy/cookie).
	 * @param array|null $rTranscodeSpec The portable supervisor Spec for a non-proxy stream, or null.
	 * @return array{kind: string, source: array|null, spec?: array}
	 */
	public static function build(array $rStreamRow, array $rArgsByKey, ?array $rTranscodeSpec = null): array {
		if ((int) ($rStreamRow['direct_proxy'] ?? 0) === 1) {
			$rSource = FanoutClient::buildSource($rStreamRow, $rArgsByKey);
			unset($rSource['ffmpeg']); // node-local; the LB agent fills it
			return ['kind' => 'proxy', 'source' => $rSource];
		}
		return self::upgrade(['kind' => 'other', 'source' => null], $rTranscodeSpec);
	}

	/**
	 * Attach a portable supervisor Spec to a directive first classified without
	 * one, for a serve-only target whose Spec is composed later (after the
	 * record's held streams are read; {@see StreamReplica::page()}). An "other"
	 * directive with a non-null Spec becomes "transcode"; a proxy, an
	 * already-transcode, or a null Spec leaves the directive unchanged — so the
	 * Spec never displaces the puller's proxy and a non-supervisable stream
	 * (null Spec) stays "other".
	 *
	 * @param array $rDirective A directive from {@see build()}.
	 * @param array|null $rTranscodeSpec The portable Spec, or null when none was composed.
	 * @return array{kind: string, source: array|null, spec?: array}
	 */
	public static function upgrade(array $rDirective, ?array $rTranscodeSpec): array {
		if (($rDirective['kind'] ?? '') === 'other' && $rTranscodeSpec !== null) {
			return ['kind' => 'transcode', 'source' => null, 'spec' => $rTranscodeSpec];
		}
		return $rDirective;
	}
}
