# Adding a Custom Language

XC_VM uses a file-based translation system. Each language is a single `.ini` file in the `src/resources/langs/` directory. Adding a new language requires no code changes — just create a file and it will appear in the admin panel automatically.

## Quick Start

1. Copy the English reference file as a template:

```bash
cp src/resources/langs/en.ini src/resources/langs/xx.ini
```

Replace `xx` with the [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) language code (e.g., `it` for Italian, `pl` for Polish, `ja` for Japanese).

2. Open `xx.ini` and translate the values (right side of `=`):

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
- Values must be enclosed in **double quotes**.
- Lines are sorted alphabetically by key for consistency.
- The file encoding must be **UTF-8** (without BOM).

## How It Works

| Step | What happens |
|------|-------------|
| Panel boot | `Translator::init()` scans `src/resources/langs/` for `*.ini` files |
| Language list | `Translator::available()` returns all found language codes |
| User selection | Language is stored in a `lang` cookie (per browser) and in the `settings.language` DB column (global default) |
| Missing key | If a translation key is used in code but missing from your `.ini` file, the system **automatically appends** it with the key name as the default value |

## Available Languages

| Code | File |
|------|------|
| `bg` | `bg.ini` — Bulgarian |
| `de` | `de.ini` — German |
| `en` | `en.ini` — English (reference) |
| `es` | `es.ini` — Spanish |
| `fr` | `fr.ini` — French |
| `pt` | `pt.ini` — Portuguese |
| `ru` | `ru.ini` — Russian |

## Tips

- **Always use `en.ini` as the source of truth** — it contains all keys. Other files may have missing keys that get auto-filled at runtime.
- **Auto-creation of missing keys**: if your file is missing a key, `Translator` will append `key = "key"` to your file automatically. You can then find and translate these untranslated entries.
- **Find untranslated keys** — look for lines where key equals value:

```bash
grep -P '^(\w+)\s*=\s*"\1"$' src/resources/langs/xx.ini
```

- **Validate your file** — make sure `parse_ini_file()` can read it:

```bash
php -r "var_dump(parse_ini_file('src/resources/langs/xx.ini', false, INI_SCANNER_RAW));" | head -20
```

## Contributing Translations

To contribute a translation to the project:

1. Fork the repository.
2. Create your language file as described above.
3. Submit a Pull Request with the new `.ini` file.

Please ensure all keys from `en.ini` are present and translated.
