<?php

/**
 * PlexRepository — plex repository
 *
 * @package XC_VM_Module_Plex
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlexRepository {
	public static function getPlexServers() {
		global $db;
		$rReturn = array();
		$db->query("SELECT * FROM `watch_folders` WHERE `type` = 'plex' ORDER BY `id` ASC;");
		if (0 < $db->num_rows()) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}
		return $rReturn;
	}

	public static function getPlexSections($rIP, $rPort, $rToken) {
		$URL = 'http://' . $rIP . ':' . $rPort . '/library/sections?X-Plex-Token=' . $rToken;
		$rSections = json_decode(json_encode(simplexml_load_string(file_get_contents($URL))), true);
		if (!isset($rSections['Directory'])) {
			return array();
		}
		if (isset($rSections['Directory']['@attributes'])) {
			$rSections['Directory'] = array($rSections['Directory']);
		}
		return $rSections['Directory'];
	}
}
