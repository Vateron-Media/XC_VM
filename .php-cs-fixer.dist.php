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
	->exclude('backups');

// NOTE: view templates (Public/Views, Modules/*/views) and procedural entry
// points ARE included. The ruleset only rewrites the import/namespace block, and
// `use` after inline HTML is valid + aliases correctly in PHP — verified. This
// keeps import hygiene consistent across class files and templates alike.

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
