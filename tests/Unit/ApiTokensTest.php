<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\ApiTokens;
use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\AdminApiController;
use XcVm\Tests\Support\InstallSchema;

/**
 * Named API tokens (Core\Auth\ApiTokens): stored as hashes, limited by
 * expiry, address and scope, taken by every API that takes a key, and the
 * legacy single key turned off by api_legacy_keys.
 */
final class ApiTokensTest extends TestCase {
	private const NOW = 1800000000;

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['api_tokens', 'users', 'users_groups'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`) VALUES (1, 'Admins', 1, 0), (2, 'Resellers', 0, 1)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `api_key`) VALUES (5, 'admin', 1, 1, 'ABCDEF0123456789ABCDEF0123456789'), (6, 'seller', 2, 1, ''), (7, 'gone', 1, 0, '')");
		DatabaseFactory::set($this->rDb);
		SettingsManager::set([]);
	}

	protected function tearDown(): void {
		ApiTokens::clear();
		unset($GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['db']);
		SettingsManager::set([]);
		DatabaseFactory::reset();
	}

	private function row(int $rID): array {
		$this->rDb->query('SELECT * FROM `api_tokens` WHERE `id` = ?;', $rID);
		return $this->rDb->get_raw_row();
	}

	public function testATokenIsKeptAsAHashAndResolvesToItsRow(): void {
		$rToken = ApiTokens::issue(5, 'billing', 'lines', false, [], null, self::NOW);
		$this->assertMatchesRegularExpression('/^xct_[0-9a-f]{40}$/', $rToken);
		$rRow = $this->row(1);
		$this->assertSame(hash('sha256', $rToken), $rRow['hash']);
		$this->assertSame(substr($rToken, 0, 12), $rRow['prefix']);
		$this->assertStringNotContainsString(substr($rToken, 12), (string) json_encode($rRow));

		$this->assertSame(5, (int) ApiTokens::resolve($rToken, '192.0.2.1', self::NOW + 5)['user_id']);
		$this->assertSame(self::NOW + 5, (int) $this->row(1)['last_used']);
		ApiTokens::resolve($rToken, '192.0.2.2', self::NOW + 30);
		$this->assertSame('192.0.2.1', $this->row(1)['last_ip'], 'written at most once a minute');
		$this->assertNull(ApiTokens::resolve($rToken . 'x', '192.0.2.1', self::NOW));
		$this->assertNull(ApiTokens::resolve('ABCDEF0123456789ABCDEF0123456789', '192.0.2.1', self::NOW), 'a legacy key is not a token');
	}

	public function testExpiryAndTheAddressListAreKept(): void {
		$rToken = ApiTokens::issue(5, 'cron', 'read', false, ['192.0.2.1', '2001:db8::1'], self::NOW + 100, self::NOW);
		$this->assertNotNull(ApiTokens::resolve($rToken, '2001:db8::1', self::NOW));
		$this->assertNull(ApiTokens::resolve($rToken, '192.0.2.9', self::NOW), 'another address');
		$this->assertNull(ApiTokens::resolve($rToken, '192.0.2.1', self::NOW + 100), 'expired');
	}

	/** @return iterable<string, array{0: string, 1: bool, 2: list<string>, 3: list<string>}> */
	public static function scopes(): iterable {
		yield 'full' => ['full', false, ['create_stream', 'edit_settings', 'get_lines'], ['mysql_query']];
		yield 'full with SQL' => ['full', true, ['mysql_query', 'delete_server'], []];
		yield 'read' => ['read', true, ['get_lines', 'get_server_stats', 'user_logs', 'live_connections', 'packages', 'check_active_code'], ['create_line', 'edit_settings', 'kill_connection', 'mysql_query', 'reload_cache', 'delete_bouquet']];
		yield 'lines' => ['lines', true, ['create_line', 'edit_line', 'get_lines', 'ban_mag', 'convert_enigma', 'generate_active_codes', 'mass_active_codes', 'get_packages', 'user_info', 'packages'], ['create_stream', 'get_streams', 'create_user', 'adjust_credits', 'edit_settings', 'mysql_query', 'get_servers']];
	}

	/** @dataProvider scopes */
	public function testAScopeAllowsItsActionsOnly(string $rScope, bool $rSql, array $rAllowed, array $rRefused): void {
		// A full administrator's key: its group allows every action, so only the scope refuses.
		$GLOBALS['rUserInfo'] = ['id' => 5, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		$GLOBALS['db'] = $this->rDb;
		ApiTokens::clear();
		$this->assertTrue(AdminApiController::permitted('mysql_query'), 'the group allows it');
		ApiTokens::resolve(ApiTokens::issue(5, 't', $rScope, $rSql, [], null), '192.0.2.1');
		foreach ($rAllowed as $rAction) {
			$this->assertTrue(ApiTokens::allows($rAction), $rAction);
		}
		foreach ($rRefused as $rAction) {
			$this->assertFalse(ApiTokens::allows($rAction), $rAction);
			$this->assertFalse(AdminApiController::permitted($rAction), $rAction . ': the Admin API asks it before the group');
		}
		ApiTokens::clear();
		$this->assertTrue(ApiTokens::allows('mysql_query'), 'no token: a session or legacy key is not narrowed');
	}

	public function testEveryKeyPathFindsTheAccountOfItsRole(): void {
		$rAdmin = ApiTokens::issue(5, 'a', 'full', false, [], null);
		$this->assertSame(5, ApiTokens::userFor($rAdmin, '192.0.2.1', 'admin'));
		$this->assertNull(ApiTokens::userFor($rAdmin, '192.0.2.1', 'reseller'), 'an admin token on the reseller API');
		$this->assertTrue(ApiTokens::allows('mysql_query'), 'and its scope no longer applies');

		$this->assertSame(6, ApiTokens::userFor(ApiTokens::issue(6, 'r', 'read', false, [], null), '192.0.2.1', 'reseller'));
		$this->assertNull(ApiTokens::userFor(ApiTokens::issue(7, 'd', 'full', false, [], null), '192.0.2.1', 'admin'), 'a disabled account');
		$this->assertNull(ApiTokens::userFor('xct_' . str_repeat('0', 40), '192.0.2.1', 'admin'));
		$this->assertNull(ApiTokens::userFor('', '192.0.2.1', 'admin'));

		$this->assertSame(5, ApiTokens::userFor('ABCDEF0123456789ABCDEF0123456789', '192.0.2.1', 'admin'), 'a legacy key');
		SettingsManager::set(['api_legacy_keys' => '0']);
		$this->assertNull(ApiTokens::userFor('ABCDEF0123456789ABCDEF0123456789', '192.0.2.1', 'admin'), 'legacy keys off');
		$this->assertSame(5, ApiTokens::userFor($rAdmin, '192.0.2.1', 'admin'), 'tokens still work');
	}

	public function testTheProfileMakesAndRevokesTokens(): void {
		$this->assertSame('name', ApiTokens::manage(5, ['sub' => 'create', 'name' => ' '], true)['error']);
		$this->assertSame('scope', ApiTokens::manage(5, ['sub' => 'create', 'name' => 'x', 'scope' => 'root'], true)['error']);
		$this->assertSame('ips', ApiTokens::manage(5, ['sub' => 'create', 'name' => 'x', 'ips' => '192.0.2.1, example.com'], true)['error']);
		$this->assertSame('days', ApiTokens::manage(5, ['sub' => 'create', 'name' => 'x', 'days' => -1], true)['error']);

		$rOut = ApiTokens::manage(5, ['sub' => 'create', 'name' => 'billing', 'scope' => 'full', 'allow_sql' => '1', 'ips' => '192.0.2.1 192.0.2.2', 'days' => '30'], true);
		$this->assertTrue($rOut['result']);
		$rRow = ApiTokens::forUser(5)[0];
		$this->assertSame(['billing', 'full', 1, '192.0.2.1,192.0.2.2'], [$rRow['name'], $rRow['scope'], (int) $rRow['allow_sql'], $rRow['ips']]);
		$this->assertEqualsWithDelta(time() + 30 * 86400, (int) $rRow['expires'], 5);
		$this->assertArrayNotHasKey('hash', $rRow);

		ApiTokens::manage(6, ['sub' => 'create', 'name' => 'mine', 'scope' => 'full', 'allow_sql' => '1'], false);
		$this->assertSame(0, (int) ApiTokens::forUser(6)[0]['allow_sql'], 'raw SQL is the Admin API\'s');

		$this->assertFalse(ApiTokens::manage(6, ['sub' => 'revoke', 'id' => $rRow['id']], false)['result'], 'another account\'s token');
		$this->assertTrue(ApiTokens::manage(5, ['sub' => 'revoke', 'id' => $rRow['id']], true)['result']);
		$this->assertNull(ApiTokens::resolve($rOut['token'], '192.0.2.1'));

		for ($i = count(ApiTokens::forUser(6)); $i < ApiTokens::MAX_PER_USER; $i++) {
			ApiTokens::issue(6, 't' . $i, 'read', false, [], null);
		}
		$this->assertSame('limit', ApiTokens::manage(6, ['sub' => 'create', 'name' => 'one more'], false)['error']);
	}
}
