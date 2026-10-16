<?php

declare(strict_types=1);

namespace App\Database;

use App\Inventories\ItemQuery;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use LogicException;
use WeakMap;

/**
 * Reaches into the JSON object an item keeps its values in (`items.values`, keyed by the ULID of the field), the same way
 * on PostgreSQL, MySQL and MariaDB: it is the only place that knows how each engine reads, compares, sorts and adds them up.
 *
 * Every method takes the key of one value: the lowercase ULID of a field, which the application makes and never a text
 * a person typed (anything else is refused, since the key ends up in the SQL); every value to compare with is a binding.
 * Reading a key goes through the query grammar of the connection, which already knows how its engine writes a path into a
 * JSON column and quotes the reserved word (`values` is one in MySQL):
 *
 * - PostgreSQL: `"items"."values"->>'key'` (the column is `jsonb`, the result is text). `jsonb` is the binary form, which
 *   is looked into without being parsed again for every key, about twice as fast as `json` for the totals; the price is
 *   that it does not keep the order of the keys, as MySQL and MariaDB do not either. The checks of presence and of
 *   containment are the grammar's (`("items"."values")::jsonb ?? 'key'` and `@>`: the `?` operator is written `??` so that
 *   PDO does not take it for a placeholder), which on a `jsonb` column cast nothing;
 * - MySQL: ``json_unquote(json_extract(`items`.`values`, '$."key"'))``;
 * - MariaDB: ``json_value(`items`.`values`, '$."key"')``. It gives the text of a string or a number and, unlike the two
 *   others, `1` or `0` for a boolean (they give `true` or `false`: {@see self::whereBoolean()}) and null for an array or an
 *   object: the presence of a key is therefore never tested through it ({@see self::wherePresent()}).
 *
 * Three things need more than the grammar gives:
 *
 * - numbers are compared, sorted and added up as numbers, never as floating point values: the stored decimal string is cast
 *   to `numeric` (PostgreSQL) or to `decimal(40,6)` (MySQL and MariaDB: at most 6 decimals are stored, and the 34 digits
 *   before the dot hold the sum of any number of values below 1e15). A bound given to compare with is cast the same way, so
 *   both sides are exact decimals ({@see self::number()}, {@see self::numberBound()});
 * - text is sorted with the collation the application configured for the connection on MySQL and MariaDB (the result of a
 *   JSON function is binary there, so capitals and accents would otherwise decide the order), and with the ICU root
 *   collation (`und-x-icu`) on PostgreSQL, which orders the same way (the case and the accents only break ties): the
 *   default collation of a PostgreSQL database is chosen when it is created and may be `C`, which puts every capital and
 *   every accent after the lowercase letters. A PostgreSQL built without ICU keeps its default ({@see self::sortableText()});
 * - a list holds a value when its JSON array contains it ({@see self::whereContainsAny()}).
 *
 * What the values hold decides what the SQL can rely on, and the application keeps it so when it writes them:
 *
 * - an empty value is never stored: the key is absent. A JSON `null` therefore cannot occur, which matters because MySQL
 *   extracts it as the text `null` (not as an SQL null) and every "no value" test here (`is null`, presence of the key)
 *   would count it as a value;
 * - the ids of choices are lowercase ULIDs: MariaDB compares text case-insensitively under the collation of the
 *   connection, so ids that differed only by their case would be taken for one, and they never do;
 * - there is one sort key at a time ({@see self::orderByCollatedText()} and the others add one expression each, and the
 *   tie is always broken by the id of the item): sorting by several fields would multiply the extractions of the JSON.
 *
 * The SQL is handed to the builder as {@see RawSql}. {@see ItemQuery} builds the sorts, filters and totals of the list of
 * items from these methods and writes none itself.
 */
final class JsonValues
{
    /** The JSON column that holds the values, as the query names it. */
    private const COLUMN = 'items.values';

