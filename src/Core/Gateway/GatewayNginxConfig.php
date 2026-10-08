<?php

namespace XcVm\Core\Gateway;

/**
 * The segment gateway's nginx include, `bin/nginx/conf/gateway.conf`
 * (Phase 12): both nginx.conf files include it in their server block, and
 * the root cron writes it from the node's mode (GatewayPolicy::mode) and
 * reloads nginx when it changes, as it does api_legacy.conf. Off, it holds a
 * comment and changes nothing; switching the mode off is the whole rollback.
 *
 * Its locations are exact matches for what the server-level rewrites make of
 * `/hls/<token>` and `/key/<token>` (`/stream/segment`, `/stream/key`), so
 * they win over the shared stream location wherever it is.
 *
 * - shadow: PHP serves, as before, and nginx mirrors each request to the
 *   gateway, which only judges the copy. A mirror fires in the location that
 *   serves the request, hence locations of their own.
 * - segments, segments+playlist: the gateway answers segments and keys (and
 *   with the second, playlist refreshes, `/auth/<token>`), passed the request as the rewrites made it
 *   (xc_fanout's `/stream/segment`, `/stream/key`, `/stream/live`). What it does not
 *   own it hands back with `X-Accel-Redirect: @gw_segment_php` (`@gw_key_php`, `@gw_live_php`),
 *   the PHP handler as before; and a gateway that does not answer (502, 504)
 *   sends the request there too, never an error to the viewer.
 */
final class GatewayNginxConfig {
	/** Under BIN_PATH. */
	public const FILE = 'nginx/conf/gateway.conf';

	/** The gateway's socket in xc_fanout (its `-gw` flag), nginx's `xc_gateway` upstream (keepalive). */
	public const SOCKET = '/home/xc_vm/bin/xc_fanout/sockets/gw.sock';

	/** The upstream both nginx.conf files declare for SOCKET. */
	public const UPSTREAM = 'xc_gateway';

	/** The stream types each mode passes to the gateway. */
	private const STREAMS = ['shadow' => ['segment', 'key', 'live'], 'segments' => ['segment', 'key'], 'segments+playlist' => ['segment', 'key', 'live']];

	/**
	 * The include for $rMode. $rGatewayUp: xc_fanout's gateway socket is
	 * there; a daemon from before the gateway, or one not restarted with it,
	 * has none, and nothing is sent to a socket that is not there.
	 */
	public static function render(string $rMode, bool $rGatewayUp = true): string {
		if (!isset(self::STREAMS[$rMode]) || !$rGatewayUp) {
			return '# Segment gateway off (gateway_mode): PHP serves /hls/, /key/ and /auth/.';
		}
		$rUpstream = self::UPSTREAM;
		$rToGateway = <<<NGINX
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header X-XC-Original-URI \$request_uri;
    proxy_set_header X-XC-Client-IP \$remote_addr;
    proxy_set_header X-XC-Host \$http_host;
NGINX;
		$rOut = [];
		if ($rMode === 'shadow') {
			$rOut[] = '# Segment gateway, shadow (gateway_mode): PHP serves /hls/, /key/ and /auth/ as';
			$rOut[] = "# before, and xc_fanout's gateway judges a mirrored copy of each request.";
			foreach (self::STREAMS[$rMode] as $rStream) {
				$rOut[] = "location = /stream/{$rStream} {\n    mirror /xc_gw_shadow;\n    mirror_request_body off;\n    limit_req zone=one burst=8;\n" . self::php($rStream, true) . '}';
			}
			$rOut[] = "location = /xc_gw_shadow {\n    internal;\n    proxy_pass http://{$rUpstream}/shadow;\n{$rToGateway}\n    proxy_set_header X-XC-Request-ID \$request_id;\n    proxy_pass_request_body off;\n    proxy_set_header Content-Length \"\";\n    proxy_connect_timeout 1s;\n    proxy_send_timeout 2s;\n    proxy_read_timeout 2s;\n}";
		} else {
			$rOut[] = '# Segment gateway (gateway_mode ' . $rMode . '): xc_fanout answers ' . implode(', ', self::STREAMS[$rMode]) . ', and';
			$rOut[] = '# hands what it does not own to PHP, which also answers while it is down.';
			foreach (self::STREAMS[$rMode] as $rStream) {
				$rOut[] = "location = /stream/{$rStream} {\n    limit_req zone=one burst=8;\n    proxy_pass http://{$rUpstream};\n{$rToGateway}\n    proxy_pass_header Server;\n    proxy_buffering off;\n    proxy_connect_timeout 1s;\n    proxy_read_timeout 60s;\n    error_page 502 504 = @gw_{$rStream}_php;\n}";
				$rOut[] = "location @gw_{$rStream}_php {\n" . self::php($rStream) . '}';
			}
		}
		return implode("\n", $rOut);
	}

	/**
	 * The stream location's PHP handler, for one stream type. $rShadow: PHP
	 * reports what it answered, under nginx's request id, to the gateway that
	 * judged the mirrored copy (GatewayShadow).
	 */
	private static function php(string $rStream, bool $rShadow = false): string {
		$rReport = $rShadow ? "    fastcgi_param XC_REQUEST_ID \$request_id;\n    fastcgi_param XC_GW_SHADOW 1;\n" : '';
		return $rReport . <<<NGINX
    include limit_queue.conf;
    fastcgi_index index.php;
    fastcgi_pass php;
    include fastcgi_params;
    fastcgi_buffering on;
    fastcgi_buffers 96 32k;
    fastcgi_buffer_size 32k;
    fastcgi_max_temp_file_size 0;
    fastcgi_keep_conn on;
    fastcgi_param SCRIPT_FILENAME /home/xc_vm/Public/stream/index.php;
    fastcgi_param SCRIPT_NAME /public/stream/index.php;
    fastcgi_param XC_STREAM {$rStream};
    fastcgi_param XC_PEER_ADDR \$realip_remote_addr;

NGINX;
	}
}
