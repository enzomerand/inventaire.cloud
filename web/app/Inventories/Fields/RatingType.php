<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Closure;

/**
 * A grade as a whole number from 1 to the field's maximum (3 to 10, five by default): a condition, a quality.
 */
final class RatingType extends AbstractFieldType
{
    /** The fewest grades a field may offer. */
    public const MIN_OF_MAX = 3;

    /** The most grades a field may offer. */
    public const MAX_OF_MAX = 10;

    /** The highest grade of a field that does not say. */
    public const DEFAULT_MAX = 5;

    public function key(): string
    {
        return 'rating';
    }

    public function label(): string
    {
        return __('Rating');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function optionRules(): array
    {
        return [
            'max' => ['nullable', 'integer', 'between:'.self::MIN_OF_MAX.','.self::MAX_OF_MAX],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        return ['max' => (int) ($options['max'] ?? self::DEFAULT_MAX)];
    }

    /**
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
     * The grade as an integer, however it was sent.
     */
    public function normalize(mixed $value, InventoryField $field): mixed
    {
        return is_numeric($value) ? (int) $value : $value;
    }
}
