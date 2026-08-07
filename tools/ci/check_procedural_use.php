<?php

/**
 * CI gate (plan: «процедурные файлы имеют use для мигрированных классов слоя»).
 *
 * Procedural / view / entry-point files are NOT namespaced. When they reference a
 * migrated class by its short name (`SettingsManager::x()`, `new UserRepository`)
 * they must import it with `use XcVm\...\SettingsManager;` — otherwise the short
 * name resolves to the (now non-existent) global class and faults at runtime.
 * PHPStan does not report most of these files (scanDirectories), so this script
 * is the regression guard.
 *
 * Exit 0 = clean. Exit 1 = at least one procedural file uses a migrated class by
 * short name without importing it.
 */

$root = dirname(__DIR__, 2) . '/src/';
$skipDir = ['/vendor/', '/tmp/', '/backups/', '/Infrastructure/Tmdb/lib/'];

/** Recursively yield every .php under src/, skipping vendored/runtime trees. */
function phpFiles(string $root, array $skipDir): Generator {
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($it as $f) {
		if (substr($f->getFilename(), -4) !== '.php') {
			continue;
		}
		$p = str_replace('\\', '/', $f->getPathname());
		foreach ($skipDir as $s) {
			if (strpos($p, $s) !== false) {
				continue 2;
			}
		}
		yield $f->getPathname();
	}
}

// 1) Build short-name -> FQCN map of every migrated (namespaced XcVm\) class.
//    Keep only UNIQUE short names to avoid ambiguous cross-namespace matches.
$byShort = [];
foreach (phpFiles($root, $skipDir) as $file) {
	$src = file_get_contents($file);
	if (!preg_match('/^namespace\s+(XcVm\\\\[^;]+);/m', $src, $nm)) {
		continue;
	}
	if (preg_match('/^(?:abstract\s+|final\s+)?(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m', $src, $cm)) {
		$byShort[$cm[1]][] = $nm[1] . '\\' . $cm[1];
	}
}
$unique = [];
foreach ($byShort as $short => $fqcns) {
	if (count(array_unique($fqcns)) === 1) {
		$unique[$short] = $fqcns[0];
	}
}

// 2) Scan procedural files (no namespace) for short-name class use without import.
$violations = [];
foreach (phpFiles($root, $skipDir) as $file) {
	$src = file_get_contents($file);
	if (preg_match('/^namespace\s+/m', $src)) {
		continue; // namespaced class file — covered by PHPStan
	}

	// Tokenize so comments/strings never count as code, and track LINE numbers:
	// PHP `use` is positional outside the top scope — an import only aliases code
	// that textually follows it, so a class used *before* its `use` faults even
	// though the import is "present".
	$tokens = token_get_all($src);
	$importLine = [];        // short name => earliest import line
	$refs = [];              // [short, line] for each ::/new reference
	$n = count($tokens);
	for ($i = 0; $i < $n; $i++) {
		$t = $tokens[$i];
		if (!is_array($t)) {
			continue;
		}
		if ($t[0] === T_USE) {
			$seg = null;
			for ($j = $i + 1; $j < $n; $j++) {
				if (is_array($tokens[$j]) && in_array($tokens[$j][0],
					[T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
					$parts = explode('\\', $tokens[$j][1]);
					$seg = end($parts);
				} elseif ($tokens[$j] === ';' || $tokens[$j] === '{') {
					break;
				}
			}
			if ($seg !== null && !isset($importLine[$seg])) {
				$importLine[$seg] = $t[2];
			}
			continue;
		}
		if ($t[0] === T_STRING && $t[1][0] >= 'A' && $t[1][0] <= 'Z') {
			$prevType = ($i > 0 && is_array($tokens[$i - 1])) ? $tokens[$i - 1][0] : null;
			if (in_array($prevType, [T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NAME_QUALIFIED], true)) {
				continue;
			}
			$nextType = ($i + 1 < $n && is_array($tokens[$i + 1])) ? $tokens[$i + 1][0] : null;
			if ($nextType === T_DOUBLE_COLON || $prevType === T_NEW) {
				$refs[] = [$t[1], $t[2]];
			}
		}
	}

	$rel = substr($file, strlen($root));
	$reported = [];
	foreach ($refs as [$short, $line]) {
		if (!isset($unique[$short]) || isset($reported[$short])) {
			continue;
		}
		if (!isset($importLine[$short])) {
			$reported[$short] = true;
			$violations[] = "{$rel}:{$line}: uses {$short} without `use` (migrated → {$unique[$short]})";
		} elseif ($importLine[$short] > $line) {
			$reported[$short] = true;
			$violations[] = "{$rel}:{$line}: uses {$short} before its `use` on line {$importLine[$short]} (positional alias fault)";
		}
	}
}

if ($violations) {
	fwrite(STDERR, "Procedural files missing `use` for migrated classes:\n");
	sort($violations);
	fwrite(STDERR, '  ' . implode("\n  ", $violations) . "\n");
	exit(1);
}
echo "OK: " . count($unique) . " migrated classes, no procedural short-name use without import.\n";
exit(0);
