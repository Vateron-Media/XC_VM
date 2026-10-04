<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\RelayAuth;
use XcVm\Core\Cluster\Crypto\Ticket;
use XcVm\Core\Cluster\DataPlaneTrust;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Cluster\RelayGuard;
use XcVm\Core\Config\SettingsManager;
use XcVm\Tests\Support\ClusterReference as Ref;

/**
 * The parent's gate on /admin/{live,vod,timeshift,thumb} (ADR 0004, Phase 8):
 * a child's relay is admitted on a panel-signed ticket naming this parent and
 * the stream, and a fresh proof by the child's node key; the legacy password
 * only from a server's address whose DATAPLANE flow is off. Every check that
 * cannot be made refuses.
 */
final class RelayAuthTest extends TestCase {
	private const CHILD = 9;
	private const STREAM = 77;
	private const TARGET = '/admin/live?stream=77&extension=ts';

	private string $rSeed;

	private string $rChildSk;

	/** @var array<int, array{sid: int, gen: int, state: string, ed_pub: string, dataplane: bool}> */
	private array $rNodes = [];

	/** @var array<string, true> */
	private array $rSpent = [];

	private bool $rNoncesUp = true;

	private int $rNow;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$this->rSeed = random_bytes(32);
		$rPair = sodium_crypto_sign_keypair();
		$this->rChildSk = sodium_crypto_sign_secretkey($rPair);
		$this->rNodes = [self::CHILD => ['sid' => self::CHILD, 'gen' => 2, 'state' => 'active', 'ed_pub' => sodium_crypto_sign_publickey($rPair), 'dataplane' => true]];
		$this->rSpent = [];
		$this->rNow = time();
		$this->trust();
		SettingsManager::set(['live_streaming_pass' => 'the-stream-secret']);
		RelayGuard::useServers(static fn(): array => [
			[(int) SERVER_ID => ['server_ip' => '10.0.0.1', 'private_ip' => null], self::CHILD => ['server_ip' => '10.0.0.9', 'private_ip' => '192.168.0.9'], 4 => ['server_ip' => '10.0.0.4', 'private_ip' => null]],
			['127.0.0.1', '10.0.0.1', '10.0.0.9', '192.168.0.9', '10.0.0.4'],
		]);
	}

	/** The trust sources; $rMain forces MAIN (true) or a load balancer (false). */
	private function trust(?bool $rMain = null): void {
		DataPlaneTrust::useSources(
			fn(int $rSid): ?array => $this->rNodes[$rSid] ?? null,
			Ref::panelPub($this->rSeed),
			function (int $rSid, string $rNonce, int $rTs): bool {
				if (!$this->rNoncesUp || isset($this->rSpent[$rSid . ':' . bin2hex($rNonce)])) {
					return false;
				}
				return $this->rSpent[$rSid . ':' . bin2hex($rNonce)] = true;
			},
			null,
			$rMain
		);
	}

	protected function tearDown(): void {
		DataPlaneTrust::useSources(null, null, null);
		RelayGuard::useServers(null);
	}

	/** @param array<string, int> $rOver */
	private function ticket(array $rOver = [], ?string $rSeed = null): string {
		$rDoc = Ticket::document('rly', 'r166666-9-77', $this->rNow - 60, $this->rNow + 3600, $rOver + ['child_sid' => self::CHILD, 'child_gen' => 2, 'parent_sid' => (int) SERVER_ID, 'stream_id' => self::STREAM]);
		return Ticket::wire($rDoc, Ref::panelSign($rSeed ?? $this->rSeed, 'rly', $rDoc));
	}

	/** @return array<string, mixed> */
	private function request(?string $rTicket = null, string $rTarget = self::TARGET, ?int $rTsMs = null, ?string $rSk = null, ?string $rNonce = null): array {
		$rTicket ??= $this->ticket();
		return [
			'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $rTarget,
			RelayGuard::TICKET => $rTicket,
			RelayGuard::AUTH => RelayAuth::header($rSk ?? $this->rChildSk, $rTicket, 'GET', $rTarget, $rTsMs ?? $this->rNow * 1000, $rNonce),
		];
	}

	private function admit(array $rServer, int $rStream = self::STREAM, ?string $rPassword = null, string $rIP = '10.0.0.9'): ?string {
		return RelayGuard::admit($rStream, $rPassword, $rIP, $rServer, true, $this->rNow * 1000);
	}

	public function testAChildsRelayIsAdmittedOnceAndAReplayIsNot(): void {
		$rServer = $this->request();
		$this->assertSame(RelayGuard::RELAY, $this->admit($rServer));
		$this->assertSame(self::CHILD, RelayGuard::relay(self::STREAM, $this->request(), $this->rNow * 1000));
		$this->assertNull($this->admit($rServer), 'the same headers again: the nonce is spent');
	}

	public function testTheTicketMustNameThisParentAndTheStreamAsked(): void {
		$this->assertNull($this->admit($this->request(), 78), 'another stream than the ticket names');
		$this->assertNull($this->admit($this->request($this->ticket(['parent_sid' => (int) SERVER_ID + 100]))), 'another parent');
		$this->assertNull($this->admit($this->request($this->ticket([], random_bytes(32)))), 'another panel');
		$rWire = $this->ticket();
		[$rDoc, $rSig] = explode('.', $rWire);
		$rTampered = rtrim(strtr(base64_encode(str_replace('"stream_id":77', '"stream_id":78', (string) base64_decode(strtr($rDoc, '-_', '+/')))), '+/', '-_'), '=') . '.' . $rSig;
		$this->assertNull($this->admit($this->request($rTampered), 78), 'a tampered ticket');
	}

	public function testARevokedOrReEnrolledChildHoldsNoTicketThatWorks(): void {
		$this->rNodes[self::CHILD]['state'] = 'revoked';
		$this->assertNull($this->admit($this->request()), 'revoked');
		$this->rNodes[self::CHILD]['state'] = 'active';
		$this->rNodes[self::CHILD]['gen'] = 3;
		$this->assertNull($this->admit($this->request()), 're-enrolled: a new generation');
		unset($this->rNodes[self::CHILD]);
		$this->assertNull($this->admit($this->request()), 'not in the node list');
	}

	public function testTheProofIsTheChildsFreshAndForThisRequest(): void {
		$this->assertNull($this->admit($this->request(null, self::TARGET, null, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))), 'another key');
		$this->assertNull($this->admit($this->request(null, self::TARGET, ($this->rNow - 91) * 1000)), 'stale');
		$rServer = $this->request();
		$rServer['REQUEST_URI'] = '/admin/live?stream=77&extension=ts&prebuffer=1';
		$this->assertNull($this->admit($rServer), 'signed for another target');
	}

	public function testItFailsClosed(): void {
		$this->rNoncesUp = false;
		$this->assertNull($this->admit($this->request()), 'no nonce window: a replay cannot be told from a first try');
		$this->rNoncesUp = true;
		DataPlaneTrust::useSources(fn(int $rSid): ?array => $this->rNodes[$rSid] ?? null, null, static fn(): bool => true, null, false);
		$this->assertNull($this->admit($this->request()), 'no panel key');
	}

	public function testHeadersNeverFallBackToThePassword(): void {
		$rServer = $this->request(null, self::TARGET, null, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
		$this->assertNull($this->admit($rServer, self::STREAM, 'the-stream-secret', '10.0.0.4'));
	}

	public function testThePasswordOnlyFromAServerWhoseDataPlaneIsOff(): void {
		$this->assertSame(RelayGuard::PASSWORD, $this->admit([], self::STREAM, 'the-stream-secret', '10.0.0.4'), 'a legacy server, as before');
		$this->assertNull($this->admit([], self::STREAM, 'wrong', '10.0.0.4'), 'a wrong password');
		$this->assertNull($this->admit([], self::STREAM, 'the-stream-secret', '203.0.113.7'), 'not a server\'s address');
		$this->assertNull($this->admit([], self::STREAM, 'the-stream-secret', '10.0.0.9'), 'a child with DATAPLANE on pulls through its agent');
		$this->assertNull($this->admit([], self::STREAM, 'the-stream-secret', '192.168.0.9'), 'nor from its private address');
		$this->rNodes[self::CHILD]['dataplane'] = false;
		$this->assertSame(RelayGuard::PASSWORD, $this->admit([], self::STREAM, 'the-stream-secret', '10.0.0.9'), 'its flow off: the password again');
		$this->assertNull(RelayGuard::admit(self::STREAM, 'the-stream-secret', '10.0.0.4', [], false), 'an endpoint that takes no password (thumb)');
	}

	/**
	 * `?password[]=…` reaches the guard as an array: refused, never a
	 * TypeError (a 500 that logs the request).
	 */
	public function testAPasswordThatIsNotAStringIsRefused(): void {
		foreach ([['the-stream-secret'], ['x' => 'the-stream-secret'], 42, 1.5, true, false] as $rGiven) {
			$this->assertNull(RelayGuard::admit(self::STREAM, $rGiven, '10.0.0.4', [], true, $this->rNow * 1000), var_export($rGiven, true));
		}
		$this->assertSame(RelayGuard::PASSWORD, RelayGuard::admit(self::STREAM, 'the-stream-secret', '10.0.0.4', [], true, $this->rNow * 1000), 'the string still works');
	}

	/**
	 * On a load balancer the guard judges tickets and proofs by MAIN's clock
	 * as the agent anchored it, not the host's: a host clock an hour ahead
	 * would otherwise refuse every fresh proof and, set back, admit tickets
	 * MAIN let expire.
	 */
	public function testALoadBalancerJudgesByMainsAnchoredClock(): void {
		$rFile = (string) tempnam(sys_get_temp_dir(), 'lease');
		try {
			// MAIN's clock is 2 h behind this host's.
			$rNowMs = (int) round(microtime(true) * 1000);
			file_put_contents($rFile, json_encode(['exp' => 0, 'gen' => 2, 'anchor_ms' => $rNowMs - 7_200_000, 'wrote_at_ms' => $rNowMs]));
			NodeLease::usePath($rFile);
			$this->trust(false);
			$rMain = DataPlaneTrust::nowMs();
			$this->assertEqualsWithDelta($rNowMs - 7_200_000, $rMain, 5_000, 'MAIN\'s time, not the host\'s');

			$this->rNow = intdiv($rMain, 1000);
			$this->assertSame(RelayGuard::RELAY, RelayGuard::admit(self::STREAM, null, '10.0.0.9', $this->request(), true), 'a proof on MAIN\'s time');
			$this->rNow = time();
			$this->assertNull(RelayGuard::admit(self::STREAM, null, '10.0.0.9', $this->request(), true), 'the host\'s own time is 2 h off MAIN\'s');

			// No anchor (or a stale file): the host's clock.
			file_put_contents($rFile, json_encode(['exp' => 0, 'gen' => 2, 'anchor_ms' => 0, 'wrote_at_ms' => $rNowMs]));
			NodeLease::usePath($rFile);
			$this->assertEqualsWithDelta((int) round(microtime(true) * 1000), DataPlaneTrust::nowMs(), 5_000);
			$this->assertNull(NodeLease::mainNowMs());

			// MAIN judges by its own clock, whatever a stray file says.
			file_put_contents($rFile, json_encode(['exp' => 0, 'gen' => 2, 'anchor_ms' => $rNowMs - 7_200_000, 'wrote_at_ms' => $rNowMs]));
			NodeLease::usePath($rFile);
			$this->trust(true);
			$this->assertEqualsWithDelta((int) round(microtime(true) * 1000), DataPlaneTrust::nowMs(), 5_000);
		} finally {
			NodeLease::usePath(null);
			@unlink($rFile);
		}
	}

	/**
	 * The four endpoints go through the guard, and a relay never gets a
	 * playlist whose segment URLs carry the password.
	 */
	public function testTheParentEndpointsUseTheGuard(): void {
		$rDir = MAIN_HOME . 'Public/admin/';
		foreach (['live', 'vod', 'timeshift', 'thumb'] as $rName) {
			$rCode = (string) file_get_contents($rDir . $rName . '.php');
			$this->assertStringContainsString('RelayGuard::admit(', $rCode, $rName);
			$this->assertStringNotContainsString('AuthService::secretMatches', $rCode, $rName . ': the password is checked by the guard only');
		}
		foreach (['live', 'timeshift'] as $rName) {
			$this->assertMatchesRegularExpression("/RelayGuard::RELAY && .*=== 'm3u8'\\)/", (string) file_get_contents($rDir . $rName . '.php'), $rName);
		}
	}
}
