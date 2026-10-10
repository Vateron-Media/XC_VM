<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * StreamProcess::buildSubtitleImport() — movies and episodes saved without
 * subtitles store movie_subtitles as NULL, and startMovie() hands that value
 * over. With a `string` parameter it threw, killing the queue daemon that
 * encodes the movie queue.
 */
final class StreamProcessSubtitleImportTest extends TestCase {

	private function build(?string $rJson): array {
		$rMethod = new ReflectionMethod(StreamProcess::class, 'buildSubtitleImport');
		$rMethod->setAccessible(true);
		return $rMethod->invoke(null, $rJson, []);
	}

	public function testNoSubtitlesImportsNothing(): void {
		$this->assertSame(['', ''], $this->build(null));
		$this->assertSame(['', ''], $this->build(''));
		$this->assertSame(['', ''], $this->build('{"files":[]}'));
	}

	/**
	 * A track's name is one argument of ffmpeg whatever it holds. It was put
	 * on the command line through escapeshellcmd(), which leaves spaces alone:
	 * "English (SDH) forced" became three arguments, the last two read as
	 * output files, and the movie's encode failed.
	 */
	public function testATracksNameIsOneArgument(): void {
		$rFile = tempnam(sys_get_temp_dir(), 'sub');
		try {
			[, $rMetadata] = $this->build((string) json_encode(['files' => [$rFile], 'names' => ['English (SDH) forced'], 'charset' => ['UTF-8'], 'location' => SERVER_ID]));
			$rArgs = [];
			exec('printf "%s\\n" ' . $rMetadata, $rArgs);
			$this->assertSame(['-map', '1', '-metadata:s:s:0', 'title=English (SDH) forced', '-metadata:s:s:0', 'language=English (SDH) forced'], $rArgs);
		} finally {
			@unlink($rFile);
		}
	}
}
