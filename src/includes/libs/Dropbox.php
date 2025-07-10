<?php

/**
 * DropPHP v2 - A simple Dropbox client that works without cURL.
 *
 * http://fabi.me/en/php-projects/dropphp-dropbox-api-client/
 *
 *
 * @author     Fabian Schlieper <fabian@fabi.me>
 * @copyright  Fabian Schlieper 2017
 * @version    2.0.0
 * @license    See LICENSE
 *
 */
class DropboxClient {
	public const API_URL = 'https://api.dropboxapi.com/';
	public const API_CONTENT_URL = 'https://content.dropboxapi.com/';
	public const BUFFER_SIZE = 4096;
	public const MAX_UPLOAD_CHUNK_SIZE = 157286400;
	public const UPLOAD_CHUNK_SIZE = 2097152;

	private $appParams;
	private $consumerToken;
	private $requestToken;
	private $accessToken;
	private $rootPath;
	private $useCurl;
	private $curlOptions;
	private $_redirectUri;
	private static $_curlHeadersRef;

	/**
	 * DropboxClient constructor.
	 *
	 * @param array $app_params ['app_key' => ..., 'app_secret' => ..., 'app_full_access' => ...]
	 *
	 * @param string $_deprecatedLocale Deprecated.
	 *
	 * @throws DropboxException
	 */
	public function __construct($app_params = array(), $_deprecatedLocale = 'en') {
		$this->appParams = $app_params;
		$this->consumerToken = array('t' => $this->appParams['app_key'], 's' => $this->appParams['app_secret']);
		$this->rootPath = (empty($app_params['app_full_access']) ? 'sandbox' : 'dropbox');
		$this->requestToken = null;
		$this->accessToken = null;
		$this->useCurl = function_exists('curl_init');
	}

	public function __wakeup() {
		if (!empty($this->accessToken['s'])) {
			$this->accessToken = null;
		}
		$this->useCurl = $this->useCurl && function_exists('curl_init');
	}

	/**
	 * Sets whether to use cURL if its available or PHP HTTP wrappers otherwise
	 *
	 * @param boolean $use_it whether to use it or not
	 * @param array $curlOptions
	 *
	 * @return bool Whether to actually use cURL (always false if not installed)
	 * @throws DropboxException
	 */
	public function SetUseCUrl($use_it, $curlOptions = array()) {
		if (!$use_it && !empty($curlOptions)) {
			throw new DropboxException('not using cURL but specified cURL options');
		}

		$this->curlOptions = $curlOptions;

		return $this->useCurl = $use_it && function_exists('curl_init');
	}

	/**
	 * Step 1. Returns a URL the user must be redirected to in order to connect the app to their Dropbox account
	 *
	 * @param string $redirect_uri URL users are redirected after authorization
	 * @param string $state Up to 500 bytes of arbitrary data passed back to $redirect_uri
	 *
	 * @return string URL
	 */
	public function BuildAuthorizeUrl($redirect_uri, $state = '') {
		$this->_redirectUri = $redirect_uri;

		return 'https://www.dropbox.com/oauth2/authorize?response_type=code&client_id=' . $this->appParams['app_key'] . '&redirect_uri=' . urlencode($redirect_uri) . '&state=' . urlencode($state);
	}

