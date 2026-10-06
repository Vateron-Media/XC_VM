<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ProxyInstallFlow;

/**
 * ProxyInstallFlow::installArchive(): root unpacks the proxy's archive into
 * the tree its service runs from, so it is sent to a directory only the SSH
 * user can enter (LbInstallFlow::privateDir), checked there, and removed with
 * that directory once it is unpacked or refused.
 */
final class AuditReviewInstallProxyArchiveTest extends TestCase {
	private const DIR = '/tmp/xcvm.aB3dE6gH9k';

	/** @var list<string> the commands run on the node */
	private array $rRan = [];

	/** @var list<string> where files were sent on the node */
	private array $rSentTo = [];

	/**
	 * @param string $rMktemp What the node answers to mktemp.
	 * @param bool   $rSends  Whether the archive arrives with its checksum.
	 * @return array{0: bool, 1: list<string>} [installArchive()'s answer, the statements it ran on MAIN]
	 */
	private function install(string $rMktemp, bool $rSends = true): array {
		$this->rRan = [];
		$this->rSentTo = [];
		$rDb = new class {
			/** @var list<string> */
			public array $rQueries = [];

			public function query(string $rSql, mixed ...$rArgs): bool {
				$this->rQueries[] = $rSql;
				return true;
			}
		};
		$rRunSSH = function ($rConn, string $rCommand) use ($rMktemp): array {
			$this->rRan[] = $rCommand;
			return ['output' => match (true) {
				str_starts_with($rCommand, 'mktemp') => $rMktemp . "\n",
				str_starts_with($rCommand, 'test -f') => "OK\n",
				default => '',
			}, 'error' => ''
			];
		};
		$rSendFileSSH = function ($rConn, string $rPath, string $rOutput, bool $rWarn = false) use ($rSends): bool {
			$this->rSentTo[] = $rOutput;
			return $rSends;
		};
		ob_start();
		try {
			$rOk = ProxyInstallFlow::installArchive(null, $rSendFileSSH, $rRunSSH, '/home/xc_vm/bin/install/', ProxyInstallFlow::getInstallFile(), 4, $rDb);
		} finally {
			ob_end_clean();
		}
		return [$rOk, $rDb->rQueries];
	}

	public function testTheArchiveIsUnpackedFromAPrivateDirectory(): void {
		[$rOk, $rQueries] = $this->install(self::DIR);
		$this->assertTrue($rOk);
		$this->assertSame('mktemp -d /tmp/xcvm.XXXXXXXXXX', $this->rRan[0]);
		$this->assertSame([self::DIR . '/proxy.tar.gz'], $this->rSentTo, 'not under a name every user of the node knows');
		$rUnpack = array_search('sudo tar -zxvf "' . self::DIR . '/proxy.tar.gz" -C "' . MAIN_HOME . '"', $this->rRan, true);
		$this->assertNotFalse($rUnpack, 'unpacked from where it was sent');
		$this->assertSame('sudo rm -rf ' . self::DIR, $this->rRan[$rUnpack + 1], 'and removed with its directory');
		$this->assertSame([], $rQueries, 'the server is not marked failed');
	}

	public function testWithoutAPrivateDirectoryNothingIsSentOrUnpacked(): void {
		foreach (['', '/tmp', self::DIR . '; id', 'mktemp: failed to create directory'] as $rAnswer) {
			[$rOk, $rQueries] = $this->install($rAnswer);
			$this->assertFalse($rOk, var_export($rAnswer, true));
			$this->assertSame(['mktemp -d /tmp/xcvm.XXXXXXXXXX'], $this->rRan);
			$this->assertSame([], $this->rSentTo);
			$this->assertSame(['UPDATE `servers` SET `status` = 4 WHERE `id` = ?;'], $rQueries, 'the server is marked failed');
		}
	}

	public function testAnArchiveThatDoesNotArriveIsNotUnpacked(): void {
		[$rOk, $rQueries] = $this->install(self::DIR, false);
		$this->assertFalse($rOk);
		$this->assertSame(['mktemp -d /tmp/xcvm.XXXXXXXXXX', 'sudo rm -rf ' . self::DIR], $this->rRan, 'only the directory is made and removed');
		$this->assertSame(['UPDATE `servers` SET `status` = 4 WHERE `id` = ?;'], $rQueries);
	}
}
