<?php

declare(strict_types=1);

use App\Database\JsonValues;
use App\Models\Item;
use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;

/*
 * The one place that knows how each engine reads the values of an item. What it makes of them is tested through the list
 * of items (ItemQueryTest.php), on the engine the tests run on; here, the SQL it writes for each of the three, whatever the
 * engine the tests run on (the connections are stand-ins that never connect).
 */

/**
 * A connection to an engine that is never opened: only its driver, its grammar and its configuration are used. The
 * PostgreSQL one answers the question whether the database has the ICU collation (the only one asked) without a database,
 * and counts the times it was asked.
 *
 * @param  array<string, mixed>  $config
 * @param  bool  $icu  What a PostgreSQL connection says when asked whether it has the ICU collation.
 */
function jsonValuesConnection(string $driver, array $config = [], bool $icu = true): Connection
{
    $pdo = fn () => throw new LogicException('Never connected.');
    $config += ['driver' => $driver, 'collation' => 'utf8mb4_unicode_ci'];

    return match ($driver) {
        'pgsql' => new class($pdo, 'db', '', $config, $icu) extends PostgresConnection
        {
            /** How many times the connection was asked about the ICU collation. */
            public int $asked = 0;

            /**
             * @param  array<string, mixed>  $config
             */
            public function __construct(Closure $pdo, string $database, string $prefix, array $config, private readonly bool $icu)
            {
                parent::__construct($pdo, $database, $prefix, $config);
            }

            /**
             * @param  string  $query
             * @param  array<mixed>  $bindings
             * @param  bool  $useReadPdo
             */
            public function selectOne($query, $bindings = [], $useReadPdo = true): object
            {
                expect($query)->toContain('pg_collation')->and($bindings)->toBe(['und-x-icu']);
                $this->asked++;

                return (object) ['exists' => $this->icu];
            }
        },
        'mysql' => new MySqlConnection($pdo, 'db', '', $config),
        'mariadb' => new MariaDbConnection($pdo, 'db', '', $config),
        default => new SQLiteConnection($pdo, 'db', '', $config),
    };
}

describe('the SQL of a value', function (): void {
    it('reads a key as text with the grammar of the engine', function (string $driver, string $sql): void {
        expect((new JsonValues(jsonValuesConnection($driver)))->text('01abc'))->toBe($sql);
    })->with([
        'PostgreSQL' => ['pgsql', '"items"."values"->>\'01abc\''],
        'MySQL' => ['mysql', 'json_unquote(json_extract(`items`.`values`, \'$."01abc"\'))'],
        'MariaDB' => ['mariadb', 'json_value(`items`.`values`, \'$."01abc"\')'],
    ]);

    it('casts a number and the bound it is compared with to the same exact decimal type', function (string $driver, string $number, string $bound): void {
        $values = new JsonValues(jsonValuesConnection($driver));

        expect($values->number('01abc'))->toBe(sprintf($number, $values->text('01abc')))->and($values->numberBound())->toBe($bound);
    })->with([
        'PostgreSQL' => ['pgsql', '(%s)::numeric', 'cast(? as numeric)'],
        'MySQL' => ['mysql', 'cast(%s as decimal(40,6))', 'cast(? as decimal(40,6))'],
        'MariaDB' => ['mariadb', 'cast(%s as decimal(40,6))', 'cast(? as decimal(40,6))'],
    ]);

    it('sorts text with the collation of the connection on MySQL and MariaDB', function (string $driver): void {
        $values = new JsonValues(jsonValuesConnection($driver, ['collation' => 'utf8mb4_0900_ai_ci']));

        expect($values->sortableText('01abc'))->toBe($values->text('01abc').' collate utf8mb4_0900_ai_ci');
    })->with(['MySQL' => 'mysql', 'MariaDB' => 'mariadb']);

    it('sorts text with the ICU root collation on PostgreSQL, whatever the collation of the database', function (): void {
        $values = new JsonValues(jsonValuesConnection('pgsql', ['collation' => 'utf8mb4_0900_ai_ci']));

        // The text is in parentheses: COLLATE binds tighter than ->>, so it would otherwise apply to the key alone.
        expect($values->sortableText('01abc'))->toBe('("items"."values"->>\'01abc\') collate "und-x-icu"');
    });

    it('keeps the default collation of a PostgreSQL that was built without ICU', function (): void {
        $values = new JsonValues(jsonValuesConnection('pgsql', icu: false));

        expect($values->sortableText('01abc'))->toBe($values->text('01abc'));
    });

    it('asks a PostgreSQL connection about ICU once, and each connection for itself', function (): void {
        $icu = jsonValuesConnection('pgsql');
        $plain = jsonValuesConnection('pgsql', icu: false);

        foreach (range(1, 3) as $_) {
            (new JsonValues($icu))->sortableText('01abc');
            (new JsonValues($plain))->sortableText('01abc');
        }

        expect($icu->asked)->toBe(1)->and($plain->asked)->toBe(1)
            ->and((new JsonValues($icu))->sortableText('01abc'))->toContain('collate "und-x-icu"')
            ->and((new JsonValues($plain))->sortableText('01abc'))->not->toContain('collate');
    });

    it('does not ask about ICU for anything but a sort', function (): void {
        $connection = jsonValuesConnection('pgsql');
        $values = new JsonValues($connection);
        $values->text('01abc');
        $values->number('01abc');

        expect($connection->asked)->toBe(0);
    });

    it('leaves out a collation that is not one of utf8mb4, which the engine would refuse', function (string $driver, mixed $collation): void {
        $values = new JsonValues(jsonValuesConnection($driver, ['collation' => $collation]));

        expect($values->sortableText('01abc'))->toBe($values->text('01abc'));
    })->with(['mysql', 'mariadb'])->with(['another character set' => 'latin1_swedish_ci', 'an injection' => 'utf8mb4_x; drop table items', 'none' => null]);

    it('reads the values on those three engines only', function (): void {
        expect(fn () => (new JsonValues(jsonValuesConnection('sqlite')))->text('01abc'))->toThrow(LogicException::class);
    });
});

