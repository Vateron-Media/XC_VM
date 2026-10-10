<?php

use PHPUnit\Framework\TestCase;

/**
 * A refused activation code at the web player's sign-in is counted as a
 * guess, as a refused password there and a refused code on the code API are:
 * it was the one sign-in that could be tried without limit.
 */
final class PlayerCodeLoginGuessesTest extends TestCase {
	public function testARefusedCodeIsCounted(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/PlayerV2/PlayerLoginController.php');
		$rStart = strpos($rSource, 'private function processCodeLogin(');
		$this->assertNotFalse($rStart);
		$rRefusal = substr($rSource, $rStart, (int) strpos($rSource, "\$line = \$res['line']", $rStart) - $rStart);
		$this->assertStringContainsString('BruteforceGuard::checkBruteforce(null, null, $code);', $rRefusal);
		$this->assertStringContainsString('BruteforceGuard::checkFlood();', $rRefusal);
	}
}
