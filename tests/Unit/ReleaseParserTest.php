<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Vod\VodItemImporter;

/**
 * The release parsers (bin/guess, bin/python/release.py) run through
 * ProcessRunner: the release name is one argument, whatever it holds, and a
 * parser that prints nothing is no parse, not a json_decode(null) deprecation.
 */
final class ReleaseParserTest extends TestCase {
	/** @var list<list<string>> */
	private array $rCalls = [];

	private string $rAnswer = '';

	protected function setUp(): void {
		ProcessRunner::useCapturer(function (array $rArgv): array {
			$this->rCalls[] = $rArgv;
			return [0, $this->rAnswer];
		});
	}

	protected function tearDown(): void {
		ProcessRunner::useCapturer(null);
	}

	public function testTheReleaseNameIsOneArgument(): void {
		$this->rAnswer = '{"title":"Show"}';
		$rName = "Show's \$(id) | rm -rf ~; `x`";
		$this->assertSame(['title' => 'Show'], AdminHelpers::runReleaseParser('guessit', $rName . '.mkv'));
		AdminHelpers::runReleaseParser('ptn', $rName);
		$this->assertSame([[MAIN_HOME . 'bin/guess', $rName . '.mkv'], ['/usr/bin/python3', MAIN_HOME . 'bin/python/release.py', $rName]], $this->rCalls);
	}

	public function testTheImporterAsksTheParserItIsSetTo(): void {
		$this->rAnswer = '{"title":"Some Film","year":2020}';
		$this->assertSame('Some Film', VodItemImporter::parserelease('Some-Film', 'ptn')['title']);
		$this->assertSame(['/usr/bin/python3', MAIN_HOME . 'bin/python/release.py', 'Some_Film'], $this->rCalls[0]);
		VodItemImporter::parserelease('|FHD| Some Film', 'guessit');
		$this->assertSame([MAIN_HOME . 'bin/guess', 'Some Film.mkv'], $this->rCalls[1], 'the provider tag dropped');
	}

	public function testNoAnswerIsNoParse(): void {
		$this->assertNull(AdminHelpers::runReleaseParser('guessit', 'x.mkv'));
		$this->assertIsArray(VodItemImporter::parserelease('Some Film', 'guessit'));
	}
}
