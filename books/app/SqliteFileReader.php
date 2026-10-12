<?php
declare(strict_types=1);

/**
 * Reads ordinary tables straight from an SQLite database file, without using
 * the server's SQLite library.
 *
 * Why: shared hosts often ship PHP linked against an old SQLite. Calibre's
 * metadata.db contains newer constructs (FTS5 tables declared WITHOUT ROWID)
 * that an old library refuses to load, so every query fails. This reader only
 * walks the table b-trees we ask for and ignores everything else in the file.
 *
 * Supports UTF-8 databases, any page size, overflow pages and reserved bytes.
 * It reads rowid tables only, which covers all of Calibre's catalogue tables.
 */
final class SqliteFileReader
{
    /** @var resource */
    private $fh;
    private int $pageSize;
    private int $usable;
    /** @var array<string, array{type:string, root:int, sql:string}> */
    private array $schema = [];

    public function __construct(string $file)
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            throw new RuntimeException("Cannot open $file");
        }
        $this->fh = $fh;
        $h = (string) fread($fh, 100);
        if (strlen($h) < 100 || substr($h, 0, 16) !== "SQLite format 3\0") {
            throw new RuntimeException('Not an SQLite database file.');
        }
        $ps = unpack('n', substr($h, 16, 2))[1];
        $this->pageSize = $ps === 1 ? 65536 : $ps;
        $this->usable = $this->pageSize - ord($h[20]);
        $encoding = unpack('N', substr($h, 56, 4))[1];
        if ($encoding > 1) {
            throw new RuntimeException('Only UTF-8 SQLite databases are supported.');
        }

        foreach ($this->walk(1) as [, $rec]) {
            [$type, $name, , $root, $sql] = array_pad($rec, 5, null);
            if ($type === 'table' && $name !== null) {
                $this->schema[strtolower((string) $name)] = ['type' => 'table', 'root' => (int) $root, 'sql' => (string) $sql];
            }
        }
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    public function hasTable(string $name): bool
    {
        return isset($this->schema[strtolower($name)]);
    }

    /** Column names of a table, in storage order. */
    public function columns(string $table): array
    {
        return array_column($this->tableInfo($table)['cols'], 'name');
    }

    /**
     * Every row of a table as column => value.
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(string $table): Generator
    {
        $info = $this->tableInfo($table);
        $cols = $info['cols'];
        foreach ($this->walk($info['root']) as [$rowid, $values]) {
            $row = [];
            foreach ($cols as $i => $c) {
                $v = $values[$i] ?? null;
                if ($c['ipk'] && $v === null) {
                    $v = $rowid; // INTEGER PRIMARY KEY is stored as the rowid
                }
                $row[$c['name']] = $v;
            }
            yield $row;
        }
    }

    private array $infoCache = [];

    private function tableInfo(string $table): array
    {
        $key = strtolower($table);
        if (isset($this->infoCache[$key])) {
            return $this->infoCache[$key];
        }
        if (!isset($this->schema[$key])) {
            throw new RuntimeException("Table $table not found in metadata.db");
        }
        $s = $this->schema[$key];
        if (preg_match('/\)\s*WITHOUT\s+ROWID\s*;?\s*$/i', $s['sql'])) {
            throw new RuntimeException("Table $table is a WITHOUT ROWID table, which this reader skips.");
        }
        return $this->infoCache[$key] = ['root' => $s['root'], 'cols' => self::parseColumns($s['sql'])];
    }

    /** Pull column names out of a CREATE TABLE statement. */
    public static function parseColumns(string $sql): array
    {
        $start = strpos($sql, '(');
        $end = strrpos($sql, ')');
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('Unexpected table definition: ' . substr($sql, 0, 80));
        }
        $body = substr($sql, $start + 1, $end - $start - 1);

        // Split on commas that are not inside parentheses or quotes.
        $parts = [];
        $depth = 0;
        $quote = null;
        $buf = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '[') {
                $quote = ']';
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $parts[] = $buf;

        $cols = [];
        foreach ($parts as $p) {
            $p = trim(preg_replace('/--[^\n]*/', '', $p) ?? $p);
            if ($p === '') {
                continue;
            }
            if (preg_match('/^(CONSTRAINT|PRIMARY\s+KEY|UNIQUE|CHECK|FOREIGN\s+KEY)\b/i', $p)) {
                continue;
            }
            if (!preg_match('/^("([^"]|"")+"|`[^`]+`|\[[^\]]+\]|\'[^\']+\'|\S+)(.*)$/s', $p, $m)) {
                continue;
            }
            $name = $m[1];
            if (in_array($name[0], ['"', '`', '[', "'"], true)) {
                $name = str_replace('""', '"', substr($name, 1, -1));
            }
            $cols[] = [
                'name' => $name,
                'ipk'  => (bool) preg_match('/^\s*INTEGER\s+PRIMARY\s+KEY\b/i', $m[3]),
            ];
        }
        return $cols;
    }

    // ---- B-tree walking ------------------------------------------------

    private function page(int $n): string
    {
        if ($n < 1) {
            throw new RuntimeException("Bad page number $n");
        }
        fseek($this->fh, ($n - 1) * $this->pageSize);
        $data = (string) fread($this->fh, $this->pageSize);
        if (strlen($data) !== $this->pageSize) {
            throw new RuntimeException("metadata.db is truncated (page $n).");
        }
        return $data;
    }

    /** @return Generator<int, array{0:int, 1:array}> rowid, column values */
    private function walk(int $pageNo, int $depth = 0): Generator
    {
        if ($depth > 40) {
            throw new RuntimeException('metadata.db looks damaged (b-tree too deep).');
        }
        $p = $this->page($pageNo);
        $off = $pageNo === 1 ? 100 : 0;
        $type = ord($p[$off]);
        $cells = self::u16($p, $off + 3);

        if ($type === 0x0D) { // table leaf
            for ($i = 0; $i < $cells; $i++) {
                $pos = self::u16($p, $off + 8 + 2 * $i);
                [$payloadLen, $pos] = self::varint($p, $pos);
                [$rowid, $pos] = self::varint($p, $pos);
                yield [$rowid, self::record($this->payload($p, $pos, $payloadLen))];
            }
        } elseif ($type === 0x05) { // table interior
            for ($i = 0; $i < $cells; $i++) {
                $pos = self::u16($p, $off + 12 + 2 * $i);
                yield from $this->walk(self::u32($p, $pos), $depth + 1);
            }
            yield from $this->walk(self::u32($p, $off + 8), $depth + 1);
        } else {
            throw new RuntimeException(sprintf('Unexpected b-tree page type 0x%02X on page %d.', $type, $pageNo));
        }
    }

    private function payload(string $p, int $pos, int $total): string
    {
        $u = $this->usable;
        $x = $u - 35;
        if ($total <= $x) {
            return substr($p, $pos, $total);
        }
        $m = intdiv(($u - 12) * 32, 255) - 23;
        $k = $m + (($total - $m) % ($u - 4));
        $local = $k <= $x ? $k : $m;
        $data = substr($p, $pos, $local);
        $next = self::u32($p, $pos + $local);
        $guard = 0;
        while (strlen($data) < $total && $next !== 0) {
            if (++$guard > 100000) {
                throw new RuntimeException('metadata.db looks damaged (overflow loop).');
            }
            $op = $this->page($next);
            $next = self::u32($op, 0);
            $data .= substr($op, 4, min($u - 4, $total - strlen($data)));
        }
        return $data;
    }

    private static function record(string $r): array
    {
        [$headerLen, $pos] = self::varint($r, 0);
        $types = [];
        while ($pos < $headerLen) {
            [$t, $pos] = self::varint($r, $pos);
            $types[] = $t;
        }
        $pos = $headerLen;
        $out = [];
        foreach ($types as $t) {
            switch (true) {
                case $t === 0:
                    $out[] = null;
                    break;
                case $t >= 1 && $t <= 6:
                    $n = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 6, 6 => 8][$t];
                    $out[] = self::signed(substr($r, $pos, $n));
                    $pos += $n;
                    break;
                case $t === 7:
                    $out[] = unpack('E', substr($r, $pos, 8))[1];
                    $pos += 8;
                    break;
                case $t === 8:
                    $out[] = 0;
                    break;
                case $t === 9:
                    $out[] = 1;
                    break;
                case $t >= 12:
                    $n = intdiv($t - ($t % 2 === 0 ? 12 : 13), 2);
                    $out[] = substr($r, $pos, $n);
                    $pos += $n;
                    break;
                default:
                    $out[] = null;
            }
        }
        return $out;
    }

    private static function signed(string $b): int
    {
        $n = strlen($b);
        if ($n === 8) {
            return unpack('J', $b)[1];
        }
        $v = 0;
        for ($i = 0; $i < $n; $i++) {
            $v = ($v << 8) | ord($b[$i]);
        }
        if ($n > 0 && (ord($b[0]) & 0x80)) {
            $v -= 1 << (8 * $n);
        }
        return $v;
    }

    /** @return array{0:int, 1:int} value, next position */
    private static function varint(string $s, int $pos): array
    {
        $v = 0;
        for ($i = 0; $i < 8; $i++) {
            $b = ord($s[$pos + $i] ?? "\0");
            $v = ($v << 7) | ($b & 0x7F);
            if ($b < 0x80) {
                return [$v, $pos + $i + 1];
            }
        }
        $v = ($v << 8) | ord($s[$pos + 8] ?? "\0");
        return [$v, $pos + 9];
    }

    private static function u16(string $s, int $pos): int
    {
        return (ord($s[$pos]) << 8) | ord($s[$pos + 1]);
    }

    private static function u32(string $s, int $pos): int
    {
        return unpack('N', substr($s, $pos, 4))[1];
    }
}
