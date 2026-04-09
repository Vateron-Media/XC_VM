<?php

class Release {
	public const MOVIE = 'movie';
	public const TVSHOW = 'tvshow';
	public const ORIGINAL_RELEASE = 1;
	public const GENERATED_RELEASE = 2;
	public const SOURCE = 'source';
	public const SOURCE_DVDRIP = 'DVDRip';
	public const SOURCE_DVDSCR = 'DVDScr';
	public const SOURCE_BDSCR = 'BDScr';
	public const SOURCE_WEB_DL = 'WEB-DL';
	public const SOURCE_BDRIP = 'BDRip';
	public const SOURCE_DVD_R = 'DVD-R';
	public const SOURCE_R5 = 'R5';
	public const SOURCE_HDRIP = 'HDRip';
	public const SOURCE_BLURAY = 'BLURAY';
	public const SOURCE_PDTV = 'PDTV';
	public const SOURCE_SDTV = 'SDTV';
	public const SOURCE_CAM = 'CAM';
	public const SOURCE_TC = 'TC';
	public const ENCODING = 'encoding';
	public const ENCODING_XVID = 'XviD';
	public const ENCODING_DIVX = 'DivX';
	public const ENCODING_X264 = 'x264';
	public const ENCODING_X265 = 'x265';
	public const ENCODING_H264 = 'h264';
	public const RESOLUTION = 'resolution';
	public const RESOLUTION_SD = 'SD';
	public const RESOLUTION_720P = '720p';
	public const RESOLUTION_1080P = '1080p';
	public const DUB = 'dub';
	public const DUB_DUBBED = 'DUBBED';
	public const DUB_AC3 = 'AC3';
	public const DUB_MD = 'MD';
	public const DUB_LD = 'LD';
	public const LANGUAGE_MULTI = 'MULTI';
	public const LANGUAGE_DEFAULT = 'VO';

	public static $sourceStatic = array(
		'CAM' => array('cam', 'camrip', 'cam-rip', 'ts', 'telesync', 'pdvd'),
		'TC' => array('tc', 'telecine'),
		'DVDRip' => array('dvdrip', 'dvd-rip'),
		'DVDScr' => array('dvdscr', 'dvd-scr', 'dvdscreener', 'screener', 'scr', 'DDC'),
		'BDScr' => array('bluray-scr', 'bdscr'),
		'WEB-DL' => array('webtv', 'web-tv', 'webdl', 'web-dl', 'webrip', 'web-rip', 'webhd', 'web'),
		'BDRip' => array('bdrip', 'bd-rip', 'brrip', 'br-rip'),
		'DVD-R' => array('dvd', 'dvdr', 'dvd-r', 'dvd-5', 'dvd-9', 'r6-dvd'),
		'R5' => array('r5'),
		'HDRip' => array('hdtv', 'hdtvrip', 'hdtv-rip', 'hdrip', 'hdlight', 'mhd', 'hd'),
		'BLURAY' => array('bluray', 'blu-ray', 'bdr'),
		'PDTV' => array('pdtv'),
		'SDTV' => array('sdtv', 'dsr', 'dsrip', 'satrip', 'dthrip', 'dvbrip')
	);
	public static $encodingStatic = array(
		'DivX' => array('divx'),
		'XviD' => array('xvid'),
		'x264' => array('x264', 'x.264'),
		'x265' => array('x265', 'x.265'),
		'h264' => array('h264', 'h.264')
	);
	public static $resolutionStatic = array(
		'SD' => array('sd'),
		'720p' => array('720p'),
		'1080p' => array('1080p')
	);
	public static $dubStatic = array(
		'DUBBED' => array('dubbed'),
		'AC3' => array('ac3.dubbed', 'ac3'),
		'MD' => array('md', 'microdubbed', 'micro-dubbed'),
		'LD' => array('ld', 'linedubbed', 'line-dubbed')
	);
	public static $languageStatic = array();
	public static $flagsStatic = array(
		'FASTSUB' => 'FASTSUB',
		'SUBFRENCH' => 'SUBFRENCH',
		'SUBFORCED' => 'SUBFORCED',
		'WORKPRINT' => array('WORKPRINT', 'WP'),
		'FANSUB' => 'FANSUB',
		'REPACK' => 'REPACK',
		'NFOFIX' => 'NFOFIX',
		'INTERNAL' => array('INTERNAL', 'INT'),
		'DOLBY DIGITAL' => 'DOLBY DIGITAL',
		'DTS' => 'DTS',
		'AAC' => 'AAC',
		'DTS-HD' => 'DTS-HD',
		'DTS-MA' => 'DTS-MA',
		'TRUEHD' => 'TRUEHD',
		'3D' => '3D',
		'HSBS' => 'HSBS',
		'HOU' => 'HOU',
		'DOC' => 'DOC',
		'RERIP' => array('rerip', 're-rip'),
		'DD5.1' => array('dd5.1', 'dd51', 'dd5-1', '5.1', '5-1'),
		'READNFO' => array('READ.NFO', 'READ-NFO', 'READNFO')
	);
	protected $release;
	protected $type;
	protected $title;
	protected $year = 0;
	protected $language;
	protected $resolution;
	protected $source;
	protected $dub;
	protected $encoding;
	protected $group;
	protected $flags = array();
	protected $season = 0;
	protected $episode = 0;