	/**
	 * Step 2.
	 *
	 * @param string $code The code GET param Dropbox generates when HTTP-redirecting the client
	 * @param string $redirect_uri The same redirect_uri as passed to BuildAuthorizeUrl() before, used for validation
	 *
	 * @return array
	 * @throws DropboxException
	 */
	public function GetBearerToken($code = '', $redirect_uri = '') {
		if (!empty($this->accessToken)) {
			return $this->accessToken;
		}

		if (empty($code)) {
			$code = filter_input(INPUT_GET, 'code', FILTER_SANITIZE_STRING);

			if (empty($code)) {
				throw new DropboxException('Missing OAuth2 code parameter!');
			}
		}

		if (!empty($redirect_uri)) {
			$this->_redirectUri = $redirect_uri;
		}

		if (empty($this->_redirectUri)) {
			throw new DropboxException('Redirect URI unknown, please specify or call BuildAuthorizeUrl() before!');
		}

		$res = $this->apiCall('oauth2/token', array('code' => $code, 'grant_type' => 'authorization_code', 'client_id' => $this->appParams['app_key'], 'client_secret' => $this->appParams['app_secret'], 'redirect_uri' => $this->_redirectUri));

		if (empty($res) || empty($res->access_token)) {
			throw new DropboxException(sprintf('Could not get bearer token! (code: %s)', $code));
		}

		return $this->accessToken = array('t' => $res->access_token, 'account_id' => $res->account_id);
	}

	/**
	 * Sets a previously retrieved (and stored) bearer token.
	 *
	 * @param array|object $token
	 *
	 * @throws DropboxException
	 */
	public function SetBearerToken($token) {
		$token = (array) $token;

		if (empty($token['t'])) {
			throw new DropboxException('Passed invalid bearer token.');
		}

		$this->accessToken = $token;
	}

	/**
	 * Checks if an access token has been set.
	 *
	 * @access public
	 * @return boolean Authorized or not
	 */
	public function IsAuthorized() {
		return !empty($this->accessToken) && !empty($this->accessToken['account_id']);
	}

	/**
	 * Retrieves information about the user's account.
	 *
	 * @access public
	 * @return object Account info object. See https://www.dropbox.com/developers/documentation/http/documentation#users-get_current_account
	 */
	public function GetAccountInfo() {
		$info = $this->apiCall('2/users/get_current_account');
		$info->uid = $info->account_id;
		$info->name_details = $info->name;
		$info->display_name = $info->name->display_name;

		return $info;
	}

	/**
	 * @param string $path
	 * @param bool $recursive
	 * @param bool $include_deleted
	 *
	 * @return mixed
	 * @throws DropboxException
	 */
	public function GetFiles($path = '', $recursive = false, $include_deleted = false) {
		if (is_object($path) && !empty($path->path)) {
			$path = $path->path;
		}

		if ($path === '/') {
			$path = '';
		}

		$res = $this->apiCall('2/files/list_folder', compact('path', 'recursive', 'include_deleted'));
		$entries = $res->entries;

		while ($res->has_more) {
			$res = $this->apiCall('2/files/list_folder/continue', array('cursor' => $res->cursor));
			$entries = array_merge($entries, $res->entries);
		}

		$entries_assoc = array();

		foreach ($entries as $entry) {
			$entries_assoc[trim($entry->path_display, '/')] = $entry;
		}

		return array_map(array(__CLASS__, 'compatMeta'), $entries_assoc);
	}

	/**
	 * See https://www.dropbox.com/developers/documentation/http/documentation#files-get_metadata
	 *
	 * @param $path
	 * @param bool $include_deleted
	 * @param null $rev
	 *
	 * @return mixed
	 * @throws DropboxException
	 */
	public function GetMetadata($path, $include_deleted = false, $rev = null) {
		if (is_object($path) && !empty($path->path)) {
			$path = $path->path;
		}

		if (!empty($rev)) {
			$path = 'rev:' . $rev;
		}

		return self::compatMeta($this->apiCall('2/files/get_metadata', compact('path', 'include_deleted')));
	}

