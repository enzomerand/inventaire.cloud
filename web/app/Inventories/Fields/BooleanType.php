<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Inventories\Exceptions\InvalidImportValue;
use App\Inventories\Imports\ImportCell;
use App\Inventories\Imports\ImportContext;
use App\Inventories\SearchText;
use App\Models\InventoryField;

/**
 * A yes or a no: true or false (a client may also send 1 and 0, as the framework's `boolean` rule does).
 *
 * `false` is a value like any other, it is stored; only an absent value (null or empty text) clears the field. So a
 * required yes/no field asks to be answered, not to be ticked.
 */
final class BooleanType extends AbstractFieldType
{
    /**
     * The word that identifies the type in the API and in the database: `boolean`.
     */
    public function key(): string
    {
        return 'boolean';
    }

    /**
     * What the type is called for a person ("Yes / no"), in the language of the request.
     */
    public function label(): string
    {
        return __('Yes / no');
    }

    /**
     * How the list sorts and filters the values (a yes or no).
     */
    public function queryKind(): QueryKind
    {
        return QueryKind::Boolean;
    }

    /**
     * A boolean, or 1 or 0.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['boolean'];
    }

    /**
     * Always a real boolean, whatever way it was sent.
     */
    public function normalize(mixed $value, InventoryField $field): mixed
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Yes or no. `false` is a value: only a missing one is nothing.
     *
     * @param  array<string, string>  $fileNames
     */
    public function exportValue(mixed $stored, InventoryField $field, array $fileNames = []): ExportValue
    {
        return is_bool($stored) ? ExportValue::boolean($stored) : ExportValue::empty();
    }

    /**
     * Whether a text says yes (`true`) or no (`false`), or neither (null): `yes`, `no`, `oui`, `non`, `true`, `false`, `vrai`, `faux`,
     * and the words of the language of the request, whatever the case and the accents; with `$plainWords` false also `1`, `0`, `y`, `n`,
     * `o`, `x`, `✓` and `✔`, which a person writes in a column of yes and no but which are no proof that a column is one.
     */
    public static function answerOf(string $text, bool $plainWords = false): ?bool
    {
        $word = SearchText::normalize($text);
        $yes = ['yes', 'oui', 'true', 'vrai', SearchText::normalize(__('Yes'))];
        $no = ['no', 'non', 'false', 'faux', SearchText::normalize(__('No'))];

        if (! $plainWords) {
            $yes = [...$yes, '1', 'y', 'o', 'x', '✓', '✔'];
            $no = [...$no, '0', 'n'];
        }

        return match (true) {
            in_array($word, $yes, true) => true,
            in_array($word, $no, true) => false,
            default => null,
        };
    }

    /**
     * The answer of a yes/no cell: a yes/no cell, 1 or 0, or the words of {@see self::answerOf()}.
     */
    public function importValue(ImportCell $cell, InventoryField $field, ImportContext $context): mixed
    {
        if ($cell->isEmpty()) {
            return null;
        }

        $value = $cell->value;

        $answer = match (true) {
            is_bool($value) => $value,
            is_int($value) || is_float($value) => $value == 1 ? true : ($value == 0 ? false : null),
            is_string($value) => self::answerOf($value),
            default => null,
        };

        return $answer ?? throw new InvalidImportValue(__('":value" is not a yes or no.', ['value' => $cell->excerpt()]));
    }
}
