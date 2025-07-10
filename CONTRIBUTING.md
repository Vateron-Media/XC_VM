# Contributing to the Project

Thank you for considering contributing to this project! Follow these guidelines to make the process smooth for everyone.

## 📌 General Guidelines
- Minimally use AI
- Follow the project's coding style and best practices.
- Ensure your changes are well-documented.
- Write meaningful commit messages.
- Keep pull requests focused on a single change.
- If you are refactoring and are not sure if the code is unused elsewhere, comment it out. It will be removed after the release.

<!-- ## 🛠️ Customizing the development environment
Unfortunately you will have to install the panel on the server completely. It does not have a developer mode
1. Clone the repository:
   ```sh
   sudo apt update && sudo apt full-upgrade -y
   sudo apt install python3-pip
   sudo apt install git
   git clone https://github.com/Vateron-Media/Xtream_install
   cd Xtream_install/
   pip3 install -r requirements.txt
   ```
2. Start the installation:
   ```sh
   sudo python3 install.py
   ``` -->

## ✨ Code Standards
- Use **K&R** coding style for PHP.
- Follow best practices for Python and Bash scripts.
- Avoid unused functions and redundant code.

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

## Code Reviews:
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