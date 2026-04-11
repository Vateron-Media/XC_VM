#!/usr/bin/env python3
"""Обновляет таблицу контрибьюторов в CONTRIBUTORS.md.

Источники данных:
  - GitHub Contributors API (коммиты) — ловит прямые пуши и PR-коммиты
  - GitHub Pulls API (merged PRs) — отдельный столбец с количеством PR

Токен берётся из переменной окружения GITHUB_TOKEN (опционально).
"""
import os
import re
import sys
from pathlib import Path

import requests

REPO = "Vateron-Media/XC_VM"
TOKEN = os.environ.get("GITHUB_TOKEN", "")
REQUEST_TIMEOUT = 15  # секунд

HEADERS = {"Accept": "application/vnd.github+json"}
if TOKEN:
    HEADERS["Authorization"] = f"Bearer {TOKEN}"

# Корень репозитория — относительно расположения скрипта
REPO_ROOT = Path(__file__).resolve().parent.parent
CONTRIBUTORS_PATH = REPO_ROOT / "CONTRIBUTORS.md"

# Список ботов, которых не включаем в таблицу
BOTS = {"dependabot[bot]", "github-actions[bot]", "renovate[bot]"}


def _gh_get(url: str) -> requests.Response:
    resp = requests.get(url, headers=HEADERS, timeout=REQUEST_TIMEOUT)
    if resp.status_code == 403 and "rate limit" in resp.text.lower():
        print("⚠ GitHub API rate limit. Используй GITHUB_TOKEN.", file=sys.stderr)
        sys.exit(1)
    resp.raise_for_status()
    return resp


def fetch_contributors_by_commits() -> dict[str, int]:
    """Получает контрибьюторов через /contributors (считает коммиты)."""
    contributors: dict[str, int] = {}
    page = 1
    while True:
        url = f"https://api.github.com/repos/{REPO}/contributors?per_page=100&page={page}"
        data = _gh_get(url).json()
        if not data:
            break
        for user in data:
            login = user.get("login", "")
            if login and login not in BOTS:
                contributors[login] = user.get("contributions", 0)
        page += 1
    return contributors


def fetch_merged_prs() -> dict[str, int]:
    """Считает merged PRs на автора."""
    pr_counts: dict[str, int] = {}
    page = 1
    while True:
        url = f"https://api.github.com/repos/{REPO}/pulls?state=closed&per_page=100&page={page}"
        data = _gh_get(url).json()
        if not data:
            break
        for pr in data:
            if pr.get("merged_at"):
                author = pr["user"]["login"]
                if author not in BOTS:
                    pr_counts[author] = pr_counts.get(author, 0) + 1
        page += 1
    return pr_counts


def generate_table(commits: dict[str, int], prs: dict[str, int]) -> str:
    all_authors = sorted(
        set(commits) | set(prs),
        key=lambda a: (commits.get(a, 0) + prs.get(a, 0)),
        reverse=True,
    )

    if not all_authors:
        return (
            "| Contributor | Commits | PRs Merged |\n"
            "| ----------- | ------- | ---------- |\n"
            "| *None yet*  | —       | —          |\n"
        )

    rows = [
        "| Contributor | Commits | PRs Merged |",
        "| ----------- | ------- | ---------- |",
    ]
    for author in all_authors:
        c = commits.get(author, 0)
        p = prs.get(author, 0)
        rows.append(f"| @{author} | {c} | {p} |")

    return "\n".join(rows) + "\n"


def update_contributors_md(table: str):
    if not CONTRIBUTORS_PATH.exists():
        print(f"Файл не найден: {CONTRIBUTORS_PATH}", file=sys.stderr)
        sys.exit(1)

    content = CONTRIBUTORS_PATH.read_text(encoding="utf-8")
    new_content = re.sub(
        r"<!-- STARS_TABLE_START -->(.*?)<!-- STARS_TABLE_END -->",
        f"<!-- STARS_TABLE_START -->\n{table}<!-- STARS_TABLE_END -->",
        content,
        flags=re.S,
    )

    if new_content == content:
        print("Маркеры STARS_TABLE_START/END не найдены — ничего не обновлено.",
              file=sys.stderr)
        sys.exit(1)

    CONTRIBUTORS_PATH.write_text(new_content, encoding="utf-8")
    print(f"✅ {CONTRIBUTORS_PATH.name} updated!")


if __name__ == "__main__":
    print("Fetching contributors (commits)...")
    commits = fetch_contributors_by_commits()
    print(f"  Найдено {len(commits)} авторов по коммитам")

    print("Fetching merged PRs...")
    prs = fetch_merged_prs()
    print(f"  Найдено {len(prs)} авторов по PR")

    table = generate_table(commits, prs)
    update_contributors_md(table)
    print("Done.")
