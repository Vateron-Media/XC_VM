<?php

$definitions = array(
	'EXT-X-VERSION' => array('category' => 'playlist', 'type' => 'int', 'position' => -3500),
	'EXT-X-TARGETDURATION' => array('category' => 'media-playlist', 'type' => 'int', 'position' => -2900),
	'EXT-X-MEDIA-SEQUENCE' => array('category' => 'media-playlist', 'type' => 'int', 'position' => -2800),
	'EXT-X-DISCONTINUITY-SEQUENCE' => array('category' => 'media-playlist', 'type' => 'int', 'position' => -2700),
	'EXT-X-PLAYLIST-TYPE' => array('category' => 'media-playlist', 'type' => 'enum', 'position' => -2600),
	'EXT-X-I-FRAMES-ONLY' => array('category' => 'media-playlist', 'type' => 'bool', 'position' => -2500),
	'EXT-X-ENDLIST' => array('category' => 'media-playlist', 'type' => 'bool', 'position' => 1000),
	'EXT-X-MEDIA' => array(
		'category' => 'master-playlist',
		'type' => array('TYPE' => 'enumerated-string', 'URI' => 'quoted-string', 'GROUP-ID' => 'quoted-string', 'LANGUAGE' => 'quoted-string', 'ASSOC-LANGUAGE' => 'quoted-string', 'NAME' => 'quoted-string', 'DEFAULT' => 'enumerated-string', 'AUTOSELECT' => 'enumerated-string', 'FORCED' => 'enumerated-string', 'INSTREAM-ID' => 'quoted-string', 'CHARACTERISTICS' => 'quoted-string', 'CHANNELS' => 'quoted-string'),
		'position' => -1900,
		'multiple' => true
	),
	'EXT-X-STREAM-INF' => array(
		'category' => 'master-playlist',
		'multiple' => true,
		'type' => array('BANDWIDTH' => 'decimal-integer', 'AVERAGE-BANDWIDTH' => 'decimal-integer', 'CODECS' => 'quoted-string', 'RESOLUTION' => 'decimal-resolution', 'FRAME-RATE' => 'decimal-floating-point', 'HDCP-LEVEL' => 'enumerated-string', 'AUDIO' => 'quoted-string', 'VIDEO' => 'quoted-string', 'SUBTITLES' => 'quoted-string', 'CLOSED-CAPTIONS' => 'quoted-string'),
		'position' => -1800,
		'uriAware' => true
	),
	'EXT-X-I-FRAME-STREAM-INF' => array('category' => 'master-playlist', 'multiple' => true, 'position' => -1700),
	'EXT-X-SESSION-DATA' => array(
		'category' => 'master-playlist',
		'type' => array('DATA-ID' => 'quoted-string', 'VALUE' => 'quoted-string', 'URI' => 'quoted-string', 'LANGUAGE' => 'quoted-string'),
		'position' => -1600
	),
	'EXT-X-SESSION-KEY' => array('category' => 'master-playlist', 'position' => -1500, 'multiple' => true),
	'EXT-X-INDEPENDENT-SEGMENTS' => array('category' => 'playlist', 'type' => 'bool', 'position' => -900),
	'EXT-X-START' => array(
		'category' => 'playlist',
		'type' => array('TIME-OFFSET' => 'signed-decimal-floating-point', 'PRECISE' => 'enumerated-string'),
		'position' => -800
	),
	'EXT-X-KEY' => array(
		'category' => 'media-segment',
		'type' => array('METHOD' => 'enumerated-string', 'URI' => 'quoted-string', 'IV' => 'hexadecimal-sequence', 'KEYFORMAT' => 'quoted-string', 'KEYFORMATVERSIONS' => 'quoted-string'),
		'position' => 100,
		'multiple' => true
	),
	'EXT-X-MAP' => array(
		'category' => 'media-segment',
		'type' => array('URI' => 'quoted-string', 'BYTERANGE' => 'byterange'),
		'position' => 200
	),
	'EXT-X-BYTERANGE' => array('category' => 'media-segment', 'type' => 'byterange', 'position' => 300),
	'EXT-X-DISCONTINUITY' => array('category' => 'media-segment', 'position' => 400, 'type' => 'bool'),
	'EXT-X-PROGRAM-DATE-TIME' => array('category' => 'media-segment', 'position' => 500, 'type' => 'datetime'),
	'EXT-X-DATERANGE' => array(
		'category' => 'media-segment',
		'position' => 600,
		'type' => array('ID' => 'quoted-string', 'CLASS' => 'quoted-string', 'START-DATE' => 'datetime', 'END-DATE' => 'datetime', 'DURATION' => 'decimal-floating-point', 'PLANNED-DURATION' => 'decimal-floating-point', 'SCTE35-CMD' => 'hexadecimal-sequence', 'SCTE35-OUT' => 'hexadecimal-sequence', 'SCTE35-IN' => 'hexadecimal-sequence', 'END-ON-NEXT' => 'enumerated-string')
	),
	'EXTINF' => array('category' => 'media-segment', 'type' => 'inf', 'position' => 1000)
);
$definitions['EXT-X-I-FRAME-STREAM-INF']['type'] = $definitions['EXT-X-STREAM-INF']['type'];
unset($definitions['EXT-X-I-FRAME-STREAM-INF']['type']['FRAME-RATE'], $definitions['EXT-X-I-FRAME-STREAM-INF']['type']['AUDIO'], $definitions['EXT-X-I-FRAME-STREAM-INF']['type']['SUBTITLES'], $definitions['EXT-X-I-FRAME-STREAM-INF']['type']['CLOSED-CAPTIONS']);

$definitions['EXT-X-I-FRAME-STREAM-INF']['type']['URI'] = 'quoted-string';
$definitions['EXT-X-SESSION-KEY']['type'] = $definitions['EXT-X-KEY']['type'];

return $definitions;
