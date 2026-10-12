<?php
declare(strict_types=1);

/**
 * Read-only queries against the Calibre metadata.db copy (data/library.db).
 * Only plain SQL is used: Calibre's own SQL functions exist only inside Calibre.
 */
final class Catalog
{
    private PDO $db;

    private function __construct(string $file)
    {
        $this->db = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
    }

    /**
     * Older PHP versions (before 8.1) hand back every SQLite value as text.
     * Give numeric columns their proper types so the rest of the code sees the
     * same thing on every server.
     */
    private static function typed(array $rows): array
    {
        static $ints = ['id', 'has_cover', 'n', 'rating', 'book', 'author', 'tag', 'series', 'publisher'];
        foreach ($rows as &$r) {
            foreach ($ints as $k) {
                if (isset($r[$k]) && is_string($r[$k]) && is_numeric($r[$k])) {
                    $r[$k] = (int) $r[$k];
                }
            }
            if (isset($r['series_index'])) {
                $r['series_index'] = (float) $r['series_index'];
            }
        }
        return $rows;
    }

    public static function open(): ?Catalog
    {
        if (!is_file(LIBRARY_DB) || filesize(LIBRARY_DB) === 0) {
            return null;
        }
        try {
            $cat = new Catalog(LIBRARY_DB);
            $cat->db->query('SELECT 1 FROM books LIMIT 1');
            return $cat;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ---- Lists of books --------------------------------------------------

    /**
     * @param array{q?:string,author?:int,tag?:int,series?:int,publisher?:int} $f
     * @return array{total:int, books:array}
     */
    public function search(array $f, string $sort, int $page, int $perPage): array
    {
        $params = [];
        $where = $this->where($f, $params);

        $count = $this->db->prepare("SELECT COUNT(*) FROM books b $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $order = $this->order($sort, $f, $params);
        $sql = self::BOOK_COLUMNS . " $where ORDER BY $order LIMIT :lim OFFSET :off";
        $st = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $st->bindValue(':off', max(0, ($page - 1) * $perPage), PDO::PARAM_INT);
        $st->execute();
        $books = self::typed($st->fetchAll());
        $this->attachAuthors($books);
        return ['total' => $total, 'books' => $books];
    }

    public function recent(int $limit): array
    {
        $st = $this->db->prepare(self::BOOK_COLUMNS . ' ORDER BY b.timestamp DESC LIMIT :lim');
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        $books = self::typed($st->fetchAll());
        $this->attachAuthors($books);
        return $books;
    }

    /** Subjects (Calibre tags) that appear among the books matching $f. */
    public function subjectsWithin(array $f, int $limit): array
    {
        $params = [];
        $where = $this->where($f, $params);
        $sql = "SELECT t.id, t.name, COUNT(*) AS n
                FROM books_tags_link l JOIN tags t ON t.id = l.tag
                WHERE l.book IN (SELECT b.id FROM books b $where)
                GROUP BY t.id ORDER BY n DESC, t.name COLLATE NOCASE LIMIT " . (int) $limit;
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return self::typed($st->fetchAll());
    }

    private const BOOK_COLUMNS = "SELECT b.id, b.title, b.timestamp, b.pubdate, b.last_modified, b.has_cover, b.series_index,
            (SELECT s.name FROM books_series_link x JOIN series s ON s.id = x.series WHERE x.book = b.id LIMIT 1) AS series_name
        FROM books b";

    private function where(array $f, array &$params): string
    {
        $c = [];
        foreach (['author' => 'books_authors_link|author', 'tag' => 'books_tags_link|tag', 'series' => 'books_series_link|series', 'publisher' => 'books_publishers_link|publisher'] as $key => $spec) {
            if (!empty($f[$key])) {
                [$table, $col] = explode('|', $spec);
                $c[] = "EXISTS (SELECT 1 FROM $table x WHERE x.book = b.id AND x.$col = :$key)";
                $params[":$key"] = (int) $f[$key];
            }
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $terms = array_slice(preg_split('/\s+/u', $q) ?: [], 0, 8);
            foreach ($terms as $i => $term) {
                $p = ":t$i";
                $params[$p] = '%' . addcslashes($term, '%_\\') . '%';
                $like = "LIKE $p ESCAPE '\\'";
                $c[] = "(b.title $like
                    OR EXISTS (SELECT 1 FROM books_authors_link x JOIN authors a ON a.id = x.author WHERE x.book = b.id AND a.name $like)
                    OR EXISTS (SELECT 1 FROM books_series_link x JOIN series s ON s.id = x.series WHERE x.book = b.id AND s.name $like)
                    OR EXISTS (SELECT 1 FROM books_tags_link x JOIN tags t ON t.id = x.tag WHERE x.book = b.id AND t.name $like)
                    OR EXISTS (SELECT 1 FROM books_publishers_link x JOIN publishers p ON p.id = x.publisher WHERE x.book = b.id AND p.name $like))";
            }
        }
        return $c ? 'WHERE ' . implode(' AND ', $c) : '';
    }

    private function order(string $sort, array $f, array &$params): string
    {
        switch ($sort) {
            case 'added':
                return 'b.timestamp DESC';
            case 'author':
                return 'b.author_sort COLLATE NOCASE, b.sort COLLATE NOCASE';
            case 'published':
                return 'b.pubdate DESC, b.sort COLLATE NOCASE';
            case 'series':
                return 'b.series_index, b.sort COLLATE NOCASE';
            case 'relevance':
                $q = trim((string) ($f['q'] ?? ''));
                if ($q !== '') {
                    $params[':full'] = '%' . addcslashes($q, '%_\\') . '%';
                    return "CASE WHEN b.title LIKE :full ESCAPE '\\' THEN 0 ELSE 1 END, b.sort COLLATE NOCASE";
                }
                // fall through
            default:
                return 'b.sort COLLATE NOCASE';
        }
    }

    private function attachAuthors(array &$books): void
    {
        if (!$books) {
            return;
        }
        $ids = array_map(fn($b) => (int) $b['id'], $books);
        $in = implode(',', $ids);
        $map = [];
        foreach ($this->db->query("SELECT l.book, a.id, a.name FROM books_authors_link l JOIN authors a ON a.id = l.author WHERE l.book IN ($in) ORDER BY l.id") as $r) {
            $map[(int) $r['book']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        foreach ($books as &$b) {
            $b['authors'] = $map[(int) $b['id']] ?? [];
        }
    }

    // ---- One book --------------------------------------------------------

    public function book(int $id): ?array
    {
        $st = $this->db->prepare(self::BOOK_COLUMNS . ' WHERE b.id = :id');
        $st->execute([':id' => $id]);
        $b = $st->fetch();
        $b = $b ? self::typed([$b])[0] : false;
        if (!$b) {
            return null;
        }
        $books = [$b];
        $this->attachAuthors($books);
        $b = $books[0];

        $one = function (string $sql) use ($id) {
            $st = $this->db->prepare($sql);
            $st->execute([':id' => $id]);
            $r = $st->fetch();
            return $r ? self::typed([$r])[0] : null;
        };
        $all = function (string $sql) use ($id) {
            $st = $this->db->prepare($sql);
            $st->execute([':id' => $id]);
            return self::typed($st->fetchAll());
        };

        $b['series'] = $one('SELECT s.id, s.name FROM books_series_link x JOIN series s ON s.id = x.series WHERE x.book = :id');
        $b['publisher'] = $one('SELECT p.id, p.name FROM books_publishers_link x JOIN publishers p ON p.id = x.publisher WHERE x.book = :id');
        $r = $one('SELECT r.rating FROM books_ratings_link x JOIN ratings r ON r.id = x.rating WHERE x.book = :id');
        $b['rating'] = $r ? (int) $r['rating'] : 0;
        $c = $one('SELECT text FROM comments WHERE book = :id');
        $b['description'] = $c['text'] ?? '';
        $b['tags'] = $all('SELECT t.id, t.name FROM books_tags_link x JOIN tags t ON t.id = x.tag WHERE x.book = :id ORDER BY t.name COLLATE NOCASE');
        $b['languages'] = array_column($all('SELECT l.lang_code FROM books_languages_link x JOIN languages l ON l.id = x.lang_code WHERE x.book = :id ORDER BY x.item_order'), 'lang_code');
        $b['identifiers'] = [];
        foreach ($all('SELECT type, val FROM identifiers WHERE book = :id') as $row) {
            $b['identifiers'][strtolower($row['type'])] = $row['val'];
        }
        $b['formats'] = array_column($all('SELECT format FROM data WHERE book = :id ORDER BY format'), 'format');
        return $b;
    }

    public function moreByAuthor(int $authorId, int $excludeBook, int $limit): array
    {
        $st = $this->db->prepare(self::BOOK_COLUMNS . ' WHERE b.id != :ex AND EXISTS (SELECT 1 FROM books_authors_link x WHERE x.book = b.id AND x.author = :a) ORDER BY b.pubdate DESC LIMIT :lim');
        $st->bindValue(':ex', $excludeBook, PDO::PARAM_INT);
        $st->bindValue(':a', $authorId, PDO::PARAM_INT);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        $books = self::typed($st->fetchAll());
        $this->attachAuthors($books);
        return $books;
    }

    // ---- Browse lists ----------------------------------------------------

    public function authors(): array
    {
        return self::typed($this->db->query('SELECT a.id, a.name, a.sort, COUNT(*) AS n FROM authors a JOIN books_authors_link l ON l.author = a.id GROUP BY a.id ORDER BY COALESCE(NULLIF(a.sort, \'\'), a.name) COLLATE NOCASE')->fetchAll());
    }

    public function subjects(): array
    {
        return self::typed($this->db->query('SELECT t.id, t.name, COUNT(*) AS n FROM tags t JOIN books_tags_link l ON l.tag = t.id GROUP BY t.id ORDER BY t.name COLLATE NOCASE')->fetchAll());
    }

    public function topSubjects(int $limit): array
    {
        return self::typed($this->db->query('SELECT t.id, t.name, COUNT(*) AS n FROM tags t JOIN books_tags_link l ON l.tag = t.id GROUP BY t.id ORDER BY n DESC, t.name COLLATE NOCASE LIMIT ' . (int) $limit)->fetchAll());
    }

    public function seriesList(): array
    {
        return self::typed($this->db->query('SELECT s.id, s.name, COUNT(*) AS n FROM series s JOIN books_series_link l ON l.series = s.id GROUP BY s.id ORDER BY COALESCE(NULLIF(s.sort, \'\'), s.name) COLLATE NOCASE')->fetchAll());
    }

    /** Name of an author, tag, series or publisher by id. */
    public function nameOf(string $kind, int $id): ?string
    {
        $table = ['author' => 'authors', 'tag' => 'tags', 'series' => 'series', 'publisher' => 'publishers'][$kind] ?? null;
        if (!$table) {
            return null;
        }
        $st = $this->db->prepare("SELECT name FROM $table WHERE id = :id");
        $st->execute([':id' => $id]);
        $n = $st->fetchColumn();
        return $n === false ? null : (string) $n;
    }

    public function stats(): array
    {
        return [
            'books'   => (int) $this->db->query('SELECT COUNT(*) FROM books')->fetchColumn(),
            'authors' => (int) $this->db->query('SELECT COUNT(DISTINCT author) FROM books_authors_link')->fetchColumn(),
        ];
    }
}
