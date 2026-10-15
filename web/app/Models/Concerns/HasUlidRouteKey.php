<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Gives a model a ULID for the outside world (URLs, API) while its numeric `id` stays internal.
 *
 * The numeric key never leaves the application: nothing reveals how many rows exist, and a row cannot be guessed
 * from its neighbours. The `ulid` column receives its value when the row is created, and is what route bindings
 * look for, whether the model is bound on its own (`/inventories/{inventory}`) or as the child of another one
 * (`/inventories/{inventory}/items/{item}`).
 */
trait HasUlidRouteKey
{
    use HasUlids;

    /**
     * The column that receives a ULID when the row is created: not the key, which stays a number.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /**
     * Rows are found by their ULID in URLs and API paths.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Looks a row up by the ULID of a URL or of an API path, whatever its case.
     *
     * ULIDs are case-insensitive but stored in lowercase, which PostgreSQL compares exactly and MySQL and MariaDB
     * (by default) do not: lowering the case here makes every engine answer the same. It is this method, not
     * `resolveRouteBinding()`, that the framework also calls for the child of a scoped binding, so one override
     * covers both.
     *
     * @param  Model|BuilderContract|Relation<*, *, *>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return BuilderContract
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, is_string($value) ? strtolower($value) : $value, $field);
    }
}
