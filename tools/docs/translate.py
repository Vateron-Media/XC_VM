#!/usr/bin/env python3
"""Generate translated docs from the canonical English source.

English (``docs/en``) is the single source of truth; every other language tree
(e.g. ``docs/ru``) is produced from it by this script. The generated tree IS
committed and refreshed by running this script LOCALLY before a release (not in
CI); ``mkdocs build`` then consumes it via the mkdocs-static-i18n plugin.

Design goals
------------
* **Engine-agnostic.** The concrete translator is chosen at runtime via the
  ``DOCS_TRANSLATE_PROVIDER`` env var (``noop`` | ``anthropic`` | ``deepl``);
  the interface is a single ``translate(text, target_lang, glossary) -> text``.
* **Incremental.** Each file's translation is cached by the sha256 of its source
  content (plus language + provider + glossary), so only changed English files
  hit the translation backend on a subsequent run — locally and in CI.
* **Markdown-safe.** Providers are instructed to preserve fenced/inline code,
  URLs, HTML and Markdown structure, and to leave glossary terms untouched.

Usage
-----
    DOCS_TRANSLATE_PROVIDER=noop \
        python3 tools/docs/translate.py --lang ru

    # provider-specific:
    DOCS_TRANSLATE_PROVIDER=anthropic ANTHROPIC_API_KEY=... \
        python3 tools/docs/translate.py --lang ru
"""

from __future__ import annotations

import argparse
import hashlib
import os
import re
import sys
import time
from pathlib import Path

# Bumped when the translation prompt/rules change, to invalidate the cache.
PROMPT_VERSION = "1"

LANG_NAMES = {
    "ru": "Russian",
    "es": "Spanish",
    "de": "German",
    "fr": "French",
    "pt": "Portuguese",
    "bg": "Bulgarian",
}


def load_glossary(path: Path) -> list[str]:
    """Do-not-translate terms, one per line (``#`` comments / blanks ignored)."""
    if not path.is_file():
        return []
    terms = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            terms.append(line)
    return terms


def cache_key(text: str, lang: str, provider: str, glossary: list[str]) -> str:
    h = hashlib.sha256()
    h.update(PROMPT_VERSION.encode())
    h.update(b"\0")
    h.update(provider.encode())
    h.update(b"\0")
    h.update(lang.encode())
    h.update(b"\0")
    h.update("\n".join(glossary).encode())
    h.update(b"\0")
    h.update(text.encode("utf-8"))
    return h.hexdigest()


# ── Providers ────────────────────────────────────────────────────────────────
# Each provider is ``translate(text, lang, glossary) -> text``. Add a new engine
# by writing one function and registering it in PROVIDERS below.

def _system_prompt(lang_name: str, glossary: list[str]) -> str:
    rules = [
        f"You are a professional technical translator. Translate the given "
        f"Markdown document from English into {lang_name}.",
        "Rules:",
        "- Output ONLY the translated Markdown, nothing else. No preamble.",
        "- Preserve the Markdown structure EXACTLY: headings, lists, tables, "
        "blockquotes, admonitions, front matter keys, link/image syntax.",
        "- NEVER translate content inside fenced code blocks (```...```) or "
        "inline code (`...`); copy it verbatim.",
        "- NEVER translate URLs, file paths, HTML tags/attributes, or link "
        "targets — translate only human-visible link text.",
        "- Keep heading text natural; anchors are derived from it automatically.",
        "- Preserve trailing/leading whitespace and blank-line layout.",
    ]
    if glossary:
        rules.append(
            "- Do NOT translate these terms (keep them verbatim): "
            + ", ".join(glossary)
        )
    return "\n".join(rules)


def provider_noop(text: str, lang: str, glossary: list[str]) -> str:
    """Passthrough: copy English verbatim. For structural/CI dry-runs."""
    return text


def provider_anthropic(text: str, lang: str, glossary: list[str]) -> str:
    """Translate via the Anthropic API (requires the `anthropic` SDK + key)."""
    import anthropic  # lazy: only needed when this provider is selected

    lang_name = LANG_NAMES.get(lang, lang)
    model = os.environ.get("DOCS_TRANSLATE_MODEL", "claude-sonnet-5")
    client = anthropic.Anthropic()  # reads ANTHROPIC_API_KEY
    msg = client.messages.create(
        model=model,
        max_tokens=16000,
        system=_system_prompt(lang_name, glossary),
        messages=[{"role": "user", "content": text}],
    )
    return "".join(
        block.text for block in msg.content if getattr(block, "type", "") == "text"
    )


