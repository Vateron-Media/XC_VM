<?php

use PHPUnit\Framework\TestCase;
use XcVm\Streaming\Fanout\IngestFeeder;

/**
 * @covers \XcVm\Streaming\Fanout\IngestFeeder
 *
 * The PHP producers' (LLOD, loopback, delay) feed into the xc_fanout daemon —
 * since Phase E the channel's only delivery path. A socket pair stands in for the
 * daemon's ingest socket; registration and dialing are injected.
 */
final class IngestFeederTest extends TestCase {

	private const P = 188;

	/** @var resource[] */
	private array $open = [];

	protected function tearDown(): void {
		foreach ($this->open as $rSocket) {
			if (is_resource($rSocket)) {
				@fclose($rSocket);
			}
		}
		$this->open = [];
	}

	/** A connected socket pair: [the feeder's end, the "daemon's" end]. */
	private function pair(): array {
		$rPair = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$this->assertIsArray($rPair, 'socket pair');
		$this->open = array_merge($this->open, $rPair);
		return $rPair;
	}

	/** $n TS packets whose bodies number them, so order and loss are visible. */
	private function packets(int $n, int $from = 0): string {
		$rOut = '';
		for ($i = $from; $i < $from + $n; $i++) {
			$rOut .= "\x47" . str_pad(pack('N', $i), self::P - 1, "\xff");
		}
		return $rOut;
	}

	/**
	 * A feeder whose "daemon" is the given socket on the first dial (a fresh pair
	 * on any redial, as a restarted daemon would give); counts registrations.
	 */
	private function feeder($rSocket, int &$rRegistrations, ?string $rKey = null, ?string $rIV = null, array &$rSeen = []): IngestFeeder {
		$rFirst = true;
		return new IngestFeeder(
			7,
			$rKey,
			$rIV,
			null,
			function (int $rID, ?string $rK, ?string $rV) use (&$rRegistrations, &$rSeen) {
				$rRegistrations++;
				$rSeen = [$rID, $rK, $rV];
				return '/run/ingest/7.sock';
			},
			function (string $rPath) use ($rSocket, &$rFirst) {
				if ($rFirst) {
					$rFirst = false;
					return $rSocket;
				}
				return $this->pair()[0];
			}
		);
	}

	private function drain($rPeer, int $rBytes, IngestFeeder $rFeeder): string {
		stream_set_blocking($rPeer, false);
		$rGot = '';
		$rDeadline = microtime(true) + 10;
		while (strlen($rGot) < $rBytes && microtime(true) < $rDeadline) {
			$rFeeder->flush();
			$rChunk = fread($rPeer, 65536);
			if ($rChunk !== false && $rChunk !== '') {
				$rGot .= $rChunk;
			} else {
				usleep(1000);
			}
		}
		return $rGot;
	}

	public function testRegistersWithTheStreamKeyAndDeliversEveryPacket(): void {
		[$rMine, $rDaemon] = $this->pair();
		$rRegs = 0;
		$rSeen = [];
		$rFeeder = $this->feeder($rMine, $rRegs, 'aa', 'bb', $rSeen);

		$this->assertTrue($rFeeder->connect());
		$this->assertSame([7, 'aa', 'bb'], $rSeen, 'the HLS key travels with the registration');

		$rData = $this->packets(50);
		$rFeeder->write($rData);
		$this->assertSame($rData, $this->drain($rDaemon, strlen($rData), $rFeeder));
		$this->assertSame(1, $rRegs);
	}

	/** A full socket must not lose or tear data: the rest waits and goes next. */
	public function testBackpressureKeepsEveryByteInOrder(): void {
		[$rMine, $rDaemon] = $this->pair();
		$rRegs = 0;
		$rFeeder = $this->feeder($rMine, $rRegs);
		$rFeeder->connect();

		$rAll = '';
		for ($i = 0; $i < 40; $i++) { // ~3 MB — far more than a socket buffer holds
			$rChunk = $this->packets(400, $i * 400);
			$rAll .= $rChunk;
			$rFeeder->write($rChunk);
		}
		$this->assertGreaterThan(0, $rFeeder->backlog(), 'the socket filled, so some of it waited');

		$rGot = $this->drain($rDaemon, strlen($rAll), $rFeeder);
		$this->assertSame(strlen($rAll), strlen($rGot));
		$this->assertTrue($rGot === $rAll, 'identical bytes, in order');
		$this->assertSame(0, $rFeeder->backlog());
	}

