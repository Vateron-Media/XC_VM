<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\ResellerAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * The reseller API's view of a sub-reseller never carries its password hash
 * or API key. `show_columns` is taken before the hide the endpoint forced, so
 * `show_columns=password,api_key` answered both; and without `hide_columns`
 * the endpoint failed on in_array(…, null).
 */
final class ResellerGetUserSecretsTest extends TestCase {
	private array $rGlobals;

	protected function setUp(): void {
		$this->rGlobals = [$GLOBALS['db'] ?? null, $GLOBALS['rUserInfo'] ?? null, $GLOBALS['rPermissions'] ?? null];
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::table('users'));
		$rDb->exec("INSERT INTO `users` (`id`, `username`, `password`, `api_key`, `owner_id`, `member_group_id`, `status`) VALUES (5, 'reseller', 'hash5', 'key5', 0, 2, 1), (6, 'sub', '\$6\$subhash', 'subkey', 5, 2, 1)");
		DatabaseFactory::set($rDb);
		$rOwn = new ReflectionProperty(UserRepository::class, 'db');
		$this->rGlobals[] = $rOwn->getValue();
		$rOwn->setValue(null, $rDb);
		$GLOBALS['db'] = $rDb;
		$GLOBALS['rUserInfo'] = ['id' => 5, 'member_group_id' => 2];
		$GLOBALS['rPermissions'] = ['is_admin' => 0, 'is_reseller' => 1, 'all_reports' => [6], 'advanced' => []];
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		(new ReflectionProperty(UserRepository::class, 'db'))->setValue(null, $this->rGlobals[3]);
		[$GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']] = $this->rGlobals;
	}

	public function testASubResellersRowCarriesNoSecret(): void {
		$rUser = ResellerAPIWrapper::getUser(6);
		$this->assertSame('STATUS_SUCCESS', $rUser['status']);
		$this->assertSame('sub', $rUser['data']['username']);
		$this->assertArrayNotHasKey('password', $rUser['data']);
		$this->assertArrayNotHasKey('api_key', $rUser['data']);

		$rAsked = ResellerAPIWrapper::filterRow($rUser, ['username', 'password', 'api_key'], ['x']);
		$this->assertSame(['username' => 'sub'], $rAsked['data'], 'asked for by name: still not answered');
	}

	/** get_user with no hide_columns: the endpoint's forced hide ran in_array() on null. */
	public function testTheEndpointNoLongerHidesByHand(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Api/ResellerRestApiController.php');
		$this->assertStringNotContainsString("in_array('password', \$rHideColumns)", $rSource);
	}
}
