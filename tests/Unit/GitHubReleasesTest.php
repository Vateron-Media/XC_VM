<?php

use XcVm\Core\Updates\GitHubReleases;
use PHPUnit\Framework\TestCase;

final class GitHubReleasesTest extends TestCase {
	public function testIsValidVersionAcceptsSemanticVersion() {
		$this->assertTrue(GitHubReleases::isValidVersion('1.2.3'));
	}

	public function testIsValidVersionRejectsLeadingZeros() {
		$this->assertFalse(GitHubReleases::isValidVersion('01.2.3'));
	}

	public function testIsValidVersionThrowsForTooLongInput() {
		$this->expectException(InvalidArgumentException::class);
		GitHubReleases::isValidVersion(str_repeat('1', 21));
	}

	public function testGetLatestVersionReturnsNullWhenAlreadyUpToDate() {
		$mock = $this->getMockBuilder(GitHubReleases::class)
			->setConstructorArgs(array('Vateron-Media', 'XC_VM', 'stable'))
			->onlyMethods(array('getReleases'))
			->getMock();

		$mock->method('getReleases')->willReturn(array('1.2.3', '1.2.2', '1.2.1'));
		$this->assertNull($mock->getLatestVersion('1.2.3'));
	}

	public function testGetLatestVersionReturnsNewestVersion() {
		$mock = $this->getMockBuilder(GitHubReleases::class)
			->setConstructorArgs(array('Vateron-Media', 'XC_VM', 'stable'))
			->onlyMethods(array('getReleases'))
			->getMock();

		$mock->method('getReleases')->willReturn(array('1.4.0', '1.3.9', '1.3.0'));
		$this->assertSame('1.4.0', $mock->getLatestVersion('1.3.9'));
	}

	private function patchChannelMock(array $releases) {
		$mock = $this->getMockBuilder(GitHubReleases::class)
			->setConstructorArgs(array('Vateron-Media', 'XC_VM', 'beta'))
			->onlyMethods(array('getReleases'))
			->getMock();
		$mock->method('getReleases')->willReturn($releases);
		return $mock;
	}

	public function testBelowBasePanelIsRoutedToTheFullBaseReleaseNotThePatch() {
		// A panel on 2.6.4 must not be handed 2.6.6-p3 (a delta since 2.6.6);
		// it gets the full 2.6.6 release first.
		$mock = $this->patchChannelMock(array('2.6.6-p3', '2.6.6-p2', '2.6.6-p1', '2.6.6', '2.6.5'));
		$this->assertSame('2.6.6', $mock->getLatestVersion('2.6.4'));
	}

	public function testPanelOnBaseGetsThePatch() {
		$mock = $this->patchChannelMock(array('2.6.6-p3', '2.6.6-p2', '2.6.6-p1', '2.6.6', '2.6.5'));
		$this->assertSame('2.6.6-p3', $mock->getLatestVersion('2.6.6'));
	}

	public function testPanelOnAnEarlierPatchGetsTheNewerPatch() {
		$mock = $this->patchChannelMock(array('2.6.6-p3', '2.6.6-p2', '2.6.6-p1', '2.6.6', '2.6.5'));
		$this->assertSame('2.6.6-p3', $mock->getLatestVersion('2.6.6-p1'));
	}

	public function testBelowBasePanelFallsBackToNewestFullReleaseWhenBaseIsAbsent() {
		// Base 2.6.6 yanked: a 2.6.4 panel still gets a full release (2.6.5), never the delta.
		$mock = $this->patchChannelMock(array('2.6.6-p3', '2.6.5'));
		$this->assertSame('2.6.5', $mock->getLatestVersion('2.6.4'));
	}

	public function testBelowBasePanelGetsNothingWhenOnlyAnUnsafePatchIsOffered() {
		// Only the patch is on the channel and the panel is below its base: nothing safe to offer.
		$mock = $this->patchChannelMock(array('2.6.6-p3'));
		$this->assertNull($mock->getLatestVersion('2.6.4'));
	}

