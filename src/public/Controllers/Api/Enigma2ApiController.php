<?php

/**
 * SimpleXMLExtended — SimpleXMLElement с поддержкой CDATA.
 *
 * Ранее определялся inline внутри switch/default в enigma2.php.
 * Вынесен на верхний уровень для корректной загрузки автолоадером.
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SimpleXMLExtended extends SimpleXMLElement {
	public function addCData($rCData) {
		$rNode = dom_import_simplexml($this);
		$rRowner = $rNode->ownerDocument;
		$rNode->appendChild($rRowner->createCDATASection($rCData));
	}
}

class Enigma2ApiController {
	private $deny = true;
	private $userInfo;
	private $url;
	private $username;
	private $password;
	private $liveCategories;
	private $vodCategories;
	private $seriesCategories;
	private $liveStreams = [];
	private $vodStreams = [];

	public function shutdown() {
		global $db;

		if ($this->deny) {
			BruteforceGuard::checkFlood();
		}

		if (is_object($db)) {
			$db->close_mysql();
		}
	}

	public function index() {
		global $db, $_INFO;
		$rSettings = SettingsManager::getAll();
		$rRequest = RequestManager::getAll();

		if ($rSettings['disable_enigma2']) {
			$this->deny = false;
			generateError('E2_DISABLED');
		}

		$this->username = $rRequest['username'];
		$this->password = $rRequest['password'];
		$rType = !empty($rRequest['type']) ? $rRequest['type'] : null;
		$rCatID = !empty($rRequest['cat_id']) ? intval($rRequest['cat_id']) : null;
		$sCatID = !empty($rRequest['scat_id']) ? intval($rRequest['scat_id']) : null;
		$rSeriesID = !empty($rRequest['series_id']) ? intval($rRequest['series_id']) : null;
		$rSeason = !empty($rRequest['season']) ? intval($rRequest['season']) : null;
		$rProtocol = stripos($_SERVER['SERVER_PROTOCOL'], 'https') === 0 ? 'https://' : 'http://';
		$this->url = !empty($_SERVER['HTTP_HOST']) ? $rProtocol . $_SERVER['HTTP_HOST'] . '/' : ServerRepository::getAll()[SERVER_ID]['site_url'];
		ini_set('memory_limit', -1);

		if (empty($this->username) || empty($this->password)) {
			generateError('NO_CREDENTIALS');
		}

		$this->userInfo = UserRepository::getUserInfo(null, $this->username, $this->password, true, false);

		if (!$this->userInfo) {
			BruteforceGuard::checkBruteforce(null, null, $this->username);
			generateError('INVALID_CREDENTIALS');
		}

		$this->deny = false;
		$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
		DatabaseFactory::set($db);
		BruteforceGuard::checkAuthFlood($this->userInfo);
		$this->liveCategories = CategoryService::getFromDatabase('live');
		$this->vodCategories = CategoryService::getFromDatabase('movie');
		$this->seriesCategories = CategoryService::getFromDatabase('series');

		if ($rSettings['enable_cache']) {
			$rChannels = $this->userInfo['channel_ids'];
		} else {
			$rChannels = array();

			if (count($this->userInfo['channel_ids']) > 0) {
				$rWhere = array();
				$rWhere[] = '`id` IN (' . implode(',', $this->userInfo['channel_ids']) . ')';
				$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
				$rOrder = 'FIELD(id,' . implode(',', $this->userInfo['channel_ids']) . ')';
				DatabaseFactory::get()->query('SELECT t1.id,t1.epg_id,t1.added,t1.allow_record,t1.year,t1.channel_id,t1.movie_properties,t1.stream_source,t1.tv_archive_server_id,t1.vframes_server_id,t1.tv_archive_duration,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t1.series_no,t1.direct_source,t2.type_output,t1.target_container,t2.live,t1.rtmp_output,t1.order,t2.type_key FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type ' . $rWhereString . ' ORDER BY ' . $rOrder . ';');
				$rChannels = DatabaseFactory::get()->get_rows();
			}
		}

		$this->userInfo['channel_ids'] = StreamSorter::sortChannels($this->userInfo['channel_ids']);

		foreach ($rChannels as $rChannel) {
			if ($rSettings['enable_cache']) {
				$rChannel = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rChannel)))['info'];
			}

			if ($rChannel['live'] == 0) {
				$this->vodStreams[] = $rChannel;
			} else {
				$this->liveStreams[] = $rChannel;
			}
		}

		$this->dispatch($rType, $rCatID, $sCatID, $rSeriesID, $rSeason);
	}

	private function dispatch($rType, $rCatID, $sCatID, $rSeriesID, $rSeason) {
		switch ($rType) {
			case 'get_live_categories':
				$this->getLiveCategories();
				break;
			case 'get_vod_categories':
				$this->getVodCategories();
				break;
			case 'get_series_categories':
				$this->getSeriesCategories();
				break;
			case 'get_series':
				$this->getSeries($rCatID, $sCatID);
				break;
			case 'get_seasons':
				$this->getSeasons($rSeriesID);
				break;
			case 'get_series_streams':
				$this->getSeriesStreams($rSeriesID, $rSeason, $rCatID);
				break;
			case 'get_live_streams':
				$this->getLiveStreams($rCatID);
				break;
			case 'get_vod_streams':
				$this->getVodStreams($rCatID);
				break;
			default:
				$this->defaultMenu();
				break;
		}
	}

	private function getLiveCategories() {
		$rSettings = SettingsManager::getAll();
		$rXML = new SimpleXMLExtended('<items/>');
		$rXML->addChild('playlist_name', 'Live [ ' . $rSettings['server_name'] . ' ]');
		$rCategory = $rXML->addChild('category');
		$rCategory->addChild('category_id', 1);
		$rCategory->addChild('category_title', 'Live [ ' . $rSettings['server_name'] . ' ]');
		$rChannels = $rXML->addChild('channel');
		$rChannels->addChild('title', base64_encode('All'));
		$rChannels->addChild('description', base64_encode('Live Streams Category [ ALL ]'));
		$rChannels->addChild('category_id', 0);
		$rCData = $rChannels->addChild('playlist_url');
		$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_live_streams&cat_id=0' . $rCategory['id']);

		foreach ($this->liveCategories as $rCategoryID => $rCategory) {
			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode($rCategory['category_name']));
			$rChannels->addChild('description', base64_encode('Live Streams Category'));
			$rChannels->addChild('category_id', $rCategory['id']);
			$rCData = $rChannels->addChild('playlist_url');
			$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_live_streams&cat_id=' . $rCategory['id']);
		}

		$this->outputXml($rXML);
	}

	private function getVodCategories() {
		$rSettings = SettingsManager::getAll();
		$rXML = new SimpleXMLExtended('<items/>');
		$rXML->addChild('playlist_name', 'Movie [ ' . $rSettings['server_name'] . ' ]');
		$rCategory = $rXML->addChild('category');
		$rCategory->addChild('category_id', 1);
		$rCategory->addChild('category_title', 'Movie [ ' . $rSettings['server_name'] . ' ]');
		$rChannels = $rXML->addChild('channel');
		$rChannels->addChild('title', base64_encode('All'));
		$rChannels->addChild('description', base64_encode('Movie Streams Category [ ALL ]'));
		$rChannels->addChild('category_id', 0);
		$rCData = $rChannels->addChild('playlist_url');
		$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_vod_streams&cat_id=0' . $rCategory['id']);

		foreach ($this->vodCategories as $rCategoryID => $rCategory) {
			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode($rCategory['category_name']));
			$rChannels->addChild('description', base64_encode('Movie Streams Category'));
			$rChannels->addChild('category_id', $rCategory['id']);
			$rCData = $rChannels->addChild('playlist_url');
			$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_vod_streams&cat_id=' . $rCategory['id']);
		}

		$this->outputXml($rXML);
	}

	private function getSeriesCategories() {
		$rSettings = SettingsManager::getAll();
		$rXML = new SimpleXMLExtended('<items/>');
		$rXML->addChild('playlist_name', 'SubCategory [ ' . $rSettings['server_name'] . ' ]');
		$rCategory = $rXML->addChild('category');
		$rCategory->addChild('category_id', 1);
		$rCategory->addChild('category_title', 'SubCategory [ ' . $rSettings['server_name'] . ' ]');
		$rChannels = $rXML->addChild('channel');
		$rChannels->addChild('title', base64_encode('All'));
		$rChannels->addChild('description', base64_encode('TV Series Category [ ALL ]'));
		$rChannels->addChild('category_id', 0);
		$rCData = $rChannels->addChild('playlist_url');
		$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_series&cat_id=0' . $rCategory['id']);

		foreach ($this->seriesCategories as $rCategoryID => $rCategory) {
			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode($rCategory['category_name']));
			$rChannels->addChild('description', base64_encode('TV Series Category'));
			$rChannels->addChild('category_id', $rCategory['id']);
			$rCData = $rChannels->addChild('playlist_url');
			$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_series&cat_id=' . $rCategory['id']);
		}

		$this->outputXml($rXML);
	}

	private function getSeries($rCatID, $sCatID) {
		global $db;

		if (!(isset($rCatID) || is_null($rCatID) || isset($sCatID) || is_null($sCatID))) {
			return;
		}

		$rCategoryID = is_null($rCatID) ? null : $rCatID;

		if (is_null($rCategoryID)) {
			$rCategoryID = is_null($sCatID) ? null : $sCatID;
			$rCatID = $sCatID;
		}

		$rCategoryName = !empty($this->seriesCategories[$rCatID]) ? $this->seriesCategories[$rCatID]['category_name'] : 'ALL';
		$rXML = new SimpleXMLExtended('<items/>');
		$rXML->addChild('playlist_name', 'TV Series [ ' . $rCategoryName . ' ]');
		$rCategory = $rXML->addChild('category');
		$rCategory->addChild('category_id', 1);
		$rCategory->addChild('category_title', 'TV Series [ ' . $rCategoryName . ' ]');

		if (count($this->userInfo['series_ids']) > 0) {
			if (SettingsManager::getAll()['vod_sort_newest']) {
				$db->query('SELECT * FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $this->userInfo['series_ids'])) . ') ORDER BY `last_modified` DESC;');
			} else {
				$db->query('SELECT * FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $this->userInfo['series_ids'])) . ') ORDER BY FIELD(`id`,' . implode(',', $this->userInfo['series_ids']) . ') ASC;');
			}

			$rSeries = $db->get_rows(true, 'id');

			foreach ($rSeries as $rSeriesID => $rSeriesInfo) {
				foreach (json_decode($rSeriesInfo['category_id'], true) as $rCategoryIDSearch) {
					if ($rCategoryID && $rCategoryID != $rCategoryIDSearch) {
						continue;
					}

					$rChannels = $rXML->addChild('channel');
					$rChannels->addChild('title', base64_encode($rSeriesInfo['title']));
					$rChannels->addChild('description', '');
					$rChannels->addChild('category_id', $rSeriesID);
					$rCData = $rChannels->addChild('playlist_url');
					$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_seasons&series_id=' . $rSeriesID);

					if (!$rCategoryID) {
						break;
					}
				}
			}
		}

		$this->outputXml($rXML);
	}

	private function getSeasons($rSeriesID) {
		global $db;

		if (!isset($rSeriesID)) {
			return;
		}

		$db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $rSeriesID);
		$rSeriesInfo = $db->get_row();
		$rCategoryName = $rSeriesInfo['title'];
		$rXML = new SimpleXMLExtended('<items/>');
		$rXML->addChild('playlist_name', 'TV Series [ ' . $rCategoryName . ' ]');
		$rCategory = $rXML->addChild('category');
		$rCategory->addChild('category_id', 1);
		$rCategory->addChild('category_title', 'TV Series [ ' . $rCategoryName . ' ]');
		$db->query('SELECT * FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id=t1.stream_id WHERE t1.series_id = ? ORDER BY t1.season_num ASC, t1.episode_num ASC', $rSeriesID);
		$rRows = $db->get_rows(true, 'season_num', false);

		foreach (array_keys($rRows) as $rSeasonNum) {
			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode('Season ' . $rSeasonNum));
			$rChannels->addChild('description', '');
			$rChannels->addChild('category_id', $rSeasonNum);
			$rCData = $rChannels->addChild('playlist_url');
			$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_series_streams&series_id=' . $rSeriesID . '&season=' . $rSeasonNum);
		}

		$this->outputXml($rXML);
	}

	private function getSeriesStreams($rSeriesID, $rSeason, $rCatID) {
		global $db;

		if (!(isset($rSeriesID) && isset($rSeason))) {
			return;
		}

		$rSettings = SettingsManager::getAll();
		$db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $rSeriesID);
		$rSeriesInfo = $db->get_row();
		$rXML = new SimpleXMLExtended('<items/>');
		$rXML->addChild('playlist_name', 'TV Series [ ' . $rSeriesInfo['title'] . ' Season ' . $rSeason . ' ]');
		$rCategory = $rXML->addChild('category');
		$rCategory->addChild('category_id', 1);
		$rCategory->addChild('category_title', 'TV Series [ ' . $rSeriesInfo['title'] . ' Season ' . $rSeason . ' ]');
		$db->query('SELECT t2.direct_source,t2.stream_source,t2.target_container,t2.id,t1.series_id,t1.season_num FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id=t1.stream_id WHERE t1.series_id = ? AND t1.season_num = ? ORDER BY  t1.episode_num ASC', $rSeriesID, $rSeason);
		$rSeriesEpisodes = $db->get_rows();
		$rEpisodeNum = 0;

		foreach ($rSeriesEpisodes as $rEpisode) {
			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode('Episode ' . sprintf('%02d', ++$rEpisodeNum)));
			$rDesc = '';
			$rDescChannel = $rChannels->addChild('desc_image');
			$rDescChannel->addCData(ImageUtils::validateURL($rSeriesInfo['cover']));
			$rChannels->addChild('description', base64_encode($rDesc));
			$rChannels->addChild('category_id', $rCatID);
			$rCDataURL = $rChannels->addChild('stream_url');
			$rEncData = 'movie/' . $this->username . '/' . $this->password . '/' . $rEpisode['id'] . '/' . $rEpisode['target_container'];
			$rToken = Encryption::encrypt($rEncData, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			$rSource = $this->url . 'play/' . $rToken;
			$rCDataURL->addCData($rSource);
		}

		$this->outputXml($rXML);
	}

	private function getLiveStreams($rCatID) {
		$rSettings = SettingsManager::getAll();
		$rCategoryID = is_null($rCatID) ? null : $rCatID;

		if (!(isset($rCatID) || is_null($rCatID))) {
			return;
		}

		$rXML = new SimpleXMLExtended('<items/>');
		$rXML->addChild('playlist_name', 'Live [ ' . $rSettings['server_name'] . ' ]');
		$rCategory = $rXML->addChild('category');
		$rCategory->addChild('category_id', 1);
		$rCategory->addChild('category_title', 'Live [ ' . $rSettings['server_name'] . ' ]');

		foreach ($this->liveStreams as $rStream) {
			if ($rCategoryID && !in_array($rCategoryID, json_decode($rStream['category_id'], true))) {
				continue;
			}

			$rChannelEPGs = array();

			if (file_exists(EPG_PATH . 'stream_' . intval($rStream['id']))) {
				foreach (igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStream['id'])) as $rRow) {
					if ($rRow['end'] >= time()) {
						$rChannelEPGs[] = $rRow;

						if (count($rChannelEPGs) >= 2) {
							break;
						}
					}
				}
			}

			$rDesc = '';
			$rShortEPG = '';
			$i = 0;

			foreach ($rChannelEPGs as $rRow) {
				$rDesc .= '[' . date('H:i', $rRow['start']) . '] ' . $rRow['title'] . "\n" . '( ' . $rRow['description'] . ')' . "\n";

				if ($i == 0) {
					$rShortEPG = '[' . date('H:i', $rRow['start']) . ' - ' . date('H:i', $rRow['end']) . '] + ' . round(($rRow['end'] - time()) / 60, 1) . ' min   ' . $rRow['title'];
					$i++;
				}
			}

			foreach (json_decode($rStream['category_id'], true) as $rCategoryIDSearch) {
				if ($rCategoryID && $rCategoryID != $rCategoryIDSearch) {
					continue;
				}

				$rChannels = $rXML->addChild('channel');
				$rChannels->addChild('title', base64_encode($rStream['stream_display_name'] . ' ' . $rShortEPG));
				$rChannels->addChild('description', base64_encode($rDesc));
				$rDescChannel = $rChannels->addChild('desc_image');
				$rDescChannel->addCData(ImageUtils::validateURL($rStream['stream_icon']));
				$rChannels->addChild('category_id', $rCategoryIDSearch);
				$rCData = $rChannels->addChild('stream_url');
				$rEncData = 'live/' . $this->username . '/' . $this->password . '/' . $rStream['id'];
				$rToken = Encryption::encrypt($rEncData, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				$rSource = $this->url . 'play/' . $rToken;
				$rCData->addCData($rSource);

				if (!$rCategoryID) {
					break;
				}
			}
		}

		$this->outputXml($rXML);
	}

	private function getVodStreams($rCatID) {
		$rSettings = SettingsManager::getAll();
		$rCategoryID = is_null($rCatID) ? null : $rCatID;

		if (!(isset($rCatID) || is_null($rCatID))) {
			return;
		}

		$rXML = new SimpleXMLExtended('<items/>');
		$rXML->addChild('playlist_name', 'Movie [ ' . $rSettings['server_name'] . ' ]');
		$rCategory = $rXML->addChild('category');
		$rCategory->addChild('category_id', 1);
		$rCategory->addChild('category_title', 'Movie [ ' . $rSettings['server_name'] . ' ]');

		foreach ($this->vodStreams as $rStream) {
			foreach (json_decode($rStream['category_id'], true) as $rCategoryIDSearch) {
				if ($rCategoryID && $rCategoryID != $rCategoryIDSearch) {
					continue;
				}

				$rProperties = json_decode($rStream['movie_properties'], true);
				$rChannels = $rXML->addChild('channel');
				$rChannels->addChild('title', base64_encode($rStream['stream_display_name']));
				$rDesc = '';

				if ($rProperties) {
					foreach ($rProperties as $rKey => $rProperty) {
						if ($rKey != 'movie_image') {
							$rDesc .= strtoupper($rKey) . ': ' . $rProperty . "\n";
						}
					}
				}

				$rDescChannel = $rChannels->addChild('desc_image');
				$rDescChannel->addCData(ImageUtils::validateURL($rProperties['movie_image']));
				$rChannels->addChild('description', base64_encode($rDesc));
				$rChannels->addChild('category_id', $rCategoryIDSearch);
				$rCDataURL = $rChannels->addChild('stream_url');
				$rEncData = 'movie/' . $this->username . '/' . $this->password . '/' . $rStream['id'] . '/' . $rStream['target_container'];
				$rToken = Encryption::encrypt($rEncData, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				$rSource = $this->url . 'play/' . $rToken;
				$rCDataURL->addCData($rSource);

				if (!$rCategoryID) {
					break;
				}
			}
		}

		$this->outputXml($rXML);
	}

	private function defaultMenu() {
		$rSettings = SettingsManager::getAll();
		$rXML = new SimpleXMLExtended('<items/>');
		$rXML->addChild('playlist_name', $rSettings['server_name']);
		$rCategory = $rXML->addChild('category');
		$rCategory->addChild('category_id', 1);
		$rCategory->addChild('category_title', $rSettings['server_name']);

		if (!empty($this->liveStreams)) {
			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode('Live Streams'));
			$rChannels->addChild('description', base64_encode('Live Streams Category'));
			$rChannels->addChild('category_id', 0);
			$rCData = $rChannels->addChild('playlist_url');
			$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_live_categories');
		}

		if (!empty($this->vodStreams)) {
			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode('VOD'));
			$rChannels->addChild('description', base64_encode('Video On Demand Category'));
			$rChannels->addChild('category_id', 1);
			$rCData = $rChannels->addChild('playlist_url');
			$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_vod_categories');
		}

		$rChannels = $rXML->addChild('channel');
		$rChannels->addChild('title', base64_encode('TV Series'));
		$rChannels->addChild('description', base64_encode('TV Series Category'));
		$rChannels->addChild('category_id', 2);
		$rCData = $rChannels->addChild('playlist_url');
		$rCData->addCData($this->url . 'enigma2?username=' . $this->username . '&password=' . $this->password . '&type=get_series_categories');

		$this->outputXml($rXML);
	}

	private function outputXml($rXML) {
		header('Content-Type: application/xml; charset=utf-8');
		echo $rXML->asXML();
	}
}
