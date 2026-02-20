<?php

/**
 * XC_VM — HTTP Request Wrapper
 *
 * Abstracts $_GET, $_POST, $_SERVER, $_COOKIE access with
 * input sanitization. Replaces duplicated cleanGlobals() and
 * parseIncomingRecursively() from CoreUtilities and StreamingUtilities.
 *
 * ---------------------------------------------------------------
 * What it replaces:
 * ---------------------------------------------------------------
 *
 *   BEFORE (duplicated in both CoreUtilities AND StreamingUtilities):
 *     CoreUtilities::cleanGlobals($_GET);
 *     CoreUtilities::cleanGlobals($_POST);
 *     CoreUtilities::cleanGlobals($_REQUEST);
 *     $rPost = CoreUtilities::parseIncomingRecursively($_POST);
 *     $rGet  = CoreUtilities::parseIncomingRecursively($_GET);
 *
 *   AFTER:
 *     $request = Request::capture();
 *     $value   = $request->get('stream_id');         // from $_GET
 *     $value   = $request->post('username');          // from $_POST
 *     $value   = $request->input('action');           // $_POST first, then $_GET
 *     $all     = $request->all();                     // merged clean input
 *
 * ---------------------------------------------------------------
 * Backward Compatibility:
 * ---------------------------------------------------------------
 *
 *   Static methods mirror the CoreUtilities API:
 *     Request::cleanGlobals($_GET);
 *     Request::parseIncomingRecursively($_POST, ...);
 *     Request::parseCleanKey($key);
 *     Request::parseCleanValue($value);
 *
 *   Legacy code continues working via CoreUtilities, which will
 *   delegate to this class during Phase 2+.
 *
 * @see CoreUtilities::cleanGlobals()
 * @see StreamingUtilities::cleanGlobals()
 */

class Request {

    /** @var array Cleaned $_GET data */
    protected $query = [];

    /** @var array Cleaned $_POST data */
    protected $post = [];

    /** @var array Cleaned $_COOKIE data */
    protected $cookies = [];

    /** @var array $_SERVER data (not cleaned — contains binary/paths) */
    protected $server = [];

    /** @var array Merged input (post + query) */
    protected $input = [];

    /** @var string Raw POST body */
    protected $rawBody = null;

    /** @var string Client IP address */
    protected $clientIp = null;

    /** @var Request|null Captured singleton for static access */
    protected static $captured = null;

    /**
     * Create from raw superglobals
     *
     * @param array $query $_GET
     * @param array $post $_POST
     * @param array $server $_SERVER
     * @param array $cookies $_COOKIE
     */
    public function __construct(array $query = [], array $post = [], array $server = [], array $cookies = []) {
        // Clean input data
        self::cleanGlobals($query);
        self::cleanGlobals($post);
        self::cleanGlobals($cookies);

        $this->query   = self::parseIncomingRecursively($query);
        $this->post    = self::parseIncomingRecursively($post);
        $this->cookies = $cookies;
        $this->server  = $server;

        // POST takes priority over GET (same as $_REQUEST default)
        $this->input = array_merge($this->query, $this->post);
    }

    /**
     * Capture current request from PHP superglobals
     *
     * Creates a Request object from the current $_GET, $_POST,
     * $_SERVER, $_COOKIE. Also cleans the original superglobals
     * for backward compatibility.
     *
     * @return Request
     */
    public static function capture() {
        if (self::$captured !== null) {
            return self::$captured;
        }

        // Clean original superglobals (backward compat — old code reads them directly)
        self::cleanGlobals($_GET);
        self::cleanGlobals($_POST);
        self::cleanGlobals($_REQUEST);
        self::cleanGlobals($_COOKIE);

        self::$captured = new self($_GET, $_POST, $_SERVER, $_COOKIE);

        return self::$captured;
    }

    /**
     * Get the current captured instance (or null if not captured)
     *
     * @return Request|null
     */
    public static function getInstance() {
        return self::$captured;
    }

