<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * LbInstallFlow::applySysctl — the last of a load balancer install's
 * post-extract steps. The upload was checked, but what `sysctl -p` refused
 * went unsaid and the marker the node's root cron reads was never looked at.
 */
final class LbInstallSysctlTest extends TestCase {
	/** @var list<string> */
	private array $rRan = [];

	private string $rOut = '';

	public static function setUpBeforeClass(): void {
		foreach (['TMP_PATH' => sys_get_temp_dir() . '/xcvm-test-tmp/', 'CONFIG_PATH' => '/home/xc_vm/config/'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
		@mkdir(TMP_PATH, 0777, true);
	}

	/**
	 * A node whose sysctl.conf reads $rConf, whose `sysctl -p` says $rRefused on
	 * stderr, and which keeps the marker only when $rMarker; the upload works when $rSends.
	 */
	private function apply(int $rUpdate, string $rConf = '', string $rRefused = '', bool $rMarker = true, bool $rSends = true): bool {
		$this->rRan = [];
		$rHas = false;
		$rNode = function ($rConn, string $rCommand) use ($rConf, $rRefused, $rMarker, &$rHas): array {
			$this->rRan[] = $rCommand;
			if ($rCommand === 'sudo cat /etc/sysctl.conf') {
				return ['output' => $rConf, 'error' => ''];
			}
			if (str_starts_with($rCommand, 'sudo sysctl -p')) {
				return ['output' => $rRefused, 'error' => ''];
			}
			if (str_contains($rCommand, 'sysctl.on')) {
				$rTouch = str_starts_with($rCommand, 'sudo touch ');
				$rHas = $rMarker ? $rTouch : !$rTouch; // a node that cannot write it stays as it was not asked to be
				return ['output' => $rHas === $rTouch ? "MARK_OK\n" : '', 'error' => ''];
			}
			return ['output' => '', 'error' => ''];
		};
		$rSend = function ($rConn, string $rFrom, string $rTo) use ($rSends): bool {
			$this->rRan[] = 'send ' . $rTo;
			return $rSends;
		};
		ob_start();
		try {
			return LbInstallFlow::applySysctl(null, $rNode, $rSend, $rUpdate, "# XC_VM\nnet.core.somaxconn = 655350\n", 7);
		} finally {
			$this->rOut = (string) ob_get_clean();
		}
	}

	public function testTheFileIsSentAppliedAndMarked(): void {
		$this->assertTrue($this->apply(1));
		$this->assertContains('send /etc/sysctl.conf', $this->rRan);
		$this->assertContains('sudo sysctl -p 2>&1 >/dev/null', $this->rRan);
		$this->assertStringNotContainsString('did not apply', $this->rOut);
		$rMarker = escapeshellarg(CONFIG_PATH . 'sysctl.on'); // another test may have defined the path first
		$this->assertSame('sudo touch ' . $rMarker . ' && sudo test -e ' . $rMarker . ' && echo MARK_OK', end($this->rRan));
	}

	public function testAKeyTheKernelRefusesIsSaidAndTheInstallGoesOn(): void {
		$this->assertTrue($this->apply(1, '', 'sysctl: setting key "net.ipv4.conf.all.accept_source_route": Invalid argument'));
		$this->assertStringContainsString("sysctl -p did not apply every key on the node (the file is in place and applies at boot):\nsysctl: setting key \"net.ipv4.conf.all.accept_source_route\": Invalid argument\n", $this->rOut);
	}

	public function testAFileThatCannotBeSentStopsTheInstall(): void {
		$this->assertFalse($this->apply(1, '', '', true, false));
		$this->assertStringContainsString('Could not write /etc/sysctl.conf on the node', $this->rOut);
		$this->assertNotContains('sudo sysctl -p 2>&1 >/dev/null', $this->rRan, 'nothing is applied from a file that is not there');
	}

	public function testAMarkerThatCannotBeWrittenStopsTheInstall(): void {
		$this->assertFalse($this->apply(1, '', '', false));
		$this->assertStringContainsString("Could not set the node's sysctl marker", $this->rOut);
		$this->assertFalse($this->apply(0, '', '', false));
		$this->assertStringContainsString("Could not clear the node's sysctl marker", $this->rOut);
	}

	public function testOurOwnFileIsLeftAndASwitchedOffNodeLosesItsMarker(): void {
		$this->assertTrue($this->apply(1, "# XC_VM\nnet.core.somaxconn = 655350\n"));
		$this->assertNotContains('send /etc/sysctl.conf', $this->rRan, 'a file that names XC_VM is ours already');
		$this->assertTrue($this->apply(0));
		$rMarker = escapeshellarg(CONFIG_PATH . 'sysctl.on');
		$this->assertSame(['sudo cat /etc/sysctl.conf', 'sudo rm -f ' . $rMarker . ' && sudo test ! -e ' . $rMarker . ' && echo MARK_OK'], $this->rRan);
	}
}
