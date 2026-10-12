#!/usr/bin/env python3
"""
Add untracked book folders (found by find_untracked.py) to a COPY of a
Calibre metadata.db, and write a plan of the folder moves that go with it.

For each folder in the untracked list:
  - a book missing from the catalogue is added with the metadata from its
    metadata.opf (title, authors, publisher, date, series, languages,
    identifiers, rating, description), its ebook files and its cover, and is
    given a new id; its folder will be renamed to end in that id;
  - files for a catalogued book whose own folder has no ebook files are
    connected to that book;
  - a duplicate of a catalogued book is set aside in a holding folder.

Nothing in the library folder is touched here. apply_moves.py carries out the
plan, and you then copy the updated database back (see tools/README.md).

Usage:
  import_untracked.py LIBRARY_FOLDER DB_COPY UNTRACKED_JSON --plan-out plan.json
                      [--tags tags.txt] [--hold "_Calibre cleanup"]

tags.txt (optional) gives tags for the books being added, one line per folder:
  Ann Cleeves/Raven Black (66)|Fiction.Crime & Mystery
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import uuid
import xml.etree.ElementTree as ET

NS = {'opf': 'http://www.idpf.org/2007/opf', 'dc': 'http://purl.org/dc/elements/1.1/'}
OPF = '{http://www.idpf.org/2007/opf}'


def title_sort(title):
    """Calibre's default title sort: a leading article moves to the end."""
    m = re.match(r'^(A|An|The)\s+(.*)$', title or '', re.I)
    return f'{m.group(2)}, {m.group(1)}' if m else (title or '')


def author_sort(name):
    parts = name.split()
    return f'{parts[-1]}, {" ".join(parts[:-1])}' if len(parts) > 1 else name


def calibre_date(value, default):
    if not value:
        return default
    s = value.strip().replace('T', ' ').replace('Z', '+00:00')
    if re.fullmatch(r'\d{4}-\d\d-\d\d', s):
        s += ' 00:00:00'
    if not re.search(r'[+-]\d\d:\d\d$', s):
        s += '+00:00'
    return s


