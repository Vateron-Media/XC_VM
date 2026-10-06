<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * The streaming password chosen for an activation code's line is one segment
 * of every playback address of that line (/live/<username>/<password>/<id>),
 * so it cannot hold the character that separates the segments: a line with
 * such a password is created by no code and set by no edit of one. The same
 * holds for the username, which a renamed code hands to its line.
 */
final class AuditActiveCodePasswordTest extends TestCase {
	private TestDb $rDb;

	/** @var array<string, mixed> */
	private array $rAdmin = ['id' => 1, 'member_group_id' => 1];

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users_packages', 'lines', 'lines_live', 'activation_codes'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`) VALUES (1, 'Month', 1, 10, 1, 'months', '[2]', '[]', '[1]', 1)");

		// An edit of a code ends with the signal every writer of a line sends.
		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'settings' => SettingsManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1]];
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach ([LineService::class, ConnectionTracker::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
	}

	protected function tearDown(): void {
		foreach ([LineService::class, ConnectionTracker::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		DatabaseFactory::reset();
		SettingsManager::set($this->rBefore['settings']);
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		unset($GLOBALS['db']);
	}

	/** One code with a line whose name and password its issuer chose. */
	private function generate(string $rPassword): array {
		return ActiveCodeService::generateCodes(['package_id' => 1, 'num_codes' => 1, 'streaming_username' => 'viewer1', 'streaming_password' => $rPassword], $this->rAdmin, true);
	}

	private function rows(string $rTable): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	public function testNoCodeIsGeneratedWithASeparatorInItsStreamingPassword(): void {
		$rResult = $this->generate('ab/cd');

		$this->assertSame('ERROR', $rResult['status']);
		$this->assertSame(0, $this->rows('lines'));
		$this->assertSame(0, $this->rows('activation_codes'));
	}

	public function testAnyOtherChosenPasswordIsStored(): void {
		$rResult = $this->generate('Ab.c-d_9!');

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame('Ab.c-d_9!', UserRepository::getLineByUsername('viewer1')['password']);
	}

	public function testAnEditDoesNotSetSuchAPassword(): void {
		$rCode = ActiveCodeService::getByCode($this->generate('abcd')['codes'][0]['code']);

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['password' => 'ab/cd'], $this->rAdmin, true);

		$this->assertSame('ERROR', $rResult['status']);
		$this->assertSame('abcd', UserRepository::getLineByUsername('viewer1')['password']);
	}

	/** The line of a generated code is named after the code when the code is renamed. */
	public function testARenamedCodeDoesNotGiveItsLineSuchAUsername(): void {
		$rGenerated = ActiveCodeService::generateCodes(['package_id' => 1, 'num_codes' => 1], $this->rAdmin, true)['codes'][0];
		$rCode = ActiveCodeService::getByCode($rGenerated['code']);

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['activation_code' => 'AB/CD'], $this->rAdmin, true);

		$this->assertSame('ERROR', $rResult['status']);
		$this->assertSame($rGenerated['username'], UserRepository::getLineById($rCode['subscriber_id'])['username']);
		$this->assertSame($rGenerated['code'], ActiveCodeService::getById((int) $rCode['id'])['activation_code']);
	}

	public function testAnyOtherNewCodeNamesItsLine(): void {
		$rCode = ActiveCodeService::getByCode(ActiveCodeService::generateCodes(['package_id' => 1, 'num_codes' => 1], $this->rAdmin, true)['codes'][0]['code']);

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['activation_code' => 'SPRING-26'], $this->rAdmin, true);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame('SPRING-26', UserRepository::getLineById($rCode['subscriber_id'])['username']);
	}
}
