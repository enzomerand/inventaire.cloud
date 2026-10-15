<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * A piece of SQL the application wrote itself, to hand to the query builder where it takes a raw expression.
 *
 * The builder's raw methods only take a literal string, so that text which comes from outside can never be written into
 * a query by mistake; an object that implements the builder's own {@see Expression} contract is the way it provides to
 * pass a fragment that is built (here from the quoting of the grammar of the connection). The rule that comes with it:
 * a fragment is made of what the application decides (identifiers it quotes, keys it checked, keywords) and every
 * value a person gave is a binding, never a part of the text. {@see JsonValues} is what builds them.
 */
final readonly class RawSql implements Expression
{
    /**
     * Wraps the SQL.
     */
    public function __construct(private string $sql) {}

    /**
     * The SQL, as it is.
     */
    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
