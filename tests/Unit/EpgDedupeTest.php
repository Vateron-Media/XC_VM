<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\EpgCronJob;
use XcVm\Tests\Support\InstallSchema;

/**
 * The EPG job keeps, of programmes with the same source, channel and start,
 * the last one stored. It does so in one pass over the duplicated keys, with
 * the rows the self-join it replaces left, without reading each programme
 * against every other of its channel.
 */
final class EpgDedupeTest extends TestCase {
	/** The statement before: every programme against every other. */
	private const SELF_JOIN = 'DELETE n1 FROM `epg_data` n1, `epg_data` n2 WHERE n1.id < n2.id AND n1.epg_id = n2.epg_id AND n1.channel_id = n2.channel_id AND n1.start = n2.start;';

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('epg_data'));
	}

	/** 40 channels of 50 programmes, then doubles, a triple, another case, NULL channels and a second source. */
	private function fill(): void {
		$rRows = [];
		for ($c = 1; $c <= 40; $c++) {
			for ($p = 0; $p < 50; $p++) {
				$rRows[] = [1, 'ch' . $c, 1000 + $p * 60];
			}
		}
		$rRows = array_merge($rRows, [[1, 'ch1', 1000], [1, 'ch2', 1060], [1, 'ch3', 1120], [1, 'ch3', 1120], [1, 'CH4', 1000], [1, null, 1000], [1, null, 1000], [2, 'ch1', 1000]]);
		foreach (array_chunk($rRows, 500) as $rChunk) {
			$this->rDb->query('INSERT INTO `epg_data` (`epg_id`, `channel_id`, `start`, `end`, `title`) VALUES ' . implode(', ', array_fill(0, count($rChunk), "(?, ?, ?, 0, 't')")), ...array_merge(...$rChunk));
		}
	}

	/** @return list<int> the ids left */
	private function left(): array {
		$this->rDb->query('SELECT `id` FROM `epg_data` ORDER BY `id`');
		return array_map('intval', $this->rDb->get_column());
	}

	private function reads(string $rStatement): int {
		$this->rDb->exec('FLUSH STATUS');
		$this->rDb->exec($rStatement);
		$rReads = 0;
		foreach ($this->rDb->pdo->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll(\PDO::FETCH_KEY_PAIR) as $rValue) {
			$rReads += (int) $rValue;
		}
		return $rReads;
	}

	public function testTheSameProgrammesAreLeftAsBefore(): void {
		$this->fill();
		$this->rDb->exec('CREATE TABLE `epg_copy` LIKE `epg_data`');
		$this->rDb->exec('INSERT INTO `epg_copy` SELECT * FROM `epg_data`');

		$this->rDb->exec(EpgCronJob::DEDUPE);
		$rNew = $this->left();
		$this->rDb->exec('TRUNCATE `epg_data`');
		$this->rDb->exec('INSERT INTO `epg_data` SELECT * FROM `epg_copy`');
		$this->rDb->exec(self::SELF_JOIN);

		$this->assertSame($this->left(), $rNew);
		$this->assertCount(2000 + 2 + 1, $rNew, 'one of each key, the two NULL channels and the second source kept');
	}

	public function testItDoesNotReadEachProgrammeAgainstItsChannel(): void {
		$this->fill();
		$this->assertLessThan(10 * 2008, $this->reads(EpgCronJob::DEDUPE));

		$this->rDb->exec('TRUNCATE `epg_data`');
		$this->fill();
		$this->assertGreaterThan(10 * 2008, $this->reads(self::SELF_JOIN), 'the self-join it replaces');
	}
}
