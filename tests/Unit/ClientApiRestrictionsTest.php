<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Public\Controllers\Api\BaseApiController;

final class ClientApiRestrictionsTest extends TestCase {
	private function line(array $rExtra = []): array {
		return $rExtra + ['bypass_ua' => 1, 'allowed_ips' => [], 'allowed_ua' => [], 'forced_country' => '', 'isp_violate' => 0, 'isp_is_server' => 0, 'is_restreamer' => 0];
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
	}

	public function testRestrictionsAreSharedAndFailClosed(): void {
		SettingsManager::set(['restrict_playlists' => 1, 'disallow_empty_user_agents' => 1, 'allow_countries' => ['US']]);
		$this->assertSame('EMPTY_USER_AGENT', BaseApiController::restriction($this->line(), '', '192.0.2.1', 'US'));
		$this->assertSame('NOT_IN_ALLOWED_IPS', BaseApiController::restriction($this->line(['allowed_ips' => ['192.0.2.2']]), 'app', '192.0.2.1', 'US'));
		$this->assertSame('NOT_IN_ALLOWED_UAS', BaseApiController::restriction($this->line(['allowed_ua' => ['other']]), 'app', '192.0.2.1', 'US'));
		$this->assertSame('FORCED_COUNTRY_INVALID', BaseApiController::restriction($this->line(['forced_country' => 'CA']), 'app', '192.0.2.1', 'US'));
		$this->assertSame('ISP_BLOCKED', BaseApiController::restriction($this->line(['isp_violate' => 1]), 'app', '192.0.2.1', 'US'));
	}

	public function testOptionalRestrictionsRemainOperatorControlled(): void {
		SettingsManager::set(['restrict_playlists' => 0]);
		$this->assertNull(BaseApiController::restriction($this->line(['allowed_ips' => ['192.0.2.2']]), 'app', '192.0.2.1', null));
	}
}
