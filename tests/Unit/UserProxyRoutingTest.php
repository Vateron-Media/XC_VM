<?php
use PHPUnit\Framework\TestCase;
use XcVm\Core\Module\Contract\StreamRoutingProviderInterface;
use XcVm\Streaming\Routing\PlaybackRouter;

final class UserProxyRoutingTest extends TestCase {
    protected function tearDown(): void { PlaybackRouter::reset(); }

    public function testUnavailableAssignedProxyFallsBackToNative(): void {
        $previous = $GLOBALS['rServers'] ?? null;
        $GLOBALS['rServers'] = [9 => ['server_type' => 1, 'parent_id' => [3], 'server_online' => 0]];
        try {
            PlaybackRouter::register(new class implements StreamRoutingProviderInterface {
                public function proxyFor(int $lineId): ?int { return $lineId === 42 ? 9 : null; }
            });
            $this->assertNull(PlaybackRouter::select(3, 42, true, 'AT', ''));
            $this->assertNull(PlaybackRouter::select(3, 43, true, 'AT', ''));
        } finally { $GLOBALS['rServers'] = $previous; }
    }

    public function testBrokenProviderFallsBackToNative(): void {
        PlaybackRouter::register(new class implements StreamRoutingProviderInterface {
            public function proxyFor(int $lineId): ?int { throw new RuntimeException('Unreadable assignments'); }
        });
        $this->assertNull(PlaybackRouter::select(3, 42, false, 'AT', ''));
    }
}
