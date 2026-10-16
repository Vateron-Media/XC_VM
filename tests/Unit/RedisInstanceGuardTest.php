<?php

use PHPUnit\Framework\TestCase;

/**
 * RedisManager::instance() returns null when Redis is unreachable (down, or in
 * the connect backoff), so a bare instance()->method() call fatals then. These
 * two sites used to do exactly that — a 500 on the reseller "kill connection"
 * action and an uncaught TypeError (past the RedisException catch) in the
 * signals daemon. They must take instance() into a variable and guard it, like
 * the rest of the codebase (StoredConnections, ConnectionIngest, …).
 */
final class RedisInstanceGuardTest extends TestCase {

	/** @return array<string, array{0: string}> */
	public static function files(): array {
		return [
			'reseller line ajax' => ['Public/Controllers/Reseller/Ajax/LineAjaxController.php'],
			'signals daemon' => ['Cli/Commands/SignalsCommand.php'],
		];
	}

	/** @dataProvider files */
	public function testNoUnguardedInstanceMethodCall(string $rRel): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . $rRel);
		$this->assertDoesNotMatchRegularExpression(
			'/RedisManager::instance\(\)\s*->/',
			$rSrc,
			$rRel . ' calls a method straight on RedisManager::instance() (null when Redis is down) — capture it in a variable and guard for null first'
		);
	}
}
