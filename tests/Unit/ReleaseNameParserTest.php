<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Vod\VodItemImporter;

/**
 * One parser of release names, the importer's. The TMDb refresh and the
 * panel's TMDb search used another (AdminHelpers::parserelease()), without
 * the importer's fixes and with one bug of its own: it took the name's
 * "file name" a second time, so "Mr. Nobody (2009)" was parsed as "Mr" and
 * "Show.Name.S01E02" lost its episode. A refresh could relabel an episode the
 * import had placed correctly.
 */
final class ReleaseNameParserTest extends TestCase {
	/** @var list<string> what the parser was asked */
	private array $rAsked = [];

	protected function tearDown(): void {
		ProcessRunner::useCapturer(null);
	}

	/** @param array<string, array<string, mixed>> $rAnswers the parser's answer by name (without ".mkv") */
	private function parser(array $rAnswers): void {
		ProcessRunner::useCapturer(function (array $rArgv) use ($rAnswers): array {
			$rName = (string) end($rArgv);
			$this->rAsked[] = $rName;
			return [0, (string) json_encode($rAnswers[preg_replace('/\.mkv$/', '', $rName)] ?? [])];
		});
	}

	public function testThereIsOneParser(): void {
		$this->assertFalse(method_exists(AdminHelpers::class, 'parserelease'));
		foreach (['Domain/Vod/TmdbCron.php', 'Infrastructure/Tmdb/TmdbApiService.php'] as $rFile) {
			$rSource = (string) file_get_contents(MAIN_HOME . $rFile);
			$this->assertStringNotContainsString('AdminHelpers::parserelease(', $rSource, $rFile);
			$this->assertStringContainsString('VodItemImporter::parserelease(', $rSource, $rFile);
		}
	}

	public function testANameWithDotsIsParsedWhole(): void {
		$this->parser(['Mr. Nobody (2009)' => ['title' => 'Mr. Nobody', 'year' => 2009]]);
		$this->assertSame('Mr. Nobody', VodItemImporter::parserelease('Mr. Nobody (2009)', 'guessit')['title']);
		$this->assertSame(['Mr. Nobody (2009).mkv'], $this->rAsked);
	}

	/**
	 * The explicit SxxExx rule corrects a parser that reads a numeric title as
	 * the season ("9-1-1"). It also wrote the first episode over a range the
	 * parser had read right, so a double episode was named as one.
	 */
	public function testAnEpisodeRangeIsKept(): void {
		$this->parser([
			'Les.Psys.S01E02E03' => ['title' => 'Les Psys', 'season' => 1, 'episode' => [2, 3]],
			'9-1-1 S02E05' => ['title' => '1', 'season' => 9, 'episode' => 1],
		]);
		$rDouble = VodItemImporter::parserelease('Les.Psys.S01E02E03', 'guessit');
		$this->assertSame([1, [2, 3]], [$rDouble['season'], $rDouble['episode']]);

		$rNumeric = VodItemImporter::parserelease('9-1-1 S02E05', 'guessit');
		$this->assertSame(['9-1-1', 2, 5], [$rNumeric['title'], $rNumeric['season'], $rNumeric['episode']], 'the rule still corrects the parser');
	}

	/** The tag is taken out as a word: trim($title, 'MULTI') ate the leading M of "Marshals". */
	public function testTheExcessWordIsTakenOutWhole(): void {
		$this->assertSame('Marshals', VodItemImporter::releaseTitle(['title' => 'Marshals MULTI', 'excess' => 'MULTI']));
		$this->assertSame('Marshals', VodItemImporter::releaseTitle(['title' => 'Marshals', 'excess' => ['MULTI']]));
		$this->assertNull(VodItemImporter::releaseTitle([]));
	}
}
