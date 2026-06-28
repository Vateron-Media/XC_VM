<?php

namespace XcVm\Module\Plex;

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

    use \XcVm\Infrastructure\Database\DatabaseAware;

	public static function getPlexServers() {
		$rReturn = array();
		self::db()->query("SELECT * FROM `watch_folders` WHERE `type` = 'plex' ORDER BY `id` ASC;");
		if (0 < self::db()->num_rows()) {
			foreach (self::db()->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}
		return $rReturn;
	}

    public static function enableAll(): void {
        self::db()->query("UPDATE `watch_folders` SET `active` = 1 WHERE `type` = 'plex';");
    }

    public static function disableAll(): void {
        self::db()->query("UPDATE `watch_folders` SET `active` = 0 WHERE `type` = 'plex';");
    }

	public static function getPlexSections($rIP, $rPort, $rToken) {
		if (!$rToken) {
			return array();
		}

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
