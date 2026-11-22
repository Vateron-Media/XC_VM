<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Server\ServerService;

/**
 * A server's save refuses a new port another program already holds on this
 * machine: nginx's reload would fail to bind it and keep the old one, while
 * the row and the nodes' announcement named the new one.
 */
final class ServerPortsTakenTest extends TestCase {
	private const ROW = ['http_broadcast_port' => 8080, 'http_ports_add' => '8090,8091', 'https_broadcast_port' => 8443, 'https_ports_add' => null, 'rtmp_port' => 8880];

	public function testOnlyANewPortSomethingHoldsIsTaken(): void {
		$rHeld = [8080, 8081, 8443, 8880, 8090];
		$rListening = static fn(int $rPort): bool => in_array($rPort, $rHeld, true);
		$this->assertSame([], ServerService::portsTaken(self::ROW, [8080, 8443, 8880, 8090], [], $rListening), 'the row\'s own ports are this nginx\'s');
		$this->assertSame([8081], ServerService::portsTaken(self::ROW, [8081, 8443, 8880], [], $rListening), 'held by another program');
		$this->assertSame([], ServerService::portsTaken(self::ROW, [8082, '8443', 'x', 0], [], $rListening), 'free, or not a port');
	}

	public function testThePortServingTheSaveRequestBelongsToThePanel(): void {
		$rListening = static fn(int $rPort): bool => in_array($rPort, [8443, 9443], true);
		$rStored = ['https_broadcast_port' => 443] + self::ROW;
		$this->assertSame([9443], ServerService::portsTaken($rStored, [8443, 9443], [8443], $rListening), 'the current web port is this nginx\'s');
		$this->assertSame([8443], ServerService::portsTaken($rStored, [8443], [], $rListening), 'RTMP cannot take the current web port');
	}

	public function testItAsksTheMachineByDefault(): void {
		$rServer = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertNotFalse($rServer);
		$rPort = (int) substr(strrchr((string) stream_socket_get_name($rServer, false), ':'), 1);
		try {
			$this->assertSame([$rPort], ServerService::portsTaken(self::ROW, [$rPort]));
		} finally {
			fclose($rServer);
		}
		$this->assertSame([], ServerService::portsTaken(self::ROW, [$rPort]), 'free once closed');
	}
}
