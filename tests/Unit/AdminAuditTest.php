<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Audit\AdminAudit;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * The admin action trail (Core\Audit\AdminAudit): an entry keeps who, from
 * where, the action, its outcome as the answer states it, and only the
 * identifying fields of the request; every admin entry point records, and
 * nothing in the panel clears the trail.
 */
final class AdminAuditTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('admin_audit'));
		DatabaseFactory::set($this->rDb);
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		AdminAudit::reset();
		AdminAudit::enable(true);
	}

	protected function tearDown(): void {
		AdminAudit::enable(false);
		AdminAudit::reset();
		DatabaseFactory::reset();
	}

	/** @return list<array<string, mixed>> */
	private function entries(): array {
		$this->rDb->query('SELECT `user_id`, `username`, `ip`, `source`, `action`, `result`, `detail` FROM `admin_audit` ORDER BY `id`;');
		return $this->rDb->get_raw_rows();
	}

	/** Run one recorded request: start, the handler's answer, the end of the request. */
	private function request(string $rSource, string $rAction, array $rRequest, string $rAnswer, array $rNote = []): void {
		ob_start(); // what reaches the client, below the trail's buffer
		$rLevel = ob_get_level();
		AdminAudit::start($rSource, $rAction, ['id' => 3, 'username' => 'ops', 'password' => 'x'], $rRequest);
		$this->assertSame($rLevel + 1, ob_get_level());
		echo $rAnswer;
		AdminAudit::note($rNote);
		AdminAudit::finish();
		ob_end_clean();
		$this->assertSame($rAnswer, ob_get_clean(), 'the answer passes through unchanged');
	}

	public function testAnEntryKeepsOnlyWhatWasActedOn(): void {
		$rDetail = AdminAudit::detail(['sub' => 'delete', 'user_id' => 7, 'code_id' => '3', 'ids' => [1, 2, [3]], 'password' => 'secret', 'api_key' => 'k', 'code' => '123456', 'secret' => 'S', 'username' => str_repeat('u', 150), 'notes' => 'n', 'id' => '', 'valid_id_list' => 'x', 0 => 'raw']);
		$this->assertSame(['sub' => 'delete', 'user_id' => '7', 'code_id' => '3', 'ids' => '1,2', 'username' => str_repeat('u', 100)], $rDetail);
		$this->assertCount(20, AdminAudit::detail(array_combine(array_map(fn($i) => 'f' . $i . '_id', range(1, 30)), range(1, 30))), 'at most 20 fields');
	}

	public function testTheOutcomeIsWhatTheAnswerSays(): void {
		$this->assertSame(1, AdminAudit::outcome('{"result":true,"location":"lines"}'));
		$this->assertSame(0, AdminAudit::outcome(' {"result": false}'));
		$this->assertSame(1, AdminAudit::outcome('{"status":"STATUS_SUCCESS","data":[]}'));
		$this->assertSame(1, AdminAudit::outcome('{"status":"STATUS_SUCCESS_MULTI"}'));
		$this->assertSame(0, AdminAudit::outcome('{"status":"STATUS_NO_PERMISSIONS"}'));
		$this->assertNull(AdminAudit::outcome('id,username\n1,a'));
		$this->assertNull(AdminAudit::outcome(''));
	}

	public function testARequestIsWrittenWhenItEndsWithItsOutcome(): void {
		$this->request('panel', 'settings', ['edit' => 1, 'server_name' => 'P', 'password' => 'x'], '{"result":true}', ['changed' => 'server_name,api_legacy_keys']);
		$this->request('api', 'delete_line', ['id' => 9], '{"status":"STATUS_NO_PERMISSIONS"}');
		$this->request('panel', 'report', [], "id,username\n");

		$rEntries = $this->entries();
		$this->assertCount(3, $rEntries);
		$this->assertSame(['3', 'ops', '192.0.2.10', 'panel', 'settings', '1'], array_map('strval', array_slice(array_values($rEntries[0]), 0, 6)));
		$this->assertSame(['edit' => '1', 'server_name' => 'P', 'changed' => 'server_name,api_legacy_keys'], json_decode($rEntries[0]['detail'], true));
		$this->assertSame(['api', 'delete_line', 0], [$rEntries[1]['source'], $rEntries[1]['action'], (int) $rEntries[1]['result']]);
		$this->assertNull($rEntries[2]['result'], 'an answer that is not the panel\'s JSON');
		$this->assertNull($rEntries[2]['detail']);
		$this->assertStringNotContainsString('"x"', (string) json_encode($rEntries));
	}

	public function testOneEntryPerRequest(): void {
		AdminAudit::start('panel', 'line', ['id' => 3], []);
		AdminAudit::start('panel', 'line', ['id' => 3], []);
		ob_end_clean();
		AdminAudit::finish();
		AdminAudit::finish();
		$this->assertCount(1, $this->entries());
	}

	public function testEveryAdminEntryPointRecordsAndNothingClearsTheTrail(): void {
		foreach (['Public/Views/admin/post.php', 'Public/index.php', 'Public/Controllers/Api/AdminApiController.php', 'Public/Controllers/Api/ActiveCodeApiController.php'] as $rFile) {
			$this->assertStringContainsString('AdminAudit::start(', (string) file_get_contents(MAIN_HOME . $rFile), $rFile);
		}
		$rIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MAIN_HOME, FilesystemIterator::SKIP_DOTS));
		foreach ($rIterator as $rFile) {
			$rPath = $rFile->getPathname();
			if (!preg_match('/\.php$/', $rPath) || str_contains($rPath, '/vendor/')) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression('/(DELETE\s+FROM|TRUNCATE(\s+TABLE)?)\s+`?admin_audit/i', (string) file_get_contents($rPath), $rPath);
		}
	}
}
