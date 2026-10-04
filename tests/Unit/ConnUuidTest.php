<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentConnections;

/**
 * The one connection uuid rule (AgentConnections::CONN_UUID): what every
 * store, event and command naming a connection accepts, kept in one place so
 * no reader drifts from the others.
 */
final class ConnUuidTest extends TestCase {
	public function testTheRuleTakesWhatAuthMintsAndNothingElse(): void {
		foreach (['a', bin2hex(random_bytes(16)), str_repeat('Z', 64), 'a_b-C9', 'LIVE1', 'live', 'ENDED_'] as $rUUID) {
			$this->assertSame(1, preg_match(AgentConnections::CONN_UUID, $rUUID), $rUUID);
		}
		// LIVE, ENDED, CONNECTIONS: the Redis store's indexes, which a record under that name would replace.
		foreach (['', str_repeat('a', 65), "abc\n", 'a/b', 'a.b', 'a b', '../x', 'é', 'LIVE', 'ENDED', 'CONNECTIONS'] as $rUUID) {
			$this->assertSame(0, preg_match(AgentConnections::CONN_UUID, $rUUID), json_encode($rUUID));
		}
	}

	public function testTheFragmentIsTheWholeRulesBody(): void {
		$this->assertSame('/^' . AgentConnections::CONN_UUID_CHARS . '\z/', AgentConnections::CONN_UUID);
	}

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testNoOtherCopyOfTheRuleInTheApplication(): void {
		$rRoot = MAIN_HOME;
		$rCopies = [];
		$rFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rRoot, \FilesystemIterator::SKIP_DOTS));
		foreach ($rFiles as $rFile) {
			$rPath = substr((string) $rFile, strlen($rRoot));
			if (!str_ends_with($rPath, '.php') || str_starts_with($rPath, 'vendor/') || $rPath === 'Core/Cluster/AgentConnections.php') {
				continue;
			}
			if (str_contains((string) file_get_contents((string) $rFile), '[A-Za-z0-9_-]{1,64}')) {
				$rCopies[] = $rPath;
			}
		}
		$this->assertSame([], $rCopies, 'use AgentConnections::CONN_UUID');
	}
}
