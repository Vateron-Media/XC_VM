<?php

use PHPUnit\Framework\TestCase;

/**
 * A docblock that says a contract is "pinned by FooTest" sends the reader to
 * that test; HeartbeatService cited a WatchdogDataContractTest that never
 * existed. Every `FooTest` (or `FooTest::testMethod`) named under src/ is a
 * test class under tests/ (and that method of it).
 */
final class SourceTestCitationsTest extends TestCase {

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testEveryCitedTestExists(): void {
		$rRoot = dirname(__DIR__, 2);
		$rTests = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rRoot . '/tests', FilesystemIterator::SKIP_DOTS)) as $rFile) {
			if (str_ends_with($rFile->getFilename(), 'Test.php')) {
				$rTests[$rFile->getBasename('.php')] = $rFile->getPathname();
			}
		}
		$rCited = 0;
		$rIterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($rRoot . '/src', FilesystemIterator::SKIP_DOTS),
			fn (SplFileInfo $rFile): bool => $rFile->getFilename() !== 'vendor'
		));
		foreach ($rIterator as $rFile) {
			if ($rFile->getExtension() !== 'php') {
				continue;
			}
			preg_match_all('/\b([A-Z][A-Za-z0-9]+Test)\b(?:::(\w+))?/', (string) file_get_contents($rFile->getPathname()), $rMatches, PREG_SET_ORDER);
			$rName = substr($rFile->getPathname(), strlen($rRoot) + 1);
			foreach ($rMatches as $rMatch) {
				$rCited++;
				$this->assertArrayHasKey($rMatch[1], $rTests, $rName . ' cites ' . $rMatch[1]);
				if (isset($rMatch[2])) {
					$this->assertMatchesRegularExpression('/function ' . preg_quote($rMatch[2], '/') . '\(/', (string) file_get_contents($rTests[$rMatch[1]]), $rName . ' cites ' . $rMatch[0]);
				}
			}
		}
		$this->assertGreaterThan(0, $rCited, 'src/ cites tests');
	}
}
