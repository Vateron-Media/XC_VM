<?php

/**
 * PHP-CS-Fixer configuration for XC_VM.
 *
 * Scope is intentionally NARROW: import/namespace hygiene only. We do NOT apply
 * @PSR12 or any indentation/brace rules — the codebase is tab-indented legacy
 * code and a full reformat would produce an unreviewable diff. The rules below
 * only touch the `use`/`namespace` block, which is exactly what the PSR-4
 * migration churned.
 *
 * Usage:
 *   make cs       # check only (dry-run, fails on diff) — used in CI
 *   make cs-fix   # apply fixes in place
 */

$finder = PhpCsFixer\Finder::create()
	->in(__DIR__ . '/src')
	->name('*.php')
	// Composer autoloader glue + third-party packages (committed + shipped).
	->exclude('vendor')
	// Bundled third-party TMDB client (vendored, kept global on purpose).
	->exclude('Modules/tmdb/lib')
	// Runtime/transient trees.
	->exclude('tmp')
	->exclude('backups')
	// View templates: HTML interleaved with many <? / <?= short-tag PHP blocks.
	// no_unused_imports cannot reliably see class usage inside short-tag blocks
	// and would strip still-needed imports. Their import correctness is enforced
	// instead by tools/ci/check_procedural_use.php (short-tag aware).
	->notPath('#^Public/Views/#')
	->notPath('#^Modules/[^/]+/views/#');

return (new PhpCsFixer\Config())
	->setIndent("\t")
	->setLineEnding("\n")
	->setRules([
		// Remove imports that are no longer referenced (cleans up leftovers from
		// the PSR-4 migration's automated `use` insertion).
		'no_unused_imports' => true,
		// Sort `use` statements alphabetically, classes/functions/consts grouped.
		'ordered_imports' => [
			'sort_algorithm' => 'alpha',
			'imports_order' => ['class', 'function', 'const'],
		],
		// `use \Foo;` -> `use Foo;` (leading slash in imports is redundant).
		'no_leading_import_slash' => true,
		// Exactly one blank line after the import block.
		'single_line_after_imports' => true,
		// Exactly one blank line after the namespace declaration.
		'blank_line_after_namespace' => true,
		// Collapse multiple blank lines between/around `use` statements.
		'no_extra_blank_lines' => ['tokens' => ['use']],
	])
	->setFinder($finder);
