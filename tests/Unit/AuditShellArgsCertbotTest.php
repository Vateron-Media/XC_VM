<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\CertbotCommand;
use XcVm\Domain\Server\ServerService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

// STATUS_* live in bootstrap.php (not loaded here); define the ones the saves return.
if (!defined('STATUS_INVALID_IP')) {
	define('STATUS_INVALID_IP', 9);
}
if (!defined('STATUS_INVALID_INPUT')) {
	define('STATUS_INVALID_INPUT', 34);
}

/**
 * The `certbot` command runs as root and starts certbot through a shell, with
 * the names of a server's `domain_name` on its command line. It asks a
 * certificate for host names only, each one argument: an address, or anything
 * else the list holds, is left out. A server's save adds addresses and names
 * only to the list.
 *
 * The command runs in a child PHP in a throwaway deploy root, where it does
 * not start anything: it says what it would have run, and /bin/sh then runs
 * that line with a `sudo` that records what it received.
 */
final class AuditShellArgsCertbotTest extends TestCase {
	private string $rHome;

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-certbot-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rHome . 'stub', 0700, true);
		file_put_contents($this->rHome . 'stub/sudo', "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\000' \"\$a\"; done > " . escapeshellarg($this->rHome . 'argv') . "\n");
		chmod($this->rHome . 'stub/sudo', 0755);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rHome));
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** A `servers` row (id 2) with this domain list, and an admin's session for its save. */
	private function serverWithDomains(string $rStored): TestDb {
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::serversTable());
		$rDb->query('INSERT INTO `servers` (`id`, `server_name`, `server_ip`, `domain_name`) VALUES (2, ?, ?, ?);', 'Node', '192.0.2.10', $rStored);
		DatabaseFactory::set($rDb);
		// Authorization::check() reads these globals.
		$GLOBALS['db'] = $rDb;
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		return $rDb;
	}

	/**
	 * The command lines the `certbot` command starts for these names (its dry
	 * run, then the request).
	 *
	 * @param list<mixed> $rDomains
	 * @return list<string>
	 */
	private function commandLines(array $rDomains): array {
		$rScript = $this->rHome . 'run.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			namespace XcVm\Cli\Commands {
				// The command runs as root; this child need not.
				function posix_getpwuid(int $rUid): array {
					return ['name' => 'root'];
				}

				// Nothing is started: the line is kept, and certbot's dry run "passes".
				function exec(string $rCommand, &$rOutput = null, &$rCode = null) {
					file_put_contents(MAIN_HOME . 'commands', $rCommand . "\0", FILE_APPEND);
					$rOutput = ['The dry run was successful.'];
					$rCode = 0;
					return '';
				}

				function shell_exec(string $rCommand) {
					return null;
				}
			}

			namespace XcVm\Core\Cluster {
				final class NodeStateSink {
					public static function forget(string $rKey): void {
					}
				}
			}

			namespace XcVm\Domain\Server {
				final class ServerRepository {
					public static function getAll(): array {
						return [1 => ['http_broadcast_port' => 80]];
					}
				}
			}

			namespace {
				define('MAIN_HOME', getenv('XCVM_TEST_HOME'));
				define('BIN_PATH', MAIN_HOME . 'bin/');
				define('PHP_BIN', PHP_BINARY);
				define('SERVER_ID', 1);
				require getenv('XCVM_TEST_SRC') . 'Cli/CommandInterface.php';
				require getenv('XCVM_TEST_SRC') . 'Cli/Commands/CertbotCommand.php';
				exit((new \XcVm\Cli\Commands\CertbotCommand())->execute([$argv[1]]));
			}
			PHP);
		$rProc = proc_open([...xcvm_test_child_php(), $rScript, base64_encode((string) json_encode(['action' => 'certbot_generate', 'domain' => $rDomains]))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rHome, ['XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_SRC' => MAIN_HOME, 'PATH' => (string) getenv('PATH')]);
		$this->assertIsResource($rProc);
		$rOut = stream_get_contents($rPipes[1]) . stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut);
		return is_file($this->rHome . 'commands') ? explode("\0", (string) file_get_contents($this->rHome . 'commands'), -1) : [];
	}

	/**
	 * What `sudo` receives when a shell runs the line, as the command's exec() does.
	 *
	 * @return list<string>
	 */
	private function sudoReceives(string $rLine): array {
		@unlink($this->rHome . 'argv');
		$rProc = proc_open(['/bin/sh', '-c', $rLine], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rHome, ['PATH' => $this->rHome . 'stub:/usr/bin:/bin']);
		proc_close($rProc);
		return is_file($this->rHome . 'argv') ? explode("\0", (string) file_get_contents($this->rHome . 'argv'), -1) : [];
	}

	/** Entries of a domain list that are not host names; `ran` is a file nothing may create. */
	public static function notHostNames(): array {
		return [
			'an address' => ['192.0.2.5'],
			'nothing' => [''],
			'with a semicolon' => ['a.example.com; touch ran'],
			'with a dollar sign' => ['$(touch ran).example.com'],
			'with backquotes' => ['b.example.com`touch ran`'],
			'with a line break' => ["c.example.com\ntouch ran"],
			'with a bar' => ['d.example.com|touch ran'],
			'with spaces' => ['e.example.com -d f.example.com'],
			'starting with two dashes' => ['--manual-auth-hook=touch'],
			'starting with a dash' => ['-g.example.com'],
		];
	}

	#[DataProvider('notHostNames')]
	public function testCertbotIsAskedForHostNamesOnlyEachOneArgument(string $rEntry): void {
		$rRan = $this->rHome . 'ran';
		$rLines = $this->commandLines(['good.example.com', 'Sub-1.Example.org', $rEntry]);
		$this->assertCount(2, $rLines, 'the dry run, then the request');
		foreach ($rLines as $i => $rLine) {
			$rArgv = $this->sudoReceives($rLine);
			$this->assertFileDoesNotExist($rRan, 'the line started certbot and nothing else');
			$this->assertSame(array_merge(['certbot'], $i === 0 ? ['--dry-run'] : [], ['--config-dir']), array_slice($rArgv, 0, $i === 0 ? 3 : 2));
			$rWebroot = (int) array_search($this->rHome . 'certbot-webroot', $rArgv, true);
			$this->assertSame(['-w', $this->rHome . 'certbot-webroot', '-d', 'good.example.com', '-d', 'Sub-1.Example.org'], array_slice($rArgv, $rWebroot - 1), 'the names end the line');
		}
	}

	/**
	 * An entry stored as a URL, after a path or with blanks around it is asked
	 * for by the host name it ends with, as it always was.
	 */
	public function testANameStoredAsAUrlIsAskedForByItsHostName(): void {
		$rLines = $this->commandLines(['http://cdn.example.com', 'https://www.example.org/', 'cdn2.example.net/', ' b.example.com ', 'http://192.0.2.5/']);
		$this->assertCount(2, $rLines, 'the dry run, then the request');
		foreach ($rLines as $rLine) {
			$this->assertSame(['-d', 'cdn.example.com', '-d', 'www.example.org', '-d', 'cdn2.example.net', '-d', 'b.example.com'], array_slice($this->sudoReceives($rLine), -8));
		}
	}

	public function testANameThatIsNotAHostNameIsLeftOut(): void {
		$rHostNames = new ReflectionMethod(CertbotCommand::class, 'hostNames');
		$this->assertSame(
			['example.com', 'sub-1.example.org', 'EXAMPLE.net', 'xn--80aswg.xn--p1ai', 'localhost'],
			$rHostNames->invoke(null, [
				'example.com', 'sub-1.example.org', 'EXAMPLE.net', 'xn--80aswg.xn--p1ai', 'localhost',
				'192.0.2.5', '2001:db8::1', '', ' ', '/', "example.com\n.org", "example.com\0.org",
				'a b.example.com', 'a;b.example.com', 'a&b.example.com', 'a|b.example.com', 'a>b.example.com', 'a$b.example.com', 'a`b`.example.com',
				"a'b.example.com", 'a"b.example.com', 'a\\b.example.com', 'a(b).example.com', 'a*.example.com', '*.example.com',
				'-a.example.com', '--help', '.example.com', 'under_score.example.com',
				'http://', 'http://192.0.2.5', 'http://example.com:8080', 'example.com/-a.example.org', 'example.com/a b',
				['example.org'], null, 7, true,
			])
		);
		$this->assertSame(
			['example.com', 'example.org', 'example.net', 'a.example.com', 'b.example.com', 'c.example.com', 'd.example.com'],
			$rHostNames->invoke(null, ['http://example.com', 'https://example.org/', 'example.net/', ' a.example.com', "b.example.com \n", '../c.example.com', 'a b/d.example.com']),
			'the host name an entry ends with'
		);
		foreach ([null, 'example.com', 7] as $rNotAList) {
			$this->assertSame([], $rHostNames->invoke(null, $rNotAList));
		}
	}

	/** Each name is quoted where it joins the line, whatever let it through. */
	public function testEveryNameJoinsTheLineAsOneShellArgument(): void {
		preg_match_all("/' -d ' \\. (\\w+)\\(/", (string) file_get_contents(MAIN_HOME . 'Cli/Commands/CertbotCommand.php'), $rJoined);
		$this->assertSame(['escapeshellarg'], $rJoined[1]);
	}

	public function testAServerKeepsAddressesAndNamesOnly(): void {
		$this->assertTrue(ServerService::domainNamesValid([]));
		// Everything the server form's own checks let into the list, today's and the earlier ones.
		$this->assertTrue(ServerService::domainNamesValid(['example.com', 'Sub-1.Example.org', 'my-cdn.example.co.uk', 'xn--80aswg.xn--p1ai', 'cdn_1.example.com', 'localhost', '192.0.2.5', '999.1.1.1', '2001:db8::1']));
		foreach ([
			'', ' example.com', 'example.com ', "example.com\n", 'a b.example.com', 'example.com,other.example',
			'a;b.example.com', 'a&b.example.com', 'a|b.example.com', 'a>b.example.com', 'a<b.example.com', 'a$b.example.com', 'a`b`.example.com',
			"a'b.example.com", 'a"b.example.com', 'a\\b.example.com', 'a(b).example.com', '*.example.com', 'http://example.com', 'example.com/', 'example.com:8080',
			'-a.example.com', '--help', '.example.com', 'example..com', 'example.com.',
			['example.com'], null, 7,
		] as $rName) {
			$this->assertFalse(ServerService::domainNamesValid(['example.com', $rName]), var_export($rName, true));
			$this->assertFalse(ServerService::domainNamesValid(['example.com', $rName], 'http://example.org,example.com'), var_export($rName, true) . ', not one of the stored entries');
		}
		$this->assertFalse(ServerService::domainNamesValid('example.com'), 'a list');
		// An entry the server already has is kept as it is.
		$this->assertTrue(ServerService::domainNamesValid(['example.com', 'http://example.org', '*.example.net'], 'http://example.org,*.example.net'));
		$this->assertFalse(ServerService::domainNamesValid(['example.com', ''], ''), 'no entry is stored: none is kept');
		$this->assertFalse(ServerService::domainNamesValid(['example.com', ''], 'example.com,'));
	}

	/**
	 * Both saves (a server, a proxy) check the list before they store
	 * anything. A save that accepts the list goes on to its next check, the
	 * address: posted empty here, so that save stops there.
	 */
	public function testBothSavesRefuseAListThatHoldsAnythingElse(): void {
		$rDb = $this->serverWithDomains('good.example.com');
		foreach (['process', 'processProxy'] as $rSave) {
			$this->assertSame(STATUS_INVALID_IP, ServerService::$rSave(['edit' => 2, 'server_ip' => '', 'domain_name' => ['good.example.com', 'new.example.com', '192.0.2.5']])['status'], $rSave . ': addresses and names are accepted');
			$this->assertSame(STATUS_INVALID_INPUT, ServerService::$rSave(['edit' => 2, 'server_name' => 'Renamed', 'server_ip' => '192.0.2.10', 'domain_name' => ['good.example.com', 'a.example.com; b']])['status'], $rSave);
			$this->assertSame(STATUS_INVALID_INPUT, ServerService::$rSave(['edit' => 2, 'server_name' => 'Renamed', 'server_ip' => '192.0.2.10', 'domain_name' => 'good.example.com'])['status'], $rSave . ': a list');
		}
		$rDb->query('SELECT `server_name`, `domain_name` FROM `servers` WHERE `id` = 2;');
		$this->assertSame(['server_name' => 'Node', 'domain_name' => 'good.example.com'], $rDb->get_row(), 'nothing was stored');
	}

	/**
	 * A server keeps the entries it already has (a migrated row may hold a
	 * name written as a URL); an entry it gains is an address or a name.
	 */
	public function testASaveKeepsTheEntriesTheServerAlreadyHas(): void {
		$this->serverWithDomains('http://cdn.example.com,old.example.com/,good.example.com');
		foreach (['process', 'processProxy'] as $rSave) {
			$this->assertSame(STATUS_INVALID_IP, ServerService::$rSave(['edit' => 2, 'server_ip' => '', 'domain_name' => ['good.example.com', 'http://cdn.example.com', 'old.example.com/', 'new.example.com']])['status'], $rSave . ': the list is accepted');
			$this->assertSame(STATUS_INVALID_INPUT, ServerService::$rSave(['edit' => 2, 'server_ip' => '', 'domain_name' => ['http://cdn.example.com', 'http://new.example.com']])['status'], $rSave . ': an entry it gains');
		}
	}
}
