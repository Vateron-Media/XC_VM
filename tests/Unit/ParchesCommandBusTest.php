<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\CommandBus;

/**
 * Cluster command-bus hardening ported from the Parches module (verified still
 * present in 2.6.7). MAIN-only (CommandBus does not ship to load balancers).
 */
final class ParchesCommandBusTest extends TestCase {

	/** 1.16.102 — a node's archive-file list (~90KB) was truncated at 64KB → empty TV Archive. */
	public function testRpcResultLimitFitsCatchUpLists(): void {
		// cluster_commands.result is mediumblob (16MB) and the cluster API body is
		// 8MB, so the 64KB cap was only the constant.
		$this->assertSame(4194304, CommandBus::MAX_RESULT);
	}

	/** 1.16.101 — concurrent enqueues racing UNIQUE(server_id, seq) lost the command. */
	public function testEnqueueRetriesWithGrowingBackoff(): void {
		$this->assertSame(8, CommandBus::ATTEMPTS);
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Domain/Cluster/CommandBus.php');
		$this->assertStringContainsString('usleep(random_int(2000, 20000) * $rAttempt)', $rSrc, 'a jittered growing pause between failed enqueue attempts');
	}

	/** 1.16.104 — a best-effort per-node lock so concurrent enqueues serialise, not deadlock. */
	public function testEnqueueSerialisesPerNodeWithABestEffortLock(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Domain/Cluster/CommandBus.php');
		$this->assertStringContainsString("GET_LOCK(CONCAT(DATABASE(), '.cmd_seq_', ?), 2)", $rSrc);
		$this->assertStringContainsString("RELEASE_LOCK(CONCAT(DATABASE(), '.cmd_seq_', ?))", $rSrc);
	}
}
