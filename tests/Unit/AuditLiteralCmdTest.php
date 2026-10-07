<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ServerDiagnoseCommand;
use XcVm\Cli\Commands\StartupCommand;
use XcVm\Cli\CronJobs\RootSignalsCronJob;

/**
 * The commands root runs to install its crontab and to flush the panel's
 * blocks are fixed: each is one string literal, and nothing of it is put
 * together when it runs. What changes from one run to the next (the crontab's
 * new list, the rules to remove) reaches the command on its standard input,
 * and the choice between iptables and ip6tables is a choice between two
 * literals.
 */
final class AuditLiteralCmdTest extends TestCase {
	/** The functions that start a command. */
	private const STARTERS = ['exec', 'shell_exec', 'popen', 'proc_open', 'passthru', 'system'];

	/** @return array<string, array{0: class-string, 1: string}> */
	public static function methods(): array {
		return [
			'the install of root\'s crontab' => [StartupCommand::class, 'installRootCrontab'],
			'the flush of the panel\'s blocks' => [RootSignalsCronJob::class, 'unblockAll'],
			'the removal of the blocks in one commit' => [RootSignalsCronJob::class, 'unblockTogether'],
			'the sync of the blocks into ipset' => [RootSignalsCronJob::class, 'syncSets'],
			'the refill of a set' => [RootSignalsCronJob::class, 'restoreSet'],
			'a block rule by rule' => [RootSignalsCronJob::class, 'blockip'],
			'the look for an address in a set' => [ServerDiagnoseCommand::class, 'iptablesBlocks'],
		];
	}

	/**
	 * The first argument of every command the method starts, as it is written.
	 *
	 * @return list<string>
	 */
	private static function commands(ReflectionMethod $rMethod): array {
		$rTokens = array_values(array_filter(
			token_get_all((string) file_get_contents((string) $rMethod->getFileName())),
			static fn(array|string $rToken): bool => !is_array($rToken) || !in_array($rToken[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
		));
		$rCommands = [];
		foreach ($rTokens as $i => $rToken) {
			if (!is_array($rToken) || $rToken[0] !== T_STRING || $rToken[2] < $rMethod->getStartLine() || $rToken[2] > $rMethod->getEndLine()) {
				continue;
			}
			$rBefore = $rTokens[$i - 1];
			if (!in_array(strtolower($rToken[1]), self::STARTERS, true) || $rTokens[$i + 1] !== '(' || (is_array($rBefore) && in_array($rBefore[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true))) {
				continue;
			}
			// The argument, up to the comma or the bracket that ends it.
			$rArgument = '';
			$rDepth = 0;
			for ($j = $i + 2; isset($rTokens[$j]); $j++) {
				$rText = is_array($rTokens[$j]) ? $rTokens[$j][1] : $rTokens[$j];
				if ($rDepth === 0 && in_array($rText, [',', ')'], true)) {
					break;
				}
				$rDepth += in_array($rText, ['(', '['], true) ? 1 : (in_array($rText, [')', ']'], true) ? -1 : 0);
				$rArgument .= is_array($rTokens[$j]) && $rTokens[$j][0] === T_CONSTANT_ENCAPSED_STRING && $rArgument === '' ? $rText : ' ' . $rText;
			}
			$rCommands[] = $rArgument;
		}
		return $rCommands;
	}

	/** @dataProvider methods */
	public function testEveryCommandIsOneStringLiteral(string $rClass, string $rName): void {
		$rCommands = self::commands(new ReflectionMethod($rClass, $rName));

		$this->assertNotSame([], $rCommands, 'the method starts a command');
		$this->assertSame([], array_values(preg_grep('/^\'[^\'\\\\]*\'$/', $rCommands, PREG_GREP_INVERT) ?: []), 'a command with a part that is not a literal');
	}

	/** @dataProvider methods */
	public function testNoCommandIsExemptedFromTheStaticCheck(string $rClass, string $rName): void {
		$rMethod = new ReflectionMethod($rClass, $rName);
		$rLines = array_slice((array) file((string) $rMethod->getFileName()), $rMethod->getStartLine() - 1, $rMethod->getEndLine() - $rMethod->getStartLine() + 1);

		$this->assertSame([], array_values(preg_grep('/nosemgrep/', $rLines) ?: []));
	}
}
