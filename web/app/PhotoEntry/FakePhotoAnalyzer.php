<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Inventories\Fields\ChoiceType;
use App\Models\InventoryField;

/**
 * An analyzer that reads nothing: it makes up a proposal for every field, always the same ones, built from the fields themselves.
 *
 * It is what the tests use, and what a development machine uses instead of Claude when `ANTHROPIC_FAKE=true` and `APP_ENV=local` (see
 * `config/services.php`): the whole flow can be tried without a key and without paying. It is never bound anywhere else. The values
 * are valid for the field they are made for (a choice that exists, a barcode with its check digit, a number inside its bounds), so
 * that they survive {@see Proposals}; the confidence goes round `high`, `medium`, `low` so that the three show up.
 */
final class FakePhotoAnalyzer implements PhotoAnalyzer
{
    /** A valid code for each format of barcode. */
    private const BARCODES = ['ean13' => '4006381333931', 'ean8' => '96385074', 'upca' => '036000291452', 'isbn10' => '0306406152', 'isbn13' => '9783161484100'];

    /**
     * @param  float  $delay  Seconds to wait before answering, so that the dialog shows its progress (a development machine; none in the tests).
     */
    public function __construct(private readonly float $delay = 0.0) {}

    /**
     * Makes the proposals up.
     *
     * @param  list<string>  $photos
     * @param  list<InventoryField>  $fields
     */
    public function analyze(array $photos, array $fields, string $locale): PhotoReading
    {
        if ($this->delay > 0) {
            usleep((int) ($this->delay * 1_000_000));
        }

        $confidences = ['high', 'medium', 'low'];
        $proposals = [];

        foreach ($fields as $index => $field) {
            $values = $this->valuesFor($field);

            if ($values !== []) {
                $proposals[] = [
                    'field' => $field->ulid,
                    'values' => $values,
                    'confidence' => $confidences[$index % 3],
                    'evidence' => count($photos) === 1 ? 'Made up (development)' : 'Made up from '.count($photos).' photos (development)',
                ];
            }
        }

        return new PhotoReading($proposals, 'fake', 0, 0);
    }

    /**
     * A value that the field accepts, as texts.
     *
     * @return list<string>
     */
    private function valuesFor(InventoryField $field): array
    {
        $decimals = (int) $field->option('decimals', 0);
        $labels = array_column(ChoiceType::choicesOf($field->options), 'label');

        return match ($field->type) {
            'text' => [mb_substr('Sample '.$field->name, 0, (int) $field->option('max_length', 255))],
            'textarea' => ['Sample text read on the object.'],
            'number' => [$this->number($field, $decimals)],
            'money' => [$decimals === 0 ? '20' : '19.'.str_repeat('9', $decimals)],
            'percentage' => [$decimals === 0 ? '12' : '12.5'],
            'boolean' => ['yes'],
            'date' => ['2026-01-15'],
            'datetime' => ['2026-01-15 10:30'],
            'time' => ['09:30'],
            'select' => array_slice($labels, 0, 1),
            'multiselect' => array_slice($labels, 0, 2),
            'tags' => ['sample', 'photo'],
            'url' => ['https://example.com/sample'],
            'email' => ['contact@example.com'],
            'phone' => ['+33 1 23 45 67 89'],
            'rating' => [(string) min(4, (int) $field->option('max', 5))],
            'color' => ['#336699'],
            'barcode' => [self::BARCODES[(string) $field->option('format', 'any')] ?? 'SAMPLE-001'],
            default => [],
        };
    }

    /**
     * A number inside the bounds of the field (its minimum when it has one, else 3, with a decimal when it keeps some).
     */
    private function number(InventoryField $field, int $decimals): string
    {
        $minimum = $field->option('min');
        $base = is_string($minimum) && $minimum !== '' ? $minimum : '3';

        return $decimals > 0 && ! str_contains($base, '.') ? $base.'.5' : $base;
    }
}
