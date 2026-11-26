<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthRepository;

/**
 * AuthRepository::codeLocation — the nginx location an access code gets.
 * Type 5 (the legacy "Ministra (new)" code) aliased Ministra/new, a directory
 * that never existed, so the STB portal's files 404'd behind such a code.
 * Both Ministra types serve the portal under src/Ministra/.
 */
final class AccessCodeLocationTest extends TestCase {

	public function testBothMinistraTypesAliasThePortalDirectory(): void {
		foreach ([2, 5] as $rCodeType) {
			[$rType, $rAlias, $rBurst] = AuthRepository::codeLocation($rCodeType);
			$this->assertSame('Ministra', $rAlias, 'type ' . $rCodeType);
			$this->assertDirectoryExists(MAIN_HOME . $rAlias, 'type ' . $rCodeType);
			$this->assertFileExists(MAIN_HOME . $rAlias . '/portal.php', 'type ' . $rCodeType);
			$this->assertSame(50, $rBurst);
		}
		$this->assertSame('ministra/new', AuthRepository::codeLocation(5)[0], 'the XC_SCOPE deployed configs and Public/index.php know');
	}

	public function testEveryDirectoryAliasExists(): void {
		// 1 and 3/4 name no directory: their requests all fall through to the
		// front controller (see the NOTE in codeLocation()).
		foreach ([0, 2, 5, 6, 7, 8] as $rCodeType) {
			$this->assertDirectoryExists(MAIN_HOME . AuthRepository::codeLocation($rCodeType)[1], 'type ' . $rCodeType);
		}
	}

	public function testBothWebPlayerTypesUseTheCurrentPlayer(): void {
		foreach ([6, 8] as $rCodeType) {
			$this->assertSame(['player_v2', 'Public/Views/player_v2', 500], AuthRepository::codeLocation($rCodeType));
		}
	}

	public function testAnUnknownTypeIsAnAdminCode(): void {
		$this->assertSame(['admin', 'Public/Views/admin', 500], AuthRepository::codeLocation(99));
		$this->assertSame(AuthRepository::codeLocation(0), AuthRepository::codeLocation(99));
	}
}