def provider_deepl(text: str, lang: str, glossary: list[str]) -> str:
    """Translate via the DeepL API (requires `deepl` SDK + DEEPL_AUTH_KEY).

    DeepL is text-oriented; ``tag_handling='html'`` is a poor fit for Markdown,
    so we send Markdown as plain text and rely on ignore-tag protection for code.
    Prefer the anthropic provider for structure-heavy docs.
    """
    import deepl  # lazy

    translator = deepl.Translator(os.environ["DEEPL_AUTH_KEY"])
    result = translator.translate_text(
        text,
        target_lang=lang.upper(),
        preserve_formatting=True,
    )
    return result.text


# ── Markdown-safe web translation (free, no API key) ─────────────────────────
# The `translators` library (UlionTse/translators) drives free web engines
# (yandex/google/bing/...). They translate PLAIN TEXT and will happily mangle
# Markdown, so we translate line-by-line and mask everything that must survive
# verbatim (code, URLs, HTML tags, glossary terms) behind @@N@@ sentinels — which
# the engines pass through unchanged — then restore them afterwards.

_TS_SENTINEL = re.compile(r"@@(\d+)@@")
_HR = re.compile(r"^\s*([-*_])( *\1){2,}\s*$")            # thematic break ---
_TABLE_SEP = re.compile(r"^\s*\|?[\s:|-]+\|?\s*$")         # |---|:--:| row
_HEADING = re.compile(r"^(\s{0,3}#{1,6}\s+)(.*)$")
_LIST = re.compile(r"^(\s*(?:[-*+]|\d+[.)])\s+)(.*)$")
_QUOTE = re.compile(r"^(\s*>+\s*)(.*)$")


def _protect_inline(text: str, glossary: list[str]) -> tuple[str, list[str]]:
    store: list[str] = []

    def keep(m: "re.Match[str]") -> str:
        store.append(m.group(0))
        return f"@@{len(store) - 1}@@"

    text = re.sub(r"`[^`]*`", keep, text)                     # inline code
    text = re.sub(r"!\[[^\]]*\]\([^)]*\)", keep, text)        # images (whole)
    text = re.sub(r"(?<=\])\([^)]*\)", keep, text)            # link target (url)
    text = re.sub(r"https?://[^\s)]+", keep, text)            # bare URLs
    text = re.sub(r"</?[A-Za-z][^>]*>", keep, text)           # HTML tags
    for term in glossary:
        text = re.sub(rf"(?<![\w-]){re.escape(term)}(?![\w-])", keep, text)
    return text, store


def _restore(text: str, store: list[str]) -> str:
    return _TS_SENTINEL.sub(lambda m: store[int(m.group(1))], text)


def _ts_span(text, tr, glossary, memo):
    """Translate one run of prose, masking inline non-translatables."""
    if not text.strip():
        return text
    if text in memo:
        return memo[text]
    protected, store = _protect_inline(text, glossary)
    if not _TS_SENTINEL.sub("", protected).strip():
        out = text  # nothing left to translate (all masked)
    else:
        out = _restore(tr(protected), store)
    memo[text] = out
    return out


def _ts_line(line, tr, glossary, memo):
    """Translate one Markdown line, preserving its structural prefix/markup."""
    if not line.strip() or _HR.match(line):
        return line
    if line.lstrip().startswith("|"):                         # table row
        if _TABLE_SEP.match(line):
            return line
        return "|".join(
            _ts_span(cell, tr, glossary, memo) if cell.strip() else cell
            for cell in line.split("|")
        )
    m = _HEADING.match(line) or _LIST.match(line) or _QUOTE.match(line)
    if m:
        prefix, rest = m.group(1), m.group(2)
        if rest.strip() and (_LIST.match(rest) or _QUOTE.match(rest)):
            return prefix + _ts_line(rest, tr, glossary, memo)  # nested marker
        return prefix + _ts_span(rest, tr, glossary, memo)
    return _ts_span(line, tr, glossary, memo)


