<?php

class M3UParser {
	public static function parse($rData, $rFile = true) {
		require_once INCLUDES_PATH . 'libs/m3u.php';
		$rParser = new M3uParser();
		$rParser->addDefaultTags();

		if ($rFile) {
			return $rParser->parseFile($rData);
		}

		return $rParser->parse($rData);
	}
}