	public function testGetUpdateFileBuildsMainArchiveUrlAndHash() {
		$mock = $this->getMockBuilder(GitHubReleases::class)
			->setConstructorArgs(array('Vateron-Media', 'XC_VM', 'stable'))
			->onlyMethods(array('getLatestVersion', 'getAssetHash'))
			->getMock();

		$mock->method('getLatestVersion')->with('1.0.0')->willReturn('1.1.0');
		$mock->method('getAssetHash')->with('1.1.0', 'xc_vm.tar.gz')->willReturn('md5-hash-value');

		$result = $mock->getUpdateFile('main', '1.0.0');
		$this->assertSame('https://github.com/Vateron-Media/XC_VM/releases/download/1.1.0/xc_vm.tar.gz', $result['url']);
		$this->assertSame('md5-hash-value', $result['md5']);
	}

	public function testSetTimeoutKeepsMinimumOneSecond() {
		$instance = new GitHubReleases('Vateron-Media', 'XC_VM', 'stable');
		$instance->setTimeout(0);

		$reflection = new ReflectionClass($instance);
		$timeout = $reflection->getProperty('timeout');
		$timeout->setAccessible(true);

		$this->assertSame(1, $timeout->getValue($instance));
	}

	public function testSetChannelUpdatesCacheFileAndClearsOldCache() {
		$instance = new GitHubReleases('Vateron-Media', 'XC_VM', 'stable');
		$reflection = new ReflectionClass($instance);

		$cacheProperty = $reflection->getProperty('cache_file');
		$cacheProperty->setAccessible(true);
		$channelProperty = $reflection->getProperty('channel');
		$channelProperty->setAccessible(true);

		$basePath = sys_get_temp_dir() . '/gitapi_test_' . uniqid('', true);
		$cacheProperty->setValue($instance, $basePath . '_stable');
		file_put_contents($basePath . '_stable', 'cache-data');

		$instance->setChannel('unstable'); // legacy alias, normalized to 'beta'

		$this->assertSame('beta', $channelProperty->getValue($instance));
		$this->assertSame($basePath . '_beta', $cacheProperty->getValue($instance));
		$this->assertFalse(file_exists($basePath . '_stable'));
	}

	public function testSetChannelRejectsInvalidValue() {
		$instance = new GitHubReleases('Vateron-Media', 'XC_VM', 'stable');
		$this->expectException(InvalidArgumentException::class);
		$instance->setChannel('nightly');
	}

	public function testAssetUrlBuildsReleaseDownloadUrl() {
		$instance = new GitHubReleases('Vateron-Media', 'XC_VM_Update', 'stable');
		$this->assertSame(
			'https://github.com/Vateron-Media/XC_VM_Update/releases/download/29062026/GeoLite2-City.mmdb',
			$instance->assetUrl('29062026', 'GeoLite2-City.mmdb')
		);
	}

	/**
	 * A release client whose API fetches are served from $byRepo (repo name →
	 * raw release list); a repo mapped to an Exception throws it instead.
	 *
	 * @param array<string,array<int,array<string,mixed>>|\Exception> $byRepo
	 */
	private function clientWithReleases(string $channel, array $byRepo): GitHubReleases {
		$mock = $this->getMockBuilder(GitHubReleases::class)
			->setConstructorArgs(array('Vateron-Media', 'XC_VM', $channel, null, 'XC_VM_Dev'))
			->onlyMethods(array('fetchRawReleases'))
			->getMock();
		$mock->method('fetchRawReleases')->willReturnCallback(function (string $repo) use ($byRepo) {
			$rReleases = $byRepo[$repo] ?? array();
			if ($rReleases instanceof \Exception) {
				throw $rReleases;
			}
			return $rReleases;
		});

		return $mock;
	}

	private const MAIN_RELEASES = array(
		array('tag_name' => '2.5.3', 'prerelease' => false),
		array('tag_name' => '2.5.3-beta.1', 'prerelease' => true),
		array('tag_name' => '2.5.2', 'prerelease' => false),
	);

	private const DEV_RELEASES = array(
		array('tag_name' => '2.5.4-dev.7', 'prerelease' => true),
		array('tag_name' => '2.5.4-dev.6', 'prerelease' => true),
	);

