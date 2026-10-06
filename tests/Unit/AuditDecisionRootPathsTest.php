<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\RootPathsCommand;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\TreeOwnership;
use XcVm\Core\Process\ProcessRunner;

/**
 * Root's crons, the service unit and the updater run code from a tree that
 * is xc_vm's. TreeOwnership is the one place that says what xc_vm has to
 * write, what root runs and which statements hand the tree over, and
 * `root:paths` prints which of root's paths xc_vm can replace. Neither
 * changes an owner or a mode.
 */
final class AuditDecisionRootPathsTest extends TestCase {
	/** A user and a group no account of this server has. */
	private const UID = 54321;
	private const GID = 54322;

	private string $rDir = '';

	protected function setUp(): void {
		// The commands of these tests are started for real.
		ProcessRunner::useRunner(null);
		ProcessRunner::useCapturer(null);
	}

	protected function tearDown(): void {
		if ($this->rDir !== '') {
			ProcessRunner::run(['rm', '-rf', '--', $this->rDir]);
		}
	}

	/**
	 * The files of the panel's tree that can hold a command or a class (PHP,
	 * shell, Python and the scripts without an extension), by their path
	 * from $rRoot.
	 *
	 * @return array<string, string>
	 */
	private static function sources(string $rRoot, string $rPrefix): array {
		$rFiles = [];
		$rWalk = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($rRoot, FilesystemIterator::SKIP_DOTS),
			static fn(SplFileInfo $rFile): bool => !$rFile->isLink() && !in_array($rFile->getFilename(), ['vendor', 'tests'], true)
		));
		foreach ($rWalk as $rFile) {
			// Files of other work in progress come and go: one that is gone is skipped.
			$rSize = @filesize($rFile->getPathname());
			$rText = $rSize === false || $rSize > 1048576 || !in_array($rFile->getExtension(), ['', 'php', 'sh', 'py'], true) || is_dir($rFile->getPathname()) ? false : @file_get_contents($rFile->getPathname());
			if ($rText !== false && !str_contains(substr($rText, 0, 512), "\0")) {
				$rFiles[$rPrefix . substr($rFile->getPathname(), strlen($rRoot))] = $rText;
			}
		}
		return $rFiles;
	}

	/** A tree of root's under the system's temporary directory. */
	private function tree(): string {
		$this->rDir = sys_get_temp_dir() . '/xcvm_rootpaths_' . bin2hex(random_bytes(6));
		mkdir($this->rDir, 0755);
		return $this->rDir . '/';
	}

	private function put(string $rPath, int $rMode, int $rUid = 0, int $rGid = 0, string $rBody = ''): void {
		if (!is_dir(dirname($rPath))) {
			mkdir(dirname($rPath), 0755, true);
		}
		str_ends_with($rPath, '/') ? mkdir($rPath) : file_put_contents($rPath, $rBody);
		chown($rPath, $rUid);
		chgrp($rPath, $rGid);
		chmod($rPath, $rMode);
	}

	/**
	 * Every recursive chown of the whole tree is one of the six listed, as it
	 * is written: a seventh, or a change to one, has to come to the list.
	 */
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testEveryChownOfTheWholeTreeIsListed(): void {
		$rRoot = dirname(__DIR__, 2) . '/';
		$rTree = '(?<![\w\/])\/home\/xc_vm\/?(?![\w\/.])|MAIN_HOME(?!\s*\.\s*[\'"]\w)|rBaseDir|\$SCRIPT(?![\w\/])|\$\{SCRIPT\}(?![\w\/])';
		$rFound = [];
		foreach (['install' => (string) file_get_contents($rRoot . 'install')] + self::sources($rRoot . 'src/', 'src/') as $rFile => $rText) {
			if ($rFile === 'src/Core/Config/TreeOwnership.php') {
				continue;
			}
			foreach (explode("\n", $rText) as $rLine) {
				if (str_contains($rLine, 'TreeOwnership::chownTree(') || (preg_match('/\bchown\b.*(?<![\w-])(-\w*R\w*|--recursive)\b/', $rLine) && preg_match('/' . $rTree . '/', $rLine))) {
					$rFound[] = $rFile . ': ' . trim($rLine);
				}
			}
		}
		$rListed = [];
		foreach (TreeOwnership::BLANKET_CHOWNS as $rFile => $rLine) {
			$rListed[] = $rFile . ': ' . $rLine;
		}
		sort($rFound);
		sort($rListed);

		$this->assertCount(6, $rListed);
		$this->assertSame($rListed, $rFound);
	}

	/**
	 * Post-update hands the tree over through chownTree(): sudo is started
	 * with the arguments the line it replaced gave it through the shell.
	 */
	public function testPostUpdateStartsTheChownItAlwaysDid(): void {
		$this->assertSame(['sudo', 'chown', '-R', 'xc_vm:xc_vm', '/home/xc_vm/'], TreeOwnership::chownTree('/home/xc_vm/'));

		// A stand-in for sudo that writes down what it is given, and a tree of
		// this test's own for it to be given.
		$rBin = rtrim($this->tree(), '/');
		$rBase = $rBin . '/tree/';
		$rLog = $rBin . '/log';
		file_put_contents($rBin . '/sudo', "#!/bin/sh\nprintf '%s\\n' \"\$#\" \"\$@\" >> " . escapeshellarg($rLog) . "\n");
		chmod($rBin . '/sudo', 0755);
		$rPath = (string) getenv('PATH');
		putenv('PATH=' . $rBin . ':' . $rPath);
		try {
			$this->assertSame($rBin . '/sudo', trim((string) shell_exec('command -v sudo')));
			exec('sudo chown -R xc_vm:xc_vm ' . $rBase);
			$rBefore = (string) file_get_contents($rLog);
			unlink($rLog);
			$rCode = ProcessRunner::run(TreeOwnership::chownTree($rBase));
		} finally {
			putenv('PATH=' . $rPath);
		}

		$this->assertSame(0, $rCode);
		$this->assertSame("4\nchown\n-R\nxc_vm:xc_vm\n" . $rBase . "\n", $rBefore);
		$this->assertSame($rBefore, file_get_contents($rLog));
	}

	/**
	 * What the service gives xc_vm at each start, the data directories the
	 * panel names and what an update keeps as the node's own are on the list
	 * of what xc_vm writes; the PHP and nginx binaries (nginx's modules with
	 * them) and the bundle's version file, which an update keeps too, are
	 * root's to write.
	 */
	public function testWhatThePanelGivesXcVmIsOnTheWritableList(): void {
		preg_match_all('/chown (?:-R )?xc_vm:xc_vm (?:\$SCRIPT|\/home\/xc_vm)\/(\S+)/', (string) file_get_contents(MAIN_HOME . 'service'), $rGiven);
		$this->assertContains('content/streams', $rGiven[1]);
		$this->assertContains('bin/xc_fanout', $rGiven[1]);

		$rData = [];
		foreach (ConstantsInitializer::paths('') as $rName => $rPath) {
			if ($rName !== 'BIN_PATH') {
				$rData[] = $rPath;
			}
		}
		$this->assertContains('content/', $rData);

		preg_match('/^UPDATE_EXCLUDE_DIRS = \[(.*?)^\]/ms', (string) file_get_contents(MAIN_HOME . 'update'), $rList);
		preg_match_all('/^\s*"([^"]+)"/m', $rList[1], $rKept);
		$this->assertContains('bin/nginx/conf/codes', $rKept[1]);

		$rMissing = [];
		foreach (array_merge($rGiven[1], $rData, $rKept[1]) as $rPath) {
			if (TreeOwnership::writable($rPath) === null) {
				$rMissing[] = $rPath;
			}
		}
		$this->assertSame(['bin/nginx_rtmp', 'bin/php', 'bin/bin_version.json', 'bin/nginx/sbin', 'bin/nginx/modules'], array_values(array_unique($rMissing)));
	}

	/**
	 * Root's chain has every directory the autoloader takes a class from,
	 * and the two programs the updater starts with sudo.
	 */
	public function testRootsChainHasTheClassDirectoriesAndWhatTheUpdaterStarts(): void {
		$rDirs = [];
		foreach (self::sources(MAIN_HOME, '') as $rFile => $rText) {
			if (str_ends_with($rFile, '.php') && str_contains($rFile, '/') && preg_match('/^namespace XcVm\\\\/m', $rText)) {
				$rDirs[strtok($rFile, '/') . '/'] = true;
			}
		}
		$this->assertContains('Core/', array_keys($rDirs));
		$this->assertSame([], array_values(array_diff(array_keys($rDirs), array_keys(TreeOwnership::ROOT_RUNS))));

		$rUpdater = (string) file_get_contents(MAIN_HOME . 'update');
		preg_match_all('/os\.system\(\'sudo %s[^\n]*/', $rUpdater, $rStarted);
		$this->assertSame(['os.system(\'sudo %s %s update "post-update"\' % (rPHPDir, rConsole))'], array_values(array_unique($rStarted[0])));
		foreach (['rPHPDir', 'rConsole'] as $rName) {
			$this->assertSame(1, preg_match('/^' . $rName . ' = rBaseDir \+ "([^"]+)"$/m', $rUpdater, $rMatch));
			$this->assertArrayHasKey($rMatch[1], TreeOwnership::ROOT_RUNS);
		}
	}

	/**
	 * What root starts, from the three texts: the panel's lines of root's
	 * crontab, the unit's Exec lines and the lines of `service`. A line run
	 * through `sudo -u` is not root's, and a program found through PATH is
	 * not the tree's.
	 */
	public function testChainTakesWhatRootStartsFromTheCrontabTheUnitAndTheService(): void {
		$rCrontab = "# m h dom mon dow command\n"
			. "0 4 * * * /usr/local/bin/backup /etc/backup.conf\n"
			. "* * * * * /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:root_signals # XC_VM\n"
			. "17 3 * * * sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:module_licenses # XC_VM\n"
			. "@reboot /home/xc_vm/Modules/extra/boot.sh > /dev/null 2>&1 # XC_VM\n";
		$rUnit = "[Service]\nUser=root\nExecStart=/bin/bash /home/xc_vm/service start\nExecStop=-/bin/bash /home/xc_vm/service stop\n";
		$rService = "SCRIPT=/home/xc_vm\n  sudo chown -R xc_vm:xc_vm \$SCRIPT/tmp\n  sudo -u xc_vm \$SCRIPT/bin/daemons.sh\n  sudo \$SCRIPT/bin/php/bin/php \$SCRIPT/console.php startup\n  sudo \$SCRIPT/bin/extra >/dev/null 2>/dev/null &\n";

		$rChain = TreeOwnership::chain('/home/xc_vm/', $rCrontab, $rUnit, $rService);

		foreach (array_keys(TreeOwnership::ROOT_RUNS) as $rPath) {
			$this->assertArrayHasKey('/home/xc_vm/' . $rPath, $rChain);
		}
		$this->assertSame(
			['/home/xc_vm/Modules/extra/boot.sh', '/bin/bash', '/home/xc_vm/bin/extra'],
			array_values(array_diff(array_keys($rChain), array_map(static fn(string $rPath): string => '/home/xc_vm/' . $rPath, array_keys(TreeOwnership::ROOT_RUNS))))
		);
		$this->assertContains("named in root's crontab", $rChain['/home/xc_vm/console.php']);
		$this->assertContains('named in `service`', $rChain['/home/xc_vm/console.php']);
		$this->assertContains('named in the systemd unit', $rChain['/home/xc_vm/service']);
		$this->assertSame(['named in the systemd unit'], $rChain['/bin/bash']);
	}

	/**
	 * For a path and each directory above it: who owns it, its mode, and how
	 * the user can write it. The user replaces a path through the first
	 * directory it writes, unless that one is sticky and neither it nor the
	 * entry below is the user's.
	 */
	public function testTrailSaysWhereTheUserCanWrite(): void {
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			$this->markTestSkipped('giving files to another user needs root');
		}
		$rBase = $this->tree();
		$this->put($rBase . 'code/', 0755);
		$this->put($rBase . 'code/root.php', 0644);
		$this->put($rBase . 'code/owned.php', 0444, self::UID);
		$this->put($rBase . 'code/group.php', 0664, 0, self::GID);
		$this->put($rBase . 'code/group_ro.php', 0646, 0, self::GID);
		$this->put($rBase . 'code/other.php', 0646);
		$this->put($rBase . 'code/sub/', 0755);
		$this->put($rBase . 'code/sub/deep.php', 0644, self::UID);
		$this->put($rBase . 'code/theirs/', 0700, self::UID);
		$this->put($rBase . 'code/theirs/root.php', 0600);
		$this->put($rBase . 'own/', 0755, self::UID);
		$this->put($rBase . 'own/root.php', 0644);
		$this->put($rBase . 'sticky/', 01777);
		$this->put($rBase . 'sticky/root.php', 0644);
		$this->put($rBase . 'sticky/owned.php', 0644, self::UID);
		symlink($rBase . 'own/root.php', $rBase . 'code/link.php');
		symlink($rBase . 'code/root.php', $rBase . 'code/safe.php');

		$rTrail = TreeOwnership::trail($rBase . 'code/root.php', self::UID, [self::GID]);
		$this->assertSame('/', $rTrail[0]['path']);
		$this->assertSame(['path' => $rBase . 'code/root.php', 'owner' => 'root', 'group' => 'root', 'mode' => '0644', 'by' => null], end($rTrail));
		$this->assertNull(TreeOwnership::through($rTrail), 'the temporary directory is sticky and the tree is root\'s');

		$rBy = static fn(string $rPath): ?string => TreeOwnership::through(TreeOwnership::trail($rBase . $rPath, self::UID, [self::GID]));
		$this->assertSame($rBase . 'code/owned.php', $rBy('code/owned.php'), 'its owner can give itself the right to write');
		$this->assertSame($rBase . 'code/group.php', $rBy('code/group.php'));
		$this->assertNull($rBy('code/group_ro.php'), 'a member of the group has the group\'s rights, not the others\'');
		$this->assertSame($rBase . 'code/other.php', $rBy('code/other.php'));
		$this->assertSame($rBase . 'own', $rBy('own/root.php'), 'through the directory it is in');
		$this->assertSame($rBase . 'own', $rBy('own/missing.php'), 'a file that is not there can be put there');
		$this->assertNull($rBy('code/missing.php'));
		$this->assertNull($rBy('sticky/root.php'));
		$this->assertSame($rBase . 'sticky', $rBy('sticky/owned.php'));
		$rOwn = TreeOwnership::trail($rBase . 'own', self::UID, []);
		$this->assertSame('54321', end($rOwn)['owner'], 'a user without a name is its number');

		$this->assertSame(
			[$rBase . 'code/group.php', $rBase . 'code/link.php -> ' . $rBase . 'own/root.php', $rBase . 'code/other.php', $rBase . 'code/owned.php', $rBase . 'code/sub/deep.php', $rBase . 'code/theirs/'],
			TreeOwnership::inside($rBase . 'code', self::UID, [self::GID])
		);
	}

	/**
	 * A symbolic link on the way to a path is a way to it: the path with the
	 * link put in its target's place is walked as well, down to the last
	 * target, there or not.
	 */
	public function testHopsFollowEveryLinkOnTheWay(): void {
		$rBase = $this->tree();
		mkdir($rBase . 'real');
		mkdir($rBase . 'own');
		mkdir($rBase . 'root');
		touch($rBase . 'real/console.php');
		symlink($rBase . 'real/console.php', $rBase . 'own/hop.php');
		symlink('../own/hop.php', $rBase . 'root/two.php');
		mkdir($rBase . 'realdir');
		symlink($rBase . 'realdir', $rBase . 'own/hopdir');
		symlink($rBase . 'own/hopdir', $rBase . 'root/bin');
		symlink($rBase . 'own/later.php', $rBase . 'root/dangling.php');

		$this->assertSame([$rBase . 'root/two.php', $rBase . 'own/hop.php', $rBase . 'real/console.php'], TreeOwnership::hops($rBase . 'root/two.php'), 'a link through a link');
		$this->assertSame([$rBase . 'root/bin/php', $rBase . 'own/hopdir/php', $rBase . 'realdir/php'], TreeOwnership::hops($rBase . 'root/bin/php'), 'a link in the middle of the path');
		$this->assertSame([$rBase . 'root/dangling.php', $rBase . 'own/later.php'], TreeOwnership::hops($rBase . 'root/dangling.php'), 'a link to what is not there yet');
		$this->assertSame([$rBase . 'real/console.php'], TreeOwnership::hops($rBase . 'real//./console.php'));
		symlink($rBase . 'root/loop', $rBase . 'root/loop');
		$this->assertSame([$rBase . 'root/loop'], TreeOwnership::hops($rBase . 'root/loop'), 'a loop ends');
	}

	/** Through each of those links the user replaces what root runs, where the link itself is root's. */
	public function testReportSaysTheUserReplacesWhatALinkOfItsOwnLeadsTo(): void {
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			$this->markTestSkipped('giving files to another user needs root');
		}
		$rBase = $this->tree();
		$this->put($rBase . 'real/console.php', 0644);
		$this->put($rBase . 'realdir/php', 0755);
		$this->put($rBase . 'root/', 0755);
		$this->put($rBase . 'own/', 0755, self::UID, self::GID);
		symlink($rBase . 'real/console.php', $rBase . 'own/hop.php');
		symlink($rBase . 'own/hop.php', $rBase . 'root/two.php');
		symlink($rBase . 'realdir', $rBase . 'own/hopdir');
		symlink($rBase . 'own/hopdir', $rBase . 'root/bin');
		symlink($rBase . 'own/later.php', $rBase . 'root/dangling.php');
		foreach (['own/hop.php', 'root/two.php', 'own/hopdir', 'root/bin', 'root/dangling.php'] as $rLink) {
			lchown($rBase . $rLink, str_starts_with($rLink, 'own/') ? self::UID : 0);
		}
		$rCrontab = '';
		foreach (['root/two.php', 'root/bin/php', 'root/dangling.php'] as $rRuns) {
			$rCrontab .= '* * * * * ' . $rBase . $rRuns . " # XC_VM\n";
		}

		$rReport = RootPathsCommand::report($rBase, self::UID, [self::GID], $rCrontab, '', '');

		foreach (['root/two.php', 'root/bin/php', 'root/dangling.php'] as $rRuns) {
			preg_match('/^' . preg_quote($rBase . $rRuns, '/') . '  \(.*?\n\n/ms', $rReport, $rMatch);
			$this->assertStringContainsString('  => xc_vm can replace it, through ' . $rBase . 'own', $rMatch[0] ?? '', $rRuns);
		}
	}

	/**
	 * The report names each path root runs with the directories above it and
	 * says which the user can replace, and it leaves the tree as it was.
	 */
	public function testReportNamesWhatTheUserCanReplaceAndChangesNothing(): void {
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			$this->markTestSkipped('giving files to another user needs root');
		}
		$rBase = $this->tree();
		$this->put($rBase . 'console.php', 0755);
		$this->put($rBase . 'service', 0750, 0, 0, "SCRIPT=" . rtrim($rBase, '/') . "\n  sudo \$SCRIPT/bin/php/bin/php \$SCRIPT/console.php startup\n");
		$this->put($rBase . 'bin/php/bin/php', 0551);
		$this->put($rBase . 'Core/', 0755);
		$this->put($rBase . 'Core/Kept.php', 0644);
		$this->put($rBase . 'Core/Left.php', 0644, self::UID, self::GID);
		$this->put($rBase . 'vendor/', 0755);
		$this->put($rBase . 'vendor/autoload.php', 0644);
		$this->put($rBase . 'bin/xc_fanout/', 0755, self::UID, self::GID);
		$this->put($rBase . 'bin/xc_fanout/xc_fanout', 0755);
		$rCrontab = '* * * * * ' . $rBase . 'bin/php/bin/php ' . $rBase . "console.php cron:root_signals # XC_VM\n";
		// Every path of the tree with its owner, group, mode, time and size.
		$rState = static fn(): string => ProcessRunner::capture(['find', $rBase, '-printf', '%p %U %G %m %T@ %s\n'])[1];
		$rBefore = $rState();

		$rReport = RootPathsCommand::report($rBase, self::UID, [self::GID], $rCrontab, '', (string) file_get_contents($rBase . 'service'));

		$this->assertStringContainsString($rBase . 'Core/Left.php 54321 54322 644 ', $rBefore);
		$this->assertSame($rBefore, $rState());
		$rOf = static function (string $rPath) use ($rReport, $rBase): string {
			return preg_match('/^' . preg_quote($rBase . $rPath, '/') . '  \(.*?\n\n/ms', $rReport, $rMatch) ? $rMatch[0] : '';
		};
		$this->assertStringContainsString("named in root's crontab", $rOf('console.php'));
		$this->assertStringContainsString('named in `service`', $rOf('console.php'));
		$this->assertMatchesRegularExpression('/^  root:root\s+0755  ' . preg_quote($rBase . 'console.php', '/') . '$/m', $rOf('console.php'));
		$this->assertStringContainsString('  => xc_vm cannot replace it', $rOf('console.php'));
		$this->assertStringContainsString('  => xc_vm cannot replace it', $rOf('vendor/'));
		$this->assertStringContainsString('  => xc_vm can replace ' . $rBase . 'Core/Left.php', $rOf('Core/'));
		$this->assertStringNotContainsString('Kept.php', $rOf('Core/'));
		$this->assertMatchesRegularExpression('/^  54321:54322\s+0755  ' . preg_quote($rBase . 'bin/xc_fanout', '/') . '  <- xc_vm writes it \(owner\)$/m', $rOf('bin/xc_fanout/xc_fanout'));
		$this->assertStringContainsString('  => xc_vm can replace it, through ' . $rBase . 'bin/xc_fanout', $rOf('bin/xc_fanout/xc_fanout'));
		$this->assertStringContainsString('  xc_vm has to write bin/xc_fanout/', $rOf('bin/xc_fanout/xc_fanout'));
		$this->assertStringContainsString('  => not there', $rOf('update'));
		$this->assertStringContainsString("\n2 of ", $rReport);
	}

	/** The check ships in both builds: the load balancer build strips neither file. */
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testTheCheckShipsToLoadBalancers(): void {
		$rMakefile = (string) file_get_contents(dirname(__DIR__, 2) . '/Makefile');

		$this->assertStringNotContainsString('RootPathsCommand', $rMakefile);
		$this->assertStringNotContainsString('TreeOwnership', $rMakefile);
		$this->assertSame('root:paths', (new RootPathsCommand())->getName());
	}
}
