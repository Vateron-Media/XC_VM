<?php

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
	public $rValid = false;
	public $rEPGSource;
	public $rFilename;

	public function __construct($rSource, $rCache = false) {
		$this->loadEPG($rSource, $rCache);
	}

	private function log($rMessage) {
		echo '[' . date('Y-m-d H:i:s') . '] ' . $rMessage . "\n";
	}

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

	public function parseEPG($rEPGID, $rChannelInfo, $rOffset = 0) {
		global $db;

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
			$rStart = strtotime((string) $rData->attributes()->start) + ($rOffset * 60);
			$rStop  = strtotime((string) $rData->attributes()->stop)  + ($rOffset * 60);

			if ($rStart === false || $rStop === false) {
				$this->log("[EPG] Warning: Invalid timestamp for channel $rChannelID");
				continue;
			}

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

	public function downloadFile($rSource, $rFilename) {
		$this->log("[EPG] Downloading EPG file: $rSource");

		$rExtension = pathinfo($rSource, PATHINFO_EXTENSION);
		$rDecompress = '';

		if ($rExtension === 'gz') {
			$rDecompress = ' | gunzip -c';
		} elseif ($rExtension === 'xz') {
			$rDecompress = ' | unxz -c';
		}

		$rCommand = 'wget -U "Mozilla/5.0" --timeout=30 --tries=3 -O - ' . escapeshellarg($rSource) . $rDecompress . ' > ' . escapeshellarg($rFilename);
		$rResult = shell_exec($rCommand);

		if (file_exists($rFilename) && filesize($rFilename) > 0) {
			$this->log("[EPG] Download successful: " . filesize($rFilename) . " bytes");
			return true;
		} else {
			$this->log("[EPG] Download failed or file is empty: $rSource");
			return false;
		}
	}

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
		} catch (Exception $e) {
			FileLogger::log('epg', 'EPG failed to process: ' . $rSource);
			$this->log("[EPG] Exception while loading EPG: " . $e->getMessage() . " | Source: $rSource");
		}
	}
}
