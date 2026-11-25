#!/usr/bin/env bash
set -euo pipefail

phpstan=false
style=false
gates=false
composer=false
phpunit=false
agent=false

enable_all() {
	phpstan=true
	style=true
	gates=true
	composer=true
	phpunit=true
	agent=true
}

while IFS= read -r file; do
	[ -n "$file" ] || continue

	case "$file" in
		.github/workflows/ci.yml)
			enable_all
			continue
			;;
		docs/*|*.md|LICENSE|mkdocs.yml|.claude/*|.github/ISSUE_TEMPLATE/*|.github/CODEOWNERS|.github/dependabot.yml|.github/workflows/*.yml|.deploy-merged-tickets|.gitignore|.gitattributes)
			continue
			;;
	esac

	known=false
	case "$file" in
		src/*.php|build/phpstan*.neon|tools/phpstan/*.php|src/composer.json|src/composer.lock)
			phpstan=true
			known=true
			;;
	esac
	case "$file" in
		src/*.php|build/phpcs.xml.dist|src/composer.json|src/composer.lock)
			style=true
			known=true
			;;
	esac
	case "$file" in
		src/*|lb_configs/*|Makefile|tools/ci/*)
			gates=true
			known=true
			;;
	esac
	case "$file" in
		src/composer.json|src/composer.lock|src/vendor/*)
			composer=true
			known=true
			;;
	esac
	case "$file" in
		src/*|tests/*|install|Makefile|lb_configs/*)
			phpunit=true
			known=true
			;;
	esac
	case "$file" in
		src/Core/*|src/Domain/*|src/Infrastructure/*|src/Streaming/*|src/bootstrap.php|src/autoload.php|src/bin/install/database.sql|src/migrations/database/*|src/vendor/*|src/composer.json|src/composer.lock|tests/bootstrap.php|tests/Support/*)
			agent=true
			known=true
			;;
	esac
	case "$file" in
		build/phpcs.xml.dist|build/phpstan*.neon|tools/phpstan/*)
			known=true
			;;
	esac

	# Unknown project surfaces are safer with every gate than with a missed dependency.
	$known || enable_all
done

printf '%s\n' \
	"phpstan=$phpstan" \
	"style=$style" \
	"gates=$gates" \
	"composer=$composer" \
	"phpunit=$phpunit" \
	"agent=$agent"
