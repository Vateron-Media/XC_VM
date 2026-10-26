# AGENTS.md

## Project

PHP library for parsing and generating M3U playlists (`M3uParser\` namespace, PSR-4 in `src/`).
Tests live in `tests/`.

## Requirements

- PHP >= 8.2

## Commands

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix --dry-run --diff
```

## Conventions

- Keep `src/` framework-free, no new runtime dependencies.
- Follow existing tag pattern: `src/Tag/*` implements `ExtTagInterface` (`isMatch()`, `__toString()`, parse in `__construct(?string $lineStr)`).
- Register new tags via `M3uParser::addTag()` / `addDefaultTags()` (see `src/TagsManagerTrait.php`).
- Use `declare(strict_types=1)`, strict comparisons, native function calls with leading `\`.
- Keep changes minimal and covered by tests in `tests/`.
