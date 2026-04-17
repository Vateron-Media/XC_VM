<?php

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

		$instance->setChannel('unstable');

		$this->assertSame('unstable', $channelProperty->getValue($instance));
		$this->assertSame($basePath . '_unstable', $cacheProperty->getValue($instance));
		$this->assertFalse(file_exists($basePath . '_stable'));
	}

	public function testSetChannelRejectsInvalidValue() {
		$instance = new GitHubReleases('Vateron-Media', 'XC_VM', 'stable');
		$this->expectException(InvalidArgumentException::class);
		$instance->setChannel('beta');
	}
}