	/**
	 * Download a file from user's Dropbox to the web server
	 *
	 * @param string|object $path Dropbox path or metadata object of the file to download.
	 * @param string $dest_path Local path for destination
	 * @param string $rev Optional. The revision of the file to retrieve. This defaults to the most recent revision.
	 * @param callback $progress_changed_callback Optional. Callback that will be called during download with 2 args: 1. bytes loaded, 2. file size
	 *
	 * @return object Dropbox file metadata
	 * @throws DropboxException
	 */
	public function DownloadFile($path, $dest_path = '', $rev = null, $progress_changed_callback = null) {
		if (is_object($path) && !empty($path->path)) {
			$path = $path->path;
		}

		if (empty($dest_path)) {
			$dest_path = basename($path);
		}

		$url = self::cleanUrl('https://content.dropboxapi.com/2/files/download');

		if (!empty($rev)) {
			$path = 'rev:' . $rev;
		}

		$context = $this->createRequestContext($url, compact('path'));
		$fh = @fopen($dest_path, 'wb');

		if ($fh === false) {
			throw new DropboxException('Could not create file ' . $dest_path . ' !');
		}

		if ($this->useCurl) {
			curl_setopt($context, CURLOPT_BINARYTRANSFER, true);
			curl_setopt($context, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($context, CURLOPT_FILE, $fh);
			$response_headers = array();
			self::execCurlAndClose($context, $response_headers);
			fclose($fh);
			$meta = self::getMetaFromHeaders($response_headers, true);
			$bytes_loaded = filesize($dest_path);
		} else {
			$rh = @fopen($url, 'rb', false, $context);

			if ($rh === false) {
				throw new DropboxException('HTTP request to ' . $url . ' failed!');
			}

			$s_meta = stream_get_meta_data($rh);
			$meta = self::getMetaFromHeaders($s_meta['wrapper_data'], true);
			$bytes_loaded = 0;

			while (!feof($rh)) {
				if (($s = fwrite($fh, fread($rh, 4096))) === false) {
					@fclose($rh);
					@fclose($fh);

					throw new DropboxException('Writing to file ' . $dest_path . ' failed!\'');
				}

				$bytes_loaded += $s;

				if (!empty($progress_changed_callback)) {
					call_user_func($progress_changed_callback, $bytes_loaded, $meta->bytes);
				}
			}

			fclose($rh);
			fclose($fh);
		}

		if ($meta->size != $bytes_loaded) {
			throw new DropboxException('Download size mismatch! (header:' . $meta->size . ' vs actual:' . $bytes_loaded . '; curl:' . $this->useCurl . ')');
		}

		return $meta;
	}

	public static function compatMeta($meta) {
		$meta->is_dir = $meta->{'.tag'} == 'folder';
		$meta->path = $meta->path_display;
		$meta->bytes = (isset($meta->size) ? $meta->size : 0);

		return $meta;
	}

	/**
	 * Upload a file to dropbox
	 *
	 * @access public
	 *
	 * @param string $src_file Local file to upload
	 * @param string $path Dropbox path for destination
	 * @param bool $overwrite
	 *
	 * @return object Dropbox file metadata
	 * @throws DropboxException
	 */
	public function UploadFile($src_file, $path = '', $overwrite = true) {
		if (empty($path)) {
			$path = basename($src_file);
		}

		$path = self::toPath($path);
		$dropbox_bn = basename($path);

		if (strpos($dropbox_bn, '.') === false) {
			try {
				$meta = $this->GetMetadata($path);

				if ($meta && $meta->is_dir) {
					$path = self::toPath($path . '/' . basename($src_file));
				}
			} catch (Exception $e) {
			}
		}

		$file_size = filesize($src_file);
		$commit_params = array('path' => $path, 'mode' => $overwrite ? 'overwrite' : 'add', 'autorename' => true);

		if (2097152 < $file_size) {
			$fh = fopen($src_file, 'rb');

			if ($fh === false) {
				throw new DropboxException('Cannot open ' . $src_file . ' for reading!');
			}

			$offset = 0;
			$res = $this->apiCall('2/files/upload_session/start', array(), true);
			$session_id = $res->session_id;

			while (!feof($fh)) {
				$content = fread($fh, 2097152);
				$this->apiCall('2/files/upload_session/append_v2', array('cursor' => compact('session_id', 'offset')), true, $content);
				$offset += strlen($content);
				unset($content);

				if ($file_size <= $offset) {
					break;
				}
			}

			@fclose($fh);

			return $this->apiCall('2/files/upload_session/finish', array('cursor' => compact('session_id', 'offset'), 'commit' => $commit_params), true);
		} else {
			$content = file_get_contents($src_file);

			return $this->apiCall('2/files/upload', $commit_params, true, $content);
		}
	}

	/**
	 * Get thumbnail for a specified image
	 *
	 * @access public
	 *
	 * @param $dropbox_file string Path to the image
	 * @param $format string Image format of the thumbnail (jpeg or png)
	 * @param bool $echo
	 * @param $size string Thumbnail size (xs, s, m, l, xl)
	 *
	 * @return string Returns the thumbnail as binary image data
	 */
	public function GetThumbnail($dropbox_file, $size = 's', $format = 'jpeg', $echo = false) {
		$path = self::toPath($dropbox_file);
		$size_transform = array('xs' => 'w32h32', 's' => 'w64h64', 'm' => 'w128h128', 'l' => 'w640h480', 'xl' => 'w1024h768');

		if (isset($size_transform[$size])) {
			$size = $size_transform[$size];
		}

		$url = 'https://content.dropboxapi.com/2/files/get_thumbnail';
		$context = $this->createRequestContext($url, compact('path', 'size', 'format'));
		$thumb = ($this->useCurl ? self::execCurlAndClose($context) : file_get_contents($url, false, $context));

		if ($echo) {
			header('Content-type: image/' . $format);
			echo $thumb;
			unset($thumb);

			return '';
		}

		return $thumb;
	}

	public function GetLink($path, $preview = true, $_short = true, &$expires = null) {
		$path = self::toPath($path);

		if (!$preview) {
			$data = $this->apiCall('2/files/get_temporary_link', array('path' => $path));
			$expires = (time() + 14400) - 60;

			return $data->link;
		} else {
			try {
				$url = $this->apiCall('2/sharing/create_shared_link_with_settings', array(
					'path' => $path,
					'settings' => array('requested_visibility' => 'public')
				));
			} catch (DropboxException $ex) {
				if ($ex->getTag() == 'shared_link_already_exists') {
					$publicLinks = array_filter($this->apiCall('2/sharing/list_shared_links', array('path' => $path))->links, function ($link) {
						return ($link->{'.tag'} == 'file') && ($link->link_permissions->resolved_visibility->{'.tag'} == 'public');
					});
					$url = reset($publicLinks);
				} else {
					throw $ex;
				}
			}

			return $url->url;
		}
	}

	public function Delta($cursor) {
		return $this->apiCall('2/files/list_folder/continue', array_merge(compact('cursor'), array()));
	}

	public function LatestCursor($path = '', $include_media_info = false) {
		$res = $this->apiCall('2/files/list_folder/get_latest_cursor', compact('path', 'include_media_info'));

		return $res->cursor;
	}

	public function GetRevisions($path, $limit = 10) {
		$path = self::toPath($path);

		return $this->apiCall('2/files/list_revisions', compact('path', 'limit'))->entries;
	}

	public function Restore($dropbox_file, $rev) {
		if (is_object($dropbox_file) && !empty($dropbox_file->path)) {
			$dropbox_file = $dropbox_file->path;
		}

		return $this->apiCall('restore/' . $this->rootPath . '/' . $dropbox_file, compact('rev'));
	}

	public function Search($path, $query, $max_results = 1000, $include_deleted = false) {
		$path = self::toPath($path);
		$mode = ($include_deleted ? 'deleted_filename' : 'filename');
		$meta = array();

		foreach ($this->apiCall('2/files/search', compact('path', 'query', 'max_results', 'mode'))->matches as $match) {
			$meta[] = self::compatMeta($match->metadata);
		}

		return $meta;
	}

	public function GetCopyRef($dropbox_file, &$expires = null) {
		if (is_object($dropbox_file) && !empty($dropbox_file->path)) {
			$dropbox_file = $dropbox_file->path;
		}

		$ref = $this->apiCall('copy_ref/' . $this->rootPath . '/' . $dropbox_file, 'GET');
		$expires = strtotime($ref->expires);

		return $ref->copy_ref;
	}

	public function Copy($from_path, $to_path, $copy_ref = false) {
		if (is_object($from_path) && !empty($from_path->path)) {
			$from_path = $from_path->path;
		}

		return $this->apiCall('fileops/copy', array('root' => $this->rootPath, $copy_ref ? 'from_copy_ref' : 'from_path' => $from_path, 'to_path' => $to_path));
	}

	/**
	 * Creates a new folder in the DropBox
	 *
	 * @access public
	 *
	 * @param $path string The path to the new folder to create
	 * @param bool $autorename
	 *
	 * @return object Dropbox folder metadata
	 */
	public function CreateFolder($path, $autorename = false) {
		$res = $this->apiCall('2/files/create_folder_v2', array('path' => $path, 'autorename' => $autorename));
		$res->metadata->{'.tag'} = 'folder';

		return self::compatMeta($res->metadata);
	}

	/**
	 * Delete file or folder
	 *
	 * @param $path mixed The path or metadata of the file/folder to be deleted.
	 *
	 * @return object Dropbox metadata of deleted file or folder
	 */
	public function Delete($path) {
		if (is_object($path) && !empty($path->path)) {
			$path = $path->path;
		}

		$res = $this->apiCall('2/files/delete_v2', array('path' => $path));

		return self::compatMeta($res->metadata);
	}

	public function Move($from_path, $to_path) {
		if (is_object($from_path) && !empty($from_path->path)) {
			$from_path = $from_path->path;
		}

		return $this->apiCall('fileops/move', array('root' => $this->rootPath, 'from_path' => $from_path, 'to_path' => $to_path));
	}

	private function createCurl($url, $http_context) {
		$ch = curl_init($url);
		$curl_opts = array(CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_BINARYTRANSFER => true);
		$curl_opts[CURLOPT_CUSTOMREQUEST] = $http_context['method'];

		if (!empty($http_context['content'])) {
			$curl_opts[CURLOPT_POSTFIELDS] = &$http_context['content'];

			if (defined('CURLOPT_POSTFIELDSIZE')) {
				$curl_opts[CURLOPT_POSTFIELDSIZE] = strlen($http_context['content']);
			}
		}

		$curl_opts[CURLOPT_HTTPHEADER] = array_map('trim', explode("\n", $http_context['header']));

		if (!empty($this->curlOptions) && is_array($this->curlOptions)) {
			$curl_opts = array_merge($curl_opts, $this->curlOptions);
		}

		curl_setopt_array($ch, $curl_opts);

		return $ch;
	}

	private static function _curlHeaderCallback($ch, $header) {
		self::$_curlHeadersRef[] = trim($header);

		return strlen($header);
	}

	private static function &execCurlAndClose($ch, &$out_response_headers = null) {
		if (is_array($out_response_headers)) {
			self::$_curlHeadersRef = &$out_response_headers;
			curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(__CLASS__, '_curlHeaderCallback'));
		}

		$res = curl_exec($ch);
		$err_no = curl_errno($ch);
		$err_str = curl_error($ch);
		curl_close($ch);

		if ($err_no || ($res === false)) {
			throw new DropboxException('cURL-Error (' . $err_no . '): ' . $err_str);
		}

		return $res;

		return null;
	}

