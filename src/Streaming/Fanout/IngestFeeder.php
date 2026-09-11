<?php

namespace XcVm\Streaming\Fanout;

/**
 * IngestFeeder — how a PHP producer (the LLOD segmenter, the loopback relay, the
 * delay worker) pushes a stream's MPEG-TS into the xc_fanout daemon.
 *
 * Since ADR 0003 Phase E the daemon is the only thing that serves clients, so for
 * these producers the feed IS the channel's delivery, not a best-effort mirror of
 * an on-disk path that no longer exists. Three things follow:
 *
 *  - a short write must not tear packets: what the socket does not take is kept
 *    and sent first on the next call (plain non-blocking fwrite() silently dropped
 *    the remainder, corrupting whatever it cut through);
 *  - the producer's own loop must never stall on the daemon: writes stay
 *    non-blocking, and a backlog past MAX_BACKLOG sheds its OLDEST whole packets
 *    (the daemon resyncs on the next PAT/PMT);
 *  - a daemon restart must not leave the channel dark until the producer happens
 *    to restart: a dead connection is re-registered (a restarted daemon hands out
 *    a fresh socket) and redialled, with a backoff.
 *
 * Input must be whole 188-byte packets. The only partial packet ever in flight is
 * the head of the backlog after a short write; on reconnect its unsent tail is
 * discarded so the new connection starts on a packet boundary.
 */
final class IngestFeeder {
	private const PACKET = 188;
	/** Backlog ceiling (bytes) — roughly ten seconds of an HD stream. */
	private const MAX_BACKLOG = 8388608;
	/** Seconds between reconnect attempts. */
	private const RETRY_SEC = 2.0;

	private int $streamID;
	private ?string $keyHex;
	private ?string $ivHex;
	/** @var callable|null fn(string $message): void */
	private $logger;
	/** @var callable fn(int $id, ?string $key, ?string $iv): ?string */
	private $register;
	/** @var callable fn(string $socket): resource|false */
	private $dial;

	/** @var resource|null */
	private $conn = null;
	private string $pending = '';
	/** Bytes of the backlog's head packet already written to the current connection. */
	private int $headSent = 0;
	private float $retryAt = 0.0;
	private int $droppedPackets = 0;

	/**
	 * @param int           $rStreamID Stream id (the daemon key).
	 * @param string|null   $rKeyHex   Hex AES-128 key when the stream's HLS is encrypted.
	 * @param string|null   $rIVHex    Hex AES-128-CBC IV to go with it.
	 * @param callable|null $rLogger   Receives one line per state change.
	 * @param callable|null $rRegister Registers the ingest, returns the socket path
	 *                                 (defaults to FanoutClient::registerIngest).
	 * @param callable|null $rDial     Opens the socket (defaults to a unix-socket dial).
	 */
	public function __construct(int $rStreamID, ?string $rKeyHex = null, ?string $rIVHex = null, ?callable $rLogger = null, ?callable $rRegister = null, ?callable $rDial = null) {
		$this->streamID = $rStreamID;
		$this->keyHex = $rKeyHex;
		$this->ivHex = $rIVHex;
		$this->logger = $rLogger;
		$this->register = $rRegister ?? static function (int $rID, ?string $rKey, ?string $rIV): ?string {
			return FanoutClient::registerIngest($rID, $rKey, $rIV);
		};
		$this->dial = $rDial ?? static function (string $rSocket) {
			return @stream_socket_client('unix://' . $rSocket, $rErrno, $rErrstr, 2);
		};
	}

	/**
	 * A feeder for a stream, carrying its HLS key/iv (the `_.key`/`_.iv` files the
	 * launcher wrote) when encrypted HLS is on — without them the daemon serves
	 * plain segments under a playlist that declares AES-128, and nothing plays.
	 *
	 * @param int           $rStreamID Stream id.
	 * @param bool          $rEncrypt  The encrypt_hls setting.
	 * @param callable|null $rLogger   See the constructor.
	 * @return self
	 */
	public static function forStream(int $rStreamID, bool $rEncrypt, ?callable $rLogger = null): self {
		[$rKey, $rIV] = $rEncrypt ? self::streamKey($rStreamID) : array(null, null);
		return new self($rStreamID, $rKey, $rIV, $rLogger);
	}

