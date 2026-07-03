<?php

use XcVm\Core\Module\ModuleUpdateChecker;
use PHPUnit\Framework\TestCase;

/**
 * Network-free coverage of the source routing + guards. The actual git/url
 * fetches are integration concerns (they hit the network) and are not exercised
 * here — only the branches that resolve without any HTTP call.
 */
final class ModuleUpdateCheckerTest extends TestCase {

    private ModuleUpdateChecker $checker;

    protected function setUp(): void {
        $this->checker = new ModuleUpdateChecker();
    }

    public function testBundledReturnsOnDiskVersion(): void {
        $result = $this->checker->latestAvailable([
            'update'            => ['source' => 'bundled'],
            'version'           => '1.2.0',
            'installed_version' => '1.0.0',
        ]);
        $this->assertSame('1.2.0', $result);
    }

    public function testAbsentUpdateBlockTreatedAsBundled(): void {
        $result = $this->checker->latestAvailable([
            'version'           => '2.0.0',
            'installed_version' => '1.0.0',
        ]);
        $this->assertSame('2.0.0', $result);
    }

    public function testUrlSourceRejectsNonHttps(): void {
        $result = $this->checker->latestAvailable([
            'update'            => ['source' => 'url', 'url' => 'http://example.com/version.json'],
            'installed_version' => '1.0.0',
        ]);
        $this->assertNull($result); // https-only guard, no network
    }

    public function testGitSourceWithInvalidRepoReturnsNull(): void {
        $result = $this->checker->latestAvailable([
            'update'            => ['source' => 'git', 'repository' => 'not-a-github-url'],
            'installed_version' => '1.0.0',
        ]);
        $this->assertNull($result); // regex fails before any network call
    }

    public function testPlatformWithoutExtensionReturnsNull(): void {
        // The xcvm_core extension is not loaded in the test runtime, so the
        // platform branch short-circuits to null.
        $result = $this->checker->latestAvailable([
            'update'            => ['source' => 'platform', 'slug' => 'watch'],
            'installed_version' => '1.0.0',
        ]);
        $this->assertNull($result);
    }
}
