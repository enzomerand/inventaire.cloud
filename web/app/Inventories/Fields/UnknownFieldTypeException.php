<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use InvalidArgumentException;

/**
 * Raised when a type is asked for by a key the registry does not hold. The API refuses such a key before it gets here;
 * this is for code that skips that check, and for a field row that names a type this version does not know.
 */
final class UnknownFieldTypeException extends InvalidArgumentException
{
    /**
     * @param  string  $key  The word that was asked for.
     */
    public function __construct(public readonly string $key)
    {
        parent::__construct("There is no field type \"{$key}\".");
    }
}
