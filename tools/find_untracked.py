#!/usr/bin/env python3
"""
Find book folders in a Calibre library that the catalogue (metadata.db) does
not know about, for example after a library was moved or copied by hand.

For each untracked folder it reads the folder's metadata.opf and checks
whether the catalogue already has that book (same identifier such as ISBN or
Amazon ID, or same title and author surname). Nothing is changed.

Usage: find_untracked.py LIBRARY_FOLDER [--out untracked.json]

The JSON it writes is the input for import_untracked.py.

Note for Dropbox, OneDrive and iCloud: files kept "online only" can't be read
until they are downloaded. If a metadata.opf can't be read, make the library
folder available offline (or open those files once) and run this again.
"""
import argparse
import json
import os
import re
import sqlite3
import time
import unicodedata
import xml.etree.ElementTree as ET

NS = {'opf': 'http://www.idpf.org/2007/opf', 'dc': 'http://purl.org/dc/elements/1.1/'}
OPF = '{http://www.idpf.org/2007/opf}'
MATCH_ID_TYPES = {'isbn', 'amazon', 'mobi-asin', 'asin', 'google', 'goodreads', 'doi'}


def norm(s):
    """Title key: no accents, no subtitle, no leading article, letters and digits only."""
    s = unicodedata.normalize('NFKD', s or '').encode('ascii', 'ignore').decode().lower()
    s = re.split(r'[:_;(\[]', s)[0]
    s = re.sub(r'^(the|a|an)\s+', '', s.strip())
    return re.sub(r'[^a-z0-9]+', ' ', s).strip()


def surname(author):
    a = (author or '').replace('|', ',')
    if ',' in a:
        return norm(a.split(',')[0])
    a = re.sub(r'\b(phd|ph\.d\.?|m\.?d\.?|dr\.?|jr\.?)\b', '', a, flags=re.I)
    parts = a.split()
    return norm(parts[-1]) if parts else ''


def read_with_retry(path, tries=6):
    for i in range(tries):
        try:
            with open(path, 'rb') as fh:
                return fh.read()
        except OSError as e:
            if e.errno not in (11, 16, 35):  # busy / would block / deadlock: cloud file still downloading
                raise
            time.sleep(1.5 * (i + 1))
    raise OSError(f'Could not read {path}; it may be an online-only cloud file.')


def parse_opf(path):
    md = ET.fromstring(read_with_retry(path)).find('opf:metadata', NS)
    ids = {}
    for e in md.findall('dc:identifier', NS):
        scheme = (e.get(OPF + 'scheme') or '').lower()
        if e.text and scheme:
            ids[scheme] = e.text.strip()
    title = md.find('dc:title', NS)
    authors = [e.text.strip() for e in md.findall('dc:creator', NS) if e.text]
    return {'title': title.text.strip() if title is not None and title.text else '', 'authors': authors, 'ids': ids}


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('library')
    ap.add_argument('--out', default='untracked.json')
    args = ap.parse_args()

    lib = os.path.abspath(args.library)
    con = sqlite3.connect(f'file:{os.path.join(lib, "metadata.db")}?mode=ro', uri=True)
    books = con.execute("""SELECT b.id, b.title, b.path,
        (SELECT group_concat(a.name, '|') FROM books_authors_link l JOIN authors a ON a.id = l.author WHERE l.book = b.id)
        FROM books b""").fetchall()

    by_title = {}
    for bid, title, _, authors in books:
        for a in (authors or '').split('|'):
            by_title.setdefault((norm(title), surname(a)), bid)
    by_id = {(t.lower(), v.lower()): b for b, t, v in con.execute('SELECT book, type, val FROM identifiers')}
    known_paths = {p for _, _, p, _ in books}

    def has_files(path):
        full = os.path.join(lib, path)
        return os.path.isdir(full) and any(f not in ('metadata.opf', 'cover.jpg') for f in os.listdir(full))
    record_has_files = {bid: has_files(p) for bid, _, p, _ in books}

    results = []
    for author_dir in sorted(os.listdir(lib)):
        a_full = os.path.join(lib, author_dir)
        if not os.path.isdir(a_full) or author_dir.startswith(('.', '_')):
            continue
        for book_dir in sorted(os.listdir(a_full)):
            rel = f'{author_dir}/{book_dir}'
            if not os.path.isdir(os.path.join(lib, rel)) or rel in known_paths:
                continue
            files = sorted(os.listdir(os.path.join(lib, rel)))
            opf = os.path.join(lib, rel, 'metadata.opf')
            info = parse_opf(opf) if os.path.exists(opf) else {'title': re.sub(r' \(\d+\)$', '', book_dir), 'authors': [author_dir], 'ids': {}}
            match = how = None
            for scheme, val in info['ids'].items():
                if scheme in MATCH_ID_TYPES and (scheme, val.lower()) in by_id:
                    match, how = by_id[(scheme, val.lower())], f'same {scheme}'
                    break
            if match is None:
                for a in info['authors'] or [author_dir]:
                    hit = by_title.get((norm(info['title']), surname(a)))
                    if hit:
                        match, how = hit, 'same title and author'
                        break
            results.append({
                'folder': rel, 'title': info['title'], 'authors': info['authors'],
                'files': [f for f in files if f not in ('metadata.opf', 'cover.jpg')],
                'cover': 'cover.jpg' in files, 'opf': os.path.exists(opf),
                'match': match, 'how': how, 'match_has_files': record_has_files.get(match) if match else None,
            })

    with open(args.out, 'w', encoding='utf-8') as fh:
        json.dump(results, fh, indent=1, ensure_ascii=False)

    dup = [r for r in results if r['match'] and r['match_has_files']]
    relink = [r for r in results if r['match'] and not r['match_has_files']]
    new = [r for r in results if not r['match']]
    print(f'Untracked book folders: {len(results)} (without metadata.opf: {sum(not r["opf"] for r in results)})')
    print(f'  Duplicates of catalogued books:            {len(dup)}')
    print(f'  Files for catalogued books missing theirs: {len(relink)}')
    print(f'  Books missing from the catalogue:          {len(new)}')
    for r in new:
        print(f'    {r["folder"]}  |  {r["title"]}  |  {", ".join(r["authors"])}')
    print(f'Details written to {args.out}. Check the duplicates before importing.')


if __name__ == '__main__':
    main()
