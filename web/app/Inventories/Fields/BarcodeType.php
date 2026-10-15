<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Closure;
use Illuminate\Validation\Rule;

/**
 * A code printed as a barcode: EAN-13, EAN-8, UPC-A, ISBN-10 or ISBN-13, whose check digit is verified, or any code of
 * up to 64 letters, digits, dashes and dots when the field says `any` (the default).
 *
 * The hyphens and spaces that ISBNs are printed with are accepted and dropped, so that the stored code is the digits
 * (an ISBN-10 may end in X). The other formats are digits only.
 */
final class BarcodeType extends AbstractFieldType
{
    /** The formats a field may be limited to. */
    public const FORMATS = ['any', 'ean13', 'ean8', 'upca', 'isbn10', 'isbn13'];

    /** Longest code of the format `any`, in characters. */
    public const ANY_MAX_LENGTH = 64;

    public function key(): string
    {
        return 'barcode';
    }

    public function label(): string
    {
        return __('Barcode');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function optionRules(): array
    {
        return [
            'format' => ['nullable', 'string', Rule::in(self::FORMATS)],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        $format = $options['format'] ?? null;

        return ['format' => is_string($format) && in_array($format, self::FORMATS, true) ? $format : 'any'];
    }

    /**
     * Drops the hyphens and spaces of an ISBN.
     */
    public function prepare(mixed $value, InventoryField $field): mixed
    {
        $value = parent::prepare($value, $field);

        return is_string($value) && in_array($this->format($field), ['isbn10', 'isbn13'], true)
            ? strtoupper(str_replace(['-', ' '], '', $value))
            : $value;
    }

    /**
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        $format = $this->format($field);

        return ['string', function (string $attribute, mixed $value, Closure $fail) use ($format): void {
            if (! is_string($value) || ! self::isValid($value, $format)) {
                $fail(match ($format) {
                    'ean13' => __('The :attribute must be a valid EAN-13 code: 13 digits, the last one a check digit.'),
                    'ean8' => __('The :attribute must be a valid EAN-8 code: 8 digits, the last one a check digit.'),
                    'upca' => __('The :attribute must be a valid UPC-A code: 12 digits, the last one a check digit.'),
                    'isbn10' => __('The :attribute must be a valid ISBN-10.'),
                    'isbn13' => __('The :attribute must be a valid ISBN-13.'),
                    default => __('The :attribute must be 1 to :max letters, digits, dashes or dots.', ['max' => self::ANY_MAX_LENGTH]),
                });
            }
        }];
    }

    /**
     * Whether the code is valid for the format, check digit included.
     */
    public static function isValid(string $code, string $format): bool
    {
        return match ($format) {
            'ean13' => self::hasValidGtin($code, 13),
            'ean8' => self::hasValidGtin($code, 8),
            'upca' => self::hasValidGtin($code, 12),
            'isbn13' => (str_starts_with($code, '978') || str_starts_with($code, '979')) && self::hasValidGtin($code, 13),
            'isbn10' => self::hasValidIsbn10($code),
            default => preg_match('/^[A-Za-z0-9.\-]{1,'.self::ANY_MAX_LENGTH.'}$/D', $code) === 1,
        };
    }

    /**
     * The format the field is limited to.
     */
    private function format(InventoryField $field): string
    {
        return (string) $field->option('format', 'any');
    }

    /**
     * Whether the code is this many digits whose last one is the GS1 check digit: the digits before it, counted from the
     * right, weigh 3, 1, 3, 1... and the check digit completes their sum to a multiple of 10. (EAN-13, EAN-8 and UPC-A
     * only differ by their length.)
     */
    private static function hasValidGtin(string $code, int $length): bool
    {
        if (preg_match('/^[0-9]{'.$length.'}$/D', $code) !== 1) {
            return false;
        }

        $sum = 0;

        for ($position = 0; $position < $length - 1; $position++) {
            $weight = ($length - 2 - $position) % 2 === 0 ? 3 : 1;
            $sum += (int) $code[$position] * $weight;
        }

        return (10 - $sum % 10) % 10 === (int) $code[$length - 1];
    }

    /**
     * Whether the code is 10 characters (digits, the last one possibly X for 10) whose weighted sum, from 10 down to 1,
     * is a multiple of 11.
     */
    private static function hasValidIsbn10(string $code): bool
    {
        if (preg_match('/^[0-9]{9}[0-9X]$/D', $code) !== 1) {
            return false;
        }

        $sum = 0;

        for ($position = 0; $position < 10; $position++) {
            $digit = $code[$position] === 'X' ? 10 : (int) $code[$position];
            $sum += $digit * (10 - $position);
        }

        return $sum % 11 === 0;
    }
}