def _ts_document(md, tr, glossary):
    memo: dict[str, str] = {}
    out, in_code, fence = [], False, "```"
    for line in md.split("\n"):
        stripped = line.lstrip()
        if not in_code and (stripped.startswith("```") or stripped.startswith("~~~")):
            in_code, fence = True, stripped[:3]
            out.append(line)
            continue
        if in_code:
            out.append(line)
            if stripped.startswith(fence):
                in_code = False
            continue
        out.append(_ts_line(line, tr, glossary, memo))
    return "\n".join(out)


def provider_translators(text: str, lang: str, glossary: list[str]) -> str:
    """Free, key-less translation via the `translators` web engines.

    Engine order is set by DOCS_TRANSLATE_TS_ENGINES (first that answers wins);
    yandex is the default lead as it is the most reliable en->ru here.
    """
    import translators as ts  # lazy

    engines = [
        e.strip() for e in
        os.environ.get("DOCS_TRANSLATE_TS_ENGINES", "yandex,google,bing,alibaba").split(",")
        if e.strip()
    ]

    def tr(s: str) -> str:
        last_err = None
        for engine in engines:
            for _ in range(3):
                try:
                    return ts.translate_text(
                        s, translator=engine, from_language="en", to_language=lang
                    )
                except Exception as exc:  # noqa: BLE001 — try the next engine
                    last_err = exc
                    time.sleep(1.0)
        raise RuntimeError(f"all translators engines failed: {last_err}")

    return _ts_document(text, tr, glossary)


PROVIDERS = {
    "noop": provider_noop,
    "translators": provider_translators,
    "anthropic": provider_anthropic,
    "deepl": provider_deepl,
}


# ── Driver ───────────────────────────────────────────────────────────────────

def main() -> int:
    repo_root = Path(__file__).resolve().parents[2]
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--lang", required=True, help="target language code, e.g. ru")
    ap.add_argument("--src", default=str(repo_root / "docs" / "en"),
                    help="source (English) docs dir")
    ap.add_argument("--dst", default=None,
                    help="destination dir (default: docs/<lang>)")
    ap.add_argument("--cache", default=str(repo_root / ".docs-cache"),
                    help="per-file translation cache dir")
    ap.add_argument("--glossary", default=str(repo_root / "tools" / "docs" / "glossary.txt"),
                    help="do-not-translate term list")
    args = ap.parse_args()

    provider_name = os.environ.get("DOCS_TRANSLATE_PROVIDER", "noop")
    translate = PROVIDERS.get(provider_name)
    if translate is None:
        print(f"error: unknown DOCS_TRANSLATE_PROVIDER={provider_name!r} "
              f"(known: {', '.join(PROVIDERS)})", file=sys.stderr)
        return 2

    src = Path(args.src)
    dst = Path(args.dst) if args.dst else repo_root / "docs" / args.lang
    cache = Path(args.cache)
    cache.mkdir(parents=True, exist_ok=True)
    glossary = load_glossary(Path(args.glossary))

    md_files = sorted(src.rglob("*.md"))
    if not md_files:
        print(f"error: no .md files under {src}", file=sys.stderr)
        return 1

    translated = cached = failed = 0
    for path in md_files:
        rel = path.relative_to(src)
        text = path.read_text(encoding="utf-8")
        key = cache_key(text, args.lang, provider_name, glossary)
        cache_file = cache / f"{key}.md"

        if cache_file.is_file():
            out = cache_file.read_text(encoding="utf-8")
            cached += 1
        else:
            try:
                out = translate(text, args.lang, glossary)
            except Exception as exc:  # noqa: BLE001
                # Graceful degradation: a flaky/rate-limited web engine must never
                # break the docs build — fall back to the English source for this
                # file (and do NOT cache it, so it is retried next run).
                print(f"  WARN {rel}: translation failed ({exc}); keeping English",
                      file=sys.stderr)
                failed += 1
                out = text
            else:
                cache_file.write_text(out, encoding="utf-8")
                translated += 1
                print(f"  translated {rel}")

        out_path = dst / rel
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(out, encoding="utf-8")

    print(f"[{provider_name}] {args.lang}: {len(md_files)} files "
          f"({translated} translated, {cached} from cache, {failed} fell back "
          f"to English) -> {dst}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
