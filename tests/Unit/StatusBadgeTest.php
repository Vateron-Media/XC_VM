<?php

use XcVm\Core\Reference\StatusBadge;
use PHPUnit\Framework\TestCase;

final class StatusBadgeTest extends TestCase {

    public function testStreamKnownStatus(): void {
        $this->assertStringContainsString('ONLINE', StatusBadge::stream(1));
        $this->assertStringContainsString('STOPPED', StatusBadge::stream(0));
    }

    public function testStreamUnknownFallsBackToStopped(): void {
        // Legacy behaviour: unknown status resolves to the STOPPED badge (index 0).
        $this->assertSame(StatusBadge::stream(0), StatusBadge::stream(999));
    }

    public function testSearchUnknownIsEmpty(): void {
        $this->assertStringContainsString('ENCODED', StatusBadge::search(9));
        $this->assertSame('', StatusBadge::search(999));
    }

    public function testVod(): void {
        $this->assertStringContainsString('Encoded', StatusBadge::vod(1));
        $this->assertSame('', StatusBadge::vod(999));
    }

    public function testWatch(): void {
        $this->assertStringContainsString('ADDED', StatusBadge::watch(1));
        $this->assertSame('', StatusBadge::watch(999));
    }

    public function testFailure(): void {
        $this->assertStringContainsString('STARTED', StatusBadge::failure('STREAM_START'));
        $this->assertSame('', StatusBadge::failure('NOPE'));
    }

    public function testStreamLog(): void {
        $this->assertSame('FFMPEG Error', StatusBadge::streamLog('FFMPEG_ERROR'));
        $this->assertSame('', StatusBadge::streamLog('NOPE'));
    }
}
