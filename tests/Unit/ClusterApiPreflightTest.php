<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * ADR 0004 steps 1-3 as ClusterApi checks them once for the three ways a
 * request names its sender: a session (MAC'd, any epoch, the 8 MB cap),
 * token_rekey (node-signed alone: no X-XCVM-Sig, epoch 0, a body of at most
 * 64 KiB) and the code ops (MAC'd with the code's K_req: epoch 0, a `sid:`
 * node, at most 64 KiB). Each shape's refusals are pinned: a header or body
 * outside the shape is a 400 naming nothing, then 426 PROTO, then 401
 * CLOCK_SKEW, each naming the node and nonce. Only a code op's body at its
 * cap reaches a database, an empty one: the code is looked up and not found.
 * Every other request is refused before the node is read, or names a `sid:`
 * node, which a session op and token_rekey refuse unread.
 */
final class ClusterApiPreflightTest extends TestCase {
	private const T0 = 1800000000000;

	private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private const CAP_UNSESSIONED = 65536;

	protected function setUp(): void {
		ClusterClock::fix(self::T0);
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	public function testEachShapeCapsItsBody(): void {
		// A session op: the 8 MB cap. At it, the request passes on to the node
		// (a `sid:` node is unknown to a session op); past it, 400.
		$this->assertRefused(400, 'BAD_REQUEST', false, $this->call('heartbeat', self::UUID, 1, true, str_repeat('x', ClusterApi::MAX_BODY + 1)));
		$this->assertRefused(401, 'UNKNOWN_NODE', true, $this->call('heartbeat', 'sid:5', 1, true, str_repeat('x', ClusterApi::MAX_BODY)));

		// token_rekey: 64 KiB, and never empty.
		$this->assertRefused(401, 'UNKNOWN_NODE', true, $this->call('token_rekey', 'sid:5', 0, false, str_repeat('x', self::CAP_UNSESSIONED)));
		$this->assertRefused(400, 'BAD_REQUEST', false, $this->call('token_rekey', 'sid:5', 0, false, str_repeat('x', self::CAP_UNSESSIONED + 1)));
		$this->assertRefused(400, 'BAD_REQUEST', false, $this->call('token_rekey', 'sid:5', 0, false, ''));

		// The code ops: 64 KiB. At it, the request passes on to the code, which
		// an empty database does not hold.
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `cluster_enrol_codes` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` INTEGER NOT NULL, `exp` INTEGER NOT NULL)');
		$rDb->exec('CREATE TABLE `cluster_enrol_requests` (`server_id` INTEGER NOT NULL, `code_id` INTEGER NOT NULL)');
		DatabaseFactory::set($rDb);
		foreach (['enrol_code', 'enrol_code_status'] as $rOp) {
			$this->assertRefused(401, 'CODE_INVALID', true, $this->call($rOp, 'sid:5', 0, true, str_repeat('x', self::CAP_UNSESSIONED)), $rOp);
			$this->assertRefused(400, 'BAD_REQUEST', false, $this->call($rOp, 'sid:5', 0, true, str_repeat('x', self::CAP_UNSESSIONED + 1)), $rOp);
		}
	}

	public function testEachShapeTakesOnlyItsOwnHeaders(): void {
		// A session op is MAC'd.
		$this->assertRefused(400, 'BAD_REQUEST', false, $this->call('heartbeat', self::UUID, 1, false, '{}'));
		// token_rekey carries no MAC, at epoch 0.
		$this->assertRefused(400, 'BAD_REQUEST', false, $this->call('token_rekey', self::UUID, 0, true, 'x'));
		$this->assertRefused(400, 'BAD_REQUEST', false, $this->call('token_rekey', self::UUID, 1, false, 'x'));
		// The code ops are MAC'd, at epoch 0, from a `sid:` node.
		foreach (['enrol_code', 'enrol_code_status'] as $rOp) {
			$this->assertRefused(400, 'BAD_REQUEST', false, $this->call($rOp, 'sid:5', 0, false, '{}'), $rOp);
			$this->assertRefused(400, 'BAD_REQUEST', false, $this->call($rOp, 'sid:5', 1, true, '{}'), $rOp);
			$this->assertRefused(400, 'BAD_REQUEST', false, $this->call($rOp, self::UUID, 0, true, '{}'), $rOp);
		}
	}

	public function testProtocolThenWindowAreCheckedAlikeForEveryShape(): void {
		$rShapes = [
			['heartbeat', self::UUID, 1, true, '{}'],
			['token_rekey', self::UUID, 0, false, 'x'],
			['enrol_code', 'sid:5', 0, true, '{}'],
			['enrol_code_status', 'sid:5', 0, true, '{}'],
		];
		foreach ($rShapes as [$rOp, $rNode, $rEpoch, $rSigned, $rBody]) {
			$rDoc = $this->assertRefused(426, 'PROTO', true, $this->call($rOp, $rNode, $rEpoch, $rSigned, $rBody, ClusterApi::PROTO_MAX + 1), $rOp);
			$this->assertSame([ClusterApi::PROTO_MIN, ClusterApi::PROTO_MAX], [$rDoc['min'], $rDoc['max']], $rOp);
			// The protocol before the window: both wrong, PROTO.
			$this->assertRefused(426, 'PROTO', true, $this->call($rOp, $rNode, $rEpoch, $rSigned, $rBody, ClusterApi::PROTO_MAX + 1, self::T0 + 91000), $rOp);
			$this->assertRefused(401, 'CLOCK_SKEW', true, $this->call($rOp, $rNode, $rEpoch, $rSigned, $rBody, ClusterApi::PROTO_MIN, self::T0 + 91000), $rOp);
			$this->assertRefused(401, 'CLOCK_SKEW', true, $this->call($rOp, $rNode, $rEpoch, $rSigned, $rBody, ClusterApi::PROTO_MIN, self::T0 - 91000), $rOp);
		}
	}

	/**
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private function call(string $rOp, string $rNode, int $rEpoch, bool $rSigned, string $rBody, int $rProto = ClusterApi::PROTO_MIN, int $rTsMs = self::T0): array {
		$rHeaders = [
			Canonical::H_PROTO => (string) $rProto, Canonical::H_AGENT => 'xc_agent/test', Canonical::H_NODE => $rNode,
			Canonical::H_EPOCH => (string) $rEpoch, Canonical::H_TS => (string) $rTsMs, Canonical::H_NONCE => str_repeat('ab', 16),
			'Content-Type' => 'application/octet-stream',
		] + ($rSigned ? [Canonical::H_SIG => str_repeat('0', 64)] : []);
		$rReq = ['method' => 'POST', 'path' => Canonical::PATH_PREFIX . $rOp, 'headers' => $rHeaders, 'body' => $rBody, 'https' => true];
		return ClusterApi::handle(new FakeClusterCrypto(), $rReq, ['cluster_api_enabled' => 1], []);
	}

	/**
	 * A panel-signed denial with this status and reason, naming the request's
	 * node and nonce or, refused before its headers are trusted, neither.
	 *
	 * @param array{status: int, headers: array<string, string>, body: string} $rRes
	 * @return array<string, mixed> the denial
	 */
	private function assertRefused(int $rStatus, string $rReason, bool $rNamed, array $rRes, string $rWhat = ''): array {
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame([$rStatus, $rReason], [$rRes['status'], $rDoc['reason'] ?? null], $rWhat . ' ' . $rRes['body']);
		$this->assertSame($rNamed ? str_repeat('ab', 16) : null, $rDoc['req_nonce'], $rWhat);
		$this->assertSame($rNamed, $rDoc['node'] !== null, $rWhat);
		return $rDoc;
	}
}