	/**
	 * @param $url string
	 * @param $params
	 * @param string $content
	 * @param int $bearer_token
	 *
	 * @return resource
	 */
	private function createRequestContext($url, $params, &$content = '', $bearer_token = -1) {
		if ($bearer_token === -1) {
			$bearer_token = $this->accessToken['t'];
		}

		$http_context = array('method' => 'POST', 'header' => '', 'content' => '');

		if (strpos($url, '/oauth2/token') !== false) {
			$http_context['header'] .= 'Content-Type: application/x-www-form-urlencoded' . "\r\n";
			$http_context['content'] = http_build_query($params);
		} else {
			if (!empty($bearer_token)) {
				$http_context['header'] .= 'Authorization: Bearer ' . $bearer_token . "\r\n";
			}

			if (empty($content) && (strpos($url, 'https://content.dropboxapi.com/') === false)) {
				if (!empty($params)) {
					$http_context['header'] .= 'Content-Type: application/json' . "\r\n";
					$http_context['content'] = json_encode($params);
				}
			} else {
				$http_context['header'] .= 'Dropbox-API-Arg: ' . str_replace('"', '"', json_encode((object) $params)) . "\r\n";
				$http_context['header'] .= 'Content-Type: application/octet-stream' . "\r\n";

				if (!empty($content)) {
					$http_context['content'] = $content;
				}
			}
		}

		if (strpos($url, 'https://content.dropboxapi.com/') === false) {
			$http_context['header'] .= 'Content-Length: ' . strlen($http_context['content']);
		}

		$http_context['header'] = trim($http_context['header']);
		$http_context['ignore_errors'] = true;

		return $this->useCurl ? $this->createCurl($url, $http_context) : stream_context_create(array('http' => $http_context));
	}

