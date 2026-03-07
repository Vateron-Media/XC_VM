#!/usr/bin/env python3
"""Clean garbage migration/phase info from PHP class file headers."""
import os, re

ROOT = '/media/divarion/FILES/Programming/Vateron_media/XC_VM/src'
changed = []

for dirpath, dirnames, filenames in os.walk(ROOT):
    for fname in filenames:
        if not fname.endswith('.php'):
            continue

        filepath = os.path.join(dirpath, fname)

        try:
            with open(filepath, 'r', encoding='utf-8') as f:
                content = f.read()
        except (UnicodeDecodeError, ValueError):
            continue

        original = content
        lines = content.split('\n')

        # Find first docblock /** ... */
        start_line = end_line = -1
        for i, line in enumerate(lines):
            if i > 60:  # Look up to 60 lines for end of docblock
                break
            stripped = line.strip()
            if start_line < 0 and i <= 10 and stripped.startswith('/**'):
                start_line = i
            if start_line >= 0 and stripped.endswith('*/'):
                end_line = i
                break

        if start_line < 0 or end_line < 0:
            continue

        doc_lines = lines[start_line:end_line + 1]
        doc_text = '\n'.join(doc_lines)
        orig_doc = doc_text

        # === SECTION REMOVALS ===

        # Remove "What it replaces:" section with surrounding dashes and content
        # Matches:  * ---\n * What it replaces:\n * ---\n( * ...\n)*
        doc_text = re.sub(
            r' \* -{3,}\n \* What it replaces:\n \* -{3,}\n(?: \*(?:\s*|   .*)\n)*',
            '', doc_text
        )

        # === FULL LINE REMOVALS ===

        # Remove multi-line "Извлечён/Извлечено из ..." (line ending with comma + continuations)
        doc_text = re.sub(r' \* Извлеч[её]н(?:о)? из [^\n]*,\n(?: \* [a-z][^\n]*\n)*', '', doc_text)
        # Remove single-line "Извлечён/Извлечено из ..."
        doc_text = re.sub(r' \* Извлеч[её]н(?:о)? из [^\n]*\n', '', doc_text)

        # Remove multi-line "Extracted from ..." (line ending with comma + continuations)
        doc_text = re.sub(r' \* Extracted from [^\n]*,\n(?: \* [a-z][^\n]*\n)*', '', doc_text)
        # Remove single-line "Extracted from ..."
        doc_text = re.sub(r' \* Extracted from [^\n]*\n', '', doc_text)

        # Remove "Thread/Multithread вынесены..." lines
        doc_text = re.sub(r' \* Thread/Multithread вынесены[^\n]*\n', '', doc_text)

        # Remove "Replaces ..." / "Заменяет ..." lines referencing old classes
        doc_text = re.sub(r' \* (?:Replaces|Заменяет)\s+(?:CoreUtilities|StreamingUtilities)[^\n]*\n', '', doc_text)

        # Remove "@see CoreUtilities::" lines
        doc_text = re.sub(r' \* @see CoreUtilities::[^\n]*\n', '', doc_text)
        # Also @see BruteforceGuard::checkFlood(, !empty($rCached)) — stale self-refs
        doc_text = re.sub(r' \* @see BruteforceGuard::[^\n]*\$rCached[^\n]*\n', '', doc_text)

        # Remove "CoreUtilities::method() → NewClass::method()" mapping lines
        doc_text = re.sub(r' \*   (?:CoreUtilities|StreamingUtilities)::\w+\([^)]*\)\s*→[^\n]*\n', '', doc_text)
        # Also posix_kill mapping lines
        doc_text = re.sub(r' \*   posix_kill\([^)]*\)\s*→[^\n]*\n', '', doc_text)

        # Remove "BruteforceGuard::checkFlood(, !empty($rCached))" stale mapping lines
        doc_text = re.sub(r' \*   BruteforceGuard::\w+\([^)]*\$rCached[^)]*\)\s*→[^\n]*\n', '', doc_text)

        # Remove "Заменяет CoreUtilities::$rConfig." style lines
        doc_text = re.sub(r' \* Заменяет (?:CoreUtilities|StreamingUtilities)::[^\n]*\n', '', doc_text)

        # Remove "Direct replacement for..." lines referencing old code
        doc_text = re.sub(r' \* (?:Direct replacement|for the inline igbinary)[^\n]*\n', '', doc_text)

        # Remove "BEFORE (CoreUtilities):" example blocks in "What it replaces" remnants
        doc_text = re.sub(r' \*   BEFORE \([^)]+\):\n(?: \*     [^\n]*\n)*', '', doc_text)
        doc_text = re.sub(r' \*   AFTER:\n(?: \*     [^\n]*\n)*', '', doc_text)
        doc_text = re.sub(r' \*   BEFORE \(inline\):\n(?: \*     [^\n]*\n)*', '', doc_text)

        # === INLINE REMOVALS (keep rest of line) ===

        # Remove (Phase X.X — Group Y) and (Phase X.X)
        doc_text = re.sub(r'\s*\(Phase\s+\d+[\.\d]*\s*(?:—\s*[^)]+)?\)', '', doc_text)

        # Remove (Фаза X.X аудит) and (Фаза X.X)
        doc_text = re.sub(r'\s*\(Фаза\s+\d+[\.\d]*(?:\s*аудит)?\)', '', doc_text)

        # Remove (§X.X)
        doc_text = re.sub(r'\s*\(§\d+[\.\d]*\)', '', doc_text)

        # Remove "XC_VM — " prefix
        doc_text = re.sub(r'XC_VM\s*—\s*', '', doc_text)

        # === CLEANUP ===

        # Remove orphaned dash separator lines
        doc_text = re.sub(r' \* -{3,}\n', '', doc_text)

        # Remove consecutive empty doc lines ( *\n *\n → *\n)
        doc_text = re.sub(r'( \*\s*\n){2,}', ' *\n', doc_text)

        # Remove empty line right after opening /**
        doc_text = re.sub(r'/\*\*\n \*\n', '/**\n', doc_text)

        # Remove empty line before closing */
        doc_text = re.sub(r'\n \*\n(\s*\*/)', r'\n\1', doc_text)

        # Remove trailing spaces on doc lines
        doc_text = re.sub(r' \* +\n', ' *\n', doc_text)

        if doc_text != orig_doc:
            new_lines = lines[:start_line] + doc_text.split('\n') + lines[end_line + 1:]
            content = '\n'.join(new_lines)

            # Remove fully empty docblocks: /**\n */
            content = re.sub(r'/\*\*\s*\n\s*\*/', '', content)

            # Clean up triple+ blank lines
            content = re.sub(r'\n{3,}', '\n\n', content)

            with open(filepath, 'w', encoding='utf-8') as f:
                f.write(content)

            rel = filepath.replace(ROOT + '/', '')
            changed.append(rel)

print(f"Changed {len(changed)} files:")
for f in sorted(changed):
    print(f"  {f}")
