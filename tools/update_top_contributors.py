#!/usr/bin/env python3
import requests
import re

REPO = "Vateron-Media/XC_VM"   # ❗ Укажи свой репозиторий (org/repo)
TOKEN = ""  # Можно оставить пустым, но с токеном больше лимиты

HEADERS = {"Accept": "application/vnd.github+json"}
if TOKEN:
    HEADERS["Authorization"] = f"Bearer {TOKEN}"


def fetch_merged_prs():
    contributors = {}

    page = 1
    while True:
        url = f"https://api.github.com/repos/{REPO}/pulls?state=closed&per_page=100&page={page}"
        resp = requests.get(url, headers=HEADERS).json()

        if not resp or isinstance(resp, dict) and resp.get("message"):
            break

        for pr in resp:
            if pr.get("merged_at"):
                author = pr["user"]["login"]
                contributors[author] = contributors.get(author, 0) + 1

        page += 1

    return contributors


def generate_table(data: dict) -> str:
    if not data:
        return (
            "| Contributor | PRs Merged |\n"
            "| ----------- | ---------- |\n"
            "| *None yet* | — |\n"
        )

    rows = [
        "| Contributor | PRs Merged |",
        "| ----------- | ---------- |",
    ]

    for author, count in sorted(data.items(), key=lambda x: x[1], reverse=True):
        rows.append(f"| @{author} | {count} |")

    return "\n".join(rows) + "\n"


def update_contributors_md(table: str):
    with open("CONTRIBUTORS.md", "r", encoding="utf-8") as f:
        content = f.read()

    new_content = re.sub(
        r"<!-- STARS_TABLE_START -->(.*?)<!-- STARS_TABLE_END -->",
        f"<!-- STARS_TABLE_START -->\n{table}<!-- STARS_TABLE_END -->",
        content,
        flags=re.S,
    )

    with open("CONTRIBUTORS.md", "w", encoding="utf-8") as f:
        f.write(new_content)

    print("CONTRIBUTORS.md updated!")


if __name__ == "__main__":
    print("Fetching merged PRs...")
    data = fetch_merged_prs()
    table = generate_table(data)
    update_contributors_md(table)
    print("Done.")
