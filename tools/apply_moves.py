#!/usr/bin/env python3
"""
Carry out the folder moves planned by import_untracked.py, inside the
library folder. Every move is a rename, so nothing is copied or deleted:
duplicates and emptied folders go into the holding folder named in the plan,
for you to look over and delete yourself. If any move fails, all moves made
so far are undone.

Usage: apply_moves.py LIBRARY_FOLDER plan.json

Calibre must be closed. Run this before copying the updated database back.
"""
import json
import os
import sys


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    lib, plan_path = os.path.abspath(sys.argv[1]), sys.argv[2]
    plan = json.load(open(plan_path, encoding='utf-8'))
    hold, moves = plan['hold'], plan['moves']
    done, kept_covers = [], []
    try:
        for m in moves:
            src, dst = os.path.join(lib, m['src']), os.path.join(lib, m['dst'])
            if not os.path.exists(src):
                raise RuntimeError(f"source is missing: {m['src']}")
            if os.path.exists(dst):
                if m['kind'] == 'relink-file' and os.path.basename(dst) == 'cover.jpg':
                    kept_covers.append(m['dst'])  # a cover is already there; keep it
                    continue
                raise RuntimeError(f"target already exists: {m['dst']}")
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            os.rename(src, dst)
            done.append((src, dst))
        emptied = []
        for author_dir in sorted({m['src'].split('/')[0] for m in moves}):
            p = os.path.join(lib, author_dir)
            if os.path.isdir(p) and not os.listdir(p):
                dst = os.path.join(lib, hold, 'empty author folders', author_dir)
                os.makedirs(os.path.dirname(dst), exist_ok=True)
                os.rename(p, dst)
                done.append((p, dst))
                emptied.append(author_dir)
    except Exception as e:
        for src, dst in reversed(done):
            try:
                os.rename(dst, src)
            except Exception as undo_error:
                print(f'COULD NOT UNDO {dst} -> {src}: {undo_error}')
        sys.exit(f'Stopped and undid {len(done)} moves: {e}')
    with open(plan_path + '.done', 'w', encoding='utf-8') as fh:
        json.dump(done, fh, indent=1, ensure_ascii=False)
    print(json.dumps({'moved': len(done), 'kept_existing_cover': kept_covers, 'empty_author_folders': emptied}, ensure_ascii=False))


if __name__ == '__main__':
    main()
