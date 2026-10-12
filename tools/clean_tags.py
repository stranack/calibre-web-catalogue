#!/usr/bin/env python3
"""
Remove stray one-off tags from a Calibre metadata.db once the library uses
two-level "Broad.Narrow" tags.

Kept:    every tag containing a dot (History.Canada), plus any plain tag that
         matches a broad heading in use (for example "Philosophy" when
         "Philosophy.Stoicism" exists), plus anything listed with --keep.
Removed: every other tag, from every book. Tags left unused are deleted.

Usage: clean_tags.py METADATA_DB [--keep "Tag one;Tag two"] [--dry-run]

Run it on a COPY of metadata.db with Calibre closed (see tools/README.md).
"""
import sqlite3
import sys


def main():
    if len(sys.argv) < 2 or sys.argv[1].startswith('-'):
        sys.exit(__doc__)
    db_path = sys.argv[1]
    dry = '--dry-run' in sys.argv
    keep = set()
    if '--keep' in sys.argv:
        keep = {t.strip() for t in sys.argv[sys.argv.index('--keep') + 1].split(';') if t.strip()}

    con = sqlite3.connect(db_path)
    cur = con.cursor()
    if cur.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
        sys.exit('metadata.db failed its integrity check; nothing changed.')

    broads = {name.split('.', 1)[0].strip().lower() for (name,) in cur.execute("SELECT name FROM tags WHERE name LIKE '%.%'")}
    if not broads:
        sys.exit('No "Broad.Narrow" tags found, so there is no scheme to clean towards. Nothing changed.')

    def stays(name):
        return '.' in name or name.strip().lower() in broads or name in keep

    cur.execute('BEGIN')
    links = cur.execute('SELECT l.id, l.book, t.name FROM books_tags_link l JOIN tags t ON t.id = l.tag').fetchall()
    gone = [(lid, book, name) for lid, book, name in links if not stays(name)]
    for lid, book, _ in gone:
        cur.execute('DELETE FROM books_tags_link WHERE id = ?', (lid,))
        cur.execute('INSERT OR IGNORE INTO metadata_dirtied (book) VALUES (?)', (book,))
    unused = [tid for (tid,) in cur.execute('SELECT id FROM tags WHERE id NOT IN (SELECT tag FROM books_tags_link)')]
    for tid in unused:
        cur.execute('DELETE FROM tags WHERE id = ?', (tid,))

    removed_names = sorted({name for _, _, name in gone}, key=str.lower)
    if dry:
        con.rollback()
    else:
        con.commit()
        if cur.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
            sys.exit('Integrity check failed after writing. Restore your backup.')
    print(f"{'Would remove' if dry else 'Removed'} {len(gone)} tag assignments from "
          f"{len({b for _, b, _ in gone})} books; {len(unused)} unused tags {'would be ' if dry else ''}deleted.")
    for n in removed_names:
        print('  -', n[:100])
    con.close()


if __name__ == '__main__':
    main()
