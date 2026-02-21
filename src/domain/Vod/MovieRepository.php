<?php

class MovieRepository {
	public static function getSimilar($rID, $rPage = 1) {
		require_once MAIN_HOME . 'includes/libs/tmdb.php';

		if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
			$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
		} else {
			$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
		}

		return json_decode(json_encode($rTMDB->getSimilarMovies($rID, $rPage)), true);
	}

	/**
	 * Send delete signal for movie files on specified servers.
	 * Extracted from admin.php::deleteMovieFile()
	 */
	public static function deleteFile($db, $rServerIDs, $rID) {
		if (is_array($rServerIDs)) {
		} else {
			$rServerIDs = array($rServerIDs);
		}

		foreach ($rServerIDs as $rServerID) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vod', 'id' => $rID)));
		}

		return true;
	}
}