	/**
	 * @param object $resp
	 * @param string $context
	 *
	 * @return object
	 * @throws DropboxException
	 */
	private static function checkForError($resp, $context = null) {
		if (!empty($resp->error)) {
			throw new DropboxException($resp, $context);
		}

		return $resp;
	}

	/**
	 * @param string $path
	 * @param array $params
	 * @param bool $content_call
	 * @param string $content
	 *
	 * @return object
	 * @throws DropboxException
	 */
	private function doSingleCall($path, $params = array(), $content_call = false, &$content = null) {
		$url = self::cleanUrl(($content_call ? 'https://content.dropboxapi.com/' : 'https://api.dropboxapi.com/') . $path);
		$context = $this->createRequestContext($url, $params, $content);
		$json = ($this->useCurl ? self::execCurlAndClose($context) : file_get_contents($url, false, $context));
		$resp = json_decode($json);

		if (is_null($resp) && $content_call) {
			return null;
		}

		if (is_null($resp) && !empty($json)) {
			throw new DropboxException('apiCall(' . $path . ') failed: ' . $json . ' (URL was ' . $url . ')');
		}

		if ((($resp === false) || is_null($resp)) && !empty($json) && !$content_call) {
			throw new DropboxException('Error apiCall(' . $path . '): ' . $json);
		}

		return self::checkForError($resp, 'apiCall(' . $path . ')');
	}

