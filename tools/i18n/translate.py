#!/usr/bin/env python3
"""Generate translations from the canonical English sources.

Two targets:

* **Docs** (default). English (``docs/en``) is the single source of truth;
  every other language tree (e.g. ``docs/ru``) is produced from it. The
  generated tree IS committed and refreshed by running this script LOCALLY
  before a release (not in CI); ``mkdocs build`` then consumes it via the
  mkdocs-static-i18n plugin.
* **Panel language files** (``--ini``). Each
  ``src/Core/Localization/lang/<lang>.ini`` is rebuilt in ``en.ini`` order:
  existing translations are kept, keys missing from it (or still holding the
  English text the runtime Translator auto-copies in) are translated, and keys
  no longer in ``en.ini`` are dropped.

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
        python3 tools/i18n/translate.py --lang ru

    # provider-specific:
    DOCS_TRANSLATE_PROVIDER=anthropic ANTHROPIC_API_KEY=... \
        python3 tools/i18n/translate.py --lang ru

    # panel language files (one language, or every <lang>.ini but en):
    DOCS_TRANSLATE_PROVIDER=translators \
        python3 tools/i18n/translate.py --ini --lang all
"""

from __future__ import annotations

import argparse
import difflib
import fcntl
import hashlib
import json
import os
import re
import subprocess
import sys
import time
from pathlib import Path

# Bumped when the translation prompt/rules change, to invalidate the cache.
PROMPT_VERSION = "6"

