<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;

/**
 * A form that is refused says why, in the words of the rule that refused it.
 *
 * A reseller's line is refused with one answer for its username (and one for
 * its password) by two rules: the minimum length of the reseller's group, and
 * the character a username or password cannot hold. The line form's text for
 * that answer names both.
 *
 * An install the panel refuses carries its reason (ServerService::install):
 * post.php answers the install form with the reason in words, and the form
 * shows it. An answer without one is shown as before.
 */
final class AuditFormRulesMessagesTest extends TestCase {
	/** The install form's answer to a new load balancer, up to the end of the request: post.php ends it itself. */
	private const POST = <<<'PHP'
<?php
namespace {
	$rIn = json_decode($argv[1], true);
	define('BIN_PATH', $rIn['dir'] . 'bin/');
	define('SERVER_ID', 1);
	require getenv('XCVM_TEST_BOOTSTRAP');
}

namespace XcVm\Domain\Server {
	// Nothing is started.
	function shell_exec(string $rCommand) {
		return null;
	}
}

namespace {
	foreach (\XcVm\Core\Config\ConstantsInitializer::statuses() as $rName => $rValue) {
		defined($rName) || define($rName, $rValue);
	}

	$db = new \TestDb();
	$db->exec(\XcVm\Tests\Support\InstallSchema::serversTable());
	foreach (['029_create_cluster_nodes', '032_create_cluster_audit', '035_add_cluster_epoch_eph', '039_add_cluster_node_root_ready', '041_add_cluster_node_features', '045_add_cluster_node_audit', '052_add_cluster_node_db_revoked_at'] as $rName) {
		$db->exec(\XcVm\Tests\Support\InstallSchema::migration($rName));
	}
	$db->exec("INSERT INTO `servers` (`id`, `server_name`, `server_ip`, `server_type`, `status`, `is_main`, `parent_id`) VALUES (1, 'main', '192.0.2.1', 0, 1, 1, '[]')");
	\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
	\XcVm\Core\Config\SettingsManager::set($rIn['settings']);
	// The texts are looked up in a directory of this test.
	\XcVm\Core\Localization\Translator::init($rIn['dir'] . 'lang');

	$rUserInfo = ['id' => 1, 'member_group_id' => 1];
	$rPermissions = ['is_admin' => 1, 'advanced' => []];
	// As post.php names the translator.
	$language = \XcVm\Core\Localization\Translator::class;
	$rData = ['type' => '2', 'server_name' => 'new', 'server_ip' => $rIn['server_ip'], 'ssh_port' => '22', 'root_username' => 'root', 'root_password' => 'secret'];

	// What is run is this repository's own source, with the imports of the file it is in.
	$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/post.php');
	preg_match_all('/^use [^;]+;$/m', $rSource, $rImports);
	if (!preg_match('/^\t+case \'server_install\':\n(.*?)^\t+case \'/ms', $rSource, $rCase)) {
		fwrite(STDERR, 'post.php has no server_install case');
		exit(1);
	}
	eval(implode("\n", $rImports[0]) . "\n" . $rCase[1]);
}
PHP;

	/** The install form of a new load balancer, without the layout around it. */
	private const FORM = <<<'PHP'
<?php
namespace XcVm\Core\Util {
	/** The view closes the layout itself: there is none around it here. */
	final class LayoutRenderer {
		public static function renderFooter(string $rScope = 'admin', array $rVars = []): void {
		}
	}
}

namespace {
	/** The view's `$language`: every text is its key. */
	final class AuditFormRulesMessagesKeys {
		public static function get(string $rKey, array $rReplace = []): string {
			return $rKey;
		}
	}

	require getenv('XCVM_TEST_BOOTSTRAP');
	\XcVm\Core\Config\SettingsManager::set([]);
	$language = AuditFormRulesMessagesKeys::class;
	$rType = 2;

	require MAIN_HOME . 'Public/Views/admin/server_install.php';
}
PHP;

	/**
	 * Runs the script of the page named first, submits the install form and
	 * answers the request with the text named second: what the page then
	 * tells the administrator, and where it goes.
	 */
	private const SUBMIT = <<<'JS'
		const [page, answer] = process.argv.slice(-2);
		const html = require('fs').readFileSync(page, 'utf8');
		const script = html.slice(html.lastIndexOf('<script>') + 8, html.lastIndexOf('</script>'));

		const told = [];
		let submit = null;
		const form = {
			addEventListener: (name, handler) => { submit = handler; },
			querySelector: () => ({ disabled: false })
		};
		const window = {
			jQuery: () => ({ on() {}, each() {} }),
			xcToast: (text, type) => told.push([text, type]),
			location: null
		};
		const sandbox = {
			window,
			document: { getElementById: (id) => (id === 'install-form' ? form : null), querySelectorAll: () => [] },
			FormData: function () {},
			fetch: () => Promise.resolve({ text: () => Promise.resolve(answer) })
		};

		const vm = require('vm');
		vm.createContext(sandbox);
		vm.runInContext(script, sandbox);
		submit({ preventDefault() {} });
		setTimeout(() => process.stdout.write(JSON.stringify({ told, location: window.location })), 0);
		JS;

