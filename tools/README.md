# Library maintenance tools

These scripts tidy a Calibre library from outside Calibre. They are separate from the website and you don't need them to run it. I wrote them while cleaning up my own library: adding two-level subjects to about 1,200 books in one pass, clearing out years of stray tags, and recovering 131 books that a library move had left out of the catalogue.

They need Python 3.8 or newer and nothing else. They read and write Calibre's `metadata.db` directly with plain SQL, the same way Calibre does, but they are not part of Calibre and Calibre's developers don't support them. Use them with care.

## Ground rules

1. **Close Calibre** on every computer that uses the library. Calibre keeps the database open while it runs, and changing it underneath Calibre can corrupt it.
2. **Wait for syncing to finish** if the library is in Dropbox or a similar service.
3. **Back up `metadata.db`** before anything else.
4. **Work on a copy** of `metadata.db`, check the result, and only then copy it back over the original.
5. When you next open Calibre it rewrites the `metadata.opf` file in each changed book's folder. That's expected.

The scripts that change data all work on a database copy you name, and the tag scripts have a `--dry-run` option that reports what would change without writing anything.

## The tools

| Script | What it does | Changes data? |
|---|---|---|
| `check_library.py` | Checks that every book's folder, ebook files and cover exist, and lists book folders the catalogue doesn't know about | No |
| `add_tags.py` | Adds tags to books from a simple list, and switches Calibre's Tag browser to show `Broad.Narrow` tags as a tree | The database copy |
| `clean_tags.py` | Removes one-off tags that don't fit a `Broad.Narrow` scheme | The database copy |
| `find_untracked.py` | Finds book folders missing from the catalogue and works out which are duplicates | No |
| `import_untracked.py` | Adds those books to a database copy and plans the folder moves | The database copy |
| `apply_moves.py` | Carries out the planned moves by renaming folders (nothing is deleted) | Library folders |

## Adding subjects in bulk

Write a list with one line per book: the Calibre book ID, a `|`, then the tags separated by `;`. You can see book IDs in Calibre by adding the ID column.

```
1413|Travel.Travel Writing;Fiction.Humour
367|History.World History
```

Then:

```
cp metadata.db metadata.db.backup
cp metadata.db work.db
python3 tools/add_tags.py work.db my-tags.txt --dry-run
python3 tools/add_tags.py work.db my-tags.txt
cp work.db metadata.db
```

Running it twice does no harm; tags a book already has are skipped. It only adds tags and never removes them.

## Clearing out stray tags

Once your subjects use `Broad.Narrow` tags, `clean_tags.py` removes every other tag. It keeps any tag with a dot, any plain tag that matches a broad heading in use (`Philosophy` when `Philosophy.Stoicism` exists), and anything you name with `--keep`.

```
python3 tools/clean_tags.py work.db --dry-run
python3 tools/clean_tags.py work.db --keep "To read;Favourites"
```

Always look at the dry-run list first.

## Recovering books after a library move

If a library was moved or copied by hand, its folder can end up holding books that the current catalogue doesn't list. Calibre ignores them, and so does the website. Each of those folders still has its own `metadata.opf` record, so the books can be brought back with their details intact.

```
python3 tools/check_library.py "/path/to/Calibre Library"
python3 tools/find_untracked.py "/path/to/Calibre Library" --out untracked.json
```

`find_untracked.py` sorts the untracked folders into three groups:

- **Duplicates** of books already in the catalogue. They are set aside, not deleted.
- **Files for catalogued books that have lost theirs.** They are reconnected to those books.
- **Books missing from the catalogue.** They are added with the title, authors, publisher, dates, series, identifiers, rating and description from their `metadata.opf`, plus their ebook files and cover, under new book IDs.

Read the list before going further, especially the duplicates, which are matched on identifiers or on title and author surname. Then:

```
cp "/path/to/Calibre Library/metadata.db" work.db
python3 tools/import_untracked.py "/path/to/Calibre Library" work.db untracked.json \
        --plan-out plan.json --tags new-book-tags.txt
python3 tools/apply_moves.py "/path/to/Calibre Library" plan.json
cp work.db "/path/to/Calibre Library/metadata.db"
python3 tools/check_library.py "/path/to/Calibre Library"
```

`--tags` is optional. It gives tags for the books being added, keyed by folder (`Ann Cleeves/Raven Black (66)|Fiction.Crime & Mystery`).

`apply_moves.py` renames folders and never deletes anything. Added books' folders are renamed to end in their new ID, as Calibre expects. Duplicates and emptied folders go into a holding folder, `_Calibre cleanup <date>`, inside the library. If any rename fails, every rename already made is undone. Calibre's own Check Library will list the holding folder until you delete it. Delete it once you've looked through it.

**Cloud storage:** Dropbox, OneDrive and iCloud can keep files online-only. `find_untracked.py` can't read a `metadata.opf` that hasn't been downloaded. Make the library available offline first, or open the files once.

## To avoid needing any of this

Use Calibre's own **Library → Switch/create library → Move current library** when moving a library, and point Calibre on every computer at the same library folder.
