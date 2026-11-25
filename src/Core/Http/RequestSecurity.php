<?php

namespace XcVm\Core\Http;

use XcVm\Core\Util\NetworkUtils;

/** Browser response headers and the same-origin guard for panel writes. */
final class RequestSecurity {
	public static function sendHeaders(): void {
		if (headers_sent()) {
			return;
		}
		header('X-Content-Type-Options: nosniff');
		header('X-Frame-Options: SAMEORIGIN');
		header("Content-Security-Policy: object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
		header('Referrer-Policy: same-origin');
		if (NetworkUtils::isHttps()) {
			header('Strict-Transport-Security: max-age=31536000');
		}
	}

	/** Reject cross-site and origin-less browser writes before a controller runs. */
	public static function enforceSameOrigin(): void {
		if (PHP_SAPI === 'cli' || in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD', 'OPTIONS'], true)) {
			return;
		}
		if (!self::sameOrigin($_SERVER)) {
			http_response_code(403);
			exit('Forbidden');
		}
	}

	/** @param array<string, mixed> $rServer */
	public static function sameOrigin(array $rServer): bool {
		$rSource = trim((string) ($rServer['HTTP_ORIGIN'] ?? ''));
		if ($rSource === '') {
			$rSource = trim((string) ($rServer['HTTP_REFERER'] ?? ''));
		}
		$rScheme = NetworkUtils::isHttps($rServer) ? 'https' : 'http';
		$rExpected = self::origin($rScheme . '://' . (string) ($rServer['HTTP_HOST'] ?? ''));
		$rActual = self::origin($rSource);
		return $rExpected !== null && $rActual !== null && $rExpected === $rActual;
	}

	private static function origin(string $rUrl): ?string {
		$rParts = parse_url($rUrl);
		if ($rParts === false || !isset($rParts['scheme'], $rParts['host']) || isset($rParts['user'], $rParts['pass'])) {
			return null;
		}
		$rScheme = strtolower((string) $rParts['scheme']);
		if (!in_array($rScheme, ['http', 'https'], true)) {
			return null;
		}
		$rPort = (int) ($rParts['port'] ?? ($rScheme === 'https' ? 443 : 80));
		return $rScheme . '://' . strtolower((string) $rParts['host']) . ':' . $rPort;
	}
}
