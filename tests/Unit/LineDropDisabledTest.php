<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;

/** The lines a query matches, and every statement it was asked. */
class LineDropDb extends DatabaseHandler {
	/** @var list<array<string, mixed>> */
	public array $rDisabled = [];
	/** @var list<array<string, mixed>> */
	public array $rLive = [];
	/** @var list<string> */
	public array $rQueries = [];
	/** @var list<array<string, mixed>> */
	private array $rRows = [];

	public function __construct() {
		$this->dbh = true;
	}

	public function query($query, $buffered = false) {
		$this->rQueries[] = (string) $query;
		if (str_contains((string) $query, 'FROM `lines` WHERE')) {
			$this->rRows = $this->rDisabled;
		} elseif (str_contains((string) $query, 'FROM `lines_live`')) {
			$this->rRows = $this->rLive;
		} else {
			$this->rRows = [];
		}
		return true;
	}

	public function num_rows() {
		return count($this->rRows);
	}

	public function get_rows(bool $use_id = false, string $column_as_id = '', bool $unique_row = true, string $sub_row_id = '') {
		return $this->rRows;
	}
}

/**
 * `cluster_kill_on_line_disable` (on by default) promised that a disabled,
 * locked or expired line loses its live sessions. Nothing read it: the line kept
 * streaming until its HLS window ran out or its TS worker was reaped. The drop
 * hangs off the signal every writer already sends after a change, so the line
 * form, a mass edit, the reseller API and an activation code's deactivation all
 * get it.
 */
final class LineDropDisabledTest extends TestCase {

	private LineDropDb $rDb;

	protected function setUp(): void {
		$this->rDb = new LineDropDb();
		LineService::setDb($this->rDb);
		ConnectionTracker::setDb($this->rDb);
		SettingsManager::set(['cluster_kill_on_line_disable' => 1, 'redis_handler' => 0, 'enable_cache' => 0]);
	}

	protected function tearDown(): void {
		foreach ([LineService::class, ConnectionTracker::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		SettingsManager::set([]);
	}

	/** @return list<string> The statements matching a fragment. */
	private function asked(string $rFragment): array {
		return array_values(array_filter($this->rDb->rQueries, static fn(string $rQ): bool => str_contains($rQ, $rFragment)));
	}

	public function testADisabledLineLosesItsSessions(): void {
		// No live row: the close itself is ConnectionTracker's (it reaches for
		// Redis and the node's registry), and what this pins is the lookup.
		$this->rDb->rDisabled = [['id' => 7]];
		$this->rDb->rLive = [];

		LineService::dropDisabled([7, '7', 0, -2]);

		$rAsked = $this->asked('FROM `lines` WHERE');
		$this->assertCount(1, $rAsked);
		$this->assertStringContainsString('`id` IN (7)', $rAsked[0], 'the ids are deduplicated and cleaned');
		$this->assertStringContainsString('`enabled` = 0 OR `admin_enabled` = 0', $rAsked[0]);
		$this->assertStringContainsString('`exp_date` < UNIX_TIMESTAMP()', $rAsked[0]);
		$this->assertCount(1, $this->asked('FROM `lines_live`'), 'and its sessions are read to be closed');
	}

	public function testALineThatIsStillOnKeepsItsSessions(): void {
		$this->rDb->rDisabled = [];
		LineService::dropDisabled([7]);
		$this->assertSame([], $this->asked('FROM `lines_live`'));
	}

	public function testTheSettingAndAnEmptyListAreHonoured(): void {
		SettingsManager::set(['cluster_kill_on_line_disable' => 0]);
		LineService::dropDisabled([7]);
		$this->assertSame([], $this->rDb->rQueries, 'switched off: not even the lookup');

		SettingsManager::set(['cluster_kill_on_line_disable' => 1]);
		LineService::dropDisabled([]);
		LineService::dropDisabled([0, -1]);
		$this->assertSame([], $this->rDb->rQueries);
	}

	public function testAnUnsetSettingIsOn(): void {
		// ClusterSettings::INTS and the column default (migration 028) say on.
		SettingsManager::set(['cluster_kill_on_line_disable' => null]);
		LineService::dropDisabled([7]);
		$this->assertNotSame([], $this->rDb->rQueries, 'unset: the lookup still runs');
	}

	public function testEveryLineWriterGoesThroughTheSignalThatDrops(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Domain/Line/LineService.php');

		// The two signals every writer calls, and the delete path, all drop.
		$this->assertSame(2, preg_match_all('/self::dropDisabled\(/', $rSource));
		$this->assertMatchesRegularExpression('/function updateLineSignal[^}]*dropDisabled/s', $rSource);
		$this->assertMatchesRegularExpression('/function updateLinesSignal[^}]*dropDisabled/s', $rSource);
		$this->assertMatchesRegularExpression('/\$rCloseCons\) \{\s*self::closeLineConnections/s', $rSource);
	}
}