describe('what ends up in the SQL', function (): void {
    it('refuses a key that is not the lowercase ULID of a field', function (string $key): void {
        $values = new JsonValues(jsonValuesConnection('pgsql'));
        $query = Item::query();

        expect(fn () => $values->text($key))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $values->number($key))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $values->wherePresent($query, $key, true))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $values->whereContainsAny($query, $key, ['a']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $values->orderByNumber($query, $key, 'asc'))->toThrow(InvalidArgumentException::class);
    })->with(['capitals' => '01ABC', 'a quote' => "a'b", 'a path' => 'a->b', 'a dot' => 'a.b', 'a space' => 'a b', 'nothing' => '', 'a comment' => 'a--']);

    it('refuses a comparison and a direction it does not know', function (): void {
        $values = new JsonValues(jsonValuesConnection('pgsql'));
        $query = Item::query();

        expect(fn () => $values->whereNumber($query, '01abc', '= 1 or 1 =', '1'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $values->whereText($query, '01abc', '<>', '1'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $values->orderByText($query, '01abc', 'asc; drop table items'))->toThrow(InvalidArgumentException::class);
    });

    it('binds every value it is given', function (): void {
        $values = new JsonValues(jsonValuesConnection('mysql'));
        $query = Item::query();

        $values->whereNumber($query, '01abc', '>=', "5'; drop table items; --");
        $values->whereTextIn($query, '01abc', ["x'", 'y']);
        $values->whereBoolean($query, '01abc', true);
        $values->orderByPosition($query, '01abc', ["a'", 'b'], 'desc');

        expect($query->toBase()->toSql())->not->toContain('drop table')->not->toContain("x'")->not->toContain("a'")
            ->and($query->toBase()->getBindings())->toBe(["5'; drop table items; --", "x'", 'y', 'true', "a'", 'b', "a'", 'b']);
    });

    it('reads a boolean the way the engine writes it as text', function (string $driver, string $yes, string $no): void {
        $values = new JsonValues(jsonValuesConnection($driver));
        $on = Item::query();
        $off = Item::query();

        $values->whereBoolean($on, '01abc', true);
        $values->whereBoolean($off, '01abc', false);

        expect([$on->toBase()->getBindings(), $off->toBase()->getBindings()])->toBe([[$yes], [$no]]);
    })->with([
        'PostgreSQL' => ['pgsql', 'true', 'false'],
        'MySQL' => ['mysql', 'true', 'false'],
        'MariaDB, whose json_value writes 1 and 0' => ['mariadb', '1', '0'],
    ]);

    it('does not add up when there is nothing to add', function (): void {
        $values = new JsonValues(jsonValuesConnection('pgsql'));

        expect($values->sums(Item::query()->toBase(), []))->toBe([]);
    });
});
