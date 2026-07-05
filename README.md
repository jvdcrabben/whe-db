# wheDB: A Simple Lightweight PHP Database Wrapper as a Zend Replacement

**wheDB** is a small, single-file PHP database wrapper built around `mysqli`.

It was created to help legacy projects remove their Zend Framework dependency while keeping familiar Zend-style database calls. Instead of pulling in a full framework just for database access, wheDB provides a lightweight compatibility layer for common MySQL operations.

It includes helpers for fetching rows, quoting values, inserting records, updating data, exporting CSV files, inspecting table metadata, and more.

## Why wheDB?

wheDB is useful when you have an older PHP project that once depended on Zend Framework mainly for database access, but you now want to remove that dependency without rewriting every query at once.

If your codebase is full of calls like `$db->fetchAll(...)`, `$db->fetchRow(...)`, `$db->insert(...)`, or `$db->quoteInto(...)`, wheDB lets you drop in a single class and keep that code working, backed by plain `mysqli` under the hood.

## Requirements

- PHP 7.4+ (uses typed parameters and nullsafe-friendly patterns)
- The `mysqli` extension enabled

## Installation

wheDB is a single file with no dependencies. Just copy `whe-db.php` into your project and require it:

```php
require_once 'whe-db.php';
```

There's no Composer package (yet) — this is intentionally a drop-in class rather than a managed dependency.

## Getting Started

```php
require_once 'whe-db.php';

$db = new wheDB('localhost', 'db_user', 'db_password', 'my_database');
```

By default, wheDB connects using the `utf8mb4` charset. You can override this with a fifth argument:

```php
$db = new wheDB('localhost', 'db_user', 'db_password', 'my_database', 'utf8');
```

## Fetching Data

wheDB provides several fetch methods, each returning results in a different shape depending on what you need.

### `fetchAll()` — every row, as an array of associative arrays

```php
$rows = $db->fetchAll('SELECT id, title FROM articles WHERE published = 1');

foreach ($rows as $row) {
    echo $row['title'];
}
```

### `fetchRow()` — a single row, as an associative array

```php
$article = $db->fetchRow('SELECT * FROM articles WHERE id = 42');

echo $article['title'];
```

### `fetchCol()` — a single column, as a flat array

```php
$titles = $db->fetchCol('SELECT title FROM articles');
// ['Ancient Rome', 'The Silk Road', ...]
```

### `fetchAssoc()` — rows keyed by their first column

```php
$articlesById = $db->fetchAssoc('SELECT id, title, author FROM articles');
// [42 => ['id' => 42, 'title' => '...', 'author' => '...'], ...]

echo $articlesById[42]['title'];
```

### `fetchOne()` — a single scalar value

```php
$count = $db->fetchOne('SELECT COUNT(*) FROM articles');
```

### `fetchPairs()` — key/value pairs from the first two selected columns

```php
$options = $db->fetchPairs('SELECT id, title FROM articles');
// [42 => 'Ancient Rome', 43 => 'The Silk Road', ...]
```

### Caching fetch results

Every fetch method accepts an optional second `$cached` argument. When `true`, the result is cached in memory (per-request) and repeat calls with the identical SQL string are served from cache instead of hitting the database again:

```php
$rows = $db->fetchAll('SELECT * FROM settings', true);
```

This is a simple in-memory cache keyed by an MD5 hash of the query — it does not persist between requests.

## Inserting Data

### `insert()`

```php
$affectedRows = $db->insert('articles', [
    'title'   => 'The Library of Alexandria',
    'author'  => 'J. Historian',
    'created' => $db->now(),
]);
```

Only columns that actually exist on the target table are included in the generated `INSERT` statement — any extra array keys are silently ignored. Auto-increment columns are skipped automatically, and `NULL` values are dropped for non-nullable columns.

### `insertIgnore()`

Same as `insert()`, but generates `INSERT IGNORE`:

```php
$db->insertIgnore('tags', ['name' => 'Mesopotamia']);
```

### `replace()`

Generates a `REPLACE INTO` statement:

```php
$db->replace('settings', ['key' => 'homepage_banner', 'value' => 'winter-sale']);
```

### `lastInsertId()`

```php
$db->insert('articles', ['title' => 'New Article']);
$id = $db->lastInsertId();
```

## Updating Data

```php
$db->update('articles', [
    'title' => 'The Library of Alexandria (Revised)',
], 'id = 42');
```

