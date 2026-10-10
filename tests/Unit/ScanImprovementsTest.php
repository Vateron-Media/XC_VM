<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\ApiTokens;
use XcVm\Core\Config\SettingsManager;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;

/**
 * A token that may only read is given no secret by get_settings: every
 * setting went to it, the stream tokens' key and the Redis password among them.
 */
final class ScanImprovementsTest extends TestCase {
	protected function tearDown(): void {
		(new ReflectionProperty(ApiTokens::class, 'rCurrent'))->setValue(null, null);
		SettingsManager::set([]);
	}

	public function testAReadOnlyTokenIsGivenNoSecretSetting(): void {
		SettingsManager::set(['server_name' => 'Panel', 'live_streaming_pass' => 'k', 'redis_password' => 'p', 'tmdb_api_key' => 't', 'metrics_token' => 'm', 'reminders_webhook' => 'https://hook.test/x', 'disable_mag_token' => 1]);
		$rCurrent = new ReflectionProperty(ApiTokens::class, 'rCurrent');

		$rCurrent->setValue(null, ['scope' => 'read']);
		$this->assertSame(['server_name' => 'Panel', 'disable_mag_token' => 1], AdminAPIWrapper::getSettings()['data'], 'what is no secret stays');

		$rCurrent->setValue(null, ['scope' => 'lines']);
		$this->assertArrayNotHasKey('live_streaming_pass', AdminAPIWrapper::getSettings()['data']);

		foreach ([['scope' => 'full'], null] as $rFull) {
			$rCurrent->setValue(null, $rFull);
			$this->assertSame('k', AdminAPIWrapper::getSettings()['data']['live_streaming_pass'], 'a full token, a session and a legacy key read them all');
		}

		foreach (['live_streaming_pass', 'redis_password', 'api_pass', 'license', 'metrics_token'] as $rSecret) {
			$this->assertContains($rSecret, AdminAPIWrapper::secretSettings());
		}
	}
}