def parse_opf(path, fallback_title, fallback_author):
    md = ET.parse(path).getroot().find('opf:metadata', NS)

    def first(tag):
        e = md.find(tag, NS)
        return e.text.strip() if e is not None and e.text else None

    metas = {m.get('name'): m.get('content') for m in md.findall('opf:meta', NS) if m.get('name')}
    ids, book_uuid = {}, None
    for e in md.findall('dc:identifier', NS):
        scheme = (e.get(OPF + 'scheme') or '').lower()
        if not e.text:
            continue
        if scheme == 'uuid':
            book_uuid = e.text.strip()
        elif scheme and scheme != 'calibre':
            ids[scheme] = e.text.strip()
    authors = [(e.text.strip(), e.get(OPF + 'file-as')) for e in md.findall('dc:creator', NS)
               if e.text and e.get(OPF + 'role') in (None, 'aut')]
    return {
        'title': first('dc:title') or fallback_title,
        'title_sort': metas.get('calibre:title_sort'),
        'authors': authors or [(fallback_author, None)],
        'publisher': first('dc:publisher'), 'pubdate': first('dc:date'), 'description': first('dc:description'),
        'languages': [e.text.strip() for e in md.findall('dc:language', NS) if e.text],
        'ids': ids, 'uuid': book_uuid,
        'series': metas.get('calibre:series'), 'series_index': metas.get('calibre:series_index'),
        'rating': metas.get('calibre:rating'), 'timestamp': metas.get('calibre:timestamp'),
    }


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('library')
    ap.add_argument('db_copy')
    ap.add_argument('untracked_json')
    ap.add_argument('--plan-out', required=True)
    ap.add_argument('--tags')
    ap.add_argument('--hold', default='_Calibre cleanup ' + datetime.date.today().isoformat())
    args = ap.parse_args()

    lib = os.path.abspath(args.library)
    untracked = json.load(open(args.untracked_json, encoding='utf-8'))
    tags_for = {}
    if args.tags:
        for line in open(args.tags, encoding='utf-8'):
            if '|' in line:
                folder, tags = line.rstrip('\n').split('|', 1)
                tags_for[folder] = [t.strip() for t in tags.split(';') if t.strip()]

    con = sqlite3.connect(args.db_copy)
    # Calibre's triggers call these two functions, which exist only inside Calibre.
    con.create_function('title_sort', 1, title_sort)
    con.create_function('uuid4', 0, lambda: str(uuid.uuid4()))
    cur = con.cursor()
    if cur.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
        raise SystemExit('The database copy failed its integrity check; nothing changed.')
    now = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M:%S.%f+00:00')
    seq = cur.execute("SELECT seq FROM sqlite_sequence WHERE name = 'books'").fetchone()
    next_id = max(cur.execute('SELECT COALESCE(MAX(id), 0) FROM books').fetchone()[0], seq[0] if seq else 0) + 1

    def lookup_or_add(table, name, extra):
        row = cur.execute(f'SELECT id FROM {table} WHERE name = ?', (name,)).fetchone()
        if row:
            return row[0]
        cols = ['name'] + list(extra)
        cur.execute(f'INSERT INTO {table} ({", ".join(cols)}) VALUES ({", ".join("?" * len(cols))})', [name, *extra.values()])
        return cur.lastrowid

    def book_files(folder):
        out = []
        for f in sorted(os.listdir(os.path.join(lib, folder))):
            if f in ('metadata.opf', 'cover.jpg') or '.' not in f:
                continue
            stem, ext = f.rsplit('.', 1)
            out.append((ext.upper(), stem, os.stat(os.path.join(lib, folder, f)).st_size, f))
        return out

    moves, added, relinked, duplicates = [], [], [], []
    cur.execute('BEGIN')
    for r in untracked:
        folder = r['folder']
        author_dir, base = folder.split('/', 1)
        title_part = re.sub(r' \(\d+\)$', '', base)
        has_cover = os.path.exists(os.path.join(lib, folder, 'cover.jpg'))

        if r['match'] and r['match_has_files']:
            duplicates.append(folder)
            moves.append({'kind': 'duplicate', 'src': folder, 'dst': f'{args.hold}/duplicates/{folder}'})
            continue

        if r['match']:
            bid = r['match']
            old_path = cur.execute('SELECT path FROM books WHERE id = ?', (bid,)).fetchone()[0]
            if os.path.isdir(os.path.join(lib, old_path)):
                target = old_path
                for _, _, _, fname in book_files(folder):
                    moves.append({'kind': 'relink-file', 'src': f'{folder}/{fname}', 'dst': f'{target}/{fname}'})
                if has_cover:
                    moves.append({'kind': 'relink-file', 'src': f'{folder}/cover.jpg', 'dst': f'{target}/cover.jpg'})
                moves.append({'kind': 'leftover', 'src': folder, 'dst': f'{args.hold}/emptied/{folder}'})
            else:
                target = f'{author_dir}/{title_part} ({bid})'
                moves.append({'kind': 'relink-folder', 'src': folder, 'dst': target})
            cur.execute('DELETE FROM data WHERE book = ?', (bid,))
            for fmt, stem, size, _ in book_files(folder):
                cur.execute('INSERT INTO data (book, format, uncompressed_size, name) VALUES (?,?,?,?)', (bid, fmt, size, stem))
            cover_now = has_cover or os.path.exists(os.path.join(lib, target, 'cover.jpg'))
            cur.execute('UPDATE books SET path = ?, has_cover = ?, last_modified = ? WHERE id = ?', (target, int(cover_now), now, bid))
            cur.execute('INSERT OR IGNORE INTO metadata_dirtied (book) VALUES (?)', (bid,))
            relinked.append({'folder': folder, 'book': bid})
            continue

        opf = os.path.join(lib, folder, 'metadata.opf')
        if not os.path.exists(opf):
            print(f'Skipped (no metadata.opf): {folder}')
            continue
        m = parse_opf(opf, title_part, author_dir)
        bid, next_id = next_id, next_id + 1
        path = f'{author_dir}/{title_part} ({bid})'
        names = [a.replace(',', '|') for a, _ in m['authors']]   # Calibre stores commas in names as |
        sorts = [fa or author_sort(a) for a, fa in m['authors']]
        cur.execute('INSERT INTO books (id, title, timestamp, pubdate, series_index, author_sort, path, has_cover, last_modified) '
                    'VALUES (?,?,?,?,?,?,?,?,?)',
                    (bid, m['title'], calibre_date(m['timestamp'], now), calibre_date(m['pubdate'], '0101-01-01 00:00:00+00:00'),
                     float(m['series_index'] or 1.0), ' & '.join(sorts), path, int(has_cover), now))
        cur.execute('UPDATE books SET sort = ?, uuid = COALESCE(?, uuid) WHERE id = ?', (m['title_sort'] or title_sort(m['title']), m['uuid'], bid))
        for name, srt in zip(names, sorts):
            cur.execute('INSERT OR IGNORE INTO books_authors_link (book, author) VALUES (?,?)',
                        (bid, lookup_or_add('authors', name, {'sort': srt, 'link': ''})))
        if m['publisher']:
            cur.execute('INSERT INTO books_publishers_link (book, publisher) VALUES (?,?)', (bid, lookup_or_add('publishers', m['publisher'], {'link': ''})))
        if m['series']:
            cur.execute('INSERT INTO books_series_link (book, series) VALUES (?,?)', (bid, lookup_or_add('series', m['series'], {'link': ''})))
        for i, lang in enumerate(m['languages']):
            row = cur.execute('SELECT id FROM languages WHERE lang_code = ?', (lang,)).fetchone()
            lid = row[0] if row else cur.execute("INSERT INTO languages (lang_code, link) VALUES (?, '')", (lang,)).lastrowid
            cur.execute('INSERT OR IGNORE INTO books_languages_link (book, lang_code, item_order) VALUES (?,?,?)', (bid, lid, i))
        try:
            rating = int(round(float(m['rating'] or 0)))
        except ValueError:
            rating = 0
        if rating > 0:
            row = cur.execute('SELECT id FROM ratings WHERE rating = ?', (rating,)).fetchone()
            rid = row[0] if row else cur.execute("INSERT INTO ratings (rating, link) VALUES (?, '')", (rating,)).lastrowid
            cur.execute('INSERT INTO books_ratings_link (book, rating) VALUES (?,?)', (bid, rid))
        for typ, val in m['ids'].items():
            cur.execute('INSERT INTO identifiers (book, type, val) VALUES (?,?,?)', (bid, typ, val))
        if m['description']:
            cur.execute('INSERT INTO comments (book, text) VALUES (?,?)', (bid, m['description']))
        for fmt, stem, size, _ in book_files(folder):
            cur.execute('INSERT INTO data (book, format, uncompressed_size, name) VALUES (?,?,?,?)', (bid, fmt, size, stem))
        for tag in tags_for.get(folder, []):
            row = cur.execute('SELECT id FROM tags WHERE name = ?', (tag,)).fetchone()
            tid = row[0] if row else cur.execute("INSERT INTO tags (name, link) VALUES (?, '')", (tag,)).lastrowid
            cur.execute('INSERT OR IGNORE INTO books_tags_link (book, tag) VALUES (?,?)', (bid, tid))
        cur.execute('INSERT OR IGNORE INTO metadata_dirtied (book) VALUES (?)', (bid,))
        moves.append({'kind': 'new', 'src': folder, 'dst': path})
        added.append({'folder': folder, 'book': bid, 'title': m['title']})

    for mv in moves:
        if mv['kind'] != 'relink-file' and os.path.exists(os.path.join(lib, mv['dst'])):
            con.rollback()
            raise SystemExit(f"A target folder already exists, so nothing was changed: {mv['dst']}")
    con.commit()
    integrity = cur.execute('PRAGMA integrity_check').fetchone()[0]
    with open(args.plan_out, 'w', encoding='utf-8') as fh:
        json.dump({'hold': args.hold, 'moves': moves}, fh, indent=1, ensure_ascii=False)
    print(json.dumps({
        'integrity': integrity, 'books_added': len(added), 'books_relinked': relinked,
        'duplicates_set_aside': len(duplicates), 'moves_planned': len(moves),
        'new_ids': [added[0]['book'], added[-1]['book']] if added else [],
        'books_in_catalogue': cur.execute('SELECT COUNT(*) FROM books').fetchone()[0],
        'added_without_tags': [a['folder'] for a in added if a['folder'] not in tags_for] if args.tags else 'no tags file given',
    }, indent=1, ensure_ascii=False))


if __name__ == '__main__':
    main()
