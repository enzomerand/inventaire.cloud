<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Closure;

/**
 * A grade as a whole number from 1 to the field's maximum (3 to 10, five by default): a condition, a quality.
 *
 * Unlike the other numbers, it is stored as a JSON **integer**, not as a decimal string: the reason for the strings
 * (MySQL and MariaDB turn a JSON number into a floating point value, which loses digits) does not exist for a whole
 * number from 1 to 10, which a double holds exactly, and an integer is what a rating is for any reader of the data.
 *
 * When the maximum of a field is lowered, the grades above the new maximum are removed from the items in the same
 * transaction ({@see FieldType::mayInvalidateValues()}).
 */
final class RatingType extends AbstractFieldType
{
    /** The fewest grades a field may offer. */
    public const MIN_OF_MAX = 3;

    /** The most grades a field may offer. */
    public const MAX_OF_MAX = 10;

    /** The highest grade of a field that does not say. */
    public const DEFAULT_MAX = 5;

    /**
     * The word that identifies the type in the API and in the database: `rating`.
     */
    public function key(): string
    {
        return 'rating';
    }

    /**
     * What the type is called for a person ("Rating"), in the language of the request.
     */
    public function label(): string
    {
        return __('Rating');
    }

    /**
     * The highest grade.
     *
     * @param  array<string, mixed>|null  $previous
     * @return array<string, list<mixed>>
     */
    public function optionRules(?array $previous = null): array
    {
        return [
            'max' => ['nullable', 'integer', 'between:'.self::MIN_OF_MAX.','.self::MAX_OF_MAX],
        ];
    }

    /**
     * Fills in the highest grade.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        return ['max' => (int) ($options['max'] ?? self::DEFAULT_MAX)];
    }

    /**
     * A whole number from 1 to the highest grade of the field.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        $max = (int) $field->option('max', self::DEFAULT_MAX);

        return [function (string $attribute, mixed $value, Closure $fail) use ($max): void {
            $grade = is_int($value) ? $value : (is_string($value) && preg_match('/^[0-9]{1,2}$/D', $value) === 1 ? (int) $value : null);

            if ($grade === null || $grade < 1 || $grade > $max) {
                $fail(__('The :attribute must be a whole number from 1 to :max.', ['max' => $max]));
            }
        }];
    }

    /**
     * Lowering the maximum leaves grades that are now too high.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $options
     */
    public function mayInvalidateValues(array $previous, array $options): bool
    {
        return (int) ($options['max'] ?? self::DEFAULT_MAX) < (int) ($previous['max'] ?? self::DEFAULT_MAX);
    }

    /**
     * The grade, unless it is outside what the field allows now (1 to its maximum): then there is nothing to show.
     */
    public function present(mixed $stored, InventoryField $field): mixed
    {
        return is_int($stored) && $stored >= 1 && $stored <= (int) $field->option('max', self::DEFAULT_MAX) ? $stored : null;
    }

    /**
     * The grade as an integer, however it was sent.
     */
    public function normalize(mixed $value, InventoryField $field): mixed
    {
        return is_numeric($value) ? (int) $value : $value;
    }
}