	public function testIsDevVersionMatchesNightlyTagsOnly() {
		$this->assertTrue(GitHubReleases::isDevVersion('2.5.4-dev.12'));
		$this->assertFalse(GitHubReleases::isDevVersion('2.5.4'));
		$this->assertFalse(GitHubReleases::isDevVersion('2.5.4-beta.1'));
		$this->assertFalse(GitHubReleases::isDevVersion('2.5.4-dev'));
	}

	public function testDevChannelMergesNightlyBuildsNewestFirst() {
		$client = $this->clientWithReleases('dev', array('XC_VM' => self::MAIN_RELEASES, 'XC_VM_Dev' => self::DEV_RELEASES));

		$this->assertSame(array('2.5.4-dev.7', '2.5.4-dev.6', '2.5.3', '2.5.3-beta.1', '2.5.2'), $client->getReleases());
		$this->assertSame('2.5.4-dev.7', $client->getLatestVersion('2.5.3'));
		$this->assertSame('2.5.4-dev.7', $client->getLatestVersion('2.5.4-dev.6'));
	}

	public function testStableReleaseSupersedesNightlyBuildsOfTheSameVersion() {
		$main = array_merge(array(array('tag_name' => '2.5.4', 'prerelease' => false)), self::MAIN_RELEASES);
		$client = $this->clientWithReleases('dev', array('XC_VM' => $main, 'XC_VM_Dev' => self::DEV_RELEASES));

		$this->assertSame('2.5.4', $client->getLatestVersion('2.5.4-dev.7'));
	}

	public function testStableChannelIgnoresTheDevRepository() {
		$client = $this->clientWithReleases('stable', array('XC_VM' => self::MAIN_RELEASES, 'XC_VM_Dev' => self::DEV_RELEASES));

		$this->assertSame(array('2.5.3', '2.5.2'), $client->getReleases());
	}

	public function testUnreachableDevRepositoryFallsBackToRegularReleases() {
		$client = $this->clientWithReleases('dev', array('XC_VM' => self::MAIN_RELEASES, 'XC_VM_Dev' => new \Exception('Resource not found (404)')));

		$this->assertSame('2.5.3', $client->getLatestVersion('2.5.2'));
	}

	public function testDevChannelWithoutDevRepositoryBehavesLikeBeta() {
		$mock = $this->getMockBuilder(GitHubReleases::class)
			->setConstructorArgs(array('Vateron-Media', 'XC_VM_Update', 'dev'))
			->onlyMethods(array('fetchRawReleases'))
			->getMock();
		$mock->expects($this->once())->method('fetchRawReleases')->willReturn(self::MAIN_RELEASES);

		$this->assertSame(array('2.5.3', '2.5.3-beta.1', '2.5.2'), $mock->getReleases());
	}

	public function testPreviousVersionsSkipNightlyBuilds() {
		$client = $this->clientWithReleases('dev', array('XC_VM' => self::MAIN_RELEASES, 'XC_VM_Dev' => self::DEV_RELEASES));

		$versions = array_column($client->getPreviousVersions('2.5.4-dev.7', 5), 'version');
		$this->assertSame(array('2.5.3', '2.5.3-beta.1', '2.5.2'), $versions);
	}

	public function testUpdateFileForNightlyBuildPointsAtDevRepository() {
		$mock = $this->getMockBuilder(GitHubReleases::class)
			->setConstructorArgs(array('Vateron-Media', 'XC_VM', 'dev', null, 'XC_VM_Dev'))
			->onlyMethods(array('getLatestVersion', 'getAssetHash'))
			->getMock();
		$mock->method('getLatestVersion')->willReturn('2.5.4-dev.7');
		$mock->method('getAssetHash')->willReturn('md5');

		$this->assertSame(
			'https://github.com/Vateron-Media/XC_VM_Dev/releases/download/2.5.4-dev.7/loadbalancer.tar.gz',
			$mock->getUpdateFile('lb_update', '2.5.3')['url']
		);
	}

	/**
	 * A release client whose HTTP layer records each requested URL into
	 * $requested and answers with $respond($url).
	 *
	 * @param list<string> $requested
	 */
	private function clientWithHttp(string $channel, callable $respond, array &$requested): GitHubReleases {
		$mock = $this->getMockBuilder(GitHubReleases::class)
			->setConstructorArgs(array('Vateron-Media', 'XC_VM', $channel, null, 'XC_VM_Dev'))
			->onlyMethods(array('makeRequest'))
			->getMock();
		$mock->method('makeRequest')->willReturnCallback(function (string $url) use ($respond, &$requested) {
			$requested[] = $url;
			return $respond($url);
		});

		return $mock;
	}