The third argument is a raw `WHERE` clause (without the `WHERE` keyword). It is **not** parameterized, so build it carefully — see [Quoting Values](#quoting-values) below if you need to interpolate user input into it.

`updateIgnore()` works the same way but generates `UPDATE IGNORE`.

## Deleting Data

```php
$db->delete('articles', 'id = 42');
```

As with `update()`, the `$where` argument is a raw SQL fragment.

## Running Raw Queries

For anything not covered by the fetch/insert/update/delete helpers, use `query()` directly:

```php
$result = $db->query('SELECT * FROM articles WHERE views > 1000');

while ($row = $result->fetch_assoc()) {
    echo $row['title'];
}
```

`query()` returns a `mysqli_result` object (or `false` on failure), the same as calling `mysqli::query()` directly.

### Multi-statement queries

If your SQL string contains multiple statements separated by semicolons, `query()` automatically detects this and routes it through `multi_query()`, which wraps execution in a transaction:

```php
$db->query("
    UPDATE articles SET status = 'archived' WHERE views < 10;
    DELETE FROM article_drafts WHERE created < NOW() - INTERVAL 1 YEAR;
");
```

## Quoting Values

### `quote()`

Safely quotes a single value for direct interpolation into SQL:

```php
$name = $db->quote("O'Brien");
// 'O\'Brien'
```

### `quoteInto()`

Replaces a `?` placeholder in a string with a quoted value — handy for building `WHERE` fragments for `update()` or `delete()`:

```php
$where = $db->quoteInto('author = ?', 'J. Historian');
$db->update('articles', ['status' => 'reviewed'], $where);
```

## Table Metadata

### `describeTable()`

Returns detailed column metadata (type, length, nullability, keys, etc.) for a table:

```php
$columns = $db->describeTable('articles');

print_r($columns['title']);
// [
//   'TABLE_NAME' => 'articles',
//   'COLUMN_NAME' => 'title',
//   'DATA_TYPE' => 'varchar',
//   'NULLABLE' => false,
//   ...
// ]
```

Results are cached in memory by default; pass `false` as the second argument to bypass the cache.

### `listColumns()`

Returns just the column names for a table:

```php
$columns = $db->listColumns('articles');
// ['id', 'title', 'author', 'created', ...]
```

### `countRows()`

```php
$total = $db->countRows('articles');
$published = $db->countRows('articles', "status = 'published'");
```

## Exporting to CSV

`queryToCsv()` runs a query and writes the results directly to a CSV file, or streams it to the browser as a download.

Write to a file on disk:

```php
$db->queryToCsv('SELECT * FROM articles', '/path/to/export.csv');
```

Stream as a browser download (sends the appropriate headers):

```php
$db->queryToCsv('SELECT * FROM articles', 'articles-export.csv', true);
```

Pass `false` as the fourth argument to omit the header row:

```php
$db->queryToCsv('SELECT * FROM articles', '/path/to/export.csv', false, false);
```

## Utility Methods

### `now()`

Returns the current date/time in MySQL's `DATETIME` format:

```php
$db->insert('articles', [
    'title'   => 'New Article',
    'created' => $db->now(),
]);
```

### `closeConnection()`

```php
$db->closeConnection();
```

## Query Logging

Set the public `$logging` property to `true` to record every executed query in the `$log` array (keyed by normalized SQL, with a count of how many times each query ran):

```php
$db->logging = true;

$db->fetchAll('SELECT * FROM articles');
$db->fetchAll('SELECT * FROM articles');

print_r($db->log);
// ['SELECT * FROM articles' => 2]
```

This is useful for spotting duplicate or N+1-style queries during development.

## Error Handling

After a failed `query()`, `insert()`, or `update()` call, check the public `$error` property for the MySQL error message:

```php
$db->update('articles', ['status' => 'bad_status'], 'id = 42');

if ($db->error) {
    error_log($db->error);
}
```

Malformed SQL passed to `query()` throws an `Exception` rather than failing silently.

## Notes and Limitations

- `update()` and `delete()` take a raw `$where` string rather than parameterized conditions — use `quote()` or `quoteInto()` to safely interpolate any user-supplied values.
- The in-memory query cache (`$cached = true`) is per-request only; it is not a persistent or shared cache.
- `insert()` and `replace()` silently drop array keys that don't correspond to real table columns, which is convenient for passing whole `$_POST`-style arrays but worth knowing about if a value seems to "disappear."

## License

Add your license of choice here (MIT is a common choice for small utility classes like this one).
