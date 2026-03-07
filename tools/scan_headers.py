#!/usr/bin/env python3
"""Scan PHP file headers for garbage patterns."""

import os
import re
import sys

BASE = '/media/divarion/FILES/Programming/Vateron_media/XC_VM/src'
DIRS = [
    'core',
    'domain',
    'infrastructure',
    'streaming',
    'modules',
    'public/Controllers',
]

PATTERNS = {
    '1_XC_VM_prefix': re.compile(r'XC_VM\s*[—–-]', re.IGNORECASE),
    '2_Replaces_CoreUtilities': re.compile(r'Replaces?\s+CoreUtilities|replaces?\s+\w+Utilities', re.IGNORECASE),
    '3_Extracted_from_ru': re.compile(r'Извлечён|Извлечено', re.IGNORECASE),
    '4_Phase_ref': re.compile(r'\(Phase\s+\d|Фаза\s+\d|\(Phase\s+\d+\.\d+', re.IGNORECASE),
    '5_Section_ref': re.compile(r'§\d+\.\d+', re.IGNORECASE),
    '6_Zamenyaet': re.compile(r'Заменяет', re.IGNORECASE),
    '7_see_CoreUtilities': re.compile(r'@see\s+CoreUtilities::', re.IGNORECASE),
    '8_What_it_replaces': re.compile(r'What it replaces', re.IGNORECASE),
    '9_Soderzhit_logiku': re.compile(r'Содержит логику из|Содержит логику', re.IGNORECASE),
    '10_migration_misc': re.compile(r'Migrated from|Moved from|Originally in|Previously in|Ранее в|Перенесён|Was in|Бывший', re.IGNORECASE),
    '11_StreamingUtilities_ref': re.compile(r'Replaces?\s+StreamingUtilities|Заменяет\s+StreamingUtilities|@see\s+StreamingUtilities::', re.IGNORECASE),
    '12_admin_php_ref': re.compile(r'Replaces?\s+admin\.php|admin/functions\.php|@see\s+admin', re.IGNORECASE),
}

REPORT = '/media/divarion/FILES/Programming/Vateron_media/XC_VM/tools/header_report.txt'

results = {}
total_files = 0
clean_files = 0

for d in DIRS:
    path = os.path.join(BASE, d)
    if not os.path.isdir(path):
        print(f"[SKIP] Directory not found: {d}")
        continue

    files = []
    for root, dirs_, fnames in os.walk(path):
        for f in sorted(fnames):
            if f.endswith('.php'):
                files.append(os.path.join(root, f))

    files.sort()

    for fpath in files:
        total_files += 1
        rel = os.path.relpath(fpath, BASE)

        try:
            with open(fpath, 'r', encoding='utf-8', errors='replace') as fh:
                lines = []
                for i, line in enumerate(fh):
                    if i >= 20:
                        break
                    lines.append(line)
                header = ''.join(lines)
        except Exception as e:
            print(f"[ERROR] {rel}: {e}")
            continue

        found = []
        for pat_name, pat_re in PATTERNS.items():
            if pat_re.search(header):
                found.append(pat_name)

        if found:
            results[rel] = {
                'patterns': found,
                'header_preview': header.rstrip(),
            }
        else:
            clean_files += 1

# === Write Report to file ===
import sys
from collections import defaultdict, Counter

with open(REPORT, 'w', encoding='utf-8') as out:
    out.write("=" * 80 + "\n")
    out.write(f"TOTAL PHP FILES SCANNED: {total_files}\n")
    out.write(f"CLEAN (no garbage): {clean_files}\n")
    out.write(f"WITH GARBAGE PATTERNS: {len(results)}\n")
    out.write("=" * 80 + "\n")

    by_dir = defaultdict(list)
    for rel, info in sorted(results.items()):
        d = rel.split('/')[0]
        by_dir[d].append((rel, info))

    for d in sorted(by_dir):
        out.write(f"\n{'='*60}\n")
        out.write(f"DIRECTORY: {d}/ ({len(by_dir[d])} files with garbage)\n")
        out.write(f"{'='*60}\n")
        for rel, info in by_dir[d]:
            out.write(f"\n  FILE: {rel}\n")
            out.write(f"  PATTERNS: {', '.join(info['patterns'])}\n")
            hlines = info['header_preview'].split('\n')[:15]
            for i, hl in enumerate(hlines, 1):
                out.write(f"    {i:2d}| {hl}\n")

    out.write("\n\n" + "=" * 80 + "\n")
    out.write("SUMMARY TABLE\n")
    out.write("=" * 80 + "\n")
    out.write(f"{'File':<65} {'Patterns'}\n")
    out.write("-" * 120 + "\n")
    for rel, info in sorted(results.items()):
        short_pats = ', '.join(p.split('_', 1)[1] for p in info['patterns'])
        out.write(f"  {rel:<63} {short_pats}\n")

    out.write("\n\nPATTERN FREQUENCY:\n")
    out.write("-" * 60 + "\n")
    counts = Counter()
    for info in results.values():
        for p in info['patterns']:
            counts[p] += 1
    for p, c in counts.most_common():
        label = p.split('_', 1)[1]
        out.write(f"  {label:<40} {c:3d} files\n")

sys.stdout.write(f"Report written to {REPORT}\n")
sys.stdout.flush()
