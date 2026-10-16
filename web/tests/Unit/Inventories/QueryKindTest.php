<?php

declare(strict_types=1);

use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\QueryKind;

describe('how the list sorts and filters each type of field', function (): void {
    it('knows the kind of every type', function (): void {
        $expected = [
            'text' => QueryKind::Text,
            'textarea' => QueryKind::Text,
            'url' => QueryKind::Text,
            'email' => QueryKind::Text,
            'phone' => QueryKind::Text,
            'barcode' => QueryKind::Text,
            'color' => QueryKind::Text,
            'number' => QueryKind::Number,
            'money' => QueryKind::Number,
            'percentage' => QueryKind::Number,
            'rating' => QueryKind::Number,
            'date' => QueryKind::Temporal,
            'datetime' => QueryKind::Temporal,
            'time' => QueryKind::Temporal,
            'boolean' => QueryKind::Boolean,
            'select' => QueryKind::Choice,
            'multiselect' => QueryKind::Choices,
            'tags' => QueryKind::Tags,
            'file' => QueryKind::Files,
            'photo' => QueryKind::Files,
        ];
        $kinds = [];

        foreach (FieldTypes::standard()->all() as $type) {
            $kinds[$type->key()] = $type->queryKind();
        }

        expect($kinds)->toEqual($expected);
    });

    it('adds up the numbers and the amounts, and nothing else', function (): void {
        $summable = [];

        foreach (FieldTypes::standard()->all() as $type) {
            if ($type->summable()) {
                $summable[] = $type->key();
            }
        }

        expect($summable)->toBe(['number', 'money']);
    });

    it('cannot sort what holds several values, or files', function (): void {
        $sortable = array_map(fn (QueryKind $kind): bool => $kind->sortable(), QueryKind::cases());

        expect(array_combine(array_column(QueryKind::cases(), 'value'), $sortable))->toBe([
            'text' => true,
            'number' => true,
            'temporal' => true,
            'boolean' => true,
            'choice' => true,
            'choices' => false,
            'tags' => false,
            'files' => false,
        ]);
    });
});