    /** What a key looks like: the lowercase ULID of a field (26 characters of the alphabet of ULIDs, lowercase). */
    private const KEY_PATTERN = '/^[0-9a-z]{26}$/D';

    /** The comparisons {@see self::whereNumber()} and {@see self::whereText()} accept. */
    private const OPERATORS = ['=', '>=', '<='];

    /** The collation PostgreSQL sorts text with when it was built with ICU: the Unicode root order, which ignores the case and counts the accents last. */
    private const ICU_COLLATION = 'und-x-icu';

    /**
     * Whether each PostgreSQL connection knows {@see self::ICU_COLLATION}, asked once per connection.
     *
     * @var WeakMap<Connection, bool>|null
     */
    private static ?WeakMap $icu = null;

    /**
     * Gets the connection whose engine decides the SQL.
     */
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * The SQL of the value as text: null when the key is absent (and, on MariaDB, when the value is a list), `true` or
     * `false` for a boolean (`1` or `0` on MariaDB), the digits of a number as they were stored.
     */
    public function text(string $key): string
    {
        $this->driver();

        return $this->connection->getQueryGrammar()->wrap(self::COLUMN.'->'.$this->checked($key));
    }

    /**
     * The SQL of the value as text to sort by: on MySQL and MariaDB with the collation configured for the connection
     * (`utf8mb4_unicode_ci` by default, which ignores the case and puts the accents beside their letter); on PostgreSQL
     * with the ICU root collation (`und-x-icu`), which does the same (the case and the accents only decide between values
     * that are otherwise equal), whatever the collation the database was created with. When the PostgreSQL in use has no
     * ICU (the collation is not in `pg_collation`; asked once per connection), the default collation of the database is
     * all there is, and the order is the one it gives.
     */
    public function sortableText(string $key): string
    {
        $text = $this->text($key);

        if ($this->driver() === 'pgsql') {
            // Parenthesized: COLLATE binds tighter than ->>, so without them it would apply to the key and not to the text.
            return $this->hasIcu() ? '('.$text.') collate "'.self::ICU_COLLATION.'"' : $text;
        }

        $collation = $this->connection->getConfig('collation');

        // The result of a JSON function is utf8mb4: a collation of another character set would be refused by the engine.
        if (! is_string($collation) || preg_match('/^utf8mb4_[A-Za-z0-9_]+$/D', $collation) !== 1) {
            return $text;
        }

        return $text.' collate '.$collation;
    }

    /**
     * The SQL of the value as an exact decimal number: `("items"."values"->>'key')::numeric` on PostgreSQL,
     * ``cast(json_unquote(json_extract(`items`.`values`, '$."key"')) as decimal(40,6))`` on MySQL (``json_value`` on MariaDB).
     * Null when the key is absent.
     */
    public function number(string $key): string
    {
        $text = $this->text($key);

        return $this->driver() === 'pgsql' ? "({$text})::numeric" : "cast({$text} as decimal(40,6))";
    }

    /**
     * The SQL of a bound number (the `?` of a canonical decimal string), cast the way {@see self::number()} casts the
     * values, so that both sides of a comparison are exact decimals.
     */
    public function numberBound(): string
    {
        return $this->driver() === 'pgsql' ? 'cast(? as numeric)' : 'cast(? as decimal(40,6))';
    }

    /**
     * Keeps the rows that hold a value for the key (or, with `$present` false, the ones that do not). It tests the key
     * itself, so it is right for a list too, which MariaDB's reading as text does not give.
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     */
    public function wherePresent(EloquentBuilder|Relation $query, string $key, bool $present): void
    {
        $path = self::COLUMN.'->'.$this->checked($key);

        if ($present) {
            $query->whereJsonContainsKey($path);
        } else {
            $query->whereJsonDoesntContainKey($path);
        }
    }