	/**
	 * @param object $target
	 * @param object $part
	 */
	private static function mergeContinue(&$target, $part) {
		$keys = array_keys(get_object_vars($target));

		foreach ($keys as $k) {
			if (is_array($target->{$k}) && !empty($part->{$k}) && is_array($part->{$k})) {
				$target->{$k} = array_merge($target->{$k}, $part->{$k});
			}
		}

		$target->has_more = $part->has_more;
		$target->cursor = $part->cursor;
	}

	/**
	 * @param string $path
	 * @param array $params
	 * @param bool $content_call
	 * @param string $content
	 *
	 * @return object
	 * @throws DropboxException
	 */
	private function apiCall($path, $params = array(), $content_call = false, &$content = null) {
		$resp = $this->doSingleCall($path, $params, $content_call, $content);

		if (!empty($resp->has_more) && (strpos($path, '/continue') === false)) {
			$path .= '/continue';
		}

		while (!$content_call && !empty($resp->has_more)) {
			if (empty($resp->cursor)) {
				throw new DropboxException('Unexpected response from ' . $path . ': has_more without cursor!');
			}

			$params['cursor'] = (is_string($resp->cursor) ? $resp->cursor : $resp->cursor->value);
			self::mergeContinue($resp, $this->doSingleCall($path, $params, $content_call, $content));
		}

		return $resp;
	}

