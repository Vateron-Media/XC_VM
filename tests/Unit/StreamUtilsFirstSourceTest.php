<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\StreamUtils;

/**
 * `streams.stream_source` is a JSON list. Reading its first entry as
 * `json_decode(...)[0]` warned on an empty list and failed on a source that
 * does not parse; the TMDb refresh then searched for an empty title.
 */
final class StreamUtilsFirstSourceTest extends TestCase {
	public function testTheFirstSourceOfAList(): void {
		$this->assertSame('s:1:/vod/a.mkv', StreamUtils::firstSource('["s:1:/vod/a.mkv","http://b/c.mkv"]'));
		$this->assertSame('http://b/c.mkv', StreamUtils::firstSource('{"x":"http://b/c.mkv"}'), 'a list stored with keys');
	}

	public function testNoSourceIsTheEmptyString(): void {
		foreach (['', '[]', 'null', 'not json', '[null]', '[["nested"]]', '[42]', null, false, 7, []] as $rStored) {
			$this->assertSame('', StreamUtils::firstSource($rStored), var_export($rStored, true));
		}
		$this->assertSame('', pathinfo(StreamUtils::firstSource('[]'))['filename'], 'what the TMDb refresh takes its title from');
	}
}
