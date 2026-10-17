<?php

use XcVm\Core\Cluster\ReplicaApply;
use PHPUnit\Framework\TestCase;

/**
 * @covers \XcVm\Core\Cluster\ReplicaApply
 *
 * The shadow drift check (ReplicaApply::streamDiffer) must compare the `runtime`
 * directive StreamRecords::data() now signs into every stream record. Without
 * it, a stored replica whose runtime directive is missing or stale — while the
 * other parts match — is reported as matching, so an incorrect serve-only LB
 * directive goes undetected by shadow checks and operators.
 */
final class StreamDifferRuntimeTest extends TestCase {

	/** @return list<string> */
	private function differ(array $rMain, array $rData): array {
		$rMethod = new \ReflectionMethod(ReplicaApply::class, 'streamDiffer');
		$rMethod->setAccessible(true);
		return $rMethod->invoke(null, $rMain, $rData);
	}

	public function testRuntimeDriftIsReported(): void {
		$rBase = ['stream' => ['id' => 1], 'runtime' => ['kind' => 'proxy', 'source' => ['urls' => ['http://a/1']]]];
		$this->assertSame([], $this->differ($rBase, $rBase), 'identical records do not differ');

		$rChanged = $rBase;
		$rChanged['runtime'] = ['kind' => 'other', 'source' => null];
		$this->assertContains('runtime', $this->differ($rBase, $rChanged), 'a changed runtime directive is reported');

		$rMissing = $rBase;
		unset($rMissing['runtime']);
		$this->assertContains('runtime', $this->differ($rBase, $rMissing), 'a missing runtime directive is reported');
	}
}
