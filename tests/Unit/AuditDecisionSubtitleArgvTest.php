<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessRunner;

/**
 * A movie's subtitles are extracted by ffmpeg started with its arguments as
 * a list, not through a shell: the movie's path ends in its stored
 * container, which reaches ffmpeg as part of one argument, whatever it holds.
 */
final class AuditDecisionSubtitleArgvTest extends TestCase {
	private const CHILD = <<<'PHP'
		[$rDir, $rAutoload, $rSource] = array_slice($argv, 1);
		define('VOD_PATH', $rDir . 'vod/');
		require $rAutoload;
		$GLOBALS['rSettings'] = ['ffmpeg_warnings' => 0];
		$GLOBALS['rFFMPEG_CPU'] = $rDir . 'ffmpeg';
		var_export(\XcVm\Streaming\Codec\FFmpegCommand::extractSubtitle(12, $rSource, 2));
		PHP;

	private string $rDir = '';

	protected function tearDown(): void {
		if ($this->rDir !== '') {
			ProcessRunner::run(['rm', '-rf', '--', $this->rDir]);
		}
	}

	public function testTheMoviesPathReachesFfmpegAsOneArgument(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-subtitle-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'vod', 0700, true);
		// In ffmpeg's place: records its arguments, and writes the subtitle file it is asked for.
		file_put_contents($this->rDir . 'ffmpeg', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"" . $this->rDir . "argv\"\nfor rLast; do :; done\necho 1 > \"\$rLast\"\n");
		chmod($this->rDir . 'ffmpeg', 0700);
		$rSource = $this->rDir . 'vod/12.mkv$(touch ' . $this->rDir . 'ran)"; touch ' . $this->rDir . 'ran2; "';

		[$rStatus, $rOut] = ProcessRunner::capture([PHP_BINARY, '-r', self::CHILD, '--', $this->rDir, MAIN_HOME . 'vendor/autoload.php', $rSource]);

		$this->assertSame(0, $rStatus, $rOut);
		$this->assertSame('true', $rOut, 'the subtitle file was written');
		$this->assertSame(['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-i', $rSource, '-map', '0:s:2', $this->rDir . 'vod/12_2.srt'], file($this->rDir . 'argv', FILE_IGNORE_NEW_LINES));
		$this->assertFileDoesNotExist($this->rDir . 'ran');
		$this->assertFileDoesNotExist($this->rDir . 'ran2');
	}
}
