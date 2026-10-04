<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\FileDigest;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\RelayAuth;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\Crypto\Ticket;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Cluster\DataPlaneTrust;
use XcVm\Core\Cluster\FileTicketServer;
use XcVm\Core\Cluster\RelaySeal;
use XcVm\Tests\Support\ClusterReference as Ref;

/**
 * The owner's side of /xfile (ADR 0004, Phase 8): a file read with a
 * panel-signed file ticket and the fetcher's node-key proof, served in
 * chunks, each with a digest this server signs; the path sealed to the owner
 * and bound to the ticket's ref. It never serves what the legacy getFile
 * would not have.
 */
final class FileTicketTest extends TestCase {
	private const FETCHER = 9;

	private string $rSeed;

	private string $rFetcherSk;

	private string $rOwnerSk;

	private string $rBoxSk;

	private string $rDir;

	private string $rFile;

	/** @var array<int, array{sid: int, gen: int, state: string, ed_pub: string, dataplane: bool}> */
	private array $rNodes = [];

	/** @var array<string, true> */
	private array $rSpent = [];

	private int $rNow;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$this->rSeed = random_bytes(32);
		$rPair = sodium_crypto_sign_keypair();
		$this->rFetcherSk = sodium_crypto_sign_secretkey($rPair);
		$this->rOwnerSk = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
		$this->rBoxSk = random_bytes(32);
		$this->rNodes = [self::FETCHER => ['sid' => self::FETCHER, 'gen' => 1, 'state' => 'active', 'ed_pub' => sodium_crypto_sign_publickey($rPair), 'dataplane' => true]];
		$this->rSpent = [];
		$this->rNow = time();
		$this->rDir = sys_get_temp_dir() . '/xcvm-xfile-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		$this->rFile = $this->rDir . '/movie.mkv';
		$rBytes = '';
		for ($i = 0; strlen($rBytes) < FileDigest::CHUNK + 5000; $i++) {
			$rBytes .= hash('sha256', (string) $i, true);
		}
		file_put_contents($this->rFile, $rBytes);
		DataPlaneTrust::useSources(
			fn(int $rSid): ?array => $this->rNodes[$rSid] ?? null,
			Ref::panelPub($this->rSeed),
			function (int $rSid, string $rNonce): bool {
				if (isset($this->rSpent[$rSid . bin2hex($rNonce)])) {
					return false;
				}
				return $this->rSpent[$rSid . bin2hex($rNonce)] = true;
			},
			fn(string $rDoc): string => NodeSig::sign($this->rOwnerSk, 'digest', $rDoc),
			false,
			$this->rBoxSk
		);
	}

	protected function tearDown(): void {
		DataPlaneTrust::useSources(null, null, null);
		@unlink($this->rFile);
		@unlink($this->rDir . '/notes.txt');
		@rmdir($this->rDir);
	}

	/** A file ticket as TicketService mints it for a node owner. */
	private function ticket(?string $rPath = null, array $rOver = [], ?string $rSealPath = null): string {
		$rPath ??= $this->rFile;
		$rRef = DataPlane::ref((int) SERVER_ID, $rPath);
		$rFile = DataPlane::SEAL_NODE . Enc::b64url(Seal::seal(sodium_crypto_scalarmult_base($this->rBoxSk), DataPlane::SEAL_PURPOSE, $rRef, $rSealPath ?? $rPath));
		$rDoc = Ticket::document('fil', DataPlane::fileTid(166666, self::FETCHER, $rRef), $this->rNow - 60, $this->rNow + 3600, $rOver + [
			'fetcher_sid' => self::FETCHER, 'fetcher_gen' => 1, 'owner_sid' => (int) SERVER_ID, 'ref' => $rRef, 'file' => $rFile,
		]);
		return Ticket::wire($rDoc, Ref::panelSign($this->rSeed, 'fil', $rDoc));
	}

	/** @return array{status: int, headers: array<string, string>, body: string} */
	private function get(int $rOffset, int $rLength = FileDigest::CHUNK, ?string $rTicket = null, ?string $rSk = null, ?array &$rServer = null): array {
		$rTicket ??= $this->ticket();
		$rTarget = '/xfile?o=' . $rOffset . '&n=' . $rLength;
		$rServer ??= [
			'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $rTarget,
			FileTicketServer::TICKET => $rTicket,
			FileTicketServer::AUTH => RelayAuth::header($rSk ?? $this->rFetcherSk, $rTicket, 'GET', $rTarget, $this->rNow * 1000),
		];
		return FileTicketServer::serve($rServer, ['o' => (string) $rOffset, 'n' => (string) $rLength], ['lb_scan_roots' => [$this->rDir]], $this->rNow * 1000);
	}

	public function testAFileIsServedChunkByChunkEachWithItsOwnDigest(): void {
		$rWhole = (string) file_get_contents($this->rFile);
		$rTicket = $this->ticket();
		$rTid = Ticket::verify(Ref::panelPub($this->rSeed), 'fil', $rTicket, $this->rNow)['tid'];
		$rRead = '';
		foreach ([0, FileDigest::CHUNK] as $rOffset) {
			$rServer = null;
			$rOut = $this->get($rOffset, FileDigest::CHUNK, $rTicket, null, $rServer);
			$this->assertSame(200, $rOut['status']);
			$rDigest = FileDigest::verify($rOut['headers']['X-XCVM-File-Digest'], $rTid, null, sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(substr($this->rOwnerSk, 0, 32))));
			$this->assertNotNull($rDigest, 'the owner\'s node key signed it');
			$this->assertTrue(FileDigest::chunkMatches($rDigest, $rOffset, $rOut['body']));
			$this->assertSame([strlen($rWhole), (int) SERVER_ID], [$rDigest['total'], $rDigest['owner_sid']]);
			$this->assertSame(explode('.', $rServer[FileTicketServer::AUTH])[1], $rDigest['nonce'], 'the digest names the request it answers');
			$this->assertSame((string) strlen($rOut['body']), $rOut['headers']['Content-Length']);
			$rRead .= $rOut['body'];
		}
		$this->assertSame($rWhole, $rRead);
		$this->assertSame(416, $this->get(strlen($rWhole) + 1)['status'], 'past the end');
	}

	public function testAChunkIsSealedWhenItsFetcherSealedAKeyToTheOwner(): void {
		$rTicket = $this->ticket();
		$rTid = (string) Ticket::verify(Ref::panelPub($this->rSeed), 'fil', $rTicket, $this->rNow)['tid'];
		$rKey = random_bytes(32);
		$rParam = Enc::b64url(Seal::seal(sodium_crypto_scalarmult_base($this->rBoxSk), RelaySeal::PURPOSE, RelaySeal::fileContext((int) SERVER_ID, $rTid), $rKey));
		$rServe = function (string $rParam) use ($rTicket): array {
			$rTarget = '/xfile?o=0&n=' . FileDigest::CHUNK . '&rk=' . $rParam;
			$rServer = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $rTarget, FileTicketServer::TICKET => $rTicket, FileTicketServer::AUTH => RelayAuth::header($this->rFetcherSk, $rTicket, 'GET', $rTarget, $this->rNow * 1000)];
			return FileTicketServer::serve($rServer, ['o' => '0', 'n' => (string) FileDigest::CHUNK, 'rk' => $rParam], ['lb_scan_roots' => [$this->rDir]], $this->rNow * 1000);
		};

		$rOut = $rServe($rParam);
		$this->assertSame(200, $rOut['status']);
		$this->assertSame(RelaySeal::VERSION, $rOut['headers'][RelaySeal::HEADER]);
		$rPlain = RelaySeal::open($rKey, $rOut['body']);
		$this->assertSame(substr((string) file_get_contents($this->rFile), 0, FileDigest::CHUNK), $rPlain, 'the chunk, framed');
		$rDigest = FileDigest::verify($rOut['headers']['X-XCVM-File-Digest'], $rTid, null, sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(substr($this->rOwnerSk, 0, 32))));
		$this->assertTrue(FileDigest::chunkMatches($rDigest, 0, (string) $rPlain), 'the digest is over the plaintext');
		$this->assertSame((string) strlen($rOut['body']), $rOut['headers']['Content-Length']);

		// A key sealed for a relay (or another ticket) is no file key.
		$rRelay = Enc::b64url(Seal::seal(sodium_crypto_scalarmult_base($this->rBoxSk), RelaySeal::PURPOSE, RelaySeal::context((int) SERVER_ID, 1), $rKey));
		$this->assertSame(404, $rServe($rRelay)['status']);
	}

	public function testAReplayIsRefused(): void {
		$rServer = null;
		$this->assertSame(200, $this->get(0, 1024, null, null, $rServer)['status']);
		$this->assertSame(404, $this->get(0, 1024, null, null, $rServer)['status'], 'the same headers again');
	}

	public function testOnlyTheFetcherTheTicketNamesAndOnlyForThisOwner(): void {
		$this->assertSame(404, $this->get(0, 1024, null, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))['status'], 'another key');
		$this->assertSame(404, $this->get(0, 1024, $this->ticket(null, ['owner_sid' => (int) SERVER_ID + 100]))['status'], 'another owner');
		$this->rNodes[self::FETCHER]['state'] = 'revoked';
		$this->assertSame(404, $this->get(0, 1024)['status'], 'a revoked fetcher');
		$this->rNodes[self::FETCHER]['state'] = 'active';
		$this->rNodes[self::FETCHER]['gen'] = 2;
		$this->assertSame(404, $this->get(0, 1024)['status'], 'a re-enrolled fetcher');
	}

	public function testThePathIsSealedToTheOwnerAndBoundToTheRef(): void {
		// A path sealed under another ref than the ticket's does not open.
		$this->assertSame(404, $this->get(0, 1024, $this->ticket(null, [], $this->rDir . '/other.mkv'))['status']);
		// Sealed to another box key.
		$rRef = DataPlane::ref((int) SERVER_ID, $this->rFile);
		$this->assertNull(FileTicketServer::path(DataPlane::SEAL_NODE . Enc::b64url(Seal::seal(sodium_crypto_scalarmult_base(random_bytes(32)), 'file', $rRef, $this->rFile)), $rRef));
		// MAIN's own seal opens on MAIN only.
		$this->assertNull(FileTicketServer::path(DataPlane::SEAL_MAIN . Enc::b64url('xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'), $rRef));
		$this->assertSame($this->rFile, FileTicketServer::path(DataPlane::SEAL_NODE . Enc::b64url(Seal::seal(sodium_crypto_scalarmult_base($this->rBoxSk), 'file', $rRef, $this->rFile)), $rRef));
	}

	public function testNothingGetFileWouldNotHaveServed(): void {
		file_put_contents($this->rDir . '/notes.txt', 'x');
		$this->assertSame(404, $this->get(0, 1024, $this->ticket($this->rDir . '/notes.txt'))['status'], 'an extension getFile refused');
		$this->assertSame(404, $this->get(0, 1024, $this->ticket('/etc/hostname.log'))['status'], 'outside the scan roots');
		$this->assertSame(400, $this->get(0, FileDigest::CHUNK + 1)['status'], 'a chunk too large');
		$this->assertSame(400, $this->get(0, 0)['status']);
	}

	public function testNothingIsServedUnsigned(): void {
		DataPlaneTrust::useSources(fn(int $rSid): ?array => $this->rNodes[$rSid] ?? null, Ref::panelPub($this->rSeed), static fn(): bool => true, static fn(): ?string => null, false, $this->rBoxSk);
		$rOut = $this->get(0, 1024);
		$this->assertSame(503, $rOut['status']);
		$this->assertSame('', $rOut['body']);
	}

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testTheRouteShipsToEveryServer(): void {
		$rRoot = dirname(__DIR__, 2);
		foreach (['src/bin/nginx/conf/nginx.conf', 'lb_configs/nginx.conf'] as $rConf) {
			$rText = (string) file_get_contents($rRoot . '/' . $rConf);
			$this->assertMatchesRegularExpression('/location = \/xfile \{[^}]*XC_ADMIN xfile;/s', $rText, $rConf);
			// A VOD pull is a request per 4 MiB chunk: the viewers' zone `one`
			// (20 r/s per client, 503) would throttle it; /xfile has its own,
			// per TCP peer, and answers 429, which the agent retries.
			$this->assertMatchesRegularExpression('/^\s*limit_req_zone \$realip_remote_addr zone=xfile:\d+m rate=\d+r\/s;\r?$/m', $rText, $rConf);
			$this->assertMatchesRegularExpression('/location = \/xfile \{\s*limit_req zone=xfile burst=\d+ nodelay;\s*limit_req_status 429;/', $rText, $rConf);
			$this->assertDoesNotMatchRegularExpression('/location = \/xfile \{[^}]*zone=one/s', $rText, $rConf);
		}
		$this->assertStringContainsString("'xfile'     => MAIN_HOME . 'Public/admin/xfile.php'", (string) file_get_contents($rRoot . '/src/Public/admin/index.php'));
	}
}
