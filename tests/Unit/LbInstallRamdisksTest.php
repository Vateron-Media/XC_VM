<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * LbInstallFlow::ensureRamdisks — a load balancer's live segments and its tmp
 * are ramdisks, mounted from its fstab. The install added both lines only when
 * the first was missing and never looked at the result: a file that held one
 * was never completed, and a write that failed went unnoticed.
 */
final class LbInstallRamdisksTest extends TestCase {
	/** @var list<string> */
	private array $rRan = [];

	public static function setUpBeforeClass(): void {
		foreach (['STREAMS_PATH' => '/tmp/xcvm-test-streams/', 'TMP_PATH' => sys_get_temp_dir() . '/xcvm-test-tmp/'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
	}

	/** A node whose fstab is $rFstab; with $rWrites off, nothing appended to it stays. */
	private function ensure(string &$rFstab, bool $rWrites = true): bool {
		$this->rRan = [];
		$rNode = function ($rConn, string $rCommand) use (&$rFstab, $rWrites): array {
			$this->rRan[] = $rCommand;
			if (str_starts_with($rCommand, 'sudo cat ')) {
				return ['output' => $rFstab, 'error' => ''];
			}
			if ($rWrites && preg_match("/^echo '(.*)' \| sudo tee -a '\/etc\/fstab' > \/dev\/null\z/", $rCommand, $rM)) {
				$rFstab .= $rM[1] . "\n";
			}
			return ['output' => '', 'error' => ''];
		};
		ob_start();
		try {
			return LbInstallFlow::ensureRamdisks(null, $rNode);
		} finally {
			ob_end_clean();
		}
	}

	private static function line(string $rPath, string $rSize): string {
		return 'tmpfs ' . $rPath . ' tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,' . $rSize . " 0 0\n";
	}

	public function testBothRamdisksAreAddedToAFreshNode(): void {
		$rFstab = "UUID=abc / ext4 defaults 0 1\n";
		$this->assertTrue($this->ensure($rFstab));
		$this->assertSame("UUID=abc / ext4 defaults 0 1\n" . self::line(STREAMS_PATH, 'size=90%') . self::line(TMP_PATH, 'size=2G'), $rFstab);
	}

	public function testAFileThatHoldsOneIsCompleted(): void {
		$rFstab = self::line(STREAMS_PATH, 'size=90%');
		$this->assertTrue($this->ensure($rFstab));
		$this->assertSame(self::line(STREAMS_PATH, 'size=90%') . self::line(TMP_PATH, 'size=2G'), $rFstab, 'tmp is added, the segments are not added twice');
	}

	public function testNothingIsWrittenWhenBothAreThere(): void {
		$rFstab = self::line(STREAMS_PATH, 'size=90%') . self::line(TMP_PATH, 'size=2G');
		$this->assertTrue($this->ensure($rFstab));
		$this->assertSame(["sudo cat '/etc/fstab'"], $this->rRan, 'read once, nothing appended');
	}

	public function testAWriteThatDidNotStickStopsTheInstall(): void {
		$rFstab = "UUID=abc / ext4 defaults 0 1\n";
		$this->assertFalse($this->ensure($rFstab, false));
		$this->assertSame("UUID=abc / ext4 defaults 0 1\n", $rFstab);
	}
}
