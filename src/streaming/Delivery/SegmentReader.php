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
}