	/** After a failure the half-sent packet's tail is discarded, so the next connection starts aligned. */
	public function testReconnectStartsOnAPacketBoundary(): void {
		[$rMine, $rDaemon] = $this->pair();
		$rRegs = 0;
		$rFeeder = $this->feeder($rMine, $rRegs);
		$rFeeder->connect();

		$rRef = new ReflectionClass($rFeeder);
		$rRef->getProperty('pending')->setValue($rFeeder, substr($this->packets(3), 100)); // head packet 100 bytes in
		$rRef->getProperty('headSent')->setValue($rFeeder, 100);
		$rDrop = $rRef->getMethod('drop');
		$rDrop->invoke($rFeeder, 'test');

		$this->assertFalse($rFeeder->isConnected());
		$this->assertSame(2 * self::P, $rFeeder->backlog(), 'only whole packets remain');
		$this->assertSame("\x47", substr((string) $rRef->getProperty('pending')->getValue($rFeeder), 0, 1));

		// Within the backoff nothing is redialled; after it, the ingest is registered again.
		$rFeeder->flush();
		$this->assertSame(1, $rRegs);
		$rRef->getProperty('retryAt')->setValue($rFeeder, 0.0);
		$rFeeder->flush();
		$this->assertSame(2, $rRegs, 're-registered (a restarted daemon hands out a fresh socket)');
		$this->assertTrue($rFeeder->isConnected());
		$this->assertSame(0, $rFeeder->backlog(), 'the aligned remainder went to the new connection');
	}

	/** With the daemon away the backlog is capped by shedding the OLDEST whole packets. */
	public function testOverflowShedsTheOldestWholePackets(): void {
		$rFeeder = new IngestFeeder(7, null, null, null, fn() => null, fn() => false);
		$rTotal = 0;
		$rLast = '';
		for ($i = 0; $i < 50; $i++) { // 50 × ~188 KB ≈ 9.4 MB > the 8 MB ceiling
			$rLast = $this->packets(1000, $i * 1000);
			$rTotal += strlen($rLast);
			$rFeeder->write($rLast);
		}
		$this->assertLessThanOrEqual(8388608, $rFeeder->backlog());
		$this->assertSame(0, $rFeeder->backlog() % self::P, 'whole packets only');
		$this->assertSame($rTotal - $rFeeder->backlog(), $rFeeder->droppedPackets() * self::P);

		$rRef = new ReflectionClass($rFeeder);
		$rPending = (string) $rRef->getProperty('pending')->getValue($rFeeder);
		$this->assertSame($rLast, substr($rPending, -strlen($rLast)), 'the newest data is what is kept');
	}

	public function testStreamKeyReadsTheLaunchersKeyFiles(): void {
		if (!defined('STREAMS_PATH')) {
			define('STREAMS_PATH', sys_get_temp_dir() . '/xcvm-feeder-test/');
		}
		if (!is_dir(STREAMS_PATH)) {
			mkdir(STREAMS_PATH, 0777, true);
		}
		$rID = 990000 + random_int(0, 9999);
		file_put_contents(STREAMS_PATH . $rID . '_.key', str_repeat("\x01", 16));
		file_put_contents(STREAMS_PATH . $rID . '_.iv', str_repeat("\x02", 16));
		try {
			$this->assertSame([str_repeat('01', 16), str_repeat('02', 16)], IngestFeeder::streamKey($rID));
			$this->assertSame([null, null], IngestFeeder::streamKey($rID + 1), 'no files, no key');
		} finally {
			@unlink(STREAMS_PATH . $rID . '_.key');
			@unlink(STREAMS_PATH . $rID . '_.iv');
		}
	}
}
