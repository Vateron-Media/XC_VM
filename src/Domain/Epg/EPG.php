<?php

namespace XcVm\Domain\Epg;

use XcVm\Core\Logging\FileLogger;
use XcVm\Core\Parsing\XmlStringStreamer;

/**
 * EPG — e p g
 *
 * @package XC_VM_Domain_Epg
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class EPG {
	use \XcVm\Infrastructure\Database\DatabaseAware;
	public $rValid = false;
	public $rEPGSource;
	public $rFilename;

	/**
	 * Load an EPG source on construction.
	 *
	 * @param string $rSource EPG source URL/path.
	 * @param bool   $rCache  Use a cached copy when available.
	 */
	public function __construct($rSource, $rCache = false) {
		$this->loadEPG($rSource, $rCache);
	}

	/**
	 * Print a timestamped log line.
	 *
	 * @param string $rMessage Message to log.
	 * @return void
	 */
	private function log($rMessage) {
		echo '[' . date('Y-m-d H:i:s') . '] ' . $rMessage . "\n";
	}

	/**
	 * Return the parsed EPG data (channels and programmes).
	 *
	 * @return array Parsed EPG data.
	 */
	public function getData() {
		$rOutput = [];
		$channelCount = 0;

		$this->log("[EPG] Starting getData() - parsing channels and languages...");

		while ($rNode = $this->rEPGSource->getNode()) {
			$rData = simplexml_load_string($rNode);
			if (!$rData) continue;

			$rNodeName = $rData->getName();

			if ($rNodeName === 'channel') {
				$rChannelID = trim((string) $rData->attributes()->id);
				$displayName = !empty($rData->{'display-name'}) ? trim((string) $rData->{'display-name'}) : 'Unknown';

				if (!array_key_exists($rChannelID, $rOutput)) {
					$rOutput[$rChannelID] = [
						'display_name' => $displayName,
						'langs'        => []
					];
					$channelCount++;
				}
				continue;
			}

			// ---------- PROGRAMME ----------
			if ($rNodeName !== 'programme') {
				continue;
			}

			$rChannelID = trim((string) $rData->attributes()->channel);

			if (!array_key_exists($rChannelID, $rOutput)) {
				continue;
			}

			if (empty($rData->title)) {
				continue;
			}

			foreach ($rData->title as $rTitle) {
				$lang = (string) $rTitle->attributes()->lang;
				if (!empty($lang) && !in_array($lang, $rOutput[$rChannelID]['langs'], true)) {
					$rOutput[$rChannelID]['langs'][] = $lang;
				}
			}
		}

		$this->log("[EPG] Finished getData() - found $channelCount channels");
		return $rOutput;
	}

	/**
	 * Parse EPG programmes for a channel from the loaded source.
	 *
	 * @param string $rEPGID       EPG channel id.
	 * @param array  $rChannelInfo Channel mapping info.
	 * @param int    $rOffset      Time offset (seconds) to apply.
	 * @return array Parsed programmes.
	 */
	public function parseEPG($rEPGID, $rChannelInfo, $rOffset = 0) {
		$db = self::db();

		$rInsertQuery = [];
		$programCount = 0;

		$this->log("[EPG] Starting parseEPG() for EPG ID: $rEPGID (offset: {$rOffset}min)");

		while ($rNode = $this->rEPGSource->getNode()) {
			$rData = simplexml_load_string($rNode);
			if (!$rData) {
				continue;
			}

			if ($rData->getName() !== 'programme') {
				continue;
			}

			$rChannelID = (string) $rData->attributes()->channel;

			if (!array_key_exists($rChannelID, $rChannelInfo)) {
				continue;
			}

			// --- timestamps ---
			$rStartRaw = strtotime((string) $rData->attributes()->start);
			$rStopRaw  = strtotime((string) $rData->attributes()->stop);

			// Validate BEFORE applying the offset: `false + (offset*60)` would
			// coerce false to 0, so the check below could never catch an invalid
			// timestamp once the offset was added.
			if ($rStartRaw === false || $rStopRaw === false) {
				$this->log("[EPG] Warning: Invalid timestamp for channel $rChannelID");
				continue;
			}

			$rStart = $rStartRaw + ($rOffset * 60);
			$rStop  = $rStopRaw  + ($rOffset * 60);

			$rLangTitle = '';
			$rLangDesc  = '';

			// Title
			if (!empty($rData->title)) {
				$rTitles = $rData->title;
				$preferredLang = $rChannelInfo[$rChannelID]['epg_lang'];

				if (is_object($rTitles)) {
					$rFound = false;
					foreach ($rTitles as $rTitle) {
						if ((string) $rTitle->attributes()->lang === $preferredLang) {
							$rLangTitle = (string) $rTitle;
							$rFound = true;
							break;
						}
					}
					if (!$rFound && count($rTitles) > 0) {
						$rLangTitle = (string) $rTitles[0];
					}
				} else {
					$rLangTitle = (string) $rTitles;
				}
			} else {
				continue;
			}

			// Description
			if (!empty($rData->desc)) {
				$rDescriptions = $rData->desc;
				$preferredLang = $rChannelInfo[$rChannelID]['epg_lang'];

				if (is_object($rDescriptions)) {
					$rFound = false;
					foreach ($rDescriptions as $rDescription) {
						if ((string) $rDescription->attributes()->lang === $preferredLang) {
							$rLangDesc = (string) $rDescription;
							$rFound = true;
							break;
						}
					}
					if (!$rFound && count($rDescriptions) > 0) {
						$rLangDesc = (string) $rDescriptions[0];
					}
				} else {
					$rLangDesc = (string) $rDescriptions;
				}
			}

			$rInsertQuery[] = '(' .
				$db->escape($rEPGID) . ', ' .
				$db->escape($rChannelID) . ', ' .
				intval($rStart) . ', ' .
				intval($rStop) . ', ' .
				$db->escape($rChannelInfo[$rChannelID]['epg_lang']) . ', ' .
				$db->escape($rLangTitle) . ', ' .
				$db->escape($rLangDesc ?? '') .
				')';

			$programCount++;
			if ($programCount % 1000 === 0) {
				$this->log("[EPG] Parsed $programCount programmes so far...");
			}
		}

		$this->log("[EPG] Finished parseEPG() - collected $programCount programmes");
		return !empty($rInsertQuery) ? $rInsertQuery : false;
	}

	/**
	 * Download an EPG source file to disk.
	 *
	 * @param string $rSource   Source URL.
	 * @param string $rFilename Local destination path.
	 * @return bool True on success.
	 */
	public function downloadFile($rSource, $rFilename) {
		$this->log("[EPG] Downloading EPG file: $rSource");

		$rExtension = pathinfo($rSource, PATHINFO_EXTENSION);
		$rDecompress = '';

		if ($rExtension === 'gz') {
			$rDecompress = ' | gunzip -c';
		} elseif ($rExtension === 'xz') {
			$rDecompress = ' | unxz -c';
		}

		$rCommand = 'wget -U "Mozilla/5.0" --connect-timeout=30 --read-timeout=120 --tries=2 -O - ' . escapeshellarg($rSource) . $rDecompress . ' > ' . escapeshellarg($rFilename);
		$rResult = shell_exec($rCommand);

		if (file_exists($rFilename) && filesize($rFilename) > 0) {
			$this->log("[EPG] Download successful: " . filesize($rFilename) . " bytes");
			return true;
		} else {
			$this->log("[EPG] Download failed or file is empty: $rSource");
			return false;
		}
	}

	/**
	 * Load and parse an EPG (XMLTV) source, optionally from cache.
	 *
	 * @param string $rSource EPG source URL/path.
	 * @param bool   $rCache  Use a cached copy when available.
	 * @return void
	 */
	public function loadEPG($rSource, $rCache) {
		try {
			$this->rFilename = TMP_PATH . md5($rSource) . '.xml';

			// If caching is enabled, check for existing file
			if (!file_exists($this->rFilename) || !$rCache) {
				if (!$this->downloadFile($rSource, $this->rFilename)) {
					$this->log("[EPG] Failed to load EPG source: $rSource");
					return;
				}
			} else {
				$this->log("[EPG] Using cached EPG file: " . basename($this->rFilename));
			}

			if (!$this->rFilename) {
				FileLogger::log('epg', 'No XML found at: ' . $rSource);
				return;
			}

			$rXML = XmlStringStreamer::createStringWalkerParser($this->rFilename);

			if (!$rXML) {
				FileLogger::log('epg', 'Not a valid EPG source: ' . $rSource);
				$this->log("[EPG] Failed to create XML parser for: $rSource");
				return;
			}

			$this->rEPGSource = $rXML;
			$this->rValid     = true;
			$this->log("[EPG] EPG source loaded successfully: $rSource");
		} catch (\Exception $e) {
			FileLogger::log('epg', 'EPG failed to process: ' . $rSource);
			$this->log("[EPG] \Exception while loading EPG: " . $e->getMessage() . " | Source: $rSource");
		}
	}
}