	private string $rDir;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDir = sys_get_temp_dir() . '/xcvm-form-rules-messages-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'lang', 0700, true);
		file_put_contents($this->rDir . 'lang/en.ini', 'cluster_mode_redis_handler = "Mode 2 is not available while the Redis handler is on."' . "\n");
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Run a script of this test in a child PHP.
	 *
	 * @param array<string, mixed> $rIn
	 */
	private function child(string $rScript, array $rIn = []): string {
		file_put_contents($this->rDir . 'run.php', $rScript);
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', '-d', 'short_open_tag=1', $this->rDir . 'run.php', (string) json_encode($rIn + ['dir' => $this->rDir])], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rDir, ['XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'PATH' => (string) getenv('PATH')] + TestDb::env());
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);
		$this->assertSame('', $rErr);
		return $rOut;
	}

	// ── a reseller's line ───────────────────────────────────────────

	/**
	 * The line form's text for each answer, for a reseller whose group asks
	 * for six characters in a username and eight in a password. What is run
	 * is the view's own source; a text of the translator is its key, followed
	 * by what the view has it fill in.
	 *
	 * @return array<int, string>
	 */
	private function lineFormTexts(): array {
		$this->assertSame(1, preg_match('/^\$rStatusMessages = \[\n.*?^\];$/ms', (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/line.php'), $rMap));

		$rPermissions = ['minimum_username_length' => 6, 'minimum_password_length' => 8];
		$language = new class() {
			public static function get(string $rKey, array $rReplace = []): string {
				return $rKey . ' ' . json_encode($rReplace);
			}
		};
		eval($rMap[0]);

		return $rStatusMessages;
	}

	public function testARefusedUsernameOrPasswordIsToldBothRulesOfItsAnswer(): void {
		$rTexts = $this->lineFormTexts();

		$this->assertSame('line_username_rule {"{num}":"6"}', $rTexts[STATUS_INVALID_USERNAME]);
		$this->assertSame('line_password_rule {"{num}":"8"}', $rTexts[STATUS_INVALID_PASSWORD]);
	}

	/**
	 * Both texts are in en.ini, each with the place the view fills the minimum
	 * into: a key that is missing is shown to the reseller as the key itself.
	 * en.ini only: the other languages are generated from it.
	 */
	public function testBothRuleTextsAreInTheEnglishLanguageFile(): void {
		$rEn = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');

		foreach (['line_username_rule', 'line_password_rule'] as $rKey) {
			$this->assertSame(1, preg_match('/^' . $rKey . ' = "[^"]*\{num\}[^"]*"$/m', $rEn), 'en.ini has no ' . $rKey . ' with {num} in it');
		}
	}

	public function testTheOtherAnswersOfTheLineFormKeepTheirTexts(): void {
		$rTexts = $this->lineFormTexts();

		$this->assertSame('The username you selected already exists. Please use another.', $rTexts[STATUS_EXISTS_USERNAME]);
		$this->assertSame('Please select a valid package.', $rTexts[STATUS_INVALID_PACKAGE]);
	}

	// ── the install form ────────────────────────────────────────────

	/**
	 * post.php's answer to the install form.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array<string, mixed>
	 */
	private function installAnswer(array $rSettings, string $rIp = '192.0.2.20'): array {
		$rAnswer = json_decode($this->child(self::POST, ['settings' => $rSettings, 'server_ip' => $rIp]), true);
		$this->assertIsArray($rAnswer);
		return $rAnswer;
	}

	public function testARefusedInstallIsAnsweredWithItsReasonInWords(): void {
		$rAnswer = $this->installAnswer(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api', 'redis_handler' => 1]);

		$this->assertFalse($rAnswer['result']);
		$this->assertSame(STATUS_FAILURE, $rAnswer['status']);
		$this->assertSame('Mode 2 is not available while the Redis handler is on.', $rAnswer['message'] ?? null);
	}

	public function testAnAnswerWithoutAReasonCarriesNone(): void {
		$rAnswer = $this->installAnswer(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api', 'redis_handler' => 1], 'not an address');

		$this->assertFalse($rAnswer['result']);
		$this->assertSame(STATUS_INVALID_IP, $rAnswer['status']);
		$this->assertNull($rAnswer['message'] ?? null);
	}

	/** @return array<string, array{0: string, 1: list<array{0: string, 1: string}>, 2: string|null}> the answer, what the form tells the administrator, and where it goes */
	public static function installAnswers(): array {
		return [
			'a refusal with its reason' => ['{"result":false,"data":{},"status":2,"message":"Mode 2 is not available."}', [['Mode 2 is not available.', 'error']], null],
			'a failure without a reason' => ['{"result":false,"data":{},"status":2,"message":null}', [['error_occured', 'error']], null],
			'a failure of an older shape' => ['{"result":false,"data":{},"status":41}', [['error_occured', 'error']], null],
			'no answer that can be read' => ['<html>', [['error_occured', 'error']], null],
			'null' => ['null', [['error_occured', 'error']], null],
			'an install that started' => ['{"result":true,"location":"server_view?id=12&status=1","status":1}', [], 'server_view?id=12&status=1'],
		];
	}

	/** @param list<array{0: string, 1: string}> $rTold */
	#[DataProvider('installAnswers')]
	public function testTheInstallFormShowsTheReasonAnAnswerCarries(string $rAnswer, array $rTold, ?string $rLocation): void {
		if (trim((string) shell_exec('command -v node')) === '') {
			$this->markTestSkipped('the page script runs in node');
		}

		$rPage = $this->child(self::FORM);
		$this->assertStringContainsString('id="install-form"', $rPage);
		file_put_contents($this->rDir . 'page.html', $rPage);

		$rProc = proc_open(['node', '-e', self::SUBMIT, '--', $this->rDir . 'page.html', $rAnswer], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);

		$this->assertSame(['told' => $rTold, 'location' => $rLocation], json_decode($rOut, true), $rOut . $rErr);
	}
}
