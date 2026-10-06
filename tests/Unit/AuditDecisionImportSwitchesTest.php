<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;

/**
 * An M3U import has two choices for a channel the panel already holds, and
 * the stream import form offers each as a switch, off until ticked: "Add
 * Source as Backup" gives the stream of that name the playlist's source as
 * one more of its own, and "Update Existing" overwrites the stream that has
 * the source with the playlist's title. The form that adds one stream has
 * neither.
 *
 * The form is a template, and an import answers through the admin globals and
 * the uploaded file: each runs in a child PHP against its own empty database,
 * and an import is posted what the rendered form posts for its switch.
 */
final class AuditDecisionImportSwitchesTest extends TestCase {
	/** The stream form, without the layout around it; every text is its language key. */
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
	final class AuditDecisionImportSwitchesKeys {
		public static function get(string $rKey, array $rReplace = []): string {
			return $rKey;
		}
	}

	require %BOOTSTRAP%;

	$db = new TestDb();
	foreach (['streams_categories', 'bouquets'] as $rTable) {
		$db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));
	}
	\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
	\XcVm\Core\Http\RequestManager::set(json_decode($argv[1], true));

	// What StreamController::index() hands the view for a stream that is not being edited.
	$language = AuditDecisionImportSwitchesKeys::class;
	$rStream = $rStreamOptions = $rStreamSys = null;
	$rSettings = $rServers = $rEPGSources = $rStreamArguments = $rTranscodeProfiles = $rOnDemand = $rServerTree = [];
	$rEPGJS = [[]];
	$rMobile = false;

	require MAIN_HOME . 'Public/Views/admin/stream.php';
}
PHP;

	/** An M3U import as the form's save hands it to the service, on a panel that holds the live stream "BBC One". */
	private const IMPORT = <<<'PHP'
<?php
require %BOOTSTRAP%;
error_reporting(E_ERROR | E_PARSE);
foreach (\XcVm\Core\Config\ConstantsInitializer::statuses() as $rName => $rValue) {
	defined($rName) || define($rName, $rValue);
}

$rIn = json_decode($argv[1], true);
$db = new TestDb();
foreach (['streams', 'streams_options', 'streams_servers', 'bouquets'] as $rTable) {
	$db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));
}
$db->query('INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`) VALUES (1, 1, ?, ?);', 'BBC One', json_encode(['http://panel.example/one']));
\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
$rUserInfo = ['id' => 1, 'member_group_id' => 1];
$rPermissions = ['is_admin' => 1, 'advanced' => []];
$rSettings = ['download_images' => 0];
\XcVm\Core\Config\SettingsManager::set($rSettings);
$_FILES['m3u_file'] = ['name' => 'channels.m3u', 'tmp_name' => $rIn['file']];

$rReturn = \XcVm\Domain\Stream\StreamService::process($rIn['post']);