    // ───────────────────────────────────────────────────────────
    //  Input Access
    // ───────────────────────────────────────────────────────────

    /**
     * Get a value from merged input (POST first, then GET)
     *
     * @param string $key Parameter name
     * @param mixed $default Default if not found
     * @return mixed
     */
    public function input($key, $default = null) {
        return isset($this->input[$key]) ? $this->input[$key] : $default;
    }

    /**
     * Get a value from $_GET (query string)
     *
     * @param string|null $key Parameter name (null returns all)
     * @param mixed $default Default if not found
     * @return mixed
     */
    public function get($key = null, $default = null) {
        if ($key === null) {
            return $this->query;
        }
        return isset($this->query[$key]) ? $this->query[$key] : $default;
    }

    /**
     * Get a value from $_POST
     *
     * @param string|null $key Parameter name (null returns all)
     * @param mixed $default Default if not found
     * @return mixed
     */
    public function post($key = null, $default = null) {
        if ($key === null) {
            return $this->post;
        }
        return isset($this->post[$key]) ? $this->post[$key] : $default;
    }

    /**
     * Get all merged input data
     *
     * @return array
     */
    public function all() {
        return $this->input;
    }

    /**
     * Check if a key exists in input
     *
     * @param string $key
     * @return bool
     */
    public function has($key) {
        return isset($this->input[$key]);
    }

    /**
     * Get an integer value from input
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    public function getInt($key, $default = 0) {
        return intval($this->input($key, $default));
    }

    /**
     * Get a boolean value from input
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public function getBool($key, $default = false) {
        $val = $this->input($key, $default);
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    // ───────────────────────────────────────────────────────────
    //  Server / Headers
    // ───────────────────────────────────────────────────────────

    /**
     * Get a $_SERVER value
     *
     * @param string $key Server key (e.g., 'REQUEST_METHOD')
     * @param mixed $default
     * @return mixed
     */
    public function server($key, $default = null) {
        return isset($this->server[$key]) ? $this->server[$key] : $default;
    }

    /**
     * Get a cookie value
     *
     * @param string $key Cookie name
     * @param mixed $default
     * @return mixed
     */
    public function cookie($key, $default = null) {
        return isset($this->cookies[$key]) ? $this->cookies[$key] : $default;
    }

    /**
     * Get HTTP request method
     *
     * @return string GET, POST, PUT, DELETE, etc.
     */
    public function method() {
        return $this->server('REQUEST_METHOD', 'GET');
    }

    /**
     * Check if request is POST
     *
     * @return bool
     */
    public function isPost() {
        return $this->method() === 'POST';
    }

