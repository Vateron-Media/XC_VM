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

Before committing, run the PHP syntax checker:

```sh
bash tools/php_syntax_check.sh
```

This is the same check that CI runs. You can also check a single file:

```sh
bash tools/php_syntax_check.sh src/domain/Device/EnigmaService.php
```

Do not submit PRs with syntax errors — CI will reject them.

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