$rStreams = [];
$db->query('SELECT `id`, `stream_display_name`, `stream_source` FROM `streams` ORDER BY `id`;');
foreach ($db->get_rows() as $rRow) {
	$rStreams[$rRow['id'] . ' ' . $rRow['stream_display_name']] = json_decode($rRow['stream_source'], true);
}
echo json_encode(['status' => $rReturn['status'], 'streams' => $rStreams]);
PHP;

	private string $rDir;

	/** @var array<string, DOMXPath> The forms rendered so far, by query string. */
	private array $rForms = [];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-import-switches-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
		foreach (['form' => self::FORM, 'import' => self::IMPORT] as $rName => $rCode) {
			file_put_contents($this->rDir . $rName . '.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), $rCode));
		}
	}

	protected function tearDown(): void {
		array_map('unlink', glob($this->rDir . '*') ?: []);
		rmdir($this->rDir);
	}

	/**
	 * The two switches, by the field of the import they post: the language
	 * key of the label, and the one of the help text.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function switches(): array {
		return [
			'Add Source as Backup' => ['add_source_as_backup', 'add_source_as_backup', 'if_an_identical_stream_name_tooltip'],
			'Update Existing' => ['update_existing', 'update_existing', 'if_the_source_exists_overwrite_tooltip'],
		];
	}

	/** What the child script $rName prints when it is handed $rIn. */
	private function child(string $rName, array $rIn): string {
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rDir . $rName . '.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rName);

		return $rOut;
	}

	/**
	 * The stream form as the panel renders it for the query string $rQuery.
	 *
	 * @param array<string, string> $rQuery
	 */
	private function form(array $rQuery): DOMXPath {
		$rKey = http_build_query($rQuery);
		if (!isset($this->rForms[$rKey])) {
			$rOut = $this->child('form', $rQuery);
			$this->assertStringContainsString('</html>', $rOut, 'the form was rendered to its end');

			$rDocument = new DOMDocument();
			$rErrors = libxml_use_internal_errors(true);
			$rDocument->loadHTML('<?xml encoding="UTF-8">' . $rOut);
			libxml_clear_errors();
			libxml_use_internal_errors($rErrors);
			$this->rForms[$rKey] = new DOMXPath($rDocument);
		}

		return $this->rForms[$rKey];
	}

	/** The import form's switch that posts $rName: the form has one. */
	private function switchNamed(string $rName): DOMElement {
		$rInputs = $this->form(['import' => ''])->query('//form[@id="stream-form"]//input[@name="' . $rName . '"]');
		$this->assertSame(1, $rInputs->length, 'the import form has one field named ' . $rName);
		$rInput = $rInputs->item(0);
		$this->assertInstanceOf(DOMElement::class, $rInput);
		$this->assertSame('checkbox', $rInput->getAttribute('type'), $rName);

		return $rInput;
	}

	/**
	 * What the import form posts for the switch $rName: nothing while it is
	 * off, and its name with its value once it is on, as a browser sends a
	 * checkbox.
	 *
	 * @return array<string, string>
	 */
	private function posted(string $rName, bool $rTicked): array {
		$rInput = $this->switchNamed($rName);
		if (!$rTicked && !$rInput->hasAttribute('checked')) {
			return [];
		}

		return [$rName => $rInput->hasAttribute('value') ? $rInput->getAttribute('value') : 'on'];
	}

	/**
	 * Import the playlist $rPlaylist ([name, source] each) with the fields
	 * $rPost and no server chosen.
	 *
	 * @param list<array{0: string, 1: string}> $rPlaylist
	 * @param array<string, string> $rPost
	 * @return array{status: int, streams: array<string, list<string>>} The answer, and the sources of every stream after it by "id name".
	 */
	private function import(array $rPlaylist, array $rPost): array {
		$rLines = ['#EXTM3U'];
		foreach ($rPlaylist as [$rName, $rSource]) {
			array_push($rLines, '#EXTINF:-1,' . $rName, $rSource);
		}
		file_put_contents($this->rDir . 'channels.m3u', implode("\n", $rLines) . "\n");

		$rOut = $this->child('import', ['file' => $this->rDir . 'channels.m3u', 'post' => $rPost + ['server_tree_data' => '[]']]);
		$rAnswer = json_decode($rOut, true);
		$this->assertIsArray($rAnswer, $rOut);

		return $rAnswer;
	}

	#[DataProvider('switches')]
	public function testTheImportFormOffersTheSwitchOff(string $rName, string $rLabel, string $rHelp): void {
		$rInput = $this->switchNamed($rName);
		$this->assertFalse($rInput->hasAttribute('checked'), 'off until ticked');
		$this->assertFalse($rInput->hasAttribute('disabled'));

		// Its label names it, and carries the help text the panel has for it.
		$rForm = $this->form(['import' => '']);
		$rLabels = $rForm->query('//form[@id="stream-form"]//label[@for="' . $rInput->getAttribute('id') . '"]');
		$this->assertSame(1, $rLabels->length, 'the switch has one label');
		$this->assertSame($rLabel, trim((string) $rLabels->item(0)->textContent));
		$this->assertSame($rHelp, $rForm->evaluate('string(.//*[@title]/@title)', $rLabels->item(0)));
		$this->assertArrayHasKey($rHelp, (array) parse_ini_file(MAIN_HOME . 'Core/Localization/lang/en.ini', false, INI_SCANNER_RAW));
	}

	public function testTheFormThatAddsOneStreamHasNeitherSwitch(): void {
		$rForm = $this->form([]);
		$this->assertSame(1, $rForm->query('//form[@id="stream-form"]//input[@name="stream_display_name"]')->length, 'the form that adds one stream');

		foreach (self::switches() as [$rName]) {
			$this->assertSame(0, $rForm->query('//input[@name="' . $rName . '"]')->length, $rName);
		}
	}

	public function testAddSourceAsBackupGivesTheStreamOfThatNameTheSource(): void {
		// The playlist's channel has the name of a stream the panel holds, and a source that is new.
		$rPlaylist = [['BBC One', 'http://provider.example/one']];
		$rSuccess = ConstantsInitializer::statuses()['STATUS_SUCCESS'];

		$rAnswer = $this->import($rPlaylist, $this->posted('add_source_as_backup', false));
		$this->assertSame($rSuccess, $rAnswer['status']);
		$this->assertSame(['1 BBC One' => ['http://panel.example/one'], '2 BBC One' => ['http://provider.example/one']], $rAnswer['streams'], 'as the form opens: a stream of its own');

		$rAnswer = $this->import($rPlaylist, $this->posted('add_source_as_backup', true));
		$this->assertSame($rSuccess, $rAnswer['status']);
		$this->assertSame(['1 BBC One' => ['http://panel.example/one', 'http://provider.example/one']], $rAnswer['streams'], 'ticked: one more source of the stream');
	}

	public function testUpdateExistingOverwritesTheStreamThatHasTheSource(): void {
		// The playlist's channel has the source of a stream the panel holds, under another title.
		$rPlaylist = [['BBC One HD', 'http://panel.example/one']];

		$rAnswer = $this->import($rPlaylist, $this->posted('update_existing', false));
		$this->assertSame(ConstantsInitializer::statuses()['STATUS_NO_SOURCES'], $rAnswer['status']);
		$this->assertSame(['1 BBC One' => ['http://panel.example/one']], $rAnswer['streams'], 'as the form opens: the stream is left as it was');

		$rAnswer = $this->import($rPlaylist, $this->posted('update_existing', true));
		$this->assertSame(ConstantsInitializer::statuses()['STATUS_SUCCESS'], $rAnswer['status']);
		$this->assertSame(['1 BBC One HD' => ['http://panel.example/one']], $rAnswer['streams'], 'ticked: the stream takes the title of the playlist');
	}
}
