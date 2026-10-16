<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

final class ResellerPackageBouquetsTest extends TestCase {
	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		foreach (['users', 'users_packages', 'bouquets'] as $table) {
			$this->db->exec(InstallSchema::table($table));
		}
		$this->db->exec("INSERT INTO users (id, owner_id, username, allowed_bouquets, override_packages) VALUES (5, 0, 'actor', '[2,3]', '{}'), (6, 5, 'child', '[3]', '{}')");
		$this->db->exec("INSERT INTO users_packages (id, bouquets, official_credits, official_duration, official_duration_in, trial_credits, trial_duration, trial_duration_in, check_compatible) VALUES (1, '[1,2,3]', 1, 1, 'months', 0, 1, 'days', 0)");
		$this->db->exec("INSERT INTO bouquets (id, bouquet_name, bouquet_channels, bouquet_radios, bouquet_movies, bouquet_series) VALUES (1, 'Extra', '[]', '[]', '[]', '[]'), (2, 'Basic', '[]', '[]', '[]', '[]'), (3, 'Sports', '[]', '[]', '[]', '[]')");
	}

	public static function actions(): array {
		return [['get_package', null, [2, 3]], ['get_package_trial', null, [2, 3]], ['get_package', 6, [3]], ['get_package_trial', 6, [3]]];
	}

	#[DataProvider('actions')]
	public function testPreviewUsesThePassedResellerAndTargetOwner(string $action, ?int $owner, array $expected): void {
		$code = <<<'PHP'
final class XC_VM { public static function db_connect(bool $migrate = false) { return TestDb::connect($GLOBALS['input']['schema']); } }
require %BOOTSTRAP%;
$input = json_decode($argv[1], true);
\XcVm\Infrastructure\Database\DatabaseFactory::connect();
$db = \XcVm\Infrastructure\Database\DatabaseFactory::get();
$db->query('SELECT * FROM users WHERE id = 5');
$actor = $db->get_row();
$rPermissions = ['all_reports' => [6]];
$actor['reports'] = [5, 6];
\XcVm\Core\Http\RequestManager::set(['package_id' => 1] + ($input['owner'] ? ['member_id' => $input['owner']] : []));
\XcVm\Tests\Support\ResellerAjax::call($input['action'], $actor, $rPermissions);
PHP;
		$code = str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), $code);
		$input = json_encode(['schema' => $this->db->schema(), 'action' => $action, 'owner' => $owner]);
		$process = proc_open([...xcvm_test_child_php(), '-r', $code, $input], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $output . $error);
		$this->assertSame('', $error);
		$answer = json_decode($output, true);
		$this->assertIsArray($answer, $output);
		$this->assertTrue($answer['result']);
		$this->assertSame($expected, json_decode($answer['data']['bouquets'], true));
		$this->assertSame($expected, array_map('intval', array_column($answer['bouquets'], 'id')));
	}
}
