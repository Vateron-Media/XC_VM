<?php

use PHPUnit\Framework\TestCase;

/**
 * A live link with `?utc=<start>` is a catch-up link for the six hours from
 * that start. A catch-up duration is counted in minutes by everything that
 * reads it (the token and the redirect auth.php writes, and timeshift.php,
 * which looks for one recorded minute per minute of it), so the link asks for
 * 360. A `/timeshift/` link names its own duration and keeps it.
 *
 * auth.php and timeshift.php are procedural, so the lines under test are read
 * from the scripts and run in a child PHP, as AuditStreamAuthTest does.
 */
final class AuditDecisionSmallUtcWindowTest extends TestCase {
	private const AUTH = 'Public/stream/auth.php';
	private const TIMESHIFT = 'Public/stream/timeshift.php';

	/** A start further back than the longest duration a link is served. */
	private const START = '1700000000';

	/** The source of $rFile from $rFrom up to $rTo. */
	private function lines(string $rFile, string $rFrom, string $rTo): string {
		$rSource = (string) file_get_contents(MAIN_HOME . $rFile);
		$rStart = strpos($rSource, $rFrom);
		$this->assertNotFalse($rStart, $rFile . ' has no: ' . $rFrom);
		$rEnd = strpos($rSource, $rTo, $rStart);
		$this->assertNotFalse($rEnd, $rFile . ' has no: ' . $rTo);
		return substr($rSource, $rStart, $rEnd - $rStart);
	}

	/**
	 * What a link asks for, per query: the type and start auth.php reads from
	 * it, the duration it puts in the token, and how many recorded minutes
	 * timeshift.php then looks for.
	 *
	 * @param array<string, array<string, string>> $rQueries
	 * @return array<string, array{0: ?string, 1: string, 2: int, 3: int}>
	 */
	private function asked(array $rQueries): array {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ";\n"
			. '\XcVm\Core\Error\ErrorResponder::$throwInsteadOfExit = true;' . "\n"
			. '$rRun = static function (array $rGet) {' . "\n"
			. '$_GET = $rGet;' . "\n"
			. $this->lines(self::AUTH, 'if (isset($_GET[\'utc\'])) {', '$rType = (isset(') . "\n"
			. '$rRequest = $_GET; // the request is read from the query once the link is mapped' . "\n"
			. $this->lines(self::AUTH, '$rStartDate = ', 'switch ($rExtension) {') . "\n"
			. '$rTimestamp = intval($rStartDate);' . "\n"
			. $this->lines(self::TIMESHIFT, '$rScan = ', '// Batch check files') . "\n"
			. 'return [$_GET["type"] ?? null, $rStartDate, $rDuration, $rScan];' . "\n"
			. '};' . "\n"
			. 'echo json_encode(array_map($rRun, ' . var_export($rQueries, true) . '));';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rErr . $rOut);
		$this->assertSame('', $rErr);

		return json_decode($rOut, true);
	}

	public function testAUtcLinkAsksForSixHoursOfTheArchive(): void {
		$rOut = $this->asked([
			'utc' => ['utc' => self::START],
			'utc, and a duration of its own' => ['utc' => self::START, 'duration' => '9000'],
		]);

		// Six hours in the minutes a duration is counted in: 360 recorded minutes from the start.
		$this->assertSame(['timeshift', self::START, 360, 360], $rOut['utc']);
		$this->assertSame(['timeshift', self::START, 360, 360], $rOut['utc, and a duration of its own']);
	}

	public function testALinkWithoutUtcKeepsTheDurationItNames(): void {
		$rLink = ['type' => 'timeshift', 'start' => self::START];

		$rOut = $this->asked([
			'a programme' => ['duration' => '60'] + $rLink,
			'the longest served' => ['duration' => '21600'] + $rLink,
			'longer than that' => ['duration' => '99999999'] + $rLink,
		]);

		$this->assertSame(['timeshift', self::START, 60, 60], $rOut['a programme']);
		$this->assertSame(['timeshift', self::START, 21600, 21600], $rOut['the longest served']);
		$this->assertSame(['timeshift', self::START, 21600, 21600], $rOut['longer than that']);
	}
}