	private function fetchRaw(GitHubReleases $client, string $repo, string $cacheFile): array {
		$method = new ReflectionMethod($client, 'fetchRawReleases');
		$method->setAccessible(true);

		return $method->invoke($client, $repo, $cacheFile);
	}

	public function testFetchRawReleasesRequestsFullPageAndCachesTheResponse() {
		$requested = array();
		$client = $this->clientWithHttp('stable', fn() => json_encode(self::MAIN_RELEASES), $requested);
		$cacheFile = sys_get_temp_dir() . '/gitapi_test_' . uniqid('', true);

		try {
			$this->assertSame(self::MAIN_RELEASES, $this->fetchRaw($client, 'XC_VM', $cacheFile));
			$this->assertSame(self::MAIN_RELEASES, $this->fetchRaw($client, 'XC_VM', $cacheFile), 'second read is served from cache');
			$this->assertSame(array('https://api.github.com/repos/Vateron-Media/XC_VM/releases?per_page=100'), $requested);
		} finally {
			@unlink($cacheFile);
		}
	}

	public function testFetchRawReleasesRejectsNonListResponse() {
		$requested = array();
		$client = $this->clientWithHttp('stable', fn() => 'not json', $requested);

		$this->expectException(\Exception::class);
		$this->fetchRaw($client, 'XC_VM', sys_get_temp_dir() . '/gitapi_test_' . uniqid('', true));
	}

	public function testChangelogOfNightlyBuildIsReadFromReleaseAsset() {
		$requested = array();
		$client = $this->clientWithHttp('dev', fn() => '{"version":"2.5.4-dev.7","changes":["a"]}', $requested);

		$this->assertSame(array(array('version' => '2.5.4-dev.7', 'changes' => array('a'))), $client->getChangelog('2.5.4-dev.7'));
		$this->assertSame(array('https://github.com/Vateron-Media/XC_VM_Dev/releases/download/2.5.4-dev.7/changelog.json'), $requested);
	}

	public function testChangelogOfReleaseIsReadFromTaggedSource() {
		$requested = array();
		$client = $this->clientWithHttp('dev', fn() => '{"version":"2.5.3","changes":[]}', $requested);

		$client->getChangelog('2.5.3');
		$this->assertSame(array('https://raw.githubusercontent.com/Vateron-Media/XC_VM/refs/tags/2.5.3/changelog.json'), $requested);
	}

	public function testChangelogIsEmptyOnInvalidJsonOrRequestFailure() {
		$requested = array();
		$this->assertSame(array(), $this->clientWithHttp('stable', fn() => 'not json', $requested)->getChangelog('2.5.3'));
		$this->assertSame(array(), $this->clientWithHttp('stable', function () {
			throw new \Exception('Resource not found (404)');
		}, $requested)->getChangelog('2.5.3'));
	}

	public function testClearCacheAlsoRemovesTheDevRepositoryCache() {
		$instance = new GitHubReleases('Vateron-Media', 'XC_VM', 'dev', null, 'XC_VM_Dev');
		$reflection = new ReflectionClass($instance);
		$prefix = sys_get_temp_dir() . '/gitapi_test_' . uniqid('', true);
		foreach (array('cache_prefix' => $prefix, 'cache_file' => $prefix . '_XC_VM_dev') as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($instance, $value);
		}
		file_put_contents($prefix . '_XC_VM_dev', '[]');
		file_put_contents($prefix . '_XC_VM_Dev', '[]');

		$instance->clearCache();

		$this->assertFileDoesNotExist($prefix . '_XC_VM_dev');
		$this->assertFileDoesNotExist($prefix . '_XC_VM_Dev');
	}

