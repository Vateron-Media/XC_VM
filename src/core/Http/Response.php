<?php

/**
 * XC_VM — HTTP Response Helper
 *
 * Standardizes HTTP response output. Replaces scattered
 * header() + echo json_encode() + exit() patterns.
 *
 * ---------------------------------------------------------------
 * Usage:
 * ---------------------------------------------------------------
 *
 *   // JSON response
 *   Response::json(['status' => 'ok', 'data' => $rows]);
 *
 *   // JSON error
 *   Response::jsonError('Invalid token', 403);
 *
 *   // Redirect
 *   Response::redirect('/auth/' . $token);
 *
 *   // Set headers without sending body
 *   Response::header('X-Custom', 'value');
 *   Response::cors();
 *
 *   // Stream-optimized: no-cache headers for HLS
 *   Response::noCache();
 *
 * @see core/Http/Request.php
 */

class Response {

    /**
     * Send a JSON response and exit
     *
     * @param mixed $data Data to JSON-encode
     * @param int $statusCode HTTP status code
     * @param int $options json_encode options
     */
    public static function json($data, $statusCode = 200, $options = 0) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, $options);
        exit;
    }

    /**
     * Send a JSON error response and exit
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param array $extra Additional fields to include
     */
    public static function jsonError($message, $statusCode = 400, array $extra = []) {
        $payload = array_merge(['error' => $message], $extra);
        self::json($payload, $statusCode);
    }

    /**
     * Send a redirect response and exit
     *
     * @param string $url Target URL
     * @param int $statusCode 301 (permanent) or 302 (temporary)
     */
    public static function redirect($url, $statusCode = 302) {
        http_response_code($statusCode);
        header('Location: ' . $url);
        exit;
    }

    /**
     * Send a 404 Not Found response and exit
     *
     * @param string $message Optional message
     */
    public static function notFound($message = 'Not Found') {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo $message;
        exit;
    }

    /**
     * Send arbitrary HTTP header
     *
     * @param string $name Header name
     * @param string $value Header value
     */
    public static function header($name, $value) {
        header($name . ': ' . $value);
    }

    /**
     * Set CORS headers (Access-Control-Allow-Origin: *)
     *
     * Matches current behavior in nginx config and auth.php
     */
    public static function cors() {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }

    /**
     * Set no-cache headers
     *
     * Used for HLS playlists and auth responses that must not be cached.
     */
    public static function noCache() {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /**
     * Send raw content with content type and exit
     *
     * @param string $content Body content
     * @param string $contentType MIME type
     * @param int $statusCode HTTP status code
     */
    public static function raw($content, $contentType = 'text/plain', $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: ' . $contentType);
        echo $content;
        exit;
    }

    /**
     * Send an empty response with status code and exit
     *
     * @param int $statusCode
     */
    public static function empty($statusCode = 204) {
        http_response_code($statusCode);
        exit;
    }
}
