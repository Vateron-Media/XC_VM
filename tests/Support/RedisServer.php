<?php

namespace XcVm\Tests\Support;

/**
 * A throwaway redis-server on 127.0.0.1 for tests, in the foreground and owned
 * by the test process (a daemon would outlive a failed run and hold the test's
 * output pipe open), never persisted.
 */
final class RedisServer {
	/**
	 * Start one in $rDir, on a port the kernel picks free (a random one could be
	 * taken), giving it up to 10 s to listen: a busy CI runner was slower than
	 * 2.5 s (Connection refused). One that never listens is a redis not available.
	 *
	 * @param list<string> $rArgs extra redis-server arguments
	 * @param string|null $rSocket a unix socket ($rArgs' --unixsocket) to wait for as well
	 * @return array{0: resource, 1: int}|null the process and its port, or null (the caller skips)
	 */
	public static function start(string $rDir, array $rArgs = [], ?string $rSocket = null): ?array {
		$rProbe = stream_socket_server('tcp://127.0.0.1:0');
		if ($rProbe === false) {
			return null;
		}
		$rPort = (int) substr((string) strrchr((string) stream_socket_get_name($rProbe, false), ':'), 1);
		fclose($rProbe);
		$rNull = ['file', '/dev/null', 'w'];
		$rProc = proc_open(array_merge(['redis-server', '--port', (string) $rPort, '--bind', '127.0.0.1', '--save', '', '--appendonly', 'no', '--dir', $rDir], $rArgs), [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes);
		if ($rProc === false) {
			return null;
		}
		$rUp = static fn (): bool => @fsockopen('127.0.0.1', $rPort) !== false && ($rSocket === null || file_exists($rSocket));
		for ($i = 0; $i < 200 && !$rUp(); $i++) {
			usleep(50000);
		}
		if (!$rUp()) {
			self::stop($rProc);
			return null;
		}
		return [$rProc, $rPort];
	}

	/** @param resource $rProc */
	public static function stop($rProc): void {
		proc_terminate($rProc);
		proc_close($rProc);
	}
}
