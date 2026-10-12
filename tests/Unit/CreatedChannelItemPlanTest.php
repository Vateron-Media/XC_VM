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

    public function testUnprobeableItemIsNeverTheTarget(): void {
        $bad = ['v' => '?1', 'a' => '?1'];
        $this->assertSame(['transcode','copy','copy'], $this->plan([$bad, self::H264, self::H264]));
    }
    public function testAllUnprobeableIsAllCopy(): void {
        $this->assertSame(['copy','copy'], $this->plan([['v' => '?0', 'a' => '?0'], ['v' => '?1', 'a' => '?1']]));
    }
    public function testH264TargetArgs(): void {
        $a = StreamProcess::channelTranscodeArgs(self::H264);
        $this->assertStringContainsString('-c:v libx264 -vf scale=1280:720 -pix_fmt yuv420p -preset veryfast -crf 23 -c:a aac -ac 2 -ar 48000 -b:a 128k', $a);
        $this->assertStringNotContainsString('copy', $a);
    }
    public function testMpeg2TargetArgs(): void {
        $a = StreamProcess::channelTranscodeArgs(self::MPEG2);
        $this->assertStringContainsString('-c:v mpeg2video -vf scale=720:576 -pix_fmt yuv420p -b:v 4000k -c:a mp2 -ac 2 -ar 48000 -b:a 128k', $a);
        $this->assertStringNotContainsString('copy', $a);
    }

    public function testAbsurdTargetIsClamped(): void {
        $a = StreamProcess::channelTranscodeArgs(['v' => 'h264,999999,999999,yuv420p', 'a' => 'aac,99,999999']);
        $this->assertStringContainsString('scale=7680:4320', $a);
        $this->assertStringContainsString('-ac 8 -ar 192000', $a);
    }
}