	private static function getMetaFromHeaders(&$header_array, $throw_on_error = false) {
		$obj = json_decode(substr(@array_shift(array_filter($header_array, function ($s) {
			return stripos($s, 'dropbox-api-result:') === 0;
		})), 20));

		if ($throw_on_error && (empty($obj) || !is_object($obj))) {
			throw new DropboxException('Could not retrieve meta data from header data: ' . print_r($header_array, true));
		}

		if ($throw_on_error) {
			self::checkForError($obj, __FUNCTION__);
		}

		return self::compatMeta($obj);
	}

	private static function toPath($file_or_path) {
		if (is_object($file_or_path)) {
			$file_or_path = $file_or_path->path;
		}

		$file_or_path = '/' . trim($file_or_path, '/');

		if ($file_or_path == '/') {
			$file_or_path = '';
		}

		return $file_or_path;
	}

	private static function cleanUrl($url) {
		$p = substr($url, 0, 8);
		$url = str_replace('//', '/', str_replace('\\', '/', substr($url, 8)));
		$url = rawurlencode($url);
		$url = str_replace('%2F', '/', $url);

		return $p . $url;
	}

	public static function contentHashStream($stream, $chunksize = 8192) {
		static $BLOCK_SIZE = 4194304;
		$streamhasher = hash_init('sha256');
		$blockhasher = hash_init('sha256');
		$current_block = 1;
		$current_blocksize = 0;

		while (!feof($stream)) {
			$max_bytes_to_read = min($chunksize, $BLOCK_SIZE - $current_blocksize);
			$chunk = fread($stream, $max_bytes_to_read);

			if (strlen($chunk) == 0) {
				break;
			}

			hash_update($blockhasher, $chunk);
			$current_blocksize += $max_bytes_to_read;

			if ($current_blocksize == $BLOCK_SIZE) {
				$blockhash = hash_final($blockhasher, true);
				hash_update($streamhasher, $blockhash);
				$blockhasher = hash_init('sha256');
				++$current_block;
				$current_blocksize = 0;
			}
		}

		if (0 < $current_blocksize) {
			$blockhash = hash_final($blockhasher, true);
			hash_update($streamhasher, $blockhash);
		}

		return hash_final($streamhasher);
	}

	public static function contentHashFile($localFileName) {
		$handle = fopen($localFileName, 'r');
		$hash = self::contentHashStream($handle);
		fclose($handle);

		return $hash;
	}

	/**
	 * @deprecated
	 * @throws DropboxException
	 */
	public function GetRequestToken() {
		throw new DropboxException('GetRequestToken() has been removed with v2 API. Request tokens do not exist in OAuth2 anymore.');
	}

	/**
	 * @deprecated
	 * @throws DropboxException
	 */
	public function GetAccessToken() {
		throw new DropboxException('GetAccessToken() has been removed with v2 API. Use GetBearerToken() instead!');
	}
}

class DropboxException extends Exception {
	private $tag;

	public function __construct($resp = null, $context = null) {
		if (is_null($resp)) {
			$el = error_get_last();
			$this->message = $el['message'];
			$this->file = $el['file'];
			$this->line = $el['line'];
		} elseif (is_object($resp) && isset($resp->error)) {
			$this->message = (empty($resp->error_description) ? json_encode($resp) . ($context ? ', in ' . $context : '') : $resp->error_description);
			$this->tag = (is_object($resp->error) ? $resp->error->{'.tag'} : $resp->error);
		} else {
			$this->message = $resp . ($context ? ', in ' . $context : '');
		}
	}

	public function getTag() {
		return $this->tag;
	}
}
