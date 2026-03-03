<?php

class SegmentReader {
	public static function getLLODSegments($rStreamID, $rPlaylist, $rPrebuffer = 1) {
		$rPrebuffer++;
		$rSegments = $rKeySegments = array();
		if (!file_exists($rPlaylist)) {
			return null;
		}
		$rSource = file_get_contents($rPlaylist);
		if (!preg_match_all('/(.*?).ts((#\\w+)+|#?)/', $rSource, $rMatches)) {
			return null;
		}
		if (0 >= count($rMatches[1])) {
			return null;
		}
		$rLastKey = null;
		for ($i = 0; $i < count($rMatches[1]); $i++) {
			$rFilename = $rMatches[1][$i];
			list($rSID, $rSegmentID) = explode('_', $rFilename);
			if (!empty($rMatches[2][$i])) {
				$rKeySegments[$rSegmentID] = array();
				$rLastKey = $rSegmentID;
			}
			if ($rLastKey) {
				$rKeySegments[$rLastKey][] = $rSegmentID;
			}
		}
		$rKeySegments = array_slice($rKeySegments, count($rKeySegments) - $rPrebuffer, $rPrebuffer, true);
		foreach ($rKeySegments as $rKeySegment => $rSubSegments) {
			foreach ($rSubSegments as $rSegmentID) {
				$rSegments[] = $rStreamID . '_' . $rSegmentID . '.ts';
			}
		}
		return (!empty($rSegments) ? $rSegments : null);
	}

	public static function getPlaylistSegments($rPlaylist, $rPrebuffer = 0, $rSegmentDuration = 10) {
		if (file_exists($rPlaylist)) {
			$rSource = file_get_contents($rPlaylist);
			$rSource = str_replace(array("\r\n", "\r"), "\n", $rSource);

			if (preg_match('/#EXT-X-MAP:URI="(.*?)"/', $rSource, $rInitMatch)) {
				$rInitSegment = $rInitMatch[1];
			}

			if (preg_match_all('/(.*?)\.(ts|m4s)/', $rSource, $rMatches)) {
				if (0 < $rPrebuffer) {
					$rTotalSegments = intval($rPrebuffer / $rSegmentDuration);
					if (!$rTotalSegments) {
						$rTotalSegments = 1;
					}
					return array_slice($rMatches[0], 0 - $rTotalSegments);
				}
				if ($rPrebuffer == -1) {
					return $rMatches[0];
				}
				preg_match('/_(.*)\\./', array_pop($rMatches[0]), $rCurrentSegment);
				return $rCurrentSegment[1];
			}
		}
	}
}