	public function __construct($name) {
		$cleaned = $this->clean($name);
		$this->original = $name;
		$this->release = $cleaned;
		$title = $cleaned;
		$language = $this->parseLanguage($title);
		$this->setLanguage($language);
		$source = $this->parseSource($title);
		$this->setSource($source);
		$encoding = $this->parseEncoding($title);
		$this->setEncoding($encoding);
		$resolution = $this->parseResolution($title);
		$this->setResolution($resolution);
		$dub = $this->parseDub($title);
		$this->setDub($dub);
		$year = $this->parseYear($title);
		$this->setYear($year);
		$flags = $this->parseFlags($title);
		$this->setFlags($flags);
		$type = $this->parseType($title);
		$this->setType($type);
		$group = $this->parseGroup($title);
		$this->setGroup($group);
		$title = $this->parseTitle($title);
		$this->setTitle($title);
	}

	public function __toString() {
		$arrays = array();

		foreach (array($this->getTitle(), $this->getYear(), ($this->getSeason() ? 'S' . sprintf('%02d', $this->getSeason()) : '') . ($this->getEpisode() ? 'E' . sprintf('%02d', $this->getEpisode()) : ''), $this->getLanguage(), $this->getResolution(), $this->getSource(), $this->getEncoding(), $this->getDub()) as $array) {
			if (is_array($array)) {
				$arrays[] = implode('.', $array);
			} elseif ($array) {
				$arrays[] = $array;
			}
		}

		return preg_replace('#\\s+#', '.', implode('.', $arrays)) . '-' . ($this->getGroup() ? $this->getGroup() : 'NOTEAM');
	}

	public function getRelease($mode = self::ORIGINAL_RELEASE) {
		switch ($mode) {
			case 2:
				return $this->__toString();

				break;

			default:
				return $this->release;

				break;
		}
	}

	private function clean($name) {
		$release = str_replace(array('[', ']', '(', ')', ',', ';', ':', '!'), ' ', $name);
		$release = preg_replace('#[\\s]+#', ' ', $release);
		$release = str_replace(' ', '.', $release);

		return $release;
	}

	public function guess() {
		$release = $this;

		if (!isset($release->year)) {
			$release->setYear($release->guessYear());
		}

		if (!isset($release->resolution)) {
			$release->setResolution($release->guessResolution());
		}

		if (!isset($release->language)) {
			$release->setLanguage($release->guessLanguage());
		}

		return $release;
	}

	private function parseAttribute(&$title, $attribute) {
		if (!in_array($attribute, array('source', 'encoding', 'resolution', 'dub'))) {
			throw new InvalidArgumentException();
		}

		$attributes = $attribute . 'Static';

		foreach (Release::$$attributes as $key => $patterns) {
			if (!is_array($patterns)) {
				$patterns = array($patterns);
			}

			foreach ($patterns as $pattern) {
				$title = preg_replace('#[\\.|\\-]' . preg_quote($pattern) . '([\\.|\\-]|$)#i', '$1', $title, 1, $replacements);

				if (0 < $replacements) {
					return $key;
				}
			}
		}

		return null;
	}

	public function getType() {
		return $this->type;
	}

	private function parseType(&$title) {
		$type = null;
		$title = preg_replace_callback('#S(?<season>\\d{1,2})E(?<episode>\\d{1,2})#i', function ($matches) use (&$type) {
			$type = self::TVSHOW;
			$this->setSeason(intval($matches[1]));

			if ($matches[2]) {
				$this->setEpisode(intval($matches[2]));
			}

			return $matches[3];
		}, $title, 1, $count);

		if ($count == 0) {
			$type = 'movie';
		}

		return $type;
	}

	public function setType($type) {
		$this->type = $type;
	}

	public function getTitle() {
		return trim(rtrim($this->title, '-'));
	}

	private function parseTitle(&$title) {
		$array = array();
		$return = '';
		$title = preg_replace('#\\.?\\-\\.#', '.', $title);
		$title = preg_replace('#\\(.*?\\)#', '', $title);
		$title = preg_replace('#\\.+#', '.', $title);
		$positions = explode('.', $this->release);

		foreach (array_intersect($positions, explode('.', $title)) as $key => $value) {
			$last = (isset($last) ? $last : 0);

			if (1 < ($key - $last)) {
				$return = implode(' ', $array);

				break;
			}

			$array[] = $value;
			$return = implode(' ', $array);
			$last = $key;
		}

		$return = trim($return);

		return $return;
	}

