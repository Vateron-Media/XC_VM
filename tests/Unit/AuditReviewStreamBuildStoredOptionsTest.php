<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * A text option whose command template leaves its value unquoted
 * (`-acodec %s`) is passed to ffmpeg as one argument, exactly as stored, so
 * a stored value of more than one word, or one typed with quotes around it,
 * stops its channel. The Custom FFmpeg Commands page gives the statement
 * that finds such values: it runs against the install schema with the
 * options an install seeds, and lists those values only.
 */
#[Group('skip-on-panel')]
final class AuditReviewStreamBuildStoredOptionsTest extends TestCase {
	public function testThePagesStatementListsTheValuesPassedAsOneArgument(): void {
		$rPage = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/en/info/custom-ffmpeg-command.md');
		$this->assertSame(1, preg_match('/```sql\s+(SELECT [^`;]*\bFROM streams_options\b[^`;]*;)\s+```/', $rPage, $rMatch), 'the page gives the statement');

		$rDb = new TestDb();
		foreach (['streams_arguments', 'streams_options'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		$this->assertSame(1, preg_match('/INSERT INTO `streams_arguments` .*?;\n/s', (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql'), $rSeed));
		$rDb->exec($rSeed[0]);
		// 20 and 10 leave the value unquoted (`-acodec %s`, `-aspect %s`); 1, 9 and 19 quote it themselves.
		$rDb->exec("INSERT INTO `streams_options` (`stream_id`, `argument_id`, `value`) VALUES (1, 20, 'aac'), (2, 20, 'aac -rtsp_transport tcp'), (3, 10, '16:9'), (4, 10, '16:9 -g 50'), (5, 1, 'Mozilla/5.0 (X11; Linux)'), (6, 19, 'A: b'), (7, 9, '1280:720 -g 50'), (8, 20, '''aac'''), (9, 20, '\"ac3\"'), (10, 20, 'pcm_s16le')");

		$rRows = $rDb->pdo->query($rMatch[1])->fetchAll(\PDO::FETCH_NUM);
		sort($rRows);
		$this->assertEquals([[2, 'force_input_acodec', 'aac -rtsp_transport tcp'], [4, 'aspect', '16:9 -g 50'], [8, 'force_input_acodec', "'aac'"], [9, 'force_input_acodec', '"ac3"']], $rRows);
	}
}