    /**
     * Check if request appears to be AJAX
     *
     * @return bool
     */
    public function isAjax() {
        return strtolower($this->server('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest';
    }

    /**
     * Get the request URI
     *
     * @return string
     */
    public function uri() {
        return $this->server('REQUEST_URI', '/');
    }

    /**
     * Get the User-Agent string
     *
     * @return string
     */
    public function userAgent() {
        return $this->server('HTTP_USER_AGENT', '');
    }

    /**
     * Get the client IP address
     *
     * Checks X-Forwarded-For and X-Real-IP headers for proxied requests.
     *
     * @return string
     */
    public function ip() {
        if ($this->clientIp !== null) {
            return $this->clientIp;
        }

        // Check common proxy headers
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            $value = $this->server($header);
            if (!empty($value)) {
                // X-Forwarded-For may contain multiple IPs — take the first
                $ip = trim(explode(',', $value)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $this->clientIp = $ip;
                    return $ip;
                }
            }
        }

        $this->clientIp = '0.0.0.0';
        return $this->clientIp;
    }

    /**
     * Get the raw POST body
     *
     * @return string
     */
    public function rawBody() {
        if ($this->rawBody === null) {
            $this->rawBody = file_get_contents('php://input') ?: '';
        }
        return $this->rawBody;
    }

    /**
     * Decode JSON from POST body
     *
     * @param bool $assoc Return as associative array
     * @return mixed|null
     */
    public function json($assoc = true) {
        $body = $this->rawBody();
        if (empty($body)) {
            return null;
        }
        return json_decode($body, $assoc);
    }

    /**
     * Get the Host header
     *
     * @return string
     */
    public function host() {
        return $this->server('HTTP_HOST', $this->server('SERVER_NAME', ''));
    }

    // ───────────────────────────────────────────────────────────
    //  Static Sanitization Methods (backward compatibility)
    // ───────────────────────────────────────────────────────────

    /**
     * Clean dangerous characters from input data (recursive)
     *
     * Replaces NULL bytes, path traversal, and RTL override characters.
     * Modifies the input array IN PLACE.
     *
     * @param array &$rData Input data (modified in place)
     * @param int $rIteration Recursion depth counter
     */
    public static function cleanGlobals(&$rData, $rIteration = 0) {
        if (10 > $rIteration) {
            foreach ($rData as $rKey => $rValue) {
                if (is_array($rValue)) {
                    self::cleanGlobals($rData[$rKey], ++$rIteration);
                } else {
                    $rValue = str_replace(chr('0'), '', $rValue);
                    $rValue = str_replace("\x0", '', $rValue);
                    $rValue = str_replace('../', '&#46;&#46;/', $rValue);
                    $rValue = str_replace('&#8238;', '', $rValue);
                    $rData[$rKey] = $rValue;
                }
            }
        } else {
            return null;
        }
    }

    /**
     * Parse and clean input recursively
     *
     * Sanitizes both keys and values. Returns a new clean array.
     *
     * @param array &$rData Raw input data
     * @param array $rInput Accumulator (internal use)
     * @param int $rIteration Recursion depth counter
     * @return array Cleaned data
     */
    public static function parseIncomingRecursively(&$rData, $rInput = [], $rIteration = 0) {
        if (20 > $rIteration) {
            if (is_array($rData)) {
                foreach ($rData as $rKey => $rValue) {
                    if (is_array($rValue)) {
                        $rInput[$rKey] = self::parseIncomingRecursively($rData[$rKey], [], $rIteration + 1);
                    } else {
                        $rKey = self::parseCleanKey($rKey);
                        $rValue = self::parseCleanValue($rValue);
                        $rInput[$rKey] = $rValue;
                    }
                }
                return $rInput;
            } else {
                return $rInput;
            }
        } else {
            return $rInput;
        }
    }

    /**
     * Sanitize an input key
     *
     * @param string $rKey
     * @return string
     */
    public static function parseCleanKey($rKey) {
        if ($rKey !== '') {
            $rKey = htmlspecialchars(urldecode($rKey));
            $rKey = str_replace('..', '', $rKey);
            $rKey = preg_replace('/\\_\\_(.+?)\\_\\_/', '', $rKey);
            return preg_replace('/^([\\w\\.\\-\\_]+)$/', '$1', $rKey);
        }
        return '';
    }

    /**
     * Sanitize an input value
     *
     * Strips dangerous HTML patterns and normalizes line breaks.
     *
     * @param string $rValue
     * @return string
     */
    public static function parseCleanValue($rValue) {
        if ($rValue != '') {
            $rValue = str_replace('&#032;', ' ', stripslashes($rValue));
            $rValue = str_replace(["\r\n", "\n\r", "\r"], "\n", $rValue);
            $rValue = str_replace('<!--', '&#60;&#33;--', $rValue);
            $rValue = str_replace('-->', '--&#62;', $rValue);
            $rValue = str_ireplace('<script', '&#60;script', $rValue);
            $rValue = preg_replace('/&amp;#([0-9]+);/s', '&#\\1;', $rValue);
            $rValue = preg_replace('/&#(\\d+?)([^\\d;])/i', '&#\\1;\\2', $rValue);
            return trim($rValue);
        }
        return '';
    }
}
