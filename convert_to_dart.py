#!/usr/bin/env python3
"""Convert kjvs.csv to a Dart file with bibleDataStrongs map."""

import csv
import re
import sys

def main():
    input_file = 'kjvs.csv'
    output_file = 'bible_data_strongs.dart'
    
    # Read all lines maintaining order
    entries = []  # List of (book_abbr, chapter, verse, text)
    
    with open(input_file, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            
            # Parse: "Book Chap:Verse|Text"
            parts = line.split('|', 1)
            if len(parts) != 2:
                print(f"Skipping malformed line: {line}", file=sys.stderr)
                continue
            
            ref_part, text = parts
            
            # Split ref into book and "chap:verse"
            # Book name is alphabetic, then space, then chap:verse
            match = re.match(r'^([A-Za-z0-9]+)\s+(\d+):(\d+)$', ref_part)
            if not match:
                print(f"Could not parse reference: {ref_part}", file=sys.stderr)
                continue
            
            book = match.group(1)
            chapter = int(match.group(2))
            verse = int(match.group(3))
            
            # Escape single quotes for Dart
            escaped_text = text.replace("'", "\\'")
            
            entries.append((book, chapter, verse, escaped_text))
    
    # Write Dart file, preserving original order
    with open(output_file, 'w', encoding='utf-8') as out:
        #out.write("// Auto-generated from kjvs.csv\n")
        #out.write("// Strong's Concordance Bible Data\n\n")
        out.write("const Map<String, Map<int, Map<int, String>>> bibleDataStrongs = {\n")
        
        current_book = None
        current_chapter = None
        
        for book, chapter, verse, text in entries:
            if book != current_book:
                if current_book is not None:
                    # Close previous chapter
                    out.write("    },\n")
                    # Close previous book
                    out.write("  },\n")
                out.write(f"  '{book}': {{\n")
                current_book = book
                current_chapter = None
            
            if chapter != current_chapter:
                if current_chapter is not None:
                    out.write("    },\n")
                out.write(f"    {chapter}: {{\n")
                current_chapter = chapter
            
            out.write(f"      {verse}: '{text}',\n")
        
        # Close last chapter
        if current_chapter is not None:
            out.write("    },\n")
        # Close last book
        if current_book is not None:
            out.write("  },\n")
        
        out.write("};\n")
    
    print(f"Successfully generated {output_file} with {len(entries)} verses")

if __name__ == '__main__':
    main()