#!/usr/bin/env php
<?php
/**
 * Script to add standardized file headers to XC_VM PHP files.
 * v2: MERGE into existing docblocks, fix shebang, manual descriptions.
 * Run: php tools/add_headers.php [--dry-run]
 */

$dryRun = in_array('--dry-run', $argv);
$basePath = dirname(__DIR__) . '/src';

// ── Exclusions ──────────────────────────────────────────────────

$excludeDirs = [
	'bin/',
	'ministra/',
	'content/',
	'tmp/',
	'resources/',
	'public/Views/',
	'includes/libs/TMDb/',
	'includes/libs/resources/',
];

$excludeFiles = [
	'includes/libs/mobiledetect.php',
	'includes/libs/Translator.php',
	'includes/libs/XmlStringStreamer.php',
	'includes/libs/Dropbox.php',
	'includes/libs/tmdb.php',
	'includes/libs/tmdb_release.php',
];

$excludePatterns = [
	'#modules/[^/]+/views/#',
];

// ── Fixed header metadata ───────────────────────────────────────

$metaLines = [
	' * @author  Divarion_D <https://github.com/Divarion-D>',
	' * @copyright 2025-2026 Vateron Media',
	' * @link    https://github.com/Vateron-Media/XC_VM',
	' * @version 0.1.0',
	' * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html',
];
$fixedMeta = implode("\n", $metaLines);

// ── Path-based descriptions for procedural files ────────────────

$pathDescriptions = [
	'autoload.php'            => 'XC_VM class autoloader',
	'bootstrap.php'           => 'Application bootstrap — init core services',
	'console.php'             => 'CLI entry point — command dispatcher',
	'config/modules.php'      => 'Module registration configuration',
	'includes/admin.php'      => 'Admin panel legacy functions',
	'includes/reseller_api.php' => 'Reseller API handler',
	'includes/ts.php'         => 'Timeshift stream handler',
	'includes/api/admin/table.php' => 'Admin DataTables JSON API',
	'includes/api/reseller/table.php' => 'Reseller DataTables JSON API',
	'includes/data/permissions.php' => 'Permission definitions',
	'www/constants.php'       => 'Web application constants',
	'www/init.php'            => 'Web request initialization',
	'www/probe.php'           => 'Stream probe endpoint',
	'www/progress.php'        => 'Stream progress endpoint',
	'www/admin/api.php'       => 'Admin API entry point',
	'www/admin/index.php'     => 'Admin web entry point',
	'www/admin/live.php'      => 'Admin live stream handler',
	'www/admin/proxy_api.php' => 'Proxy API handler',
	'www/admin/thumb.php'     => 'Thumbnail generator endpoint',
	'www/admin/timeshift.php' => 'Admin timeshift handler',
	'www/admin/vod.php'       => 'Admin VOD handler',
	'www/stream/auth.php'     => 'Stream authentication and session handler',
	'www/stream/index.php'    => 'Stream routing entry point',
	'www/stream/init.php'     => 'Streaming bootstrap — core require chain',
	'www/stream/key.php'      => 'HLS encryption key endpoint',
	'www/stream/live.php'     => 'Live stream delivery endpoint',
	'www/stream/rtmp.php'     => 'RTMP stream handler',
	'www/stream/segment.php'  => 'HLS segment delivery endpoint',
	'www/stream/subtitle.php' => 'Subtitle delivery endpoint',
	'www/stream/thumb.php'    => 'Stream thumbnail endpoint',
	'www/stream/timeshift.php' => 'Timeshift stream endpoint',
	'www/stream/vod.php'      => 'VOD stream delivery endpoint',
	'cli/migration_logic.php' => 'Database migration logic',
	'public/index.php'        => 'Public HTTP entry point — router dispatch',
	'public/routes/admin.php' => 'Admin panel route definitions',
	'public/routes/player.php' => 'Player route definitions',
	'public/routes/reseller.php' => 'Reseller panel route definitions',
	'infrastructure/bootstrap/admin_functions_fc.php'  => 'Admin helper functions bootstrap',
	'infrastructure/bootstrap/admin_session_fc.php'    => 'Admin session initialization',
	'infrastructure/bootstrap/player_functions.php'    => 'Player helper functions bootstrap',
	'infrastructure/bootstrap/player_session.php'      => 'Player session initialization',
	'infrastructure/bootstrap/player_utility_functions.php' => 'Player utility functions',
	'infrastructure/bootstrap/reseller_functions.php'  => 'Reseller helper functions bootstrap',
	'infrastructure/bootstrap/reseller_session.php'    => 'Reseller session initialization',
	'infrastructure/legacy/player_resize_body.php'     => 'Player resize table body (legacy)',
	'infrastructure/legacy/reseller_api_actions.php'   => 'Reseller API action handlers (legacy)',
	'infrastructure/legacy/reseller_resize_body.php'   => 'Reseller resize table body (legacy)',
	'infrastructure/legacy/reseller_table_body.php'    => 'Reseller table body renderer (legacy)',
];

// ── Collect files ───────────────────────────────────────────────

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS)
);

$files = [];
foreach ($iterator as $file) {
	if ($file->getExtension() !== 'php') continue;

	$rel = str_replace($basePath . '/', '', $file->getPathname());

	$skip = false;
	foreach ($excludeDirs as $dir) {
		if (strpos($rel, $dir) === 0) { $skip = true; break; }
	}
	if ($skip) continue;
	if (in_array($rel, $excludeFiles)) continue;
	foreach ($excludePatterns as $pat) {
		if (preg_match($pat, $rel)) { $skip = true; break; }
	}
	if ($skip) continue;

	$files[] = $file->getPathname();
}

sort($files);

echo "Found " . count($files) . " files to process\n";
if ($dryRun) echo "[DRY RUN MODE]\n";
echo str_repeat('─', 60) . "\n";

$added = 0;
$merged = 0;
$skipped = 0;
$errors = 0;

foreach ($files as $filepath) {
	$rel = str_replace($basePath . '/', '', $filepath);
	$content = file_get_contents($filepath);

	// Skip if already has our metadata
	if (preg_match('/@author\s+Divarion_D/', $content)) {
		echo "  SKIP (has header): {$rel}\n";
		$skipped++;
		continue;
	}

	$package = buildPackage($rel);
	$description = buildDescription($rel, $content, $pathDescriptions);

	// ── Strip shebang for uniform processing ────────────────────
	$shebang = '';
	$body = $content;
	if (strpos($content, '#!') === 0) {
		$nlPos = strpos($content, "\n");
		$shebang = substr($content, 0, $nlPos + 1);
		$body = substr($content, $nlPos + 1);
	}

	// ── Check for existing docblock after <?php ─────────────────
	$hasDocblock = preg_match('/^(<\?php\s*\n)(\s*\/\*\*.*?\*\/)\s*\n/s', $body, $docMatch);

	if ($hasDocblock) {
		// ── Merge metadata into existing docblock ───────────────
		$beforeDoc = $docMatch[1]; // <?php\n
		$existingDoc = $docMatch[2];

		$injection = " *\n"
			. " * @package {$package}\n"
			. $fixedMeta;

		// Insert metadata before closing */
		$newDoc = preg_replace(
			'/\s*\*\/\s*$/',
			"\n" . $injection . "\n */",
			$existingDoc
		);

		$newBody = $beforeDoc . $newDoc . "\n\n";
		// Append everything after the old docblock
		$afterDocStart = strlen($docMatch[0]);
		$newBody .= ltrim(substr($body, $afterDocStart), "\n");

		$label = 'MERGED';
		$merged++;
	} else {
		// ── Create new docblock ─────────────────────────────────
		$header = "/**\n"
			. " * {$description}\n"
			. " *\n"
			. " * @package {$package}\n"
			. $fixedMeta . "\n"
			. " */";

		$count = 0;
		$newBody = preg_replace(
			'/^(<\?php)\s*\n/',
			"$1\n\n{$header}\n\n",
			$body,
			1,
			$count
		);

		if ($count === 0) {
			echo "  ERROR (no <?php tag?): {$rel}\n";
			$errors++;
			continue;
		}

		$label = 'ADDED';
		$added++;
	}

	$newContent = $shebang . $newBody;

	if (!$dryRun) {
		file_put_contents($filepath, $newContent);
	}

	echo "  {$label}: {$rel}\n";
}

echo str_repeat('─', 60) . "\n";
echo "Done: {$added} added, {$merged} merged, {$skipped} skipped, {$errors} errors\n";

// ── Helper functions ────────────────────────────────────────────

function buildPackage(string $rel): string {
	$parts = explode('/', dirname($rel));

	$map = [
		'core'           => 'XC_VM_Core',
		'domain'         => 'XC_VM_Domain',
		'cli'            => 'XC_VM_CLI',
		'infrastructure' => 'XC_VM_Infrastructure',
		'streaming'      => 'XC_VM_Streaming',
		'modules'        => 'XC_VM_Module',
		'public'         => 'XC_VM_Public',
		'includes'       => 'XC_VM_Includes',
		'www'            => 'XC_VM_Web',
		'config'         => 'XC_VM_Config',
		'signals'        => 'XC_VM_Signals',
	];

	if (empty($parts) || $parts[0] === '.') {
		return 'XC_VM';
	}

	$prefix = $map[$parts[0]] ?? 'XC_VM';
	$sub = array_slice($parts, 1);

	if (empty($sub)) {
		return $prefix;
	}

	$subParts = array_map(function ($p) {
		return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $p)));
	}, $sub);

	return $prefix . '_' . implode('_', $subParts);
}

function buildDescription(string $rel, string $content, array $pathDescriptions): string {
	if (isset($pathDescriptions[$rel])) {
		return $pathDescriptions[$rel];
	}

	// Extract class/interface/trait name
	if (preg_match('/^\s*(abstract\s+)?class\s+(\w+)/m', $content, $m)) {
		return $m[2] . ' — ' . classToDescription($m[2]);
	}
	if (preg_match('/^\s*interface\s+(\w+)/m', $content, $m)) {
		return $m[1] . ' interface';
	}
	if (preg_match('/^\s*trait\s+(\w+)/m', $content, $m)) {
		return $m[1] . ' trait';
	}

	// Fallback: path-based
	$dir = basename(dirname($rel));
	$filename = pathinfo($rel, PATHINFO_FILENAME);
	if ($dir !== '.') {
		return ucfirst($dir) . '/' . $filename . ' handler';
	}
	return ucfirst($filename);
}

function classToDescription(string $className): string {
	$words = preg_split('/(?=[A-Z])/', $className, -1, PREG_SPLIT_NO_EMPTY);
	$words = array_map('strtolower', $words);

	$suffixes = ['controller', 'service', 'repository', 'command', 'handler', 'manager', 'factory',
				 'interface', 'trait', 'module', 'cron', 'job', 'guard', 'loader', 'reader', 'runner',
				 'resolver', 'dispatcher', 'validator', 'generator', 'tracker', 'sorter', 'selector'];

	$lastWord = end($words);
	$prefix = array_slice($words, 0, -1);

	if (in_array($lastWord, $suffixes) && count($prefix) > 0) {
		return implode(' ', $prefix) . ' ' . $lastWord;
	}

	return implode(' ', $words);
}