LANG_NAMES = {
    "ru": "Russian",
    "es": "Spanish",
    "de": "German",
    "fr": "French",
    "pt": "Portuguese",
    "bg": "Bulgarian",
    "ar": "Arabic",
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
        "- Your reply is written straight to the translated file, so reply "
        "with the translated Markdown alone.",
        "- Keep the Markdown structure as it is: headings, lists, tables, "
        "blockquotes, admonitions, front matter keys, link/image syntax.",
        "- Copy fenced code blocks (```...```) and inline code (`...`) "
        "verbatim; they are commands and identifiers.",
        "- Keep URLs, file paths, HTML tags/attributes and link targets "
        "unchanged; translate only human-visible link text.",
        "- Keep heading text natural; anchors are derived from it automatically.",
        "- Preserve inline emphasis markers (**bold**, *italic*, _underscore_) "
        "verbatim and balanced: wrap the translated words with the same opening "
        "and closing markers, including every closing **.",
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
    model = os.environ.get("DOCS_TRANSLATE_MODEL", "claude-sonnet-5-5")
    client = anthropic.Anthropic()  # reads ANTHROPIC_API_KEY
    msg = client.messages.create(
        model=model,
        max_tokens=16000,
        system=_system_prompt(lang_name, glossary),
        messages=[{"role": "user", "content": text}],
    )
    if msg.stop_reason != "end_turn":
        raise RuntimeError(f"translation stopped early ({msg.stop_reason})")
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
# verbatim (code, URLs, HTML tags, glossary terms) behind {N} sentinels, then
# restore them afterwards. Curly {N} is what MT engines are trained to preserve
# (software format strings), so it survives code-heavy lines far better than
# @@N@@ / ZZZ…ZZZ / 〔N〕 did. A trailing English possessive (`XC_VM's`) is
# consumed INTO the masked span and dropped on restore (Russian has no `'s`).

_TS_SENTINEL = re.compile(r"\{(\d+)\}")
_POSS = r"(?:['’]s|['’])?"  # optional trailing possessive, dropped on restore
_HR = re.compile(r"^\s*([-*_])( *\1){2,}\s*$")  # thematic break ---
_TABLE_SEP = re.compile(r"^\s*\|?[\s:|-]+\|?\s*$")  # |---|:--:| row
_HEADING = re.compile(r"^(\s{0,3}#{1,6}\s+)(.*)$")
_LIST = re.compile(r"^(\s*(?:[-*+]|\d+[.)])\s+)(.*)$")
_QUOTE = re.compile(r"^(\s*>+\s*)(.*)$")


def _protect_inline(text, glossary, tr=None, memo=None):
    store: list[str] = []

    def keep(m: "re.Match[str]") -> str:  # store the whole match
        store.append(m.group(0))
        return f"{{{len(store) - 1}}}"

    def keep1(m: "re.Match[str]") -> str:  # store group 1 (drops possessive)
        store.append(m.group(1))
        return f"{{{len(store) - 1}}}"

    # Bold spans `**...**`: translate the inner text on its own, then store the
    # whole balanced `**...**` as ONE atomic sentinel. The MT engine never sees
    # the `**` markers, so it cannot reorder or collapse them — masking each `**`
    # separately let the words move out from between the pair and left `****`
    # (empty bold) or a dropped closing `**`. Needs a translator; without one
    # (plain masking use) bold is left untouched.
    if tr is not None:

        def keep_bold(m: "re.Match[str]") -> str:
            inner = _ts_span(m.group(1), tr, glossary, memo if memo is not None else {})
            store.append("**" + inner + "**")
            return f"{{{len(store) - 1}}}"

        text = re.sub(r"\*\*(.+?)\*\*", keep_bold, text)  # bold (inner translated)

    text = re.sub(rf"(`[^`]*`){_POSS}", keep1, text)  # inline code (+poss)
    text = re.sub(r"!\[[^\]]*\]\([^)]*\)", keep, text)  # images (whole)
    text = re.sub(r"(?<=\])\([^)]*\)", keep, text)  # link target (url)
    text = re.sub(r"https?://[^\s)]+", keep, text)  # bare URLs
    text = re.sub(r"</?[A-Za-z][^>]*>", keep, text)  # HTML tags
    # Named placeholders ({bin}), printf specs (%s, %1$d) and HTML entities.
    # Letter-led {name} never collides with the numeric {N} sentinels above.
    text = re.sub(r"\{[A-Za-z_]\w*\}|%(?:\d+\$)?[sdf]|&#?\w+;", keep, text)
    for term in glossary:
        text = re.sub(rf"(?<![\w-])({re.escape(term)}){_POSS}(?![\w-])", keep1, text)
    return text, store


def _restore(text: str, store: list[str]) -> str:
    return _TS_SENTINEL.sub(lambda m: store[int(m.group(1))], text)


def _ts_span(text, tr, glossary, memo):
    """Translate one run of prose, masking inline non-translatables.

    An engine can still occasionally mangle a `{N}` sentinel (split/duplicate a
    brace) non-deterministically. Every brace we insert must be gone after
    restore, so a clean result has exactly as many `{`/`}` as the source span. If
    a fragment survives, we retry (a fresh translation usually comes back clean);
    after a few failures we keep the English span rather than emit broken tokens.
    """
    if not text.strip():
        return text
    if text in memo:
        return memo[text]
    protected, store = _protect_inline(text, glossary, tr, memo)
    if not _TS_SENTINEL.sub("", protected).strip():
        out = text  # nothing left to translate (all masked)
    else:
        base_braces = text.count("{") + text.count("}")
        src_bold = text.count("**")
        out = None
        for _ in range(4):
            cand = _restore(tr(protected), store)
            # Clean result: no leftover sentinel braces AND the bold markers are
            # still balanced (a dropped `**` sentinel restores fewer `**` than
            # the source — reject it and retry, else fall back to English).
            if (
                cand.count("{") + cand.count("}") <= base_braces
                and cand.count("**") == src_bold
            ):
                out = cand
                break
        if out is None:
            out = text  # give up: keep English, never emit a broken sentinel
    memo[text] = out
    return out


def _ts_line(line, tr, glossary, memo):
    """Translate one Markdown line, preserving its structural prefix/markup."""
    if not line.strip() or _HR.match(line):
        return line
    if line.lstrip().startswith("|"):  # table row
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


def _md_lines(md):
    """Yield ``(line, in_code)`` per line; fence lines count as code."""
    in_code, fence = False, "```"
    for line in md.split("\n"):
        stripped = line.lstrip()
        if in_code:
            yield line, True
            if stripped.startswith(fence):
                in_code = False
        elif stripped.startswith(("```", "~~~")):
            in_code, fence = True, stripped[:3]
            yield line, True
        else:
            yield line, False


def _ts_document(md, tr, glossary, reuse=None):
    """``reuse`` maps a line index to its translation from the last run."""
    reuse = reuse or {}
    memo: dict[str, str] = {}
    return "\n".join(
        line if code else reuse[i] if i in reuse else _ts_line(line, tr, glossary, memo)
        for i, (line, code) in enumerate(_md_lines(md))
    )


# ── Line reuse and heading anchors (docs) ────────────────────────────────────
# A web engine words the same line a little differently on every call, so a file
# re-translated whole after a one-line edit drifts everywhere. Lines unchanged
# since the last committed translation keep it; only new lines are translated.


def _git(repo_root: Path, *args: str) -> str | None:
    r = subprocess.run(["git", *args], cwd=repo_root, capture_output=True, text=True)
    return r.stdout if r.returncode == 0 else None


def previous_lines(repo_root: Path, src_rel: str, dst_rel: str, text: str) -> dict[int, str]:
    """Line index in ``text`` -> its translation in the last commit of ``dst_rel``.

    Only for line-for-line translations (the `translators` provider): the
    committed pair must have equal line counts with identical code and blank
    lines, else nothing is reused. Lines still left in English are not reused.
    """
    commit = (_git(repo_root, "log", "-1", "--format=%H", "--", dst_rel) or "").strip()
    old_en = commit and _git(repo_root, "show", f"{commit}:{src_rel}")
    old_tr = commit and _git(repo_root, "show", f"{commit}:{dst_rel}")
    if not old_en or not old_tr:
        return {}
    a, b = old_en.split("\n"), old_tr.split("\n")
    if len(a) != len(b) or any(
        line != tr for (line, code), tr in zip(_md_lines(old_en), b) if code or not line.strip()
    ):
        return {}
    reuse = {}
    ops = difflib.SequenceMatcher(None, a, text.split("\n"), autojunk=False).get_opcodes()
    for tag, i1, i2, j1, _ in ops:
        if tag == "equal":
            for k in range(i2 - i1):
                if b[i1 + k] != a[i1 + k]:
                    reuse[j1 + k] = b[i1 + k]
    return reuse


# A pinned anchor from an earlier run (or a `{ #id }` the engine carried over).
_PINNED = re.compile(r'<a id="[^"]*"></a>|\s*\{\s*#[^}]*\}\s*$')


def _heading_indexes(md: str) -> list[int]:
    return [i for i, (line, code) in enumerate(_md_lines(md)) if not code and _HEADING.match(line)]


def _heading_ids(headings: list[str]) -> list[str]:
    """The anchor ids MkDocs gives these heading lines, in order."""
    import markdown  # lazy: installed with mkdocs in the docs venv

    md = markdown.Markdown(extensions=["toc", "attr_list"])
    md.convert("\n\n".join(headings))
    ids: list[str] = []

    def walk(tokens):
        for t in tokens:
            ids.append(t["id"])
            walk(t["children"])

    walk(md.toc_tokens)
    return ids


def pin_anchors(src: str, out: str) -> str | None:
    """Put each English heading anchor into its translated heading as an empty
    ``<a id>``, so links to ``page#section`` keep working; unlike ``{ #id }`` it
    stays invisible on GitHub and in editors too. ``None`` if the headings do
    not line up."""
    src_lines, out_lines = src.split("\n"), out.split("\n")
    src_idx, out_idx = _heading_indexes(src), _heading_indexes(out)
    heads = []
    for i in out_idx:
        prefix, text = _HEADING.match(out_lines[i]).groups()
        heads.append((prefix, _PINNED.sub("", text)))
    ids = _heading_ids([src_lines[i] for i in src_idx])
    own = _heading_ids([p + t for p, t in heads])
    if not len(ids) == len(src_idx) == len(out_idx) == len(own):
        return None
    for i, (prefix, text), hid, oid in zip(out_idx, heads, ids, own):
        out_lines[i] = prefix + text if hid == oid else f'{prefix}<a id="{hid}"></a>{text}'
    return "\n".join(out_lines)


# (engine, lang) pairs an engine refused as an unsupported language this run.
_TS_UNSUPPORTED: set[tuple[str, str]] = set()


def provider_translators(text: str, lang: str, glossary: list[str], reuse=None) -> str:
    """Free, key-less translation via the `translators` web engines.

    Engine order is set by DOCS_TRANSLATE_TS_ENGINES (first that answers wins).
    yandex leads as the most reliable en->ru here, but its free endpoint lacks
    languages such as ar; modernMt / reverso / translateCom cover those and,
    unlike google / bing, are reachable from networks that cut those off.
    """
    import translators as ts  # lazy

    engines = [
        e.strip()
        for e in os.environ.get(
            "DOCS_TRANSLATE_TS_ENGINES", "yandex,modernMt,reverso,translateCom,google,bing,alibaba"
        ).split(",")
        if e.strip()
    ]

    def tr(s: str) -> str:
        errors = []
        for engine in engines:
            if (engine, lang) in _TS_UNSUPPORTED:
                continue
            for _ in range(3):
                try:
                    return ts.translate_text(
                        s, translator=engine, from_language="en", to_language=lang
                    )
                except Exception as exc:  # noqa: BLE001 — try the next engine
                    if "Unsupported" in str(exc):  # no retry will change that
                        _TS_UNSUPPORTED.add((engine, lang))
                        errors.append(f"{engine}: no {lang}")
                        break
                    last_err = exc
                    time.sleep(1.0)
            else:
                errors.append(f"{engine}: {last_err}")
        raise RuntimeError("all translators engines failed: " + "; ".join(errors))

    return _ts_document(text, tr, glossary, reuse)


PROVIDERS = {
    "noop": provider_noop,
    "translators": provider_translators,
    "anthropic": provider_anthropic,
    "deepl": provider_deepl,
}


# ── Panel language files (.ini) ──────────────────────────────────────────────
# Read by PHP with parse_ini_file(..., INI_SCANNER_RAW): `key = "value"`, inner
# quotes escaped as \", `;` comments and a `[Language]` section header.

_INI_ENTRY = re.compile(r"^(?P<key>[^;#\[\s=][^=]*?)\s*=\s*(?P<val>.*?)\s*$")


def _ini_unquote(raw: str) -> str:
    if len(raw) >= 2 and raw[0] == raw[-1] == '"':
        raw = raw[1:-1]
    return raw.replace('\\"', '"')


def _ini_quote(value: str) -> str:
    return '"' + " ".join(value.splitlines()).replace('"', '\\"') + '"'


def parse_ini(path: Path) -> dict[str, str]:
    """key => unescaped value; a repeated key keeps the last value, as PHP does."""
    entries: dict[str, str] = {}
    if path.is_file():
        for line in path.read_text(encoding="utf-8").splitlines():
            m = _INI_ENTRY.match(line)
            if m:
                entries[m.group("key")] = _ini_unquote(m.group("val"))
    return entries


def _render_ini(en_lines, values) -> str:
    """en.ini's layout with each key's value from ``values`` (English when absent)."""
    out = []
    for line in en_lines:
        m = _INI_ENTRY.match(line)
        if not m:
            out.append(line)  # comment, blank line or [Language] section
            continue
        key = m.group("key")
        out.append(f"{key} = {_ini_quote(values.get(key, _ini_unquote(m.group('val'))))}")
    return "\n".join(out) + "\n"


def sync_ini(en_path, dst_path, lang, translate, glossary, cache, provider_name, save_cache=lambda: None):
    """Rebuild ``dst_path`` in en.ini order; return (kept, translated, failed, removed).

    ``cache`` maps a cache_key of the English value to its translation, so a
    value the engine legitimately returns unchanged ("CPU") is not re-sent on
    every run just because it still equals the English text.

    The file and the cache (``save_cache``) are written after EVERY new
    translation, so a run that crashes or is killed loses at most one key; the
    keys it had not reached yet stay as they were (or English) and the next
    run picks them up.
    """
    current = parse_ini(dst_path)
    en_lines = en_path.read_text(encoding="utf-8").splitlines()
    en_keys = {m.group("key") for m in map(_INI_ENTRY.match, en_lines) if m}
    values = {k: v for k, v in current.items() if k in en_keys}
    kept = translated = failed = 0
    dst_path.parent.mkdir(parents=True, exist_ok=True)
    for line in en_lines:
        m = _INI_ENTRY.match(line)
        if not m:
            continue
        key, en_value = m.group("key"), _ini_unquote(m.group("val"))
        value = current.get(key)
        if value is not None and (value != en_value or not en_value.strip()):
            kept += 1
            continue
        ck = cache_key(en_value, lang, provider_name, glossary)
        if ck in cache:
            values[key] = cache[ck]
            continue
        try:
            value = translate(en_value, lang, glossary).strip()
        except Exception as exc:  # noqa: BLE001 — keep English, retry next run
            print(f"  WARN {key}: translation failed ({exc}); keeping English", file=sys.stderr)
            failed += 1
            values[key] = en_value
            continue
        cache[ck] = values[key] = value
        translated += 1
        dst_path.write_text(_render_ini(en_lines, values), encoding="utf-8")
        save_cache()
        if translated % 25 == 0:
            print(f"  … {translated} keys translated", flush=True)
    removed = sorted(set(current) - en_keys)
    dst_path.write_text(_render_ini(en_lines, values), encoding="utf-8")
    return kept, translated, failed, removed


def run_ini(args, translate, provider_name, glossary, repo_root) -> int:
    src = Path(args.ini_dir) if args.ini_dir else repo_root / "src" / "Core" / "Localization" / "lang"
    en_path = src / "en.ini"
    if not en_path.is_file():
        print(f"error: {en_path} not found", file=sys.stderr)
        return 1
    langs = (
        sorted(p.stem for p in src.glob("*.ini") if p.stem != "en")
        if args.lang == "all"
        else [args.lang]
    )
    dst = Path(args.dst) if args.dst else src
    cache_dir = Path(args.cache)
    cache_dir.mkdir(parents=True, exist_ok=True)

    # Two runs would each rewrite the same .ini and cache and drop the other's
    # translations; the second one stops instead. Released when the process ends.
    lock = open(cache_dir / "ini.lock", "w")  # noqa: SIM115 — held for the whole run
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        print("error: another --ini run is in progress; wait for it or stop it", file=sys.stderr)
        return 1

    for lang in langs:
        cache_file = cache_dir / f"ini-{lang}.json"
        cache = json.loads(cache_file.read_text(encoding="utf-8")) if cache_file.is_file() else {}

        def save_cache(cache=cache, cache_file=cache_file):
            cache_file.write_text(json.dumps(cache, ensure_ascii=False, indent=0), encoding="utf-8")

        print(f"→ {lang}.ini …", flush=True)
        try:
            kept, translated, failed, removed = sync_ini(
                en_path, dst / f"{lang}.ini", lang, translate, glossary, cache, provider_name, save_cache
            )
        finally:
            save_cache()  # the cache hits and fallbacks since the last translation, even on Ctrl+C
        for key in removed:
            print(f"  removed {key}")
        print(
            f"[{provider_name}] {lang}.ini: {kept} kept, {translated} translated, "
            f"{failed} fell back to English, {len(removed)} removed"
        )
    return 0


# ── Driver ───────────────────────────────────────────────────────────────────


def main() -> int:
    repo_root = Path(__file__).resolve().parents[2]
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument(
        "--lang",
        required=True,
        help="target language code, e.g. ru ('all' = every <lang>.ini with --ini)",
    )
    ap.add_argument(
        "--ini",
        action="store_true",
        help="sync the panel .ini language files with en.ini instead of the docs",
    )
    ap.add_argument(
        "--ini-dir",
        default=None,
        help="language files dir (default: src/Core/Localization/lang)",
    )
    ap.add_argument(
        "--src",
        default=str(repo_root / "docs" / "en"),
        help="source (English) docs dir",
    )
    ap.add_argument(
        "--dst",
        default=None,
        help="destination dir (default: docs/<lang>; with --ini: in place)",
    )
    ap.add_argument(
        "--cache",
        default=str(repo_root / "build" / "docs-cache"),
        help="per-file translation cache dir",
    )
    ap.add_argument(
        "--glossary",
        default=str(repo_root / "tools" / "i18n" / "glossary.txt"),
        help="do-not-translate term list",
    )
    args = ap.parse_args()

    provider_name = os.environ.get("DOCS_TRANSLATE_PROVIDER", "noop")
    translate = PROVIDERS.get(provider_name)
    if translate is None:
        print(
            f"error: unknown DOCS_TRANSLATE_PROVIDER={provider_name!r} "
            f"(known: {', '.join(PROVIDERS)})",
            file=sys.stderr,
        )
        return 2

    if args.ini:
        return run_ini(args, translate, provider_name, load_glossary(Path(args.glossary)), repo_root)

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
            # Printed BEFORE the (potentially slow, network-bound) call so the run
            # visibly shows which file it is on instead of going silent. flush keeps
            # it live even when stdout is a pipe (make -u also helps).
            print(f"→ translating {rel} …", flush=True)
            kwargs = {}
            if translate is provider_translators:
                try:
                    kwargs["reuse"] = previous_lines(
                        repo_root,
                        str(path.resolve().relative_to(repo_root)),
                        str((dst / rel).resolve().relative_to(repo_root)),
                        text,
                    )
                except ValueError:  # --src/--dst outside the repo: no history
                    pass
                if kwargs.get("reuse"):
                    print(f"  reusing {len(kwargs['reuse'])} lines from the last translation")
            try:
                out = translate(text, args.lang, glossary, **kwargs)
            except Exception as exc:  # noqa: BLE001
                # Graceful degradation: a flaky/rate-limited web engine must never
                # break the docs build — fall back to the English source for this
                # file (and do NOT cache it, so it is retried next run).
                print(
                    f"  WARN {rel}: translation failed ({exc}); keeping English",
                    file=sys.stderr,
                )
                failed += 1
                out = text
            else:
                cache_file.write_text(out, encoding="utf-8")
                translated += 1
                print(f"  translated {rel}")

        pinned = pin_anchors(text, out)
        if pinned is None:
            print(f"  WARN {rel}: headings differ from English; anchors not pinned", file=sys.stderr)
        else:
            out = pinned

        out_path = dst / rel
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(out, encoding="utf-8")

    # Prune orphans: delete generated files whose English source no longer exists
    # (renamed/removed in docs/en), then drop any now-empty dirs — so the
    # translated tree always mirrors docs/en 1:1 and never ships stale pages.
    expected = {p.relative_to(src) for p in md_files}
    pruned = 0
    if dst.is_dir():
        for gen in sorted(dst.rglob("*.md")):
            if gen.relative_to(dst) not in expected:
                gen.unlink()
                pruned += 1
                print(f"  pruned orphan {gen.relative_to(dst)}")
        for d in sorted((p for p in dst.rglob("*") if p.is_dir()), reverse=True):
            if not any(d.iterdir()):
                d.rmdir()

    print(
        f"[{provider_name}] {args.lang}: {len(md_files)} files "
        f"({translated} translated, {cached} from cache, {failed} fell back "
        f"to English, {pruned} pruned) -> {dst}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
