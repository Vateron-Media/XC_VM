<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;

/**
 * A line's username and its password hold no slash, and the administrator's
 * line, MAG and Enigma2 forms are each refused a save that sets one that
 * does. The save answers with the status of the refusal, and the form says
 * which of the two rules it was; any other refusal, and a request that fails,
 * keep the general message.
 */
final class AuditAdminMisc2FormAnswersTest extends TestCase {
	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}
	}

	/** @return array<string, array{0: string}> */
	public static function forms(): array {
		return ['the line form' => ['line'], 'the MAG form' => ['mag'], 'the Enigma2 form' => ['enigma']];
	}

	#[DataProvider('forms')]
	public function testTheFormSaysWhichRuleARefusedUsernameOrPasswordBroke(string $rForm): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/' . $rForm . '.php');

		// The texts of the two refusals, as the page writes them into its script, in English.
		$this->assertSame(1, preg_match('/^\s*var statusText = <\?= (json_encode\(.+\)); \?>;$/m', $rSource, $rMap), 'the page has the texts of the refusals');
		$language = new class () {
			public static function get(string $rKey): string {
				return parse_ini_file(MAIN_HOME . 'Core/Localization/lang/en.ini', false, INI_SCANNER_RAW)[$rKey] ?? $rKey;
			}
		};
		// The expression is this repository's own source, matched above: no input reaches it.
		$rTexts = json_decode((string) eval('return ' . $rMap[1] . ';'), true);
		$this->assertSame([
			STATUS_INVALID_USERNAME => 'The username cannot contain a slash (/).',
			STATUS_INVALID_PASSWORD => 'The password cannot contain a slash (/).',
		], $rTexts);

		// The handler of the save's answer, and the one of a request that fails.
		$this->assertSame(1, preg_match('/\.then\(function\(txt\) \{(.*?)\n\s*\}\)\n\s*\.catch\(function\(\) \{(.*?)\n\s*\}\);/s', $rSource, $rHandlers), 'the form has one answer handler');
		$this->assertStringContainsString("xcToast((dt && statusText[dt.status]) || errText, 'error');", $rHandlers[1]);
		$this->assertStringNotContainsString("xcToast(errText, 'error');", $rHandlers[1]);
		$this->assertStringContainsString("xcToast(errText, 'error');", $rHandlers[2]);
	}

	/** The save's answer carries the status the form reads. */
	#[DataProvider('forms')]
	public function testARefusedSaveAnswersWithItsStatus(string $rForm): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/post.php');

		$this->assertSame(1, preg_match('/\n\t+case \'' . $rForm . '\':\n(.*?)\n\t+case \'/s', $rSource, $rCase));
		$this->assertStringContainsString("echo json_encode(array('result' => false, 'data' => \$rReturn['data'], 'status' => \$rReturn['status']));", $rCase[1]);
	}
}
