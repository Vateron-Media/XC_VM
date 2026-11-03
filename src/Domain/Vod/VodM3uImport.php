<?php

namespace XcVm\Domain\Vod;

use XcVm\Core\Cluster\NodeRpc;
use XcVm\Domain\Server\ServerRepository;

/** M3U input shared by movie and series imports. */
final class VodM3uImport {
	private const MAX_BYTES = 10485760;
	public const MEDIA_EXTENSIONS = ['mp4', 'mkv', 'avi', 'mpg', 'mpeg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts', 'webm'];

	public static function hasPlaylist(array $rData): bool {
		$rFile = $_FILES['m3u_file'] ?? [];
		return !empty($rFile['tmp_name']) || (isset($rFile['error']) && $rFile['error'] !== UPLOAD_ERR_NO_FILE) || trim((string) ($rData['m3u_url'] ?? '')) !== '';
	}

	/** @return list<array{url: string, title: string, container: string}> */
	public static function entries(array $rData, bool $rSelect = true): array {
		$rFile = $_FILES['m3u_file'] ?? [];
		if (!empty($rFile['tmp_name']) || (isset($rFile['error']) && $rFile['error'] !== UPLOAD_ERR_NO_FILE)) {
			if (($rFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !in_array(strtolower(pathinfo((string) ($rFile['name'] ?? ''), PATHINFO_EXTENSION)), ['m3u', 'm3u8'], true) || filesize($rFile['tmp_name']) > self::MAX_BYTES) {
				throw new \InvalidArgumentException('vod_m3u_invalid');
			}
			$rContent = file_get_contents($rFile['tmp_name']);
		} else {
			$rContent = self::fetch(trim((string) ($rData['m3u_url'] ?? '')));
		}
		if (!is_string($rContent) || trim($rContent) === '') {
			throw new \InvalidArgumentException('vod_m3u_invalid');
		}
		$rEntries = self::parse($rContent, $rData);
		if ($rSelect && isset($rData['m3u_selection']) && $rData['m3u_selection'] !== '') {
			$rSelection = json_decode($rData['m3u_selection'], true);
			if (!is_array($rSelection)) {
				throw new \InvalidArgumentException('vod_m3u_invalid');
			}
			$rSelected = array_fill_keys(array_filter($rSelection, static fn($rID): bool => is_string($rID) && preg_match('/^[a-f0-9]{64}$/D', $rID) === 1), true);
			$rEntries = array_values(array_filter($rEntries, static fn(array $rEntry): bool => isset($rSelected[hash('sha256', $rEntry['url'])])));
		}
		return $rEntries;
	}

	/** @return list<array{url: string, title: string, container: string}> */
	public static function parse(string $rContent, array $rData = []): array {
		$rParser = new \M3uParser\M3uParser();
		$rParser->addDefaultTags();
		$rParsed = $rParser->parse($rContent);
		$rEntries = [];
		$rSeen = [];
		foreach ($rParsed as $rEntry) {
			$rURL = str_replace(' ', '%20', trim((string) $rEntry->getPath()));
			if (!self::httpUrl($rURL) || isset($rSeen[$rURL])) {
				continue;
			}
			$rSeen[$rURL] = true;
			$rTag = $rEntry->getExtTags()[0] ?? null;
			$rTitle = $rTag instanceof \M3uParser\Tag\ExtInf ? $rTag->getTitle() : '';
			$rExtension = strtolower(pathinfo((string) parse_url($rURL, PHP_URL_PATH), PATHINFO_EXTENSION));
			$rContainer = (!empty($rData['movie_symlink']) || !empty($rData['direct_source'])) ? $rExtension : (string) ($rData['target_container'] ?? 'mp4');
			$rEntries[] = ['url' => $rURL, 'title' => $rTitle ?: basename((string) parse_url($rURL, PHP_URL_PATH)), 'container' => $rContainer ?: 'mp4'];
		}
		return $rEntries;
	}

	public static function httpUrl(string $rURL): bool {
		return filter_var($rURL, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($rURL, PHP_URL_SCHEME)), ['http', 'https'], true);
	}

	private static function fetch(string $rURL): string {
		if (!self::httpUrl($rURL)) {
			throw new \InvalidArgumentException('vod_m3u_invalid');
		}
		$rContent = '';
		$rCurl = curl_init($rURL);
		curl_setopt_array($rCurl, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_FAILONERROR => true, CURLOPT_WRITEFUNCTION => static function ($rCurl, string $rChunk) use (&$rContent): int {
			if (strlen($rContent) + strlen($rChunk) > self::MAX_BYTES) {
				return 0;
			}
			$rContent .= $rChunk;
			return strlen($rChunk);
		}
		]);
		$rOK = curl_exec($rCurl);
		curl_close($rCurl);
		if (!$rOK) {
			throw new \InvalidArgumentException('vod_m3u_invalid');
		}
		return $rContent;
	}

	/** Validate on the destination node before accepting the batch. */
	public static function destination(array $rData): ?array {
		if (empty($rData['m3u_download'])) {
			return null;
		}
		if (!self::hasPlaylist($rData) || !preg_match('/^s:([1-9][0-9]*):(\/[^\x00]*)$/s', (string) ($rData['vod_download_path'] ?? ''), $rParts)) {
			throw new \InvalidArgumentException('vod_download_invalid_path');
		}
		$rServerID = (int) $rParts[1];
		if (!isset(ServerRepository::getAll()[$rServerID])) {
			throw new \InvalidArgumentException('vod_download_invalid_path');
		}
		$rReply = json_decode((string) NodeRpc::request($rServerID, ['action' => 'vod_download', 'function' => 'validate', 'directory' => $rParts[2]]), true);
		if (empty($rReply['result'])) {
			throw new \InvalidArgumentException('vod_download_invalid_path');
		}
		return ['server_id' => $rServerID, 'directory' => $rParts[2]];
	}
}
