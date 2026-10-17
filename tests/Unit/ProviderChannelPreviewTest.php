<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Domain\Stream\ProviderService;

final class ProviderChannelPreviewTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('providers_streams'));
		foreach ([[1, 'live', 10, 'News One'], [1, 'live', 11, 'News Two'], [1, 'live', 12, 'Sport <HD>'], [1, 'movie', 13, 'Movie'], [2, 'live', 14, 'Other Provider']] as [$rProvider, $rType, $rId, $rName]) {
			$this->rDb->query('INSERT INTO providers_streams (provider_id, type, stream_id, stream_display_name, category_array, channel_id) VALUES (?, ?, ?, ?, ?, ?)', $rProvider, $rType, $rId, $rName, '["Österreich & Sport"]', 'channel.' . $rId);
		}
	}

	private function request(array $rRequest, bool $rAllowed = true): array {
		$rCode = <<<'PHP'
require $argv[1];
$in = json_decode($argv[2], true);
$GLOBALS['db'] = new class (TestDb::connect($in['schema'])) extends \XcVm\Core\Database\DatabaseHandler {
    public function __construct(PDO $pdo) { $this->dbh = $pdo; }
};
$GLOBALS['rUserInfo'] = ['id' => 2, 'member_group_id' => 2];
$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => $in['allowed'] ? ['streams'] : ['epg']];
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
\XcVm\Core\Http\RequestManager::set($in['request']);
(new \XcVm\Public\Controllers\Admin\Ajax\ProviderAjaxController())->providerStreams();
PHP;
		[$rExit, $rOutput] = ProcessRunner::capture([PHP_BINARY, '-r', $rCode, dirname(__DIR__) . '/bootstrap.php', json_encode(['schema' => $this->rDb->schema(), 'allowed' => $rAllowed, 'request' => $rRequest + ['provider_id' => 1, 'draw' => 7]])]);
		$this->assertSame(0, $rExit, $rOutput);
		$rResult = json_decode($rOutput, true);
		$this->assertIsArray($rResult, $rOutput);
		return $rResult;
	}

	public function testPreviewIsScopedToTheProviderAndLiveChannels(): void {
		$rResult = $this->request([]);
		$this->assertSame(7, $rResult['draw']);
		$this->assertSame(3, $rResult['recordsTotal']);
		$this->assertSame([10, 11, 12], array_map('intval', array_column($rResult['data'], 'stream_id')));
		$this->assertSame('Sport <HD>', $rResult['data'][2]['stream_display_name'], 'the text renderer needs raw text, not pre-escaped HTML');
		$this->assertSame(['Österreich & Sport'], json_decode($rResult['data'][0]['category_array'], true));
		$this->assertSame(['stream_id', 'category_array', 'stream_display_name', 'modified', 'stream_icon', 'channel_id'], array_keys($rResult['data'][0]));
	}

	public function testSearchCountsAndPaginationRemainCorrect(): void {
		$rResult = $this->request(['search' => ['value' => 'News'], 'start' => 1, 'length' => 1]);
		$this->assertSame(3, $rResult['recordsTotal']);
		$this->assertSame(2, $rResult['recordsFiltered']);
		$this->assertSame([11], array_map('intval', array_column($rResult['data'], 'stream_id')));
		$this->assertSame(1, $this->request(['search' => ['value' => 'channel.12']])['recordsFiltered']);
		$this->assertSame([], $this->request(['search' => ['value' => "' OR 1=1 --"]])['data']);
	}

	public function testInvalidPaginationAndMalformedSearchDoNotBreakTheQuery(): void {
		$rResult = $this->request(['start' => -50, 'length' => -1, 'search' => 'not-an-array']);
		$this->assertCount(1, $rResult['data']);
		$this->assertSame(10, (int) $rResult['data'][0]['stream_id']);
	}

	public function testDeniedAndMissingProvidersReturnNoChannels(): void {
		foreach ([$this->request([], false), $this->request(['provider_id' => 0]), $this->request(['provider_id' => 999])] as $rResult) {
			$this->assertSame(7, $rResult['draw']);
			$this->assertSame(0, $rResult['recordsTotal']);
			$this->assertSame([], $rResult['data']);
		}
	}

	public function testPreviewBrowserInteractions(): void {
		[$rNodeExit] = ProcessRunner::capture(['node', '--version']);
		if ($rNodeExit !== 0) {
			$this->markTestSkipped('Node.js is required to exercise the provider preview.');
		}
		foreach (['provider-channel-preview.cjs', 'provider-add-source-check.cjs'] as $rScript) {
			[$rExit, $rOutput] = ProcessRunner::capture(['node', dirname(__DIR__) . '/Support/' . $rScript]);
			$this->assertSame(0, $rExit, $rOutput);
		}
	}

	public function testProbeResolvesOnlyTheSelectedProvidersSavedLiveSource(): void {
		$this->rDb->exec(InstallSchema::table('providers'));
		$this->rDb->query('INSERT INTO providers (id, ip, port, username, password, `ssl`, hls, legacy) VALUES (1, ?, 443, ?, ?, 1, 0, 1)', 'https://provider.test/', 'user/name', 'secret?&');
		$rProperty = new ReflectionProperty(ProviderService::class, 'db');
		$rPrevious = $rProperty->getValue();
		ProviderService::setDb($this->rDb);
		try {
			$this->assertSame('https://provider.test:443/live/user%2Fname/secret%3F%26/10.ts', ProviderService::liveSourceUrl(1, 10));
			$this->assertNull(ProviderService::liveSourceUrl(1, 14), 'a different providers channel must not be probed');
			$this->assertNull(ProviderService::liveSourceUrl(1, 13), 'the live preview must not resolve a movie');
			$this->assertNull(ProviderService::liveSourceUrl(0, 10));
			$this->rDb->query('UPDATE providers SET hls = 1 WHERE id = 1');
			$this->assertStringEndsWith('/10.m3u8', ProviderService::liveSourceUrl(1, 10));
			$this->rDb->query('UPDATE providers SET hls = 0, legacy = 0 WHERE id = 1');
			$this->assertStringEndsWith('/10', ProviderService::liveSourceUrl(1, 10));
		} finally {
			$rProperty->setValue(null, $rPrevious);
		}
	}
}
