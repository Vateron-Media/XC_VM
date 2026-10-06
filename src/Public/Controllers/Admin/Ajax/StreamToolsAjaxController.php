<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\CurlClient;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\SourceDriverRegistry;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Streaming\Codec\FfmpegPaths;

/**
 * Admin-ajax controller for "Streams & VOD" tools, lists and reviews.
 *
 * Actions: review_selection, review_bouquet, serieslist, streamlist,
 * adaptivelist, titlesync, probe_stream, check_stream, get_episode_ids.
 *
 * The Select2 search lists keep the `JSON_PARTIAL_OUTPUT_ON_ERROR` envelope,
 * and probe_stream/check_stream echo a raw HTML table rather than JSON.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class StreamToolsAjaxController extends BaseAjaxController {
	/** action=review_selection — resolve created-channel source streams for review. */
	public function reviewSelection(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'edit_cchannel'], ['adv', 'create_channel']]);

		global $db;
		$rReturn = ['streams' => [], 'result' => true];

		if (RequestManager::has('data')) {
			foreach (RequestManager::get('data') as $rStreamID) {
				$db->query('SELECT `id`, `stream_display_name`, `stream_source` FROM `streams` WHERE `id` = ?;', $rStreamID);

				if ($db->num_rows() == 1) {
					$rReturn['streams'][] = $db->get_row();
				}
			}
		}

		$this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	/** action=review_bouquet — resolve bouquet members (streams/movies/radios/series) for review. */
	public function reviewBouquet(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'edit_bouquet'], ['adv', 'add_bouquet']]);

		$rReturn = ['streams' => [], 'movies' => [], 'series' => [], 'radios' => [], 'result' => true];
		$this->collectBouquetItems($rReturn, 'streams', 'stream', 'SELECT `id`, `stream_display_name`, `type` FROM `streams` WHERE `id` = ? AND `type` IN (1,3);');
		$this->collectBouquetItems($rReturn, 'movies', 'movies', 'SELECT `id`, `stream_display_name`, `type` FROM `streams` WHERE `id` = ? AND `type` = 2;');
		$this->collectBouquetItems($rReturn, 'radios', 'radios', 'SELECT `id`, `stream_display_name`, `type` FROM `streams` WHERE `id` = ? AND `type` = 4;');
		$this->collectBouquetItems($rReturn, 'series', 'series', 'SELECT `id`, `title` FROM `streams_series` WHERE `id` = ?;');

		$this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	/** action=serieslist — Select2 series search. */
	public function serieslist(): never {
		$this->requireXhr();
		$this->gate('adv', 'episodes');

		global $db;
		$rReturn = ['total_count' => 0, 'items' => [], 'result' => true];

		if (RequestManager::has('search')) {
			$rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;
			$db->query('SELECT COUNT(`id`) AS `count` FROM `streams_series` WHERE `title` LIKE ?;', '%' . RequestManager::get('search') . '%');
			$rReturn['total_count'] = $db->get_row()['count'];
			$db->query('SELECT `id`, `title` FROM `streams_series` WHERE `title` LIKE ? ORDER BY `title` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . RequestManager::get('search') . '%');

			foreach ($db->get_rows() as $rRow) {
				$rReturn['items'][] = ['id' => $rRow['id'], 'text' => $rRow['title']];
			}
		}

		$this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	/** action=streamlist — Select2 stream search. */
	public function streamlist(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'manage_mag'], ['adv', 'streams']]);

		global $db;
		$rReturn = ['total_count' => 0, 'items' => [], 'result' => true];

		if (RequestManager::has('search')) {
			$rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;
			$db->query('SELECT COUNT(`id`) AS `id` FROM `streams` WHERE `stream_display_name` LIKE ?;', '%' . RequestManager::get('search') . '%');
			$rReturn['total_count'] = $db->get_row()['id'];
			$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `stream_display_name` LIKE ? ORDER BY `stream_display_name` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . RequestManager::get('search') . '%');

			foreach ($db->get_rows() as $rRow) {
				$rReturn['items'][] = ['id' => $rRow['id'], 'text' => $rRow['stream_display_name']];
			}
		}

		$this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	/** action=adaptivelist — Select2 live-stream search (id-prefixed labels). */
	public function adaptivelist(): never {
		$this->requireXhr();
		$this->gate('adv', 'edit_stream');

		global $db;
		$rReturn = ['total_count' => 0, 'items' => [], 'result' => true];

		if (RequestManager::has('search')) {
			$rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;
			$db->query('SELECT COUNT(`id`) AS `id` FROM `streams` WHERE (`stream_display_name` LIKE ? OR `id` LIKE ?) AND `type` = 1;', '%' . RequestManager::get('search') . '%', RequestManager::get('search') . '%');
			$rReturn['total_count'] = $db->get_row()['id'];
			$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE (`stream_display_name` LIKE ? OR `id` LIKE ?) AND `type` = 1 ORDER BY `stream_display_name` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . RequestManager::get('search') . '%', RequestManager::get('search') . '%');

			foreach ($db->get_rows() as $rRow) {
				$rReturn['items'][] = ['id' => $rRow['id'], 'text' => '[' . $rRow['id'] . '] ' . $rRow['stream_display_name']];
			}
		}

		$this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	/** action=titlesync — Select2 provider-stream search grouped by provider. */
	public function titlesync(): never {
		$this->requireXhr();
		$this->gate('adv', 'edit_stream');

		global $db;
		$rReturn = ['total_count' => 0, 'items' => [], 'result' => true];

		if (RequestManager::has('search')) {
			$rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;
			$db->query("SELECT COUNT(`stream_id`) AS `stream_id` FROM `providers_streams` WHERE `type` = 'live' AND (`stream_display_name` LIKE ? OR `stream_id` LIKE ?);", '%' . RequestManager::get('search') . '%', RequestManager::get('search') . '%');
			$rReturn['total_count'] = $db->get_row()['stream_id'];
			$db->query("SELECT `providers`.`name`, `providers_streams`.`provider_id`, `providers_streams`.`stream_id`, `providers_streams`.`stream_display_name` FROM `providers_streams` LEFT JOIN `providers` ON `providers`.`id` = `providers_streams`.`provider_id` WHERE `providers_streams`.`type` = 'live' AND (`stream_display_name` LIKE ? OR `stream_id` LIKE ?) ORDER BY `stream_display_name` ASC LIMIT " . ($rPage - 1) * 100 . ', 100;', '%' . RequestManager::get('search') . '%', RequestManager::get('search') . '%');
			$rGroups = [];

			foreach ($db->get_rows() as $rRow) {
				$rGroups[$rRow['provider_id']][] = $rRow;
			}

			foreach ($rGroups as $rRows) {
				$rGroup = ['text' => $rRows[0]['name'], 'children' => []];

				foreach ($rRows as $rRow) {
					$rGroup['children'][] = ['id' => $rRow['provider_id'] . '_' . $rRow['stream_id'], 'text' => '[' . $rRow['stream_id'] . '] ' . $rRow['stream_display_name']];
				}

				$rReturn['items'][] = $rGroup;
			}
		}

		$this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	/** action=probe_stream — probe a source URL and echo an HTML info table. */
	public function probeStream(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'add_stream'], ['adv', 'edit_stream']]);

		global $rServers;
		$rAnalyseDuration = abs(intval(SettingsManager::get('stream_max_analyze')));
		$rTimeout = intval($rAnalyseDuration / 1000000) + SettingsManager::get('probe_extra_wait');
		set_time_limit(intval($rTimeout));
		ini_set('max_execution_time', intval($rTimeout));
		ini_set('default_socket_timeout', intval($rTimeout));
		$rServerID = SERVER_ID;

		if (!empty(RequestManager::get('server')) && !empty($rServers[intval(RequestManager::get('server'))]['server_online'])) {
			$rServerID = intval(RequestManager::get('server'));
		}

		$rStreamInfoText = self::driverSourceTable((string) RequestManager::get('url')) ?? "<table style='width: 380px;' class='table-data' align='center'><tbody><tr><td colspan='4'>Stream probe failed!</td></tr></tbody></table>";
		$rStreamInfo = null;

		if (!empty(RequestManager::get('url'))) {
			// Not resolved here: the node probeSource asks resolves a platform page itself.
			$rProxy = (string) RequestManager::get('proxy');
			$rURL = (string) RequestManager::get('url');

			if (StreamUtils::detectXC_VM($rURL) && SettingsManager::get('api_probe')) {
				$rURLInfo = parse_url($rURL);
				$rProbeURL = $rURLInfo['scheme'] . '://' . $rURLInfo['host'] . ((isset($rURLInfo['port']) ? ':' . $rURLInfo['port'] : '')) . '/probe/' . base64_encode($rURLInfo['path'] ?? '');

				if ($rAPIInfo = json_decode(CurlClient::getURL($rProbeURL), true)) {
					$rStreamInfo = [];

					foreach (($rAPIInfo['codecs'] ?? []) as $rCodec) {
						$rStreamInfo['streams'][] = $rCodec;
					}

					$rStreamInfo['container'] = $rAPIInfo['container'] ?? '';
				}
			}

			if (!$rStreamInfo) {
				$rProbeResult = ServerRepository::probeSource($rServerID, RequestManager::get('url'), (RequestManager::get('user_agent') ?? null), ($rProxy !== '' ? $rProxy : null), (RequestManager::get('cookies') ?? null), (RequestManager::get('headers') ?? null));
				$rStreamInfo = $rProbeResult['data'] ?? [];
				$rStreamInfo['container'] = $rStreamInfo['format']['format_name'] ?? '';
			}
		}

		if (RequestManager::has('map')) {
			echo json_encode($rStreamInfo);

			exit();
		}

		if (!empty($rStreamInfo['streams']) && is_array($rStreamInfo['streams'])) {
			$rInfo = ['width' => 0, 'height' => 0, 'vbitrate' => 0, 'vcodec' => '', 'fps' => 0, 'abitrate' => 0, 'acodec' => ''];

			foreach ($rStreamInfo['streams'] as $rCodec) {
				if ($rCodec['codec_type'] == 'video') {
					$rInfo['width'] = intval($rCodec['width'] ?? 0);
					$rInfo['height'] = intval($rCodec['height'] ?? 0);
					$rInfo['vbitrate'] = intval($rCodec['bit_rate'] ?? 0);
					$rInfo['vcodec'] = $rCodec['codec_name'] ?? '';
					$rInfo['fps'] = intval(explode('/', $rCodec['r_frame_rate'] ?? '0/0')[0]);

					if (!$rInfo['fps']) {
						$rInfo['fps'] = intval(explode('/', $rCodec['avg_frame_rate'])[0]);
					}
				} elseif ($rCodec['codec_type'] == 'audio') {
					$rInfo['abitrate'] = intval($rCodec['bit_rate'] ?? 0);
					$rInfo['acodec'] = $rCodec['codec_name'] ?? '';
				}
			}

			if (0 < $rInfo['fps']) {
				if (1000 <= $rInfo['fps']) {
					$rInfo['fps'] = intval($rInfo['fps'] / 1000);
				}

				$rFPS = $rInfo['fps'] . '&nbsp;FPS';
			} else {
				$rFPS = '--';
			}

			$rStreamInfoText = "<table class='table-data' style='width: 380px;' align='center'><tbody><tr><td class='nowrap' style='color: #20a009;width: 25%;'><i class='mdi mdi-image-size-select-large' data-name='mdi-image-size-select-large'></i></td><td class='nowrap' style='color: #20a009;'><i class='mdi mdi-video' data-name='mdi-video'></i></td><td class='nowrap' style='color: #20a009;'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td><td class='nowrap' style='color: #20a009;width: 20%;'><i class='mdi mdi-layers' data-name='mdi-layers'></i></td><td class='nowrap' style='color: #" . ((strtolower($rStreamInfo['container']) == 'mpegts' ? '20a009' : 'd65656')) . ";width: 18%;'><i class='mdi " . ((strtolower($rStreamInfo['container']) == 'mpegts' ? 'mdi-check' : 'mdi-close')) . "' data-name='" . ((strtolower($rStreamInfo['container']) == 'mpegts' ? 'mdi-check' : 'mdi-close')) . "'></i></td></tr><tr><td class='nowrap'>" . $rInfo['width'] . '&nbsp;x&nbsp;' . $rInfo['height'] . "</td><td class='nowrap'>" . $rInfo['vcodec'] . "</td><td class='nowrap'>" . $rInfo['acodec'] . "</td><td class='nowrap'>" . $rFPS . "</td><td class='nowrap'>LLOD&nbsp;v3</td></tr></tbody></table>";
		}

		echo $rStreamInfoText;

		exit();
	}

	/** action=check_stream — probe a stored stream or ad-hoc URL and echo an HTML info table. */
	public function checkStream(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'add_stream'], ['adv', 'edit_stream']]);

		$rAnalyseDuration = abs(intval(SettingsManager::get('stream_max_analyze')));
		$rTimeout = intval($rAnalyseDuration / 1000000) + SettingsManager::get('probe_extra_wait');
		set_time_limit(intval($rTimeout));
		ini_set('max_execution_time', intval($rTimeout));
		ini_set('default_socket_timeout', intval($rTimeout));

		if (RequestManager::has('url')) {
			$rSource = (string) RequestManager::get('url');
			$rProxy = (string) RequestManager::get('proxy');
			$rFetch = RequestManager::has('ua') ? ['-user_agent', (string) RequestManager::get('ua')] : [];
			if (RequestManager::has('cookie')) {
				array_push($rFetch, '-cookies', StreamUtils::fixCookie(RequestManager::get('cookie')));
			}
		} else {
			$rStream = StreamRepository::getById(RequestManager::get('stream'));
			$rStreamOptions = StreamRepository::getOptions(RequestManager::get('stream'));
			$rFetch = ((string) $rStreamOptions[1]['value'] !== '') ? ['-user_agent', (string) $rStreamOptions[1]['value']] : [];
			if (RequestManager::has('cookie')) {
				array_push($rFetch, '-cookies', StreamUtils::fixCookie($rStreamOptions[17]['value']));
			}
			$rSource = (string) json_decode($rStream['stream_source'], true)[intval(RequestManager::get('id'))];
			$rProxy = (string) ($rStreamOptions[2]['value'] ?? '');
		}
		$rURL = StreamUtils::parseStreamURL($rSource, $rProxy);
		if ($rProxy !== '') {
			array_push($rFetch, '-http_proxy', StreamUtils::proxyURL($rProxy));
		}

		if ((string) $rURL !== '') {
			$rStreamInfoText = self::driverSourceTable((string) $rURL) ?? "<table style='width: 300px;' class='table-data' align='center'><tbody><tr><td colspan='4'>Stream probe failed!</td></tr></tbody></table>";
			$rStreamInfo = null;

			if (StreamUtils::detectXC_VM($rURL) && SettingsManager::get('api_probe')) {
				$rURLInfo = parse_url($rURL);
				$rProbeURL = $rURLInfo['scheme'] . '://' . $rURLInfo['host'] . ((isset($rURLInfo['port']) ? ':' . $rURLInfo['port'] : '')) . '/probe/' . base64_encode($rURLInfo['path'] ?? '');

				if ($rAPIInfo = json_decode(CurlClient::getURL($rProbeURL), true)) {
					$rStreamInfo = [];

					foreach (($rAPIInfo['codecs'] ?? []) as $rCodec) {
						$rStreamInfo['streams'][] = $rCodec;
					}
				}
			}

			if (!$rStreamInfo) {
				[, $rProbe] = ProcessRunner::capture(['timeout', (string) intval($rTimeout), FfmpegPaths::probe(), ...$rFetch, '-v', 'quiet', '-probesize', '5000000', '-print_format', 'json', '-show_format', '-show_streams', '-i', (string) $rURL], 1 << 20);
				$rStreamInfo = json_decode($rProbe, true);
			}

			if (0 < count($rStreamInfo['streams'] ?? [])) {
				$rInfo = [];

				foreach ($rStreamInfo['streams'] as $rCodec) {
					if ($rCodec['codec_type'] == 'video') {
						$rInfo['width'] = intval($rCodec['width']);
						$rInfo['height'] = intval($rCodec['height']);
						$rInfo['vbitrate'] = intval($rCodec['bit_rate'] ?? 0);
						$rInfo['vcodec'] = $rCodec['codec_name'];
						$rInfo['fps'] = intval(explode('/', $rCodec['r_frame_rate'])[0]);

						if (!$rInfo['fps']) {
							$rInfo['fps'] = intval(explode('/', $rCodec['avg_frame_rate'])[0]);
						}
					} elseif ($rCodec['codec_type'] == 'audio') {
						$rInfo['abitrate'] = intval($rCodec['bit_rate'] ?? 0);
						$rInfo['acodec'] = $rCodec['codec_name'];
					}
				}

				if (0 < $rInfo['fps']) {
					if (1000 <= $rInfo['fps']) {
						$rInfo['fps'] = intval($rInfo['fps'] / 1000);
					}

					$rFPS = $rInfo['fps'] . '&nbsp;FPS';
				} else {
					$rFPS = '--';
				}

				$rStreamInfoText = "<table class='table-data' style='width: 300px;' align='center'><tbody><tr><td style='color: #20a009;width: 34%;'><i class='mdi mdi-image-size-select-large' data-name='mdi-image-size-select-large'></i></td><td style='color: #20a009;width: 23%;'><i class='mdi mdi-video' data-name='mdi-video'></i></td><td style='color: #20a009;width: 23%;'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td><td style='color: #20a009;width: 23%;'><i class='mdi mdi-layers' data-name='mdi-layers'></i></td></tr><tr><td class='double'>" . $rInfo['width'] . '&nbsp;x&nbsp;' . $rInfo['height'] . '</td><td>' . $rInfo['vcodec'] . '</td><td>' . $rInfo['acodec'] . '</td><td>' . $rFPS . '</td></tr></tbody></table>';
			}

			echo $rStreamInfoText;
		}

		exit();
	}

	/**
	 * action=check_proxy (POST: proxy, url) — would $rURL (a platform page)
	 * restream through this proxy? The steps a stream takes, stopping at the
	 * first that fails: the proxy answers (dead), yt-dlp resolves through it
	 * (blocked), ffprobe plays the result through it (noplay); else ok. The
	 * Proxy Checker page calls it once per proxy.
	 */
	public function checkProxy(): never {
		$this->requireXhr();
		$this->gate('adv', 'stream_tools');
		set_time_limit(180);

		$rProxy = StreamUtils::proxyURL((string) RequestManager::get('proxy'));
		$rSource = trim((string) RequestManager::get('url'));
		if ($rProxy === '' || !StreamUtils::needsResolver($rSource)) {
			$this->fail(['error' => 'A proxy and a YouTube (or other platform) link are required.']);
		}
		$rStart = microtime(true);
		$rDone = function (string $rStatus, string $rIP = '') use ($rStart): never {
			$this->ok(['status' => $rStatus, 'ip' => $rIP, 'seconds' => round(microtime(true) - $rStart, 1)]);
		};

		$rCurl = curl_init('https://api.ipify.org');
		curl_setopt_array($rCurl, [CURLOPT_PROXY => $rProxy, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 8]);
		$rIP = trim((string) curl_exec($rCurl));
		curl_close($rCurl);
		if (!filter_var($rIP, FILTER_VALIDATE_IP)) {
			$rDone('dead');
		}

		$rURL = StreamUtils::parseStreamURL($rSource, $rProxy);
		if (!str_starts_with($rURL, 'http')) {
			$rDone('blocked', $rIP);
		}

		[, $rStreams] = ProcessRunner::capture(['timeout', '30', FfmpegPaths::probe(), '-v', 'quiet', '-http_proxy', $rProxy, '-show_entries', 'stream=codec_type', '-of', 'csv=p=0', '-i', $rURL]);
		$rDone(str_contains($rStreams, 'video') ? 'ok' : 'noplay', $rIP);
	}

	/** action=get_episode_ids — parse episode numbers from filenames (guessit/release.py). */
	public function getEpisodeIds(): never {
		$this->requireXhr();
		$this->gate('adv', 'add_episode');

		$rReturn = [];
		$rData = json_decode(RequestManager::get('data'), true);

		if (!is_array($rData)) {
			$this->fail();
		}

		$rInput = [];
		$rGuessit = SettingsManager::get('parse_type') == 'guessit';

		foreach ($rData as $rEpisodeID => $rName) {
			$rInput[$rEpisodeID] = $rGuessit ? pathinfo($rName)['filename'] : pathinfo(str_replace('-', '_', $rName))['filename'];
		}

		$rEpisodes = AdminHelpers::runReleaseParser($rGuessit ? 'guessit' : 'ptn', (string) json_encode($rInput));

		// No answer (the parser failed): no episode found.
		foreach (is_array($rEpisodes) ? $rEpisodes : [] as $rEpisodeID => $rEpisode) {
			if (isset($rEpisode['episode'])) {
				if (is_array($rEpisode['episode'])) {
					$rReturn[] = [$rEpisodeID, intval($rEpisode['episode'][0])];
				} else {
					$rReturn[] = [$rEpisodeID, intval($rEpisode['episode'])];
				}
			}
		}

		$this->ok(['data' => $rReturn]);
	}

	/**
	 * Collect bouquet member rows of one kind into $rReturn[$rReturnKey].
	 * Shared by {@see self::reviewBouquet()} across its four member kinds, which
	 * differ only in the data key, SQL and target key.
	 *
	 * @param array<string, mixed> $rReturn
	 */
	private function collectBouquetItems(array &$rReturn, string $rReturnKey, string $rDataKey, string $rSql): void {
		global $db;

		if (!isset(RequestManager::getAll()['data'][$rDataKey])) {
			return;
		}

		foreach (RequestManager::get('data')[$rDataKey] as $rID) {
			$db->query($rSql, $rID);

			if ($db->num_rows() == 1) {
				$rReturn[$rReturnKey][] = $db->get_row();
			}
		}
	}

	/**
	 * The info table for a module source-driver URL, or null for a source
	 * ffprobe reads. A driver source has no codecs to show before its engine
	 * runs; the driver only says whether it is reachable.
	 */
	private static function driverSourceTable(string $rURL): ?string {
		$rDriver = SourceDriverRegistry::for($rURL);
		if ($rDriver === null) {
			return null;
		}
		try {
			$rUp = $rDriver->available(0, $rURL);
		} catch (\Throwable $e) {
			$rUp = false;
		}
		return "<table style='width: 300px;' class='table-data' align='center'><tbody><tr><td colspan='4'>Module source " . ($rUp ? 'available' : 'unavailable') . '</td></tr></tbody></table>';
	}
}
