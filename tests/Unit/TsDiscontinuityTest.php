<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Streaming\Delivery\TsDiscontinuity;

final class TsDiscontinuityTest extends TestCase {
	private function packet(int $pid, int $counter, int $adaptation = 1): string {
		return pack('C4', 0x47, 0x40 | ($pid >> 8), $pid & 255, ($adaptation << 4) | $counter) . str_repeat('x', 184);
	}

	public function testEachPidGetsOneResetAndPayloadAndPcrArePreserved(): void {
		$reset = new TsDiscontinuity();
		$video = $this->packet(256, 0);
		$audio = $this->packet(257, 3);
		$output = $reset->push($video . $audio);
		$this->assertIsString($output);
		$this->assertSame(188 * 4, strlen($output));
		$this->assertSame($video, substr($output, 188, 188));
		$this->assertSame($audio, substr($output, 188 * 3, 188));
		$this->assertSame("\x47\x01\x00\x2f\xb7\x80", substr($output, 0, 6));
		$this->assertSame("\x47\x01\x01\x22\xb7\x80", substr($output, 188 * 2, 6));
		$this->assertSame(str_repeat("\xff", 182), substr($output, 6, 182));
		$this->assertSame($this->packet(256, 1), $reset->push($this->packet(256, 1)));
		$this->assertTrue($reset->complete());
	}

	public function testChunkBoundariesCannotSplitOrDuplicateTransportPackets(): void {
		$packet = $this->packet(256, 0);
		$whole = (new TsDiscontinuity())->push($packet);
		$split = new TsDiscontinuity();
		$this->assertSame('', $split->push(substr($packet, 0, 73)));
		$this->assertFalse($split->complete());
		$this->assertSame($whole, $split->push(substr($packet, 73)));
		$this->assertTrue($split->complete());
	}

	public function testAdaptationOnlyPacketsDoNotAdvanceTheCounter(): void {
		$packet = $this->packet(256, 4, 2);
		$output = (new TsDiscontinuity())->push($packet);
		$this->assertSame(0x24, ord($output[3]));
		$this->assertSame($packet, substr($output, 188));
	}

	public function testNullPacketsArePreservedWithoutAPidReset(): void {
		$packet = $this->packet(8191, 0);
		$this->assertSame($packet, (new TsDiscontinuity())->push($packet));
	}

	public function testInvalidTransportPacketIsRejected(): void {
		$this->assertFalse((new TsDiscontinuity())->push(str_repeat('x', 188)));
		$this->assertFalse((new TsDiscontinuity())->push($this->packet(256, 0, 0)));
	}
}
