#!/usr/bin/env python3
"""
Add two-level subject tags ("Broad.Narrow") to a Calibre metadata.db.

Only adds: existing tags, titles and other metadata are left alone.
Books touched are queued in metadata_dirtied so Calibre refreshes each
book's metadata.opf backup next time it starts, and the "tags" category is
switched to hierarchical display in Calibre's Tag browser.

Usage: add_tags.py METADATA_DB ASSIGNMENTS_FILE [--dry-run]

ASSIGNMENTS_FILE has one line per book: the Calibre book id, a "|", then the
tags separated by ";", for example:
    1413|Travel.Travel Writing;Fiction.Humour

Run it on a COPY of metadata.db with Calibre closed, check the result, then
copy it back (see tools/README.md).
"""
import json
import sqlite3
import sys


def main():
    if len(sys.argv) < 3:
        sys.exit(__doc__)
    db_path, assign_path = sys.argv[1], sys.argv[2]
    dry = '--dry-run' in sys.argv

    wanted = {}
    with open(assign_path, encoding='utf-8') as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            book, tags = line.split('|', 1)
            names = [t.strip() for t in tags.split(';') if t.strip()]
            for t in names:
                if ',' in t:
                    sys.exit(f'Tag contains a comma, which Calibre uses as a separator: {t}')
            wanted[int(book)] = names

    con = sqlite3.connect(db_path)
    cur = con.cursor()
    if cur.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
        sys.exit('metadata.db failed its integrity check; nothing changed.')
    tag_cols = [r[1] for r in cur.execute('PRAGMA table_info(tags)')]
    existing_books = {r[0] for r in cur.execute('SELECT id FROM books')}

    cur.execute('BEGIN')
    tag_ids = {}
    new_tags = 0
    for name in sorted({t for ts in wanted.values() for t in ts}):
        row = cur.execute('SELECT id FROM tags WHERE name = ?', (name,)).fetchone()
        if row is None:
            if 'link' in tag_cols:
                cur.execute("INSERT INTO tags (name, link) VALUES (?, '')", (name,))
            else:
                cur.execute('INSERT INTO tags (name) VALUES (?)', (name,))
            new_tags += 1
            row = (cur.lastrowid,)
        tag_ids[name] = row[0]

    links = 0
    books_touched = 0
    skipped = []
    for book, names in wanted.items():
        if book not in existing_books:
            skipped.append(book)
            continue
        before = cur.execute('SELECT COUNT(*) FROM books_tags_link WHERE book = ?', (book,)).fetchone()[0]
        for name in names:
            cur.execute('INSERT OR IGNORE INTO books_tags_link (book, tag) VALUES (?, ?)', (book, tag_ids[name]))
        after = cur.execute('SELECT COUNT(*) FROM books_tags_link WHERE book = ?', (book,)).fetchone()[0]
        if after > before:
            links += after - before
            books_touched += 1
            cur.execute('INSERT OR IGNORE INTO metadata_dirtied (book) VALUES (?)', (book,))

    # Show "Broad.Narrow" tags as a tree in Calibre's Tag browser.
    row = cur.execute("SELECT val FROM preferences WHERE key = 'categories_using_hierarchy'").fetchone()
    cats = json.loads(row[0]) if row else []
    if 'tags' not in cats:
        cats.append('tags')
        if row:
            cur.execute("UPDATE preferences SET val = ? WHERE key = 'categories_using_hierarchy'", (json.dumps(cats),))
        else:
            cur.execute("INSERT INTO preferences (key, val) VALUES ('categories_using_hierarchy', ?)", (json.dumps(cats),))

    if dry:
        con.rollback()
    else:
        con.commit()
        if cur.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
            sys.exit('Integrity check failed after writing. Restore the backup.')
    con.close()
    print(json.dumps({
        'dry_run': dry, 'books_in_file': len(wanted), 'books_tagged': books_touched,
        'tag_links_added': links, 'new_tags': new_tags, 'books_not_in_library': skipped,
    }))


if __name__ == '__main__':
    main()