	/** A patch build is release X.Y.Z with the fixes made since, published by the build-patch workflow as X.Y.Z-pN. */
	public function testAPatchBuildIsARelease() {
		foreach (array('2.6.5-p1' => true, '2.6.5-p12' => true, '2.6.5' => false, '2.6.5-dev.3' => false, '2.6.5-p' => false, '2.6.5-p1a' => false, '2.6.5p1' => false) as $tag => $isPatch) {
			$this->assertSame($isPatch, GitHubReleases::isPatchVersion($tag), $tag);
		}
		foreach (array('2.6.5' => true, '2.6.5-p1' => true, '2.6.5-dev.3' => false, '2.6.5-beta.1' => false, 'v2.6.5' => false, '' => false, "2.6.5-p1\n" => false) as $tag => $isRelease) {
			$this->assertSame($isRelease, GitHubReleases::isReleaseVersion((string) $tag), var_export($tag, true));
		}
	}

	private const PATCHED_RELEASES = array(
		// The API lists by publication: a patch of 2.6.5 made after 2.6.6 comes first.
		array('tag_name' => '2.6.5-p2', 'prerelease' => false),
		array('tag_name' => '2.6.6', 'prerelease' => false),
		array('tag_name' => '2.6.5-p1', 'prerelease' => false),
		array('tag_name' => '2.6.5', 'prerelease' => false),
		array('tag_name' => '2.6.4-p1', 'prerelease' => true),
		array('tag_name' => '2.6.4', 'prerelease' => true),
	);

	/** The newest version is offered, whatever was published last. */
	public function testAPatchOfAnOlderReleaseDoesNotHideTheNextRelease() {
		$client = $this->clientWithReleases('stable', array('XC_VM' => self::PATCHED_RELEASES));

		$this->assertSame(array('2.6.6', '2.6.5-p2', '2.6.5-p1', '2.6.5'), $client->getReleases(), 'by version, and a pre-release\'s patch is not on the stable channel');
		$this->assertSame('2.6.6', $client->getLatestVersion('2.6.5'));
		$this->assertSame('2.6.6', $client->getLatestVersion('2.6.5-p2'));
		$this->assertNull($client->getLatestVersion('2.6.6'));
	}

	/** A panel on the release gets its patch builds, the newest at once. */
	public function testAPanelOnTheReleaseIsOfferedItsNewestPatch() {
		$releases = array_slice(self::PATCHED_RELEASES, 2);
		array_unshift($releases, array('tag_name' => '2.6.5-p2', 'prerelease' => false));
		$client = $this->clientWithReleases('stable', array('XC_VM' => $releases));

		$this->assertSame('2.6.5-p2', $client->getLatestVersion('2.6.5'));
		$this->assertSame('2.6.5-p2', $client->getLatestVersion('2.6.5-p1'));
		$this->assertNull($client->getLatestVersion('2.6.5-p2'));
		$this->assertSame('2.6.5', $client->getLatestVersion('2.6.4'), 'a panel below the base takes the full base release first, never a patch delta onto an older tree');
	}

	/** On the beta channel a pre-release's patches are offered too. */
	public function testAPatchFollowsItsReleasesChannel() {
		$client = $this->clientWithReleases('beta', array('XC_VM' => array_slice(self::PATCHED_RELEASES, 4)));

		$this->assertSame('2.6.4-p1', $client->getLatestVersion('2.6.4'));
	}

	/** A rollback may name a patch build, and the release under it. */
	public function testPreviousVersionsListPatchBuilds() {
		$client = $this->clientWithReleases('stable', array('XC_VM' => self::PATCHED_RELEASES));

		$this->assertSame(array('2.6.5-p2', '2.6.5-p1', '2.6.5'), array_column($client->getPreviousVersions('2.6.6', 5), 'version'));
		$this->assertSame(array('2.6.5-p1', '2.6.5'), array_column($client->getPreviousVersions('2.6.5-p2', 5), 'version'));
	}

	/** Its changelog is its own asset: the file in the tree at its tag is still the release's. */
	public function testChangelogOfAPatchBuildIsReadFromReleaseAsset() {
		$requested = array();
		$client = $this->clientWithHttp('stable', fn() => '{"version":"2.6.5-p1","changes":["fix"]}', $requested);

		$this->assertSame(array(array('version' => '2.6.5-p1', 'changes' => array('fix'))), $client->getChangelog('2.6.5-p1'));
		$this->assertSame(array('https://github.com/Vateron-Media/XC_VM/releases/download/2.6.5-p1/changelog.json'), $requested);
	}
}
