# Calibre Web Catalogue

A small PHP site that publishes a [Calibre](https://calibre-ebook.com) ebook library as a browsable catalogue of covers, titles and authors. It runs on ordinary shared hosting (I use Bluehost) and reads the library straight from Dropbox, so the computer running Calibre doesn't need to be on.

Once an hour the site checks whether Calibre's `metadata.db` has changed. When it has, it rebuilds its catalogue and fetches cover thumbnails for any new or changed books. The site is browse-only: it never downloads the ebook files, and its Dropbox access is read-only.

## Features

- A home page with search, a "Recently added" shelf, subject shortcuts and a sortable grid of every book
- Search across titles, authors, series, subjects (Calibre tags) and publishers, with results you can narrow by subject
- A page for each book with its description and a catalogue record: publisher, date, language, ISBN (linked to Open Library, Google Books and WorldCat) and the date it was added
- A–Z lists of authors, subjects and series
- Light and dark themes, and layouts that work on a phone
- An admin page for connecting Dropbox, running a sync and checking status

## Requirements

- PHP 8.1 or newer with the `pdo_sqlite`, `curl`, `gd` and `dom` extensions, all standard on shared hosts
- Apache or LiteSpeed with `.htaccess` support, which keeps the settings and data folders private
- A cron job, or a free web cron service, to run the hourly sync
- A Calibre library stored in Dropbox

The server's own SQLite version doesn't matter. Calibre's `metadata.db` uses table types that older SQLite libraries can't open, so the sync reads the file with a small built-in reader (`app/SqliteFileReader.php`). It then copies the catalogue tables into a plain database that any SQLite version can query.

## Layout

```
tools/               optional library maintenance scripts (not uploaded)
books/               upload this folder to your web space
  index.php          home page, search and filtered lists
  book.php           book detail page
  browse.php         authors, subjects and series lists
  admin.php          connect Dropbox, run a sync, check status
  cron.php           optional web-triggered sync
  app/               code and settings (blocked from the web)
  data/              built catalogue and sync state (blocked from the web)
  covers/            cover thumbnails, filled in by the sync
  assets/            stylesheet, script, icon
```

## Setup

The steps below use cPanel, which most shared hosts provide. Setup takes about 20 minutes.

### 1. Create a Dropbox app

1. Go to <https://www.dropbox.com/developers/apps> and click **Create app**.
2. Choose **Scoped access**, then **Full Dropbox**. An app-folder app can only see its own folder, so it can't read a library stored elsewhere.
3. Give it a name. It has to be unique across Dropbox, and only you will see it.
4. On the **Permissions** tab, tick **files.metadata.read** and **files.content.read**, then click **Submit**. Do this before you connect the site in step 4.
5. On the **Settings** tab, note the **App key** and **App secret**.

You don't need to apply for production status. A development app keeps working for the account that created it.

### 2. Upload the files

Copy the `books` folder into your web space, for example `public_html/books`. With cPanel, zip the folder, upload the zip in **File Manager**, and use **Extract**. Make sure the `.htaccess` files come along; File Manager hides them unless you turn on **Show Hidden Files** in its settings.

### 3. Add your settings

In `books/app/`, copy `config.sample.php` to `config.php` and set:

- `admin_password`: a long password for the admin page.
- `dropbox_app_key` and `dropbox_app_secret`: from step 1.
- `dropbox_library_path`: where the library sits inside Dropbox, starting with a slash. If it's at `~/Dropbox/Books/Calibre Library` on your computer, use `/Books/Calibre Library`.
- `timezone`: for dates on the admin page, for example `America/Toronto`.

`config.php` is listed in `.gitignore`, so your secrets stay out of version control.

### 4. Connect Dropbox and run the first sync

1. Visit `admin.php` and sign in.
2. Under **Dropbox**, open the permission page, click **Allow**, and paste the code Dropbox gives you into the admin page.
3. Click **Sync now**. The first sync builds the catalogue and then fetches covers 25 at a time. Each step runs for up to 20 seconds, and the page continues by itself until every cover is in. A library of about 1,200 books takes a few minutes, and the covers use around 65 MB.

### 5. Schedule hourly updates

In cPanel, open **Cron Jobs**, choose **Once Per Hour**, and paste the command shown on the admin page. It looks like this:

```
php -q /home/USERNAME/public_html/books/app/sync.php --quiet
```

Each run takes a second or two when nothing has changed. If you can't use cron, set a random `cron_key` in `config.php` and point a web cron service at the `cron.php?key=...` address the admin page shows.

## How updates work

When you add or edit a book, Calibre saves `metadata.db` and Dropbox uploads it. At the next check the site downloads the file, rebuilds its catalogue, fetches covers for new or changed books and removes covers of books you've deleted. **Sync now** on the admin page does the same thing on demand, and **Full refresh** also retries covers that failed before.

Subjects come from Calibre's **Tags** column and series from the **Series** column.

### Two-level subjects

Tags written as `Broad.Narrow`, such as `History.Canada` or `Buddhism.Zen`, are shown as two levels: the Subjects page lists each broad heading with its narrower headings beneath, the home page offers the broad headings, and a broad heading's results can be narrowed further. This is the same convention Calibre uses for hierarchical tags. To see them as a tree in Calibre too, add **tags** under *Preferences → Look & feel → Tag browser → Categories with hierarchical items*.

When a library has any tags in this form, one-off tags that don't fit the scheme are left off the site (they stay in Calibre). A library with no dotted tags shows every tag, as before.

## Privacy

Anyone with the address can browse the catalogue. By default the pages ask search engines not to index them; set `allow_search_engines` to `true` to change that.

The `app/` and `data/` folders are blocked from the web by `.htaccess`, including the settings file and the Dropbox token. For extra separation, set `data_dir` in `config.php` to a folder outside your web space and reconnect Dropbox.

## Opening books in Dropbox

Each book page can link to the book's files and folder on the Dropbox website. Only someone signed in to the Dropbox account that holds the library can open them, so the links don't share the books. By default they appear only on browsers where you've signed in to `admin.php` (the browser is remembered for a year). Set `dropbox_links` in `config.php` to `everyone` to show them to all visitors, or `off` to hide them.

## Other sources

Set `local_library_path` in `config.php` to read a Calibre library folder on the server itself instead of Dropbox, for example one you upload by SFTP.

## Troubleshooting

**The admin page says the password isn't set.** `config.php` still has the sample password, or one shorter than 8 characters.

**"Dropbox rejected the code."** Codes expire after a few minutes and work only once. Open the permission page again.

**"path/not_found".** `dropbox_library_path` doesn't match where the library is in Dropbox. Leave out your computer's own folders.

**"missing_scope".** The Dropbox app is missing a permission. Tick both permissions, click **Submit**, then disconnect and reconnect on the admin page.

**Some books show a plain cloth-style cover.** Calibre has no cover for that book, or Dropbox couldn't make a thumbnail. **Full refresh** retries them.

**"The downloaded metadata.db could not be read."** Dropbox was probably mid-upload while Calibre was saving. The next run will try again.

## Library maintenance tools

The `tools/` folder has a few optional Python scripts for tidying a Calibre library from outside Calibre: adding subject tags in bulk, clearing out stray tags, checking that the catalogue matches the files on disk, and recovering books left out of the catalogue after a library move. They aren't needed to run the site. See [tools/README.md](tools/README.md).

## Changing the look

Colours and fonts are set at the top of `books/assets/style.css`. The site name comes from `site_title` in `config.php`, and `per_page` sets how many books appear on each page.

## Licence

Released under the [MIT Licence](LICENSE). You're welcome to use and adapt it for your own library.