    /**
     * Keeps the rows where the value, compared as an exact number, is `>=`, `<=` or `=` the bound. The rows that hold no
     * value are never kept.
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     * @param  string  $operator  `>=`, `<=` or `=`.
     * @param  string  $bound  A canonical decimal string (`-12.5`).
     */
    public function whereNumber(EloquentBuilder|Relation $query, string $key, string $operator, string $bound): void
    {
        $query->whereRaw(new RawSql($this->number($key).' '.$this->operator($operator).' '.$this->numberBound()), [$bound]);
    }

    /**
     * Keeps the rows where the value, compared as text, is `>=`, `<=` or `=` the bound. Meant for the values that are
     * written with a fixed width (a date, a moment, a time, `true` and `false`), where the order of the text is the order
     * of the values. The rows that hold no value are never kept.
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     * @param  string  $operator  `>=`, `<=` or `=`.
     */
    public function whereText(EloquentBuilder|Relation $query, string $key, string $operator, string $bound): void
    {
        $query->whereRaw(new RawSql($this->text($key).' '.$this->operator($operator).' ?'), [$bound]);
    }

    /**
     * Keeps the rows where the value is the boolean (`true` or `false`: not the rows that hold no value).
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     */
    public function whereBoolean(EloquentBuilder|Relation $query, string $key, bool $value): void
    {
        // What a JSON boolean reads as text depends on the engine (see the class documentation).
        $text = $this->driver() === 'mariadb' ? ($value ? '1' : '0') : ($value ? 'true' : 'false');

        $this->whereText($query, $key, '=', $text);
    }

    /**
     * Keeps the rows where the value, as text, is one of these.
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     * @param  list<string>  $values
     */
    public function whereTextIn(EloquentBuilder|Relation $query, string $key, array $values): void
    {
        $query->whereIn(new RawSql($this->text($key)), $values);
    }

    /**
     * Keeps the rows where the value, a JSON list, contains at least one of these texts.
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     * @param  list<string>  $values
     */
    public function whereContainsAny(EloquentBuilder|Relation $query, string $key, array $values): void
    {
        $path = self::COLUMN.'->'.$this->checked($key);

        $query->where(function (EloquentBuilder $any) use ($path, $values): void {
            foreach ($values as $value) {
                $any->orWhereJsonContains($path, $value);
            }
        });
    }

    /**
     * Orders by the value as text with the collation of the connection ({@see self::sortableText()}). The rows that hold
     * no value come last, in either direction.
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     * @param  'asc'|'desc'  $direction  Anything else is refused by the builder with an {@see InvalidArgumentException}.
     */
    public function orderByCollatedText(EloquentBuilder|Relation $query, string $key, string $direction): void
    {
        $this->orderEmptyLast($query, $key);
        $query->orderBy(new RawSql($this->sortableText($key)), $direction);
    }

    /**
     * Orders by the value as text, as it is stored: right for the values written with a fixed width (a date, a moment, a
     * time, and a boolean, whose `false` or `0` comes before `true` or `1`). The rows that hold no value come last, in either direction.
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     * @param  'asc'|'desc'  $direction  Anything else is refused by the builder with an {@see InvalidArgumentException}.
     */
    public function orderByText(EloquentBuilder|Relation $query, string $key, string $direction): void
    {
        $this->orderEmptyLast($query, $key);
        $query->orderBy(new RawSql($this->text($key)), $direction);
    }

    /**
     * Orders by the value as an exact number. The rows that hold no value come last, in either direction.
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     * @param  'asc'|'desc'  $direction  Anything else is refused by the builder with an {@see InvalidArgumentException}.
     */
    public function orderByNumber(EloquentBuilder|Relation $query, string $key, string $direction): void
    {
        $this->orderEmptyLast($query, $key);
        $query->orderBy(new RawSql($this->number($key)), $direction);
    }

