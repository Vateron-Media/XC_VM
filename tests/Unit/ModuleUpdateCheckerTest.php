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

    /**
     * A checker whose release list and manifest fetches are canned: $tags newest
     * first, $manifests tag => module.json body ('' = unreadable).
     */
    private function gitChecker(array $tags, array $manifests): ModuleUpdateChecker {
        return new class($tags, $manifests) extends ModuleUpdateChecker {
            public array $fetched = array();

            public function __construct(private array $tags, private array $manifests) {
            }

            protected function releaseTags(string $owner, string $repo, string $channel): array {
                return $this->tags;
            }

            protected function httpGet(string $url): string {
                $this->fetched[] = $url;
                $tag = basename(dirname($url));
                return $this->manifests[$tag] ?? '';
            }
        };
    }

    private function gitModule(string $installed): array {
        return array('update' => array('source' => 'git', 'repository' => 'https://github.com/Vateron-Media/Module_Test'), 'installed_version' => $installed);
    }

    public function testGitOffersTheNewestReleaseThisCoreCanRun(): void {
        $checker = $this->gitChecker(array('1.3.0', '1.2.0', '1.1.0', '1.0.0'), array(
            '1.3.0' => json_encode(array('requires_core' => '>=99.0')),
            '1.2.0' => json_encode(array('requires_core' => '>=2.0')),
        ));

        $this->assertSame('1.2.0', $checker->latestAvailable($this->gitModule('1.0.0')));
        $this->assertNull($checker->lastError());
        $this->assertSame('https://raw.githubusercontent.com/Vateron-Media/Module_Test/1.3.0/module.json', $checker->fetched[0]);
    }

    public function testGitOffersNothingWhenNoNewerReleaseFitsThisCore(): void {
        $checker = $this->gitChecker(array('1.2.0', '1.1.0', '1.0.0'), array(
            '1.2.0' => json_encode(array('requires_core' => '>=99.0')),
            '1.1.0' => json_encode(array('requires_core' => '>=98.0')),
        ));

        $this->assertNull($checker->latestAvailable($this->gitModule('1.0.0')));
        $this->assertNull($checker->lastError(), 'not an error: nothing to offer');
        $this->assertCount(2, $checker->fetched, 'the installed release is not checked');
    }

    public function testGitOffersAReleaseWhoseManifestCannotBeRead(): void {
        $checker = $this->gitChecker(array('1.1.0', '1.0.0'), array());

        $this->assertSame('1.1.0', $checker->latestAvailable($this->gitModule('1.0.0')));
    }

    public function testUrlOffersAVersionOnlyWhenThisCoreCanRunIt(): void {
        $module = array('update' => array('source' => 'url', 'url' => 'https://example.test/version.json'));

        $fits = $this->gitChecker(array(), array('example.test' => json_encode(array('version' => '1.2.0', 'requires_core' => '>=2.0'))));
        $this->assertSame('1.2.0', $fits->latestAvailable($module));

        $ahead = $this->gitChecker(array(), array('example.test' => json_encode(array('version' => '1.2.0', 'requires_core' => '>=99.0'))));
        $this->assertNull($ahead->latestAvailable($module));
        $this->assertNull($ahead->lastError(), 'not an error: nothing to offer');
    }
}
