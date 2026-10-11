# Adding a Custom Language

XC_VM uses a file-based translation system. Each language is a single `.ini` file in the `src/Core/Localization/lang/` directory. Adding a new language requires no code changes — just create a file and it will appear in the admin panel automatically.

On an installed panel, use the [translation manager](#translation-manager) instead: it keeps your translation outside the code, where an update does not replace it. The steps below are for a language that ships with XC_VM.

## Quick Start

1. Generate a machine-translated starting point from `en.ini`:

```bash
make lang-translate LANG_TRANSLATE=xx
```

Replace `xx` with the [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) language code (e.g., `it` for Italian, `pl` for Polish, `ja` for Japanese). The file does not have to exist yet — it is created with every key from `en.ini`. See [Keeping translations in sync](#keeping-translations-in-sync).

To translate by hand instead, copy the English file as a template:

```bash
cp src/Core/Localization/lang/en.ini src/Core/Localization/lang/xx.ini
```

2. Open `xx.ini` and review or translate the values (right side of `=`):

```ini
[Language]
a_to_z = "A to Z"          ; ← translate this
access_code = "Access Code" ; ← translate this
actions = "Actions"         ; ← translate this
```

3. Go to **Settings → Interface → Interface Language** and select the new language code.

That's it. No restart required.

## File Format

Each `.ini` file follows this structure:

```ini
[Language]
key = "Translated text"
another_key = "Another translated text"
```

**Rules:**

- The `[Language]` section header is **required** on the first line.
- Keys are `snake_case` identifiers — **do not change them**.
- Values must be enclosed in **double quotes**; a literal quote inside a value is written as `\"`.
- Keep placeholders such as `{bin}`, `{count}` and `%s`, and any HTML tags, exactly as they are in `en.ini`.
- Keys follow the order and `;` comment sections of `en.ini`.
- The file encoding must be **UTF-8** (without BOM).

## How It Works

| Step | What happens |
|------|-------------|
| Panel boot | `Translator::init()` scans `src/Core/Localization/lang/` (bundled) and `config/lang/` (custom) for `*.ini` files |
| Language list | `Translator::available()` returns all found language codes, of both directories |
| Loading a language | The bundled file is read first, the custom file over it; a key neither has falls back to `en.ini` |
| User selection | Language is stored in a `lang` cookie (per browser) and in the `settings.language` DB column (global default) |
| Missing key | If a translation key is used in code but missing from the language, the English value from `en.ini` is shown (or the key name if `en.ini` lacks it too). No language file is written at runtime: the [translation manager](#translation-manager) lists what a language lacks (**Missing only**) |

## Translation manager

**Translations**, in the profile menu next to **General Settings**, manages panel languages without a code change. It needs the `settings` permission, like the Settings page.

| Action | What it does |
| --- | --- |
| **Download** | Any language as an `.ini` file in `en.ini` order. A key the language does not translate carries the English text. `en.ini` is the template to translate |
| **Upload** | An `.ini` file for a new or an existing language code (`tr`, `fil`, `pt-BR`). The code you type names the language; the name of the uploaded file is not used |
| **Edit** | A key / English / translation table with a search and a **Missing only** filter. **Save Changes** stores the changed rows |
| **Translated** | How many of the keys of `en.ini` the language translates |

### Where custom translations live

Uploads and edits are stored in `/home/xc_vm/config/lang/<code>.ini`. The bundled files in `Core/Localization/lang/` are never changed:

- `config/` is kept by an update, so custom translations **survive updates**. The bundled files are replaced by every update.
- A custom file holds only what differs from the bundled file of its language. It is read **over** the bundled file: it can override a bundled translation, add a key the bundled file lacks, or be a language the panel does not ship.
- A value that is empty, or still identical to the English text, is not a translation. It counts as missing, as it does for `make lang-translate`. Clearing a row in the editor, or typing the English text again, removes the custom translation of that key.
- The [recovery bundle](../administration/operations.md#recovery-bundle) holds `config/lang/`. A database backup does not.

### What is checked and sanitised

Views print a translation as HTML, so an uploaded or edited value is checked before it is stored:

| Check | Rule |
| --- | --- |
| Language code | Two or three lowercase letters, optionally `-` and a region of two to four letters. It is the only source of the file name |
| File | At most 1 MB, and readable as an `.ini` file. A refused file names the first line that cannot be read |
| Keys | Only keys that exist in `en.ini` are kept |
| HTML | `<b>`, `<i>`, `<strong>`, `<em>`, `<code>` and `<br>` are kept, without attributes. Any other tag, and any tag with an attribute, is stored as text (`&lt;a href=…&gt;`) |
| Other characters | `&` and `"` are stored as entities; an entity already there (`&amp;`, `&nbsp;`) stays. A backslash is stored as `&#92;` and an apostrophe `'` as the typographic `’` |
| Placeholders | `{name}` and `%s` are kept as they are |
| Lines | A value is one line: a line break becomes a space |

- The bundled files are the panel's own text and are not sanitised. A value uploaded unchanged from a downloaded bundled language stays the bundled value.
- A link or an icon (`<a href=…>`, `<i class=…>`) cannot be part of a custom translation. The few English texts that carry one keep it only while their bundled translation is used.
- A file placed in `config/lang/` by hand is read as it is. Use the upload to have it checked.

## Available Languages

| Code | File |
|------|------|
| `ar` | `ar.ini` — Arabic |
| `bg` | `bg.ini` — Bulgarian |
| `de` | `de.ini` — German |
| `en` | `en.ini` — English (reference) |
| `es` | `es.ini` — Spanish |
| `fr` | `fr.ini` — French |
| `pt` | `pt.ini` — Portuguese |
| `ru` | `ru.ini` — Russian |

## Keeping translations in sync

`en.ini` is the source of truth. Developers add new UI strings to `en.ini` **only**; the other languages are brought up to date with:

```bash
make lang-translate                      # every <lang>.ini except en
make lang-translate LANG_TRANSLATE=ru    # a single language
```

This runs `tools/i18n/translate.py --ini`, which rebuilds each language file in `en.ini` order:

| Case | Result |
| --- | --- |
| Key translated already | Kept as is |
| Key missing from the file | Machine-translated from `en.ini` |
| Value still identical to the English text | Machine-translated |
| Key no longer in `en.ini` | **Removed** (listed in the output) |

- Placeholders (`{bin}`, `%s`), HTML tags, entities (`&mdash;`) and escaped quotes are never sent to the translation engine, so they come back unchanged.
- The engine is selected with `DOCS_TRANSLATE_PROVIDER`, the same as for the docs: `translators` (default, free web engines, no API key), `anthropic` (needs `ANTHROPIC_API_KEY`) or `noop` (copies English; a quick way to add missing keys and prune stale ones without translating).
- Translations are cached in `build/docs-cache/ini-<lang>.json`: a repeat run makes no network calls, and an interrupted run (Ctrl+C) resumes where it stopped. If the engine fails for a key, the English value is kept and retried on the next run.
- Machine translation is a starting point. Review the `git diff` of the language files before committing, and fix wording by editing the translated value — a value that differs from English is never overwritten.

## Tips

- **Always use `en.ini` as the source of truth** — it contains all keys. Other files may have missing keys; at runtime those show the English text.
- **Missing keys**: a language that is missing a key shows the English text. Older versions appended such a key to the language file at runtime; nothing is written now. `make lang-translate` translates the keys a bundled file lacks.
- **Validate your file** — make sure `parse_ini_file()` can read it:

```bash
php -r "var_dump(parse_ini_file('src/Core/Localization/lang/xx.ini', false, INI_SCANNER_RAW));" | head -20
```

## Contributing Translations

To contribute a translation to the project:

1. Fork the repository.
2. Create your language file as described above.
3. Submit a Pull Request with the new `.ini` file.

Please ensure all keys from `en.ini` are present and translated.

## Related files

| File | Role |
| --- | --- |
| `src/Core/Localization/Translator.php` | Translation lookup |
| `src/Core/Localization/TranslationStore.php` | Translation manager: upload, edit, download, sanitising |
| `src/Core/Localization/lang/` | Bundled language files |
| `config/lang/` | Custom language files (kept by an update) |
| `tools/i18n/translate.py` | Language-file sync (`--ini`) and docs translation |
