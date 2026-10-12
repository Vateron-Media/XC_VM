<?php
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

final class CreatedChannelItemPlanTest extends TestCase {
    private function plan(array $items, ?array $profile = null): array {
        $m = new \ReflectionMethod(StreamProcess::class, 'channelItemPlan');
        $m->setAccessible(true);
        return $m->invoke(null, $items, $profile);
    }
    private const H264 = ['v' => 'h264,1280,720,yuv420p', 'a' => 'aac,2,48000'];
    private const MPEG2 = ['v' => 'mpeg2video,720,576,yuv420p', 'a' => 'mp2,2,48000'];

    public function testUniformSetIsAllCopy(): void {
        $this->assertSame(['copy','copy'], $this->plan([self::H264, self::H264]));
    }
    public function testOddItemIsTranscodedToTheDominantTarget(): void {
        $this->assertSame(['copy','transcode','copy'], $this->plan([self::H264, self::MPEG2, self::H264]));
    }
    public function testExplicitProfileForcesNonMatchingToTranscode(): void {
        $this->assertSame(['transcode','transcode'], $this->plan([self::H264, self::H264], self::MPEG2));
    }
    public function testDifferentResolutionAloneTriggersTranscode(): void {
        $other = ['v' => 'h264,854,480,yuv420p', 'a' => 'aac,2,48000'];
        $this->assertSame(['copy','transcode'], $this->plan([self::H264, $other]));
    }
}
