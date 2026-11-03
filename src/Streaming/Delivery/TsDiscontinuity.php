<?php

declare(strict_types=1);

namespace XcVm\Streaming\Delivery;

/** Signal a producer change without rewriting the resumed segment's PES/PCR data. */
final class TsDiscontinuity {
	private string $pending = '';

	/** @var array<int, true> */
	private array $seen = [];

	/**
	 * Insert an adaptation-only discontinuity packet before each PID's first
	 * packet in the resumed segment. Preserve packet boundaries across reads.
	 * Return false for invalid TS, rather than emit a malformed splice.
	 */
	public function push(string $data): string|false {
		$this->pending .= $data;
		$output = '';
		$consumed = 0;
		$length = strlen($this->pending);
		while ($consumed + 188 <= $length) {
			$packet = substr($this->pending, $consumed, 188);
			if ($packet[0] !== "\x47") {
				return false;
			}
			$pid = ((ord($packet[1]) & 0x1f) << 8) | ord($packet[2]);
			$control = ord($packet[3]);
			if (($control & 0x30) === 0) {
				return false;
			}
			if ($pid !== 0x1fff && !isset($this->seen[$pid])) {
				$this->seen[$pid] = true;
				// Only payload packets increment CC. This adaptation-only packet
				// announces a discontinuity before the original packet's counter.
				$counter = (($control & 0x0f) - (($control & 0x10) ? 1 : 0) + 16) & 0x0f;
				$output .= pack('C6', 0x47, ($pid >> 8) & 0x1f, $pid & 0xff, 0x20 | $counter, 183, 0x80) . str_repeat("\xff", 182);
			}
			$output .= $packet;
			$consumed += 188;
		}
		$this->pending = substr($this->pending, $consumed);
		return $output;
	}

	public function complete(): bool {
		return $this->pending === '';
	}
}