	/**
	 * The stream's HLS key and IV as hex, or [null, null] when either file is
	 * missing or malformed.
	 *
	 * @param int $rStreamID Stream id.
	 * @return array{0:?string,1:?string}
	 */
	public static function streamKey(int $rStreamID): array {
		if (!defined('STREAMS_PATH')) {
			return array(null, null);
		}
		$rKey = @file_get_contents(STREAMS_PATH . $rStreamID . '_.key');
		$rIV = @file_get_contents(STREAMS_PATH . $rStreamID . '_.iv');
		if (!is_string($rKey) || strlen($rKey) !== 16 || !is_string($rIV) || strlen($rIV) !== 16) {
			return array(null, null);
		}
		return array(bin2hex($rKey), bin2hex($rIV));
	}

	/**
	 * Register the ingest and dial it now (rather than on the first write).
	 *
	 * @return bool True when connected.
	 */
	public function connect(): bool {
		if ($this->conn) {
			return true;
		}
		$rSocket = ($this->register)($this->streamID, $this->keyHex, $this->ivHex);
		if ($rSocket === null || $rSocket === '') {
			$this->retryAt = microtime(true) + self::RETRY_SEC;
			return false;
		}
		$rConn = ($this->dial)($rSocket);
		if (!is_resource($rConn)) {
			$this->log('[fanout] could not connect to the daemon ingest socket ' . $rSocket);
			$this->retryAt = microtime(true) + self::RETRY_SEC;
			return false;
		}
		stream_set_blocking($rConn, false);
		$this->conn = $rConn;
		$this->headSent = 0;
		$this->log('[fanout] feeding the daemon ingest');
		return true;
	}

	/** @return bool Whether a connection to the daemon is open. */
	public function isConnected(): bool {
		return (bool) $this->conn;
	}

	/** @return int Bytes waiting to be written. */
	public function backlog(): int {
		return strlen($this->pending);
	}

	/** @return int Whole packets shed because the backlog overflowed. */
	public function droppedPackets(): int {
		return $this->droppedPackets;
	}

	/**
	 * Queue whole TS packets for the daemon and send what the socket takes now.
	 *
	 * @param string $rData Whole 188-byte packets.
	 * @return void
	 */
	public function write(string $rData): void {
		if ($rData !== '') {
			$this->pending .= $rData;
			$this->shedOverflow();
		}
		$this->flush();
	}

	/**
	 * Send as much of the backlog as the socket takes without blocking; reconnect
	 * first when the connection is down and the backoff has passed. Call it from
	 * the producer's loop even when there is nothing new to write.
	 *
	 * @return void
	 */
	public function flush(): void {
		if (!$this->conn) {
			if (microtime(true) < $this->retryAt || !$this->connect()) {
				return;
			}
		}
		while ($this->pending !== '') {
			$rWritten = @fwrite($this->conn, $this->pending);
			if ($rWritten === false) {
				$this->drop('[fanout] the daemon ingest connection failed; reconnecting');
				return;
			}
			if ($rWritten === 0) {
				return; // socket buffer full — the rest goes on the next call
			}
			$this->pending = (string) substr($this->pending, $rWritten);
			$this->headSent = ($this->headSent + $rWritten) % self::PACKET;
		}
	}

	/**
	 * Close the connection. The daemon keeps the stream registered; viewers wait
	 * for the next producer rather than being dropped.
	 *
	 * @return void
	 */
	public function close(): void {
		if ($this->conn) {
			@fclose($this->conn);
		}
		$this->conn = null;
	}

	/**
	 * Tear the connection down after a failure: discard the rest of a packet the
	 * old connection only half received, so the next one starts aligned.
	 */
	private function drop(string $rWhy): void {
		$this->close();
		if ($this->headSent > 0) {
			$this->pending = (string) substr($this->pending, self::PACKET - $this->headSent);
			$this->headSent = 0;
		}
		$this->retryAt = microtime(true) + self::RETRY_SEC;
		$this->log($rWhy);
	}

	/**
	 * Keep the backlog under MAX_BACKLOG by dropping its oldest whole packets —
	 * never the partially written head packet, which the connection still needs
	 * to complete.
	 */
	private function shedOverflow(): void {
		$rExcess = strlen($this->pending) - self::MAX_BACKLOG;
		if ($rExcess <= 0) {
			return;
		}
		$rKeep = $this->headSent > 0 ? self::PACKET - $this->headSent : 0;
		$rShed = (int) (ceil($rExcess / self::PACKET) * self::PACKET);
		$this->pending = substr($this->pending, 0, $rKeep) . substr($this->pending, $rKeep + $rShed);
		$this->droppedPackets += intdiv($rShed, self::PACKET);
	}

	private function log(string $rMessage): void {
		if ($this->logger) {
			($this->logger)($rMessage);
		}
	}
}
