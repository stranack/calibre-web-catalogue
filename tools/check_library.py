#!/usr/bin/env python3
"""
Read-only check that a Calibre catalogue and its folders agree: every book's
folder, ebook files and cover exist, and no book folder is untracked.

Usage: check_library.py LIBRARY_FOLDER
"""
import os
import sqlite3
import sys


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    lib = os.path.abspath(sys.argv[1])
    con = sqlite3.connect(f'file:{os.path.join(lib, "metadata.db")}?mode=ro', uri=True)
    print('Integrity:', con.execute('PRAGMA quick_check').fetchone()[0])
    print('Books:', con.execute('SELECT COUNT(*) FROM books').fetchone()[0])
    no_folder, no_file, no_cover = [], [], []
    for bid, title, path, has_cover in con.execute('SELECT id, title, path, has_cover FROM books'):
        full = os.path.join(lib, path)
        if not os.path.isdir(full):
            no_folder.append(f'{bid} {title}')
            continue
        for fmt, name in con.execute('SELECT format, name FROM data WHERE book = ?', (bid,)).fetchall():
            if not os.path.exists(os.path.join(full, f'{name}.{fmt.lower()}')):
                no_file.append(f'{bid} {title} ({fmt})')
        if has_cover and not os.path.exists(os.path.join(full, 'cover.jpg')):
            no_cover.append(f'{bid} {title}')
    known = {p for (p,) in con.execute('SELECT path FROM books')}
    untracked = [f'{a}/{b}' for a in sorted(os.listdir(lib))
                 if os.path.isdir(os.path.join(lib, a)) and not a.startswith(('.', '_'))
                 for b in sorted(os.listdir(os.path.join(lib, a)))
                 if os.path.isdir(os.path.join(lib, a, b)) and f'{a}/{b}' not in known]
    no_files_at_all = [f'{b} {t}' for b, t in con.execute('SELECT id, title FROM books WHERE id NOT IN (SELECT book FROM data)')]
    for label, items in [('Books whose folder is missing', no_folder), ('Ebook files listed but missing', no_file),
                         ('Covers listed but missing', no_cover), ('Books with no ebook file at all', no_files_at_all),
                         ('Untracked book folders', untracked)]:
        print(f'{label}: {len(items)}')
        for i in items[:50]:
            print('   ', i)
    sys.exit(1 if (no_folder or no_file or no_cover or untracked) else 0)


if __name__ == '__main__':
    main()
