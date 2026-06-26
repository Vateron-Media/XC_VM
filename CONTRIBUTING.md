# Contributing to the Project

Thank you for considering contributing to this project! Follow these guidelines to make the process smooth for everyone.

## 📌 General Guidelines

- Minimally use AI
- Follow the project's coding style and best practices.
- Ensure your changes are well-documented.
- Write meaningful commit messages.
- Keep pull requests focused on a single change.
- If you are refactoring and are not sure if the code is unused elsewhere, comment it out. It will be removed after the release.

## 🛠️ Installation

To install the panel, follow these steps:

1. **Update system**

   ```sh
   sudo apt update && sudo apt full-upgrade -y
   ```

2. **Install dependencies**

   ```sh
   sudo apt install -y python3-pip unzip
   ```

3. **Download latest release**

   ```sh
   latest_version=$(curl -s https://api.github.com/repos/Vateron-Media/XC_VM/releases/latest | grep '"tag_name":' | cut -d '"' -f 4)
   wget "https://github.com/Vateron-Media/XC_VM/releases/download/${latest_version}/XC_VM.zip"
   ```

4. **Unpack and install**

   ```sh
   unzip XC_VM.zip
   sudo python3 install
   ```

---

## ✨ Code Standards

- Use **K&R** coding style for PHP.
- Follow best practices for Python and Bash scripts.
- Avoid unused functions and redundant code.

## 🔍 Pre-Commit Checks

Install the dev tooling once, then run the linters and tests before committing
(CI runs the same set):

```sh
make dev-tools     # install PHPStan + PHP-CS-Fixer (composer install)
make phpstan       # static analysis (also catches syntax errors)
make cs            # code style — import/namespace hygiene (PHP-CS-Fixer)
make gates         # PSR-4 regression gates
php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist   # unit tests
make dev-clean     # done? remove the dev tools again, restoring prod-only vendor/
```

Do not submit PRs that fail these — CI will reject them.

## 🔬 Static Analysis (PHPStan)

The project is analysed with **PHPStan** (level 5). There is no Composer — the
pinned PHAR is downloaded on first run, and a bootstrap registers the project's
constants/classes (`tools/phpstan/`).

Run the analysis before submitting a PR:

```sh
make phpstan
```

This is the same check CI runs. It must report **`[OK] No errors`**.

How the gate works:

- A committed baseline (`phpstan-baseline.neon`) freezes the pre-existing
  findings, so CI only fails on **new** issues your change introduces — fix
  those before pushing.
- The baseline is **not** a list of accepted bugs (most entries are false
  positives from dynamic DB-row shapes or templates). Do **not** grow it to hide
  a real problem in new code.
- If you fix a batch of existing findings, regenerate the (smaller) baseline:

  ```sh
  make phpstan-baseline
  ```

- After editing `tools/phpstan/constants.stub.php` or any PHPDoc types, clear the
  result cache first: `php tools/phpstan/phpstan.phar clear-result-cache`.

## 🧪 Adding Tests

- Add new PHP tests under `tests/Unit/`.
- Prefer focused tests for the class or file you changed instead of broad project-wide mock coverage.
- Name test files after the target class, for example `GitHubReleasesTest.php`.
- Cover real behavior: valid inputs, invalid inputs, edge cases, and side effects.
- If the code writes to stdout, capture output inside the test so PHPUnit output stays readable.

Run a single test file while developing:

```sh
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist tests/Unit/GitHubReleasesTest.php
```

Show which test is executing now:

```sh
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist --debug --no-progress
```

Run the default targeted unit suite before submitting a PR:

```sh
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist
```

<!-- ## 🧪 Writing and Running Tests
- Write unit tests for PHP scripts.
- To run tests:
  ```sh
  php8.4 /home/xc_vm/bin/install/php/phpunit-12.0.5.phar --configuration /home/xc_vm/tests/phpunit.xml 
  ```
- Ensure all tests pass before submitting PRs. -->

## 🔥 Submitting a Pull Request

1. Fork the repository and create a new branch:

   ```sh
   git checkout -b feature/your-feature
   ```

2. Make your changes and commit them:

   ```sh
   git commit -m "Add feature: description"
   ```

3. Push your branch:

   ```sh
   git push origin feature/your-feature
   ```

4. Open a pull request on GitHub.

## Code Reviews

- All PRs must be reviewed by at least 2 maintainers. Address review comments before merging.

## 🚀 Reporting Issues

- Use **GitHub Issues** to report bugs and suggest features.
- Provide clear steps to reproduce issues.
- Attach relevant logs or error messages.

## 🔀 Branch Naming Conventions

To maintain a clean and organized repository, follow these branch naming conventions:

| Title           | Template                       | Example                        |
|-----------------|--------------------------------|--------------------------------|
| Features        | `feature/<short-description>`  | `feature/user-authentication`  |
| Bug Fixes       | `fix/<short-description>`      | `fix/login-bug`                |
| Hotfixes        | `hotfix/<short-description>`   | `hotfix/critical-error`        |
| Refactoring     | `refactor/<short-description>` | `refactor/code-cleanup`        |
| Testing         | `test/<short-description>`     | `test/api-endpoints`           |
| Documentation   | `docs/<short-description>`     | `docs/documentation-api`       |

## 🌟 Recognition

- Your GitHub profile will be added to [CONTRIBUTORS.md](CONTRIBUTORS.md)

Thank you for contributing! 🎉
