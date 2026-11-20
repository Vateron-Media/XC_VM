<?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Updates\UpdateChannels;
use PHPUnit\Framework\TestCase;

// AppConfig.php (procedural) defines these in production but is never loaded in
// the test process; define the real values so forRepo() dispatch can be tested.
if (!defined('GIT_REPO_MAIN')) {
	define('GIT_REPO_MAIN', 'XC_VM');
}
if (!defined('GIT_REPO_BIN')) {
	define('GIT_REPO_BIN', 'XC_VM_Binaries');
}
if (!defined('GIT_REPO_FANOUT')) {
	define('GIT_REPO_FANOUT', 'XC_VM_Fanout');
}
if (!defined('GIT_REPO_DEV')) {
	define('GIT_REPO_DEV', 'XC_VM_Dev');
}
if (!defined('GIT_OWNER')) {
	define('GIT_OWNER', 'Vateron-Media');
}

/**
 * UpdateChannels — per-repository release channel resolution.
 *
 * Each core repo (MAIN/BIN/FANOUT) reads its own `update_channel_*` setting;
 * values normalize to 'stable'/'beta' (with 'unstable' as a legacy alias for
 * 'beta' and any unknown value falling back to 'stable'). forRepo() maps a
 * GitHub repo name to the owning channel, with UPDATE/PROXY/unknown following
 * MAIN. These lock that contract down against the seeded settings.
 */
final class UpdateChannelsTest extends TestCase {

	protected function setUp(): void {
		SettingsManager::set([]);
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
	}

	public function testChannelsDefaultToStableWhenUnset(): void {
		$this->assertSame('stable', UpdateChannels::main());
		$this->assertSame('stable', UpdateChannels::bin());
		$this->assertSame('stable', UpdateChannels::fanout());
	}

	public function testEachChannelReadsItsOwnSetting(): void {
		SettingsManager::set([
			'update_channel_main'   => 'beta',
			'update_channel_bin'    => 'stable',
			'update_channel_fanout' => 'beta',
		]);

		$this->assertSame('beta', UpdateChannels::main());
		$this->assertSame('stable', UpdateChannels::bin());
		$this->assertSame('beta', UpdateChannels::fanout());
	}

	public function testUnstableIsALegacyAliasForBeta(): void {
		SettingsManager::set(['update_channel_main' => 'unstable']);
		$this->assertSame('beta', UpdateChannels::main());
	}

	public function testUnknownOrEmptyValueFallsBackToStable(): void {
		SettingsManager::set(['update_channel_bin' => 'nightly']);
		$this->assertSame('stable', UpdateChannels::bin());

		SettingsManager::set(['update_channel_bin' => '']);
		$this->assertSame('stable', UpdateChannels::bin());
	}

	public function testForRepoDispatchesToTheOwningChannel(): void {
		SettingsManager::set([
			'update_channel_main'   => 'stable',
			'update_channel_bin'    => 'beta',
			'update_channel_fanout' => 'beta',
		]);

		$this->assertSame('beta', UpdateChannels::forRepo(GIT_REPO_BIN), 'BIN repo → bin channel');
		$this->assertSame('beta', UpdateChannels::forRepo(GIT_REPO_FANOUT), 'FANOUT repo → fanout channel');
		$this->assertSame('stable', UpdateChannels::forRepo(GIT_REPO_MAIN), 'MAIN repo → main channel');
	}

	public function testForRepoFollowsMainForUnknownRepos(): void {
		SettingsManager::set([
			'update_channel_main' => 'beta',
			'update_channel_bin'  => 'stable',
		]);

		// UPDATE (GeoLite/ASN) and PROXY have no channel of their own → follow MAIN.
		$this->assertSame('beta', UpdateChannels::forRepo('XC_VM_Data'));
		$this->assertSame('beta', UpdateChannels::forRepo('some-unknown-repo'));
	}

	public function testDevChannelIsOfferedForMainOnly(): void {
		SettingsManager::set([
			'update_channel_main'   => 'dev',
			'update_channel_bin'    => 'dev',
			'update_channel_fanout' => 'dev',
		]);

		$this->assertSame('dev', UpdateChannels::main());
		$this->assertSame('stable', UpdateChannels::bin(), 'BIN has no nightly builds');
		$this->assertSame('stable', UpdateChannels::fanout(), 'FANOUT has no nightly builds');
		$this->assertSame('dev', UpdateChannels::forRepo('XC_VM_GeoLite'), 'UPDATE follows MAIN');
	}

	public function testMainReleasesCarriesTheConfiguredChannelAndDevRepo(): void {
		SettingsManager::set(['update_channel_main' => 'dev']);
		$releases = UpdateChannels::mainReleases();

		$this->assertSame(
			'https://github.com/Vateron-Media/XC_VM_Dev/releases/download/2.5.4-dev.3/xc_vm.tar.gz',
			$releases->assetUrl('2.5.4-dev.3', 'xc_vm.tar.gz')
		);
		$this->assertSame(
			'https://github.com/Vateron-Media/XC_VM/releases/download/2.5.3/xc_vm.tar.gz',
			$releases->assetUrl('2.5.3', 'xc_vm.tar.gz')
		);
	}
}
