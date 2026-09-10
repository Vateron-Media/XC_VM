<?php

use XcVm\Core\GeoIP\GeoLiteReleaseUpdater;
use XcVm\Core\Updates\GitHubReleases;
use PHPUnit\Framework\TestCase;

/**
 * @covers XcVm\Core\GeoIP\GeoLiteReleaseUpdater
 */
final class GeoLiteReleaseUpdaterTest extends TestCase {

	/** @param string[] $releases */
	private function repo(array $releases): GitHubReleases {
		$rRepo = $this->getMockBuilder(GitHubReleases::class)
			->setConstructorArgs(array('Vateron-Media', 'XC_VM_Update', 'stable'))
			->onlyMethods(array('getReleases', 'assetUrl', 'getAssetHash'))
			->getMock();
		$rRepo->method('getReleases')->willReturn($releases);
		$rRepo->method('assetUrl')->willReturnCallback(fn($rVersion, $rAsset) => 'gh/' . $rVersion . '/' . $rAsset);
		$rRepo->method('getAssetHash')->willReturnCallback(fn($rVersion, $rAsset) => 'md5-' . $rAsset);
		return $rRepo;
	}

	/**
	 * @param array<int,array> $specs   captures each downloadReleaseFile() spec
	 * @param array<string,mixed> $recorded captures each recordVersion() call
	 */
	private function updater(GitHubReleases $rRepo, array &$specs, array &$recorded, ?bool $downloadResult = true): GeoLiteReleaseUpdater {
		$rUpdater = $this->getMockBuilder(GeoLiteReleaseUpdater::class)
			->setConstructorArgs(array($rRepo))
			->onlyMethods(array('downloadReleaseFile', 'recordVersion'))
			->getMock();
		$rUpdater->method('downloadReleaseFile')->willReturnCallback(function ($rFile) use (&$specs, $downloadResult) {
			$specs[] = $rFile;
			return $downloadResult;
		});
		$rUpdater->method('recordVersion')->willReturnCallback(function ($rKey, $rVersion) use (&$recorded) {
			$recorded[$rKey] = $rVersion;
		});
		return $rUpdater;
	}

	public function testUpdateGeoLiteDownloadsCityAndCountryWithCorrectSpecsAndVersion() {
		$specs = array();
		$recorded = array();
		$rUpdater = $this->updater($this->repo(array('29062026')), $specs, $recorded);

		$this->assertFalse($rUpdater->updateGeoLite(false));

		$this->assertCount(2, $specs);
		$this->assertSame('gh/29062026/GeoLite2-City.mmdb', $specs[0]['fileurl']);
		$this->assertSame('/home/xc_vm/bin/maxmind/GeoLite2-City.mmdb', $specs[0]['path']);
		$this->assertSame('md5-GeoLite2-City.mmdb', $specs[0]['md5']);
		$this->assertSame('gh/29062026/GeoLite2-Country.mmdb', $specs[1]['fileurl']);
		$this->assertSame('/home/xc_vm/bin/maxmind/GeoLite2-Country.mmdb', $specs[1]['path']);
		$this->assertSame('29062026', $recorded['geolite2_version']);
	}

	public function testUpdateGeoLiteReportsErrorWhenNoRelease() {
		$specs = array();
		$recorded = array();
		$rUpdater = $this->updater($this->repo(array()), $specs, $recorded);

		$this->assertTrue($rUpdater->updateGeoLite(false));
		$this->assertCount(0, $specs);
		$this->assertArrayNotHasKey('geolite2_version', $recorded);
	}

	public function testUpdateIspDownloadsIspAndRecordsVersion() {
		$specs = array();
		$recorded = array();
		$rUpdater = $this->updater($this->repo(array('29062026')), $specs, $recorded);

		$rUpdater->updateIsp(false);

		$this->assertCount(1, $specs);
		$this->assertSame('gh/29062026/GeoIP2-ISP.mmdb', $specs[0]['fileurl']);
		$this->assertSame('/home/xc_vm/bin/maxmind/GeoIP2-ISP.mmdb', $specs[0]['path']);
		$this->assertSame('md5-GeoIP2-ISP.mmdb', $specs[0]['md5']);
		$this->assertSame('29062026', $recorded['geoisp_version']);
	}

	public function testUpdateIspDoesNotRecordVersionOnDownloadError() {
		$specs = array();
		$recorded = array();
		$rUpdater = $this->updater($this->repo(array('29062026')), $specs, $recorded, null);

		$rUpdater->updateIsp(false);

		$this->assertCount(1, $specs);
		$this->assertArrayNotHasKey('geoisp_version', $recorded);
	}
}