    /**
     * Orders by the position of the value (the id of a choice) in a list of ids. The rows that hold no value, or one that
     * is not in the list, come last, in either direction. Nothing is added when the list is empty.
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     * @param  list<string>  $ids  The ids in the order they sort.
     * @param  'asc'|'desc'  $direction  Anything else is refused by the builder with an {@see InvalidArgumentException}.
     */
    public function orderByPosition(EloquentBuilder|Relation $query, string $key, array $ids, string $direction): void
    {
        if ($ids === []) {
            return;
        }

        $text = $this->text($key);
        $whens = '';

        foreach (array_keys($ids) as $position) {
            $whens .= " when ? then {$position}";
        }

        // The ids are bound; a binding of an order clause follows the order the clauses are added in.
        $query->orderBy(new RawSql("case when {$text} in (".implode(', ', array_fill(0, count($ids), '?')).') then 0 else 1 end'), 'asc');
        $query->addBinding($ids, 'order');
        $query->orderBy(new RawSql("case {$text}{$whens} end"), $direction);
        $query->addBinding($ids, 'order');
    }

    /**
     * The exact sum of the values of each key over the rows of the query, in one aggregate query: the sum of a key is null
     * when no row holds a value for it. Only the rows the query matches count, whatever its order and its limits: the
     * order, the limit and the offset of the query are left out (an offset would otherwise skip the one row the sum is in).
     *
     * @param  list<string>  $keys
     * @return array<string, mixed> The sum of each key (as the database gives it: a string of digits), by key.
     */
    public function sums(QueryBuilder $query, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $selects = [];

        foreach ($keys as $position => $key) {
            $selects[] = new RawSql('sum('.$this->number($key).') as sum_'.$position);
        }

        $aggregate = $query->clone()->reorder()->select($selects);
        $aggregate->limit = null;
        $aggregate->offset = null;

        $row = (array) ($aggregate->first() ?? []);
        $sums = [];

        foreach ($keys as $position => $key) {
            $sums[$key] = $row['sum_'.$position] ?? null;
        }

        return $sums;
    }

    /**
     * Whether the PostgreSQL connection has the ICU collation, asked to the database the first time and kept for the
     * connection after that.
     */
    private function hasIcu(): bool
    {
        self::$icu ??= new WeakMap;

        if (! isset(self::$icu[$this->connection])) {
            self::$icu[$this->connection] = (bool) $this->connection->scalar(
                "select exists (select 1 from pg_collation where collname = ? and collprovider = 'i')",
                [self::ICU_COLLATION],
            );
        }

        return self::$icu[$this->connection];
    }

    /**
     * Puts the rows that hold no value for the key after the others, whichever way the rest is ordered: the first key of
     * an order is whether the value is missing, which is portable where `NULLS LAST` is not.
     *
     * @param  EloquentBuilder<*>|Relation<*, *, *>  $query
     */
    private function orderEmptyLast(EloquentBuilder|Relation $query, string $key): void
    {
        $query->orderBy(new RawSql('case when '.$this->text($key).' is null then 1 else 0 end'), 'asc');
    }

    /**
     * The key, once it is known to be what the application makes.
     *
     * @throws InvalidArgumentException When it is not a lowercase ULID (or anything else made of lowercase letters and digits).
     */
    private function checked(string $key): string
    {
        return preg_match(self::KEY_PATTERN, $key) === 1 ? $key : throw new InvalidArgumentException('A JSON key must be the lowercase ULID of a field.');
    }

    /**
     * The comparison, once it is known to be one of the three.
     *
     * @throws InvalidArgumentException When it is not.
     */
    private function operator(string $operator): string
    {
        return in_array($operator, self::OPERATORS, true) ? $operator : throw new InvalidArgumentException('Unsupported comparison.');
    }

    /**
     * The driver of the connection, when it is one of the three engines the application runs on.
     *
     * @throws LogicException For any other.
     */
    private function driver(): string
    {
        $driver = $this->connection->getDriverName();

        return in_array($driver, ['pgsql', 'mysql', 'mariadb'], true) ? $driver : throw new LogicException("The JSON values are not read on the \"{$driver}\" driver.");
    }
}
