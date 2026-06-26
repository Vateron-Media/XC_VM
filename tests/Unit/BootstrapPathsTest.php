<?php

use PHPUnit\Framework\TestCase;

/**
 * Runtime guard for Фаза 1: after the 7 top-level dirs were renamed to PascalCase
 * (core→Core, domain→Domain, ...), no live require/include may still point at a
 * lowercase directory name. PHPStan cannot catch these (a constant concatenated
 * with a string literal), so they would only fault at runtime on a deployed box.
 *
 * resources/config/content/signals stay lowercase by design and are NOT checked.
 */
final class BootstrapPathsTest extends TestCase {
	/** The 7 renamed directories — their lowercase form must never appear in a require path. */
	private const RENAMED = ['core', 'domain', 'infrastructure', 'streaming', 'modules', 'cli', 'public'];

	public function testNoLiveRequireUsesALowercaseRenamedDir(): void {
		$pattern = '#(?:require|include)(?:_once)?\b[^;]*[\'"](' . implode('|', self::RENAMED) . ')/#';
		$violations = [];

		foreach ($this->sourceFiles() as $file) {
			foreach (file($file) as $no => $line) {
				$trimmed = ltrim($line);
				if ($trimmed === '' || $trimmed[0] === '*' || str_starts_with($trimmed, '//')
					|| str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*')) {
					continue;
				}
				if (preg_match($pattern, $line, $m)) {
					$violations[] = substr($file, strlen(MAIN_HOME)) . ':' . ($no + 1)
						. " → '{$m[1]}/'  " . trim($line);
				}
			}
		}

		$this->assertSame(
			[],
			$violations,
			"Lowercase require/include paths to renamed dirs:\n" . implode("\n", $violations)
		);
	}

	/** @return iterable<string> absolute paths of every .php under src/ except vendor/tmp/backups. */
	private function sourceFiles(): iterable {
		$it = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator(MAIN_HOME, FilesystemIterator::SKIP_DOTS),
				static function (SplFileInfo $current): bool {
					$name = $current->getFilename();
					if ($current->isDir()) {
						return !in_array($name, ['vendor', 'tmp', 'backups'], true);
					}
					return str_ends_with($name, '.php');
				}
			)
		);
		foreach ($it as $file) {
			yield $file->getPathname();
		}
	}
}