	public function setTitle($title) {
		$this->title = $title;
	}

	public function getSeason() {
		return $this->season;
	}

	public function setSeason($season) {
		$this->season = $season;
	}

	public function getEpisode() {
		return $this->episode;
	}

	public function setEpisode($episode) {
		$this->episode = $episode;
	}

	public function getLanguage() {
		return $this->language;
	}

	private function parseLanguage(&$title) {
		$languages = array();

		foreach (Release::$languageStatic as $langue => $patterns) {
			if (!is_array($patterns)) {
				$patterns = array($patterns);
			}

			foreach ($patterns as $pattern) {
				$title = preg_replace('#[\\.|\\-]' . preg_quote($pattern) . '([\\.|\\-]|$)#i', '$1', $title, 1, $replacements);

				if (0 < $replacements) {
					$languages[] = $langue;

					break;
				}
			}
		}

		if (count($languages) == 1) {
			return $languages[0];
		} elseif (1 < count($languages)) {
			return 'MULTI';
		} else {
			return null;
		}
	}

	public function guessLanguage() {
		return 'VO';
	}

	public function setLanguage($language) {
		$this->language = $language;
	}

	public function getResolution() {
		return $this->resolution;
	}

	private function parseResolution(&$title) {
		return $this->parseAttribute($title, 'resolution');
	}

	public function guessResolution() {
		if (($this->getSource() == 'BLURAY') || ($this->getSource() == 'BDScr')) {
			return '1080p';
		} else {
			return 'SD';
		}
	}

	public function setResolution($resolution) {
		$this->resolution = $resolution;
	}

	public function getSource() {
		return $this->source;
	}

	private function parseSource(&$title) {
		return $this->parseAttribute($title, 'source');
	}

	public function setSource($source) {
		$this->source = $source;
	}

	public function getDub() {
		return $this->dub;
	}

	private function parseDub(&$title) {
		return $this->parseAttribute($title, 'dub');
	}

	public function setDub($dub) {
		$this->dub = $dub;
	}

	public function getEncoding() {
		return $this->encoding;
	}

	private function parseEncoding(&$title) {
		return $this->parseAttribute($title, 'encoding');
	}

	public function setEncoding($encoding) {
		$this->encoding = $encoding;
	}

	public function getYear() {
		return $this->year;
	}

	private function parseYear(&$title) {
		$year = null;
		$title = preg_replace_callback('#[\\.|\\-|\\(|\\ ](\\d{4})([\\.|\\-|\\|\\ )])?#', function ($matches) use (&$year) {
			if (isset($matches[1])) {
				$year = $matches[1];
			}

			return isset($matches[2]) ? $matches[2] : '';
		}, $title, 1);

		return $year;
	}

	public function guessYear() {
		return date('Y');
	}

	public function setYear($year) {
		$this->year = $year;
	}

	public function getGroup() {
		return $this->group;
	}

	private function parseGroup(&$title) {
		$group = null;
		$title = preg_replace_callback('#\\-([a-zA-Z0-9_\\.]+)$#', function ($matches) use (&$group) {
			if (12 < strlen($matches[1])) {
				preg_match('#(\\w+)#', $matches[1], $matches);
			}

			$group = preg_replace('#^\\.+|\\.+$#', '', $matches[1]);

			return '';
		}, $title);

		return $group;
	}

	public function setGroup($group) {
		$this->group = $group;
	}

	public function getFlags() {
		return $this->flags;
	}

	private function parseFlags(&$title) {
		$flags = array();

		foreach (Release::$flagsStatic as $key => $patterns) {
			if (!is_array($patterns)) {
				$patterns = array($patterns);
			}

			foreach ($patterns as $pattern) {
				$title = preg_replace('#[\\.|\\-]' . preg_quote($pattern) . '([\\.|\\-]|$)#i', '$1', $title, 1, $replacements);

				if (0 < $replacements) {
					$flags[] = $key;
				}
			}
		}

		return $flags;
	}

	public function setFlags($flags) {
		$this->flags = (is_array($flags) ? $flags : array($flags));
	}

	public function getScore() {
		$score = 0;
		$score += ($this->getTitle() ? 1 : 0);
		$score += ($this->getYear() ? 1 : 0);
		$score += ($this->getLanguage() ? 1 : 0);
		$score += ($this->getResolution() ? 1 : 0);
		$score += ($this->getSource() ? 1 : 0);
		$score += ($this->getEncoding() ? 1 : 0);
		$score += ($this->getDub() ? 1 : 0);

		return $score;
	}
}
