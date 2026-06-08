#!/usr/bin/env python3
"""Validate that bible_data_strongs.dart matches kjvs.csv exactly.

Note: Dart uses single-quoted strings, so any apostrophe (') in the original
text is escaped as \' in the Dart file. The validation accounts for this.
"""

import re
import sys

def parse_csv_line(line):
    """Parse a CSV line and return (book, chapter, verse, text)."""
    line = line.strip()
    if not line:
        return None
    
    parts = line.split('|', 1)
    if len(parts) != 2:
        return None
    
    ref_part, text = parts
    
    match = re.match(r'^([A-Za-z0-9]+)\s+(\d+):(\d+)$', ref_part)
    if not match:
        return None
    
    return (match.group(1), int(match.group(2)), int(match.group(3)), text)


def unescape_dart(text):
    """Convert Dart-escaped string back to original by unescaping \'."""
    return text.replace("\\'", "'")


def main():
    # Read CSV
    csv_entries = {}
    with open('kjvs.csv', 'r', encoding='utf-8') as f:
        for line in f:
            parsed = parse_csv_line(line)
            if parsed:
                book, chap, verse, text = parsed
                key = (book, chap, verse)
                csv_entries[key] = text
    
    # Read Dart file and extract verse texts
    dart_entries = {}
    current_book = None
    current_chapter = None
    
    with open('bible_data_strongs.dart', 'r', encoding='utf-8') as f:
        for line in f:
            # Match book lines: 'Gen': {
            book_match = re.match(r"^\s+'([A-Za-z0-9]+)':\s*{$", line)
            if book_match:
                current_book = book_match.group(1)
                current_chapter = None
                continue
            
            # Match chapter lines: 1: {
            chap_match = re.match(r"^\s+(\d+):\s*{$", line)
            if chap_match and current_book:
                current_chapter = int(chap_match.group(1))
                continue
            
            # Match verse lines: 1: 'text',
            verse_match = re.match(r"^\s+(\d+):\s+'(.*)',\s*$", line)
            if verse_match and current_book and current_chapter is not None:
                verse = int(verse_match.group(1))
                text = verse_match.group(2)
                dart_entries[(current_book, current_chapter, verse)] = text
    
    # Compare (unescape Dart text before comparing)
    mismatches = []
    missing_in_csv = []
    missing_in_dart = []
    
    all_keys = set(csv_entries.keys()) | set(dart_entries.keys())
    
    for key in sorted(all_keys):
        csv_text = csv_entries.get(key)
        dart_text = dart_entries.get(key)
        
        if csv_text is None:
            missing_in_csv.append(key)
            continue
        
        if dart_text is None:
            missing_in_dart.append(key)
            continue
        
        # Unescape Dart text for comparison
        dart_unescaped = unescape_dart(dart_text)
        
        if csv_text != dart_unescaped:
            mismatches.append((key, csv_text, dart_text))
    
    print(f"Total CSV entries: {len(csv_entries)}")
    print(f"Total Dart entries: {len(dart_entries)}")
    
    if missing_in_csv:
        print(f"\n! Missing in CSV ({len(missing_in_csv)}):")
        for k in missing_in_csv[:10]:
            print(f"  {k}")
    
    if missing_in_dart:
        print(f"\n! Missing in Dart ({len(missing_in_dart)}):")
        for k in missing_in_dart[:10]:
            print(f"  {k}")
    
    if mismatches:
        print(f"\n! TEXT MISMATCHES ({len(mismatches)}):")
        for key, csv_t, dart_t in mismatches[:10]:
            print(f"  {key}:")
            print(f"    CSV:  '{csv_t}'")
            print(f"    Dart: '{dart_t}'")
    else:
        print("\n✓ ALL entries match perfectly!")
    
    # Also check ordering - verify Dart file preserves CSV order
    csv_order = list(csv_entries.keys())
    dart_order = list(dart_entries.keys())
    
    if csv_order == dart_order:
        print("✓ Order matches perfectly!")
    else:
        # Find first divergence
        order_ok = True
        for i, (ck, dk) in enumerate(zip(csv_order, dart_order)):
            if ck != dk:
                print(f"\n! Order mismatch at position {i}:")
                print(f"  CSV:  {ck}")
                print(f"  Dart: {dk}")
                order_ok = False
                break
        if order_ok and len(csv_order) != len(dart_order):
            print(f"\n! Length mismatch: CSV has {len(csv_order)}, Dart has {len(dart_order)}")
    
    if not missing_in_csv and not missing_in_dart and not mismatches:
        print("\n✓ VALIDATION PASSED - Data is 100% identical")
        return 0
    else:
        print(f"\n! VALIDATION FAILED: {len(missing_in_csv)} missing in CSV, "
              f"{len(missing_in_dart)} missing in Dart, "
              f"{len(mismatches)} text mismatches")
        return 1


if __name__ == '__main__':
    sys.exit(main())