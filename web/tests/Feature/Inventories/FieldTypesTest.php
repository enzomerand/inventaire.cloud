<?php

declare(strict_types=1);

use App\Inventories\Fields\BarcodeType;
use App\Inventories\Fields\Currencies;
use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\UnknownFieldTypeException;
use App\Models\InventoryField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/*
 * Every field type, in memory (no database): what it accepts, what it refuses, the canonical form it stores, and the
 * options it takes. The pipeline of a write is run here the way App\Inventories\ItemValues runs it: prepare, is it
 * empty, the rules, normalize.
 */

/**
 * A field of a type, in memory, with the options a person would send (normalized by the type, defaults included).
 *
 * @param  array<string, mixed>  $options
 */
function typedFieldOf(string $type, array $options = []): InventoryField
{
    return new InventoryField([
        'name' => 'Field',
        'type' => $type,
        'options' => app(FieldTypes::class)->get($type)->normalizeOptions($options, null),
    ]);
}

/**
 * What writing a value to a field comes to: `['empty']` when it clears the field, `['stored', value]` with the canonical
 * value, or `['refused', messages]`.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: string, 1?: mixed}
 */
function writtenToField(string $type, mixed $value, array $options = []): array
{
    $field = typedFieldOf($type, $options);
    $handler = app(FieldTypes::class)->get($type);
    $prepared = $handler->prepare($value, $field);

    if ($handler->isEmpty($prepared)) {
        return ['empty'];
    }

    $validator = Validator::make(['value' => $prepared], ['value' => $handler->valueRules($field)], [], ['value' => 'Price']);

    return $validator->fails()
        ? ['refused', $validator->errors()->get('value')]
        : ['stored', $handler->normalize($prepared, $field)];
}

describe('the registry', function (): void {
    it('lists the eighteen types, in the order they are offered', function (): void {
        expect(app(FieldTypes::class)->keys())->toBe([
            'text', 'textarea', 'number', 'money', 'percentage', 'boolean', 'date', 'datetime', 'time',
            'select', 'multiselect', 'tags', 'url', 'email', 'phone', 'rating', 'color', 'barcode',
        ]);
    });

    it('is one object for the whole process', function (): void {
        expect(app(FieldTypes::class))->toBe(app(FieldTypes::class));
    });

    it('gives each type its own key and a label in the language of the request', function (): void {
        $types = app(FieldTypes::class);

        foreach ($types->all() as $type) {
            expect($types->get($type->key()))->toBe($type)
                ->and($type->label())->not->toBe('');
        }

        $this->useLanguage('en');
        expect($types->get('textarea')->label())->toBe('Long text');

        $this->useLanguage('fr');
        expect($types->get('textarea')->label())->toBe('Texte long');
    });

    it('answers for a key it does not know', function (): void {
        $types = app(FieldTypes::class);

        expect($types->find('text'))->toBeInstanceOf(FieldType::class)
            ->and($types->find('relation'))->toBeNull()
            ->and($types->of(new InventoryField(['type' => 'relation'])))->toBeNull()
            ->and($types->of(new InventoryField(['type' => 'text'])))->toBeInstanceOf(FieldType::class);

        expect(fn () => $types->get('relation'))->toThrow(UnknownFieldTypeException::class, 'There is no field type "relation".');
    });

    it('lets a type clear its field with an empty value, but not with false or zero', function (): void {
        $types = app(FieldTypes::class);
        $field = new InventoryField(['type' => 'text']);

        foreach ($types->all() as $type) {
            expect($type->isEmpty(null))->toBeTrue()
                ->and($type->isEmpty(''))->toBeTrue()
                ->and($type->isEmpty([]))->toBeTrue()
                ->and($type->isEmpty(false))->toBeFalse()
                ->and($type->isEmpty(0))->toBeFalse()
                ->and($type->isEmpty('0'))->toBeFalse();
        }

        expect($types->get('text')->prepare('   ', $field))->toBe('');
    });
});

describe('a value that is accepted', function (): void {
    it('is stored in its canonical form', function (string $type, array $options, mixed $sent, mixed $stored): void {
        expect(writtenToField($type, $sent, $options))->toBe(['stored', $stored]);
    })->with([
        // text
        'text: as typed' => ['text', [], 'Hello', 'Hello'],
        'text: trimmed' => ['text', [], "  padded \t", 'padded'],
        'text: every script, emoji included' => ['text', [], 'é à ü 日本語 😀', 'é à ü 日本語 😀'],
        'text: at its limit' => ['text', ['max_length' => 5], 'abcde', 'abcde'],
        'text: 255 characters by default' => ['text', [], str_repeat('é', 255), str_repeat('é', 255)],
        'text: 1000 characters when the field allows it' => ['text', ['max_length' => 1000], str_repeat('a', 1000), str_repeat('a', 1000)],
        'textarea: line breaks of every system become \n' => ['textarea', [], "one\r\ntwo\rthree\nfour", "one\ntwo\nthree\nfour"],
        'textarea: tabs are kept' => ['textarea', [], "a\tb", "a\tb"],
        'textarea: trimmed' => ['textarea', [], "\n\n  text \n", 'text'],
        'textarea: 10000 characters' => ['textarea', [], str_repeat('x', 10000), str_repeat('x', 10000)],
        // numbers
        'number: an integer' => ['number', [], 12, '12'],
        'number: a numeric string' => ['number', [], '12', '12'],
        'number: a float that is whole' => ['number', [], 12.0, '12'],
        'number: negative' => ['number', [], '-5', '-5'],
        'number: negative zero is zero' => ['number', [], '-0', '0'],
        'number: leading zeros are dropped' => ['number', [], '007', '7'],
        'number: zero is a value' => ['number', [], 0, '0'],
        'number: the largest' => ['number', [], '999999999999999', '999999999999999'],
        'number: padded to its decimals' => ['number', ['decimals' => 2], 12.5, '12.50'],
        'number: whole padded to its decimals' => ['number', ['decimals' => 2], '12', '12.00'],
        'number: extra zeros are no decimals' => ['number', ['decimals' => 2], '12.500', '12.50'],
        'number: six decimals' => ['number', ['decimals' => 6], '0.000001', '0.000001'],
        'number: float noise that fits' => ['number', ['decimals' => 1], 0.1, '0.1'],
        'number: a float that PHP writes with an exponent, shifted back exactly' => ['number', ['decimals' => 6], 1.0e-5, '0.000010'],
        'number: a negative one' => ['number', ['decimals' => 6], -2.0e-6, '-0.000002'],
        'number: a float with an exponent and two digits' => ['number', ['decimals' => 6], 1.2e-5, '0.000012'],
        'number: at its bounds' => ['number', ['decimals' => 1, 'min' => '0', 'max' => 10], '10', '10.0'],
        'number: a negative minimum' => ['number', ['min' => '-5'], '-5', '-5'],
        'money: the euro has two decimals' => ['money', [], '12.5', '12.50'],
        'money: an integer' => ['money', [], 12, '12.00'],
        'money: the yen has none' => ['money', ['currency' => 'JPY'], '1500', '1500'],
        'money: the Kuwaiti dinar has three' => ['money', ['currency' => 'KWD'], '1.5', '1.500'],
        'money: negative' => ['money', [], '-0.5', '-0.50'],
        'percentage: two decimals by default' => ['percentage', [], '12.5', '12.50'],
        'percentage: over a hundred' => ['percentage', [], 150, '150.00'],
        'percentage: four decimals' => ['percentage', ['decimals' => 4], '0.0001', '0.0001'],
        'percentage: none' => ['percentage', ['decimals' => 0], 7, '7'],
        // yes / no
        'boolean: true' => ['boolean', [], true, true],
        'boolean: false is a value' => ['boolean', [], false, false],
        'boolean: 1' => ['boolean', [], 1, true],
        'boolean: 0' => ['boolean', [], 0, false],
        'boolean: "1"' => ['boolean', [], '1', true],
        'boolean: "0"' => ['boolean', [], '0', false],
        // dates
        'date: a day' => ['date', [], '2026-10-15', '2026-10-15'],
        'date: the first year' => ['date', [], '1000-01-01', '1000-01-01'],
        'date: the last year' => ['date', [], '9999-12-31', '9999-12-31'],
        'date: a leap day' => ['date', [], '2028-02-29', '2028-02-29'],
        'datetime: an offset becomes UTC' => ['datetime', [], '2026-10-15T14:30:00+02:00', '2026-10-15T12:30:00Z'],
        'datetime: UTC stays' => ['datetime', [], '2026-10-15T12:30:00Z', '2026-10-15T12:30:00Z'],
        'datetime: seconds' => ['datetime', [], '2026-10-15T12:30:45Z', '2026-10-15T12:30:45Z'],
        'datetime: no seconds' => ['datetime', [], '2026-10-15T12:30Z', '2026-10-15T12:30:00Z'],
        'datetime: an offset without a colon' => ['datetime', [], '2026-10-15T23:30:00-0500', '2026-10-16T04:30:00Z'],
        'datetime: the day changes with the offset' => ['datetime', [], '2026-12-31T23:59:59-14:00', '2027-01-01T13:59:59Z'],
        'datetime: a leap day' => ['datetime', [], '2028-02-29T00:00:00+01:00', '2028-02-28T23:00:00Z'],
        'time: midnight' => ['time', [], '00:00', '00:00'],
        'time: the last minute' => ['time', [], '23:59', '23:59'],
        'time: a morning' => ['time', [], '09:05', '09:05'],
        // links, contacts
        'url: https' => ['url', [], 'https://example.com/path?x=1#top', 'https://example.com/path?x=1#top'],
        'url: http' => ['url', [], 'http://example.com', 'http://example.com'],
        'url: capitals' => ['url', [], 'HTTPS://EXAMPLE.COM', 'HTTPS://EXAMPLE.COM'],
        'url: accents in the path' => ['url', [], 'https://exemple.fr/café', 'https://exemple.fr/café'],
        'url: trimmed' => ['url', [], ' https://example.com ', 'https://example.com'],
        'email: as written' => ['email', [], '  Name@Example.COM ', 'Name@Example.COM'],
        'email: a plus tag' => ['email', [], 'a+b@example.co.uk', 'a+b@example.co.uk'],
        'phone: international' => ['phone', [], '+33 1 23 45 67 89', '+33 1 23 45 67 89'],
        'phone: punctuation' => ['phone', [], '(01) 23.45-67', '(01) 23.45-67'],
        'phone: three digits' => ['phone', [], '112', '112'],
        // others
        'rating: the lowest' => ['rating', [], 1, 1],
        'rating: the highest of five' => ['rating', [], 5, 5],
        'rating: a numeric string' => ['rating', [], '3', 3],
        'rating: the highest of ten' => ['rating', ['max' => 10], 10, 10],
        'color: lowercase' => ['color', [], '#1a2b3c', '#1a2b3c'],
        'color: capitals become lowercase' => ['color', [], '#AABBCC', '#aabbcc'],
        'tags: trimmed, empty ones dropped' => ['tags', [], [' red ', '', '   ', 'blue'], ['red', 'blue']],
        'tags: repeats in other capitals dropped, the first stays' => ['tags', [], ['Red', 'red', ' RED ', 'Blue'], ['Red', 'Blue']],
        'tags: thirty' => ['tags', [], array_map(fn (int $number): string => "tag {$number}", range(1, 30)), array_map(fn (int $number): string => "tag {$number}", range(1, 30))],
        'tags: fifty characters' => ['tags', [], [str_repeat('é', 50)], [str_repeat('é', 50)]],
        'tags: an object becomes a list' => ['tags', [], ['a' => 'x', 'b' => 'y'], ['x', 'y']],
        'barcode: any code' => ['barcode', [], 'ABC-123.x', 'ABC-123.x'],
        'barcode: 64 characters of any' => ['barcode', [], str_repeat('a', 64), str_repeat('a', 64)],
        'barcode: an EAN-13' => ['barcode', ['format' => 'ean13'], '4006381333931', '4006381333931'],
        'barcode: an EAN-8' => ['barcode', ['format' => 'ean8'], '96385074', '96385074'],
        'barcode: a UPC-A' => ['barcode', ['format' => 'upca'], '036000291452', '036000291452'],
        'barcode: an ISBN-13 loses its hyphens' => ['barcode', ['format' => 'isbn13'], '978-0-306-40615-7', '9780306406157'],
        'barcode: an ISBN-10 loses its hyphens' => ['barcode', ['format' => 'isbn10'], '0-306-40615-2', '0306406152'],
        'barcode: an ISBN-10 ending in X' => ['barcode', ['format' => 'isbn10'], '080442957X', '080442957X'],
        'barcode: a lowercase x becomes a capital' => ['barcode', ['format' => 'isbn10'], '080442957x', '080442957X'],
        'barcode: an ISBN-13 from the 979 range' => ['barcode', ['format' => 'isbn13'], '9791032305690', '9791032305690'],
    ]);

    it('clears the field when it is empty', function (string $type, mixed $sent): void {
        expect(writtenToField($type, $sent))->toBe(['empty']);
    })->with(function (): array {
        $cases = [];

        foreach (['text', 'textarea', 'number', 'money', 'percentage', 'date', 'datetime', 'time', 'url', 'email', 'phone', 'rating', 'color', 'barcode', 'tags'] as $type) {
            $cases["{$type}: null"] = [$type, null];
            $cases["{$type}: an empty string"] = [$type, ''];
            $cases["{$type}: spaces"] = [$type, "  \t "];
        }

        $cases['tags: an empty list'] = ['tags', []];
        $cases['tags: only blank tags'] = ['tags', ['', ' ']];
        $cases['text: a null character, which is trimmed away'] = ['text', "\0"];
        $cases['boolean: null'] = ['boolean', null];
        $cases['boolean: an empty string'] = ['boolean', ''];

        return $cases;
    });
});

describe('a value that is refused', function (): void {
    it('does not become a value', function (string $type, array $options, mixed $sent): void {
        $written = writtenToField($type, $sent, $options);

        expect($written[0])->toBe('refused')
            ->and($written[1])->not->toBe([]);
    })->with([
        'text: too long' => ['text', [], str_repeat('a', 256)],
        'text: longer than its field allows' => ['text', ['max_length' => 5], 'abcdef'],
        'text: a line break' => ['text', [], "a\nb"],
        'text: a tab inside' => ['text', [], "a\tb"],
        'text: a text direction control' => ['text', [], "a\u{202E}b"],
        'text: a null character, which PostgreSQL cannot read back' => ['text', [], "a\0b"],
        'text: not valid UTF-8' => ['text', [], "bad \xB1 text"],
        'text: a number' => ['text', [], 12],
        'text: a boolean' => ['text', [], true],
        'text: a list' => ['text', [], ['a']],
        'textarea: a control character' => ['textarea', [], "a\x07b"],
        'textarea: a null character' => ['textarea', [], "a\0b"],
        'textarea: a line separator' => ['textarea', [], "a\u{2028}b"],
        'textarea: a text direction control' => ['textarea', [], "a\u{202E}b"],
        'textarea: too long' => ['textarea', [], str_repeat('x', 10001)],
        'textarea: a number' => ['textarea', [], 12],
        'number: decimals the field does not have' => ['number', [], '12.5'],
        'number: more decimals than the field has' => ['number', ['decimals' => 2], '12.345'],
        'number: a float that is not exact' => ['number', ['decimals' => 2], 0.1 + 0.2],
        'number: text' => ['number', [], 'abc'],
        'number: an exponent' => ['number', [], '1e3'],
        'number: a comma' => ['number', [], '12,5'],
        'number: a boolean' => ['number', [], true],
        'number: a list' => ['number', [], [1]],
        'number: 1e15' => ['number', [], '1000000000000000'],
        'number: a float of 1e15' => ['number', [], 1e15],
        'number: a float written with an exponent that has more decimals than allowed' => ['number', ['decimals' => 6], 1.5e-7],
        'number: a float written with an exponent that is too large' => ['number', [], 2.5e20],
        'number: a float too far from zero to be read exactly' => ['number', [], 1.0e-100],
        'number: a float too far from zero, large' => ['number', [], 1.0e100],
        'number: below its minimum' => ['number', ['min' => '0'], '-1'],
        'number: above its maximum' => ['number', ['max' => '10'], '10.5'],
        'money: more decimals than the currency' => ['money', [], '12.345'],
        'money: decimals for a currency that has none' => ['money', ['currency' => 'JPY'], '1500.5'],
        'money: text' => ['money', [], 'free'],
        'percentage: more decimals than the field' => ['percentage', [], '12.345'],
        'percentage: decimals when there are none' => ['percentage', ['decimals' => 0], '12.5'],
        'boolean: yes' => ['boolean', [], 'yes'],
        'boolean: true as text' => ['boolean', [], 'true'],
        'boolean: 2' => ['boolean', [], 2],
        'boolean: a list' => ['boolean', [], [true]],
        'date: a day that does not exist' => ['date', [], '2026-02-30'],
        'date: not a leap year' => ['date', [], '2027-02-29'],
        'date: a month that does not exist' => ['date', [], '2026-13-01'],
        'date: two digits for the year' => ['date', [], '26-10-15'],
        'date: no zero padding' => ['date', [], '2026-1-5'],
        'date: before the year 1000' => ['date', [], '0999-12-31'],
        'date: with a time' => ['date', [], '2026-10-15T00:00:00'],
        'date: a number' => ['date', [], 20261015],
        'datetime: without an offset' => ['datetime', [], '2026-10-15T12:30:00'],
        'datetime: a space instead of the T' => ['datetime', [], '2026-10-15 12:30:00Z'],
        'datetime: a day that does not exist' => ['datetime', [], '2026-02-30T00:00:00Z'],
        'datetime: hour 24' => ['datetime', [], '2026-10-15T24:00:00Z'],
        'datetime: minute 60' => ['datetime', [], '2026-10-15T12:60:00Z'],
        'datetime: second 60' => ['datetime', [], '2026-10-15T12:30:60Z'],
        'datetime: an offset that does not exist' => ['datetime', [], '2026-10-15T12:30:00+99:99'],
        'datetime: before the year 1000' => ['datetime', [], '0999-12-31T23:59:59Z'],
        'datetime: before the year 1000 once in UTC' => ['datetime', [], '1000-01-01T00:00:00+05:00'],
        'datetime: after the year 9999 once in UTC' => ['datetime', [], '9999-12-31T23:59:59-01:00'],
        'datetime: a timestamp' => ['datetime', [], 1760531400],
        'datetime: a fraction of a second, as a browser writes it' => ['datetime', [], '2026-10-15T12:30:45.123Z'],
        'datetime: a fraction of a second with an offset' => ['datetime', [], '2026-10-15T12:30:45.5+02:00'],
        'datetime: a fraction of zeros' => ['datetime', [], '2026-10-15T12:30:45.000Z'],
        'time: 24:00' => ['time', [], '24:00'],
        'time: minute 60' => ['time', [], '12:60'],
        'time: one digit for the hour' => ['time', [], '9:05'],
        'time: with seconds' => ['time', [], '12:30:00'],
        'time: no colon' => ['time', [], '1230'],
        'url: another scheme' => ['url', [], 'ftp://example.com'],
        'url: a script' => ['url', [], 'javascript:alert(1)'],
        'url: no scheme' => ['url', [], 'example.com'],
        'url: no host' => ['url', [], 'https://'],
        'url: a space in the host' => ['url', [], 'https://exa mple.com'],
        'url: a null character' => ['url', [], "https://example.com/a\0b"],
        'url: too long' => ['url', [], 'https://example.com/'.str_repeat('a', 2040)],
        'email: no domain' => ['email', [], 'name@'],
        'email: no at sign' => ['email', [], 'plain'],
        'email: a null character' => ['email', [], "na\0me@example.com"],
        'email: too long' => ['email', [], str_repeat('a', 250).'@b.co'],
        'phone: two digits' => ['phone', [], '12'],
        'phone: letters' => ['phone', [], 'call me'],
        'phone: a plus in the middle' => ['phone', [], '33 +1 23 45'],
        'phone: a line break' => ['phone', [], "123\n456"],
        'phone: a null character' => ['phone', [], "123\0456"],
        'phone: too long' => ['phone', [], str_repeat('1', 33)],
        'rating: zero' => ['rating', [], 0],
        'rating: above the maximum' => ['rating', [], 6],
        'rating: above the maximum of the field' => ['rating', ['max' => 3], 4],
        'rating: negative' => ['rating', [], -1],
        'rating: a fraction' => ['rating', [], 2.5],
        'rating: text' => ['rating', [], 'good'],
        'rating: a boolean' => ['rating', [], true],
        'color: three digits' => ['color', [], '#abc'],
        'color: no hash' => ['color', [], 'aabbcc'],
        'color: not hexadecimal' => ['color', [], '#gggggg'],
        'color: a null character' => ['color', [], "#aabb\0c"],
        'tags: thirty-one' => ['tags', [], array_map(fn (int $number): string => "tag {$number}", range(1, 31))],
        'tags: a tag of 51 characters' => ['tags', [], [str_repeat('a', 51)]],
        'tags: a line break in a tag' => ['tags', [], ["a\nb"]],
        'tags: a null character in a tag' => ['tags', [], ["a\0b"]],
        'tags: a number' => ['tags', [], ['a', 1]],
        'tags: text instead of a list' => ['tags', [], 'red'],
        'barcode: a space in any' => ['barcode', [], 'has space'],
        'barcode: a null character' => ['barcode', [], "ab\0cd"],
        'barcode: 65 characters of any' => ['barcode', [], str_repeat('a', 65)],
        'barcode: a wrong EAN-13 check digit' => ['barcode', ['format' => 'ean13'], '4006381333932'],
        'barcode: twelve digits for an EAN-13' => ['barcode', ['format' => 'ean13'], '400638133393'],
        'barcode: letters in an EAN-13' => ['barcode', ['format' => 'ean13'], '400638133393A'],
        'barcode: a wrong EAN-8 check digit' => ['barcode', ['format' => 'ean8'], '96385075'],
        'barcode: a wrong UPC-A check digit' => ['barcode', ['format' => 'upca'], '036000291453'],
        'barcode: an ISBN-13 that starts with 977' => ['barcode', ['format' => 'isbn13'], '9770306406153'],
        'barcode: a wrong ISBN-13 check digit' => ['barcode', ['format' => 'isbn13'], '9780306406158'],
        'barcode: a wrong ISBN-10 check digit' => ['barcode', ['format' => 'isbn10'], '0306406153'],
        'barcode: an X in the middle of an ISBN-10' => ['barcode', ['format' => 'isbn10'], '03064X6152'],
        'barcode: a number' => ['barcode', [], 12345],
    ]);

    it('says what is wrong, naming the field', function (): void {
        $this->useLanguage('en');

        expect(writtenToField('number', '12.345', ['decimals' => 2]))->toBe(['refused', ['The Price must have at most 2 decimals.']])
            ->and(writtenToField('number', '12.5'))->toBe(['refused', ['The Price must be a whole number.']])
            ->and(writtenToField('number', 'abc'))->toBe(['refused', ['The Price must be a number, written with a dot.']])
            ->and(writtenToField('number', '-1', ['min' => '0']))->toBe(['refused', ['The Price must be at least 0.']])
            ->and(writtenToField('number', '11', ['max' => '10.5']))->toBe(['refused', ['The Price must be at most 10.5.']])
            ->and(writtenToField('datetime', '2026-10-15T12:30:45.123Z'))->toBe(['refused', ['The Price must be a date and time to the second with its offset from UTC, such as 2026-10-15T14:30:00+02:00.']])
            ->and(writtenToField('number', 1.0e-100))->toBe(['refused', ['The Price is a number that cannot be read exactly: send it as text, written with a dot.']])
            ->and(writtenToField('number', 1.0e100))->toBe(['refused', ['The Price is a number that cannot be read exactly: send it as text, written with a dot.']])
            ->and(writtenToField('number', 2.5e20))->toBe(['refused', ['The Price is too large.']])
            ->and(writtenToField('barcode', '4006381333932', ['format' => 'ean13']))->toBe(['refused', ['The Price must be a valid EAN-13 code: 13 digits, the last one a check digit.']]);

        $this->useLanguage('fr');

        expect(writtenToField('number', '12.5'))->toBe(['refused', ['Le champ Price doit être un nombre entier.']]);
    });
});

describe('the options of a type', function (): void {
    /**
     * What a person sending these options to a type gets: the canonical options, or the keys that were refused.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: mixed}
     */
    function optionsSentTo(string $type, array $options, ?array $previous = null): array
    {
        $handler = app(FieldTypes::class)->get($type);
        $rules = [];

        foreach ($handler->optionRules() as $name => $nameRules) {
            $rules["options.{$name}"] = $nameRules;
        }

        foreach (array_diff(array_keys($options), array_keys($handler->optionRules())) as $unknown) {
            return ['refused', ["options.{$unknown}"]];
        }

        $validator = Validator::make(['options' => $options], $rules);

        if ($validator->fails()) {
            return ['refused', array_keys($validator->errors()->messages())];
        }

        try {
            return ['normalized', $handler->normalizeOptions($options, $previous)];
        } catch (ValidationException $exception) {
            return ['refused', array_keys($exception->errors())];
        }
    }

    it('are filled with their defaults and put in canonical form', function (string $type, array $sent, array $normalized): void {
        expect(optionsSentTo($type, $sent))->toBe(['normalized', $normalized]);
    })->with([
        'text: 255 characters' => ['text', [], ['max_length' => 255]],
        'text: as many as asked' => ['text', ['max_length' => '100'], ['max_length' => 100]],
        'text: the limit' => ['text', ['max_length' => 1000], ['max_length' => 1000]],
        'number: whole by default' => ['number', [], ['decimals' => 0]],
        'number: every option' => ['number', ['decimals' => 2, 'min' => '0.50', 'max' => 100, 'unit' => ' kg '], ['decimals' => 2, 'min' => '0.5', 'max' => '100', 'unit' => 'kg']],
        'number: a bound that is null is no bound' => ['number', ['min' => null, 'max' => null, 'unit' => null], ['decimals' => 0]],
        'number: a unit made of spaces is no unit' => ['number', ['unit' => '   '], ['decimals' => 0]],
        'number: equal bounds' => ['number', ['min' => '5', 'max' => '5.0'], ['decimals' => 0, 'min' => '5', 'max' => '5']],
        'number: a bound with six decimals' => ['number', ['decimals' => 6, 'min' => '0.000001'], ['decimals' => 6, 'min' => '0.000001']],
        'money: the euro, with its decimals' => ['money', [], ['currency' => 'EUR', 'decimals' => 2]],
        'money: another currency' => ['money', ['currency' => 'USD'], ['currency' => 'USD', 'decimals' => 2]],
        'money: a currency without decimals' => ['money', ['currency' => 'JPY'], ['currency' => 'JPY', 'decimals' => 0]],
        'money: a currency with three' => ['money', ['currency' => 'KWD'], ['currency' => 'KWD', 'decimals' => 3]],
        'percentage: two decimals' => ['percentage', [], ['decimals' => 2]],
        'percentage: none' => ['percentage', ['decimals' => 0], ['decimals' => 0]],
        'rating: five' => ['rating', [], ['max' => 5]],
        'rating: ten' => ['rating', ['max' => 10], ['max' => 10]],
        'barcode: any' => ['barcode', [], ['format' => 'any']],
        'barcode: an ISBN-13' => ['barcode', ['format' => 'isbn13'], ['format' => 'isbn13']],
        'a type without options' => ['date', [], []],
    ]);

    it('are refused when they do not follow the rules of the type', function (string $type, array $sent, string $key): void {
        expect(optionsSentTo($type, $sent))->toBe(['refused', [$key]]);
    })->with([
        'text: zero characters' => ['text', ['max_length' => 0], 'options.max_length'],
        'text: more than a thousand' => ['text', ['max_length' => 1001], 'options.max_length'],
        'text: not a number' => ['text', ['max_length' => 'long'], 'options.max_length'],
        'text: a fraction' => ['text', ['max_length' => 1.5], 'options.max_length'],
        'text: an option it does not have' => ['text', ['decimals' => 2], 'options.decimals'],
        'number: seven decimals' => ['number', ['decimals' => 7], 'options.decimals'],
        'number: negative decimals' => ['number', ['decimals' => -1], 'options.decimals'],
        'number: a minimum that is text' => ['number', ['min' => 'abc'], 'options.min'],
        'number: a maximum that is too large' => ['number', ['max' => '1000000000000000'], 'options.max'],
        'number: a unit that is too long' => ['number', ['unit' => str_repeat('k', 17)], 'options.unit'],
        'number: a unit on two lines' => ['number', ['unit' => "k\ng"], 'options.unit'],
        'number: a minimum above the maximum' => ['number', ['min' => '10', 'max' => '9'], 'options.max'],
        'money: a name instead of a code' => ['money', ['currency' => 'euro'], 'options.currency'],
        'money: a code in lowercase' => ['money', ['currency' => 'eur'], 'options.currency'],
        'money: a code that does not exist' => ['money', ['currency' => 'ZZZ'], 'options.currency'],
        'money: the code for no currency' => ['money', ['currency' => 'XXX'], 'options.currency'],
        'percentage: five decimals' => ['percentage', ['decimals' => 5], 'options.decimals'],
        'rating: two' => ['rating', ['max' => 2], 'options.max'],
        'rating: eleven' => ['rating', ['max' => 11], 'options.max'],
        'barcode: a format that does not exist' => ['barcode', ['format' => 'ean14'], 'options.format'],
        'a type without options takes none' => ['date', ['format' => 'x'], 'options.format'],
    ]);

    it('are given as a merge of what is sent over what the field has, and judged together', function (): void {
        $types = app(FieldTypes::class);
        $number = $types->get('number');

        // The caller (InventoryFields) merges before normalizing; the minimum kept from before is checked against the new maximum.
        $merged = array_replace(['decimals' => 0, 'min' => '10'], ['max' => '5']);

        expect(fn () => $number->normalizeOptions($merged, ['decimals' => 0, 'min' => '10']))->toThrow(ValidationException::class);
    });
});

describe('the choices of a select', function (): void {
    /**
     * The choices of a select field built from the labels given.
     *
     * @param  list<string>  $labels
     * @return array<string, string> Id by label.
     */
    function idsOfChoices(array $labels, ?string $type = 'select'): array
    {
        $field = typedFieldOf($type, ['choices' => array_map(fn (string $label): array => ['label' => $label], $labels)]);

        return array_column($field->options['choices'], 'id', 'label');
    }

    it('get an id of 8 lowercase letters and digits, chosen by the server', function (): void {
        $ids = idsOfChoices(['Red', 'Green', 'Blue']);

        expect($ids)->toHaveCount(3);

        foreach ($ids as $id) {
            expect($id)->toMatch('/^[a-z0-9]{8}$/');
        }

        expect(array_unique($ids))->toHaveCount(3);
    });

    it('keep the id of a choice the field already had, and give new ones to the others', function (): void {
        $select = app(FieldTypes::class)->get('select');
        $before = typedFieldOf('select', ['choices' => [['label' => 'Red', 'color' => 'red'], ['label' => 'Blue']]])->options;
        [$red, $blue] = $before['choices'];

        $after = $select->normalizeOptions(['choices' => [
            ['id' => $blue['id'], 'label' => 'Navy', 'color' => 'blue'],
            ['label' => 'Green'],
            ['id' => $red['id'], 'label' => 'Red'],
        ]], $before)['choices'];

        expect($after[0])->toBe(['id' => $blue['id'], 'label' => 'Navy', 'color' => 'blue'])
            ->and($after[1]['label'])->toBe('Green')
            ->and($after[1]['id'])->toMatch('/^[a-z0-9]{8}$/')->not->toBe($red['id'])->not->toBe($blue['id'])
            ->and($after[2])->toBe(['id' => $red['id'], 'label' => 'Red']);
    });

    it('never take an id from the client for a choice the field never had', function (): void {
        $select = app(FieldTypes::class)->get('select');
        $before = typedFieldOf('select', ['choices' => [['label' => 'Red']]])->options;

        $after = $select->normalizeOptions(['choices' => [['id' => 'abcd1234', 'label' => 'Red']]], $before)['choices'];

        expect($after[0]['id'])->not->toBe('abcd1234')->and($after[0]['id'])->not->toBe($before['choices'][0]['id']);

        // While the field is being created, no id is known yet.
        $created = $select->normalizeOptions(['choices' => [['id' => 'abcd1234', 'label' => 'Red']]], null)['choices'];

        expect($created[0]['id'])->not->toBe('abcd1234');
    });

    it('never give the same id to two choices', function (): void {
        $select = app(FieldTypes::class)->get('select');
        $before = typedFieldOf('select', ['choices' => [['label' => 'Red']]])->options;
        $id = $before['choices'][0]['id'];

        $after = $select->normalizeOptions(['choices' => [['id' => $id, 'label' => 'Red'], ['id' => $id, 'label' => 'Crimson']]], $before)['choices'];

        expect($after[0]['id'])->toBe($id)->and($after[1]['id'])->not->toBe($id);
    });

    it('are trimmed, and a color is only kept when it is one of the palette', function (): void {
        $choices = typedFieldOf('select', ['choices' => [['label' => '  Red  ', 'color' => 'red'], ['label' => 'None', 'color' => null]]])->options['choices'];

        expect($choices[0])->toMatchArray(['label' => 'Red', 'color' => 'red'])
            ->and($choices[1])->not->toHaveKey('color');
    });

    it('are refused when they do not follow the rules', function (array $options): void {
        $verdict = optionsSentTo('select', $options);

        expect($verdict[0])->toBe('refused');
    })->with([
        'no choices' => [[]],
        'null' => [['choices' => null]],
        'an empty list' => [['choices' => []]],
        'a label that is missing' => [['choices' => [['color' => 'red']]]],
        'a label that is empty' => [['choices' => [['label' => '']]]],
        'a label that is too long' => [['choices' => [['label' => str_repeat('a', 101)]]]],
        'a label on two lines' => [['choices' => [['label' => "a\nb"]]]],
        'two labels that only differ by their case' => [['choices' => [['label' => 'Red'], ['label' => ' red ']]]],
        'two labels that only differ by case folding' => [['choices' => [['label' => 'STRASSE'], ['label' => 'straße']]]],
        'two labels that are one accented letter written two ways' => [['choices' => [['label' => "Caf\u{00E9}"], ['label' => "Cafe\u{0301}"]]]],
        'a color that is not in the palette' => [['choices' => [['label' => 'Red', 'color' => 'chartreuse']]]],
        'an id of the wrong shape' => [['choices' => [['id' => 'ABC', 'label' => 'Red']]]],
        'an object instead of a list' => [['choices' => ['a' => ['label' => 'Red']]]],
        'a choice that is not an object' => [['choices' => ['Red']]],
        'more than two hundred choices' => [['choices' => array_map(fn (int $number): array => ['label' => "Choice {$number}"], range(1, 201))]],
    ]);

    it('are accepted up to two hundred', function (): void {
        $options = ['choices' => array_map(fn (int $number): array => ['label' => "Choice {$number}"], range(1, 200))];

        expect(optionsSentTo('select', $options)[0])->toBe('normalized');
    });

    it('make a select accept the id of one of its choices and no other', function (): void {
        $ids = idsOfChoices(['Red', 'Green']);
        $options = ['choices' => [['label' => 'Red'], ['label' => 'Green']]];
        $field = typedFieldOf('select', $options);
        $id = $field->options['choices'][0]['id'];

        $validator = fn (mixed $value) => Validator::make(['v' => $value], ['v' => app(FieldTypes::class)->get('select')->valueRules($field)])->passes();

        expect($validator($id))->toBeTrue()
            ->and($validator('zzzzzzzz'))->toBeFalse()
            ->and($validator('Red'))->toBeFalse()
            ->and($validator(12))->toBeFalse()
            ->and($validator([$id]))->toBeFalse()
            ->and($ids)->toHaveCount(2);
    });

    it('make a multiple choice accept a list of ids of its choices, each one once', function (): void {
        $field = typedFieldOf('multiselect', ['choices' => [['label' => 'Red'], ['label' => 'Green'], ['label' => 'Blue']]]);
        [$red, $green] = array_column($field->options['choices'], 'id');
        $multiselect = app(FieldTypes::class)->get('multiselect');
        $written = function (mixed $sent) use ($multiselect, $field): array {
            $prepared = $multiselect->prepare($sent, $field);

            if ($multiselect->isEmpty($prepared)) {
                return ['empty'];
            }

            $validator = Validator::make(['v' => $prepared], ['v' => $multiselect->valueRules($field)]);

            return $validator->fails() ? ['refused'] : ['stored', $multiselect->normalize($prepared, $field)];
        };

        expect($written([$red, $green]))->toBe(['stored', [$red, $green]])
            ->and($written([$green, $red, $green]))->toBe(['stored', [$green, $red]])
            ->and($written([" {$red} "]))->toBe(['stored', [$red]])
            ->and($written([]))->toBe(['empty'])
            ->and($written(['', ' ']))->toBe(['empty'])
            ->and($written([$red, 'zzzzzzzz']))->toBe(['refused'])
            ->and($written([1]))->toBe(['refused'])
            ->and($written($red))->toBe(['refused'])
            ->and($written([[$red]]))->toBe(['refused']);
    });

    it('are dropped from what is read when the choice was deleted from the field', function (): void {
        $select = typedFieldOf('select', ['choices' => [['label' => 'Red'], ['label' => 'Green']]]);
        [$red, $green] = array_column($select->options['choices'], 'id');
        $multiselect = typedFieldOf('multiselect', ['choices' => [['label' => 'Red'], ['label' => 'Green']]]);
        [$mRed, $mGreen] = array_column($multiselect->options['choices'], 'id');

        $types = app(FieldTypes::class);

        expect($types->get('select')->present($red, $select))->toBe($red)
            ->and($types->get('select')->present('gone0000', $select))->toBeNull()
            ->and($types->get('select')->present(12, $select))->toBeNull()
            ->and($types->get('multiselect')->present([$mRed, 'gone0000', $mGreen], $multiselect))->toBe([$mRed, $mGreen])
            ->and($types->get('multiselect')->present(['gone0000'], $multiselect))->toBe([])
            ->and($types->get('multiselect')->present('not a list', $multiselect))->toBe([])
            ->and($types->get('text')->present('as stored', new InventoryField(['type' => 'text'])))->toBe('as stored');
    });
});

describe('the barcode check digits', function (): void {
    it('accept the codes of the standards', function (string $format, string $code): void {
        expect(BarcodeType::isValid($code, $format))->toBeTrue();
    })->with([
        ['ean13', '4006381333931'],
        ['ean13', '5901234123457'],
        ['ean13', '0012345678905'],
        ['ean8', '96385074'],
        ['ean8', '73513537'],
        ['upca', '036000291452'],
        ['upca', '012345678905'],
        ['isbn13', '9780306406157'],
        ['isbn10', '0306406152'],
        ['isbn10', '080442957X'],
    ]);

    it('refuse a code whose check digit is any of the nine others', function (): void {
        $valid = '4006381333931';

        foreach (range(0, 9) as $digit) {
            $code = substr($valid, 0, 12).$digit;

            expect(BarcodeType::isValid($code, 'ean13'))->toBe($code === $valid);
        }
    });
});

describe('the currencies a field can be given', function (): void {
    it('are the ones in use somewhere today, not the ones ICU only remembers', function (string $code, bool $inUse): void {
        expect(Currencies::exists($code))->toBe($inUse);
    })->with([
        'the euro' => ['EUR', true],
        'the dollar' => ['USD', true],
        'the yen' => ['JPY', true],
        'the Kuwaiti dinar' => ['KWD', true],
        'the Swiss franc' => ['CHF', true],
        'the CFA franc' => ['XOF', true],
        'the Andorran peseta' => ['ADP', false],
        'the French franc' => ['FRF', false],
        'the old Afghani' => ['AFA', false],
        'the Spanish peseta' => ['ESP', false],
        'the no-currency code' => ['XXX', false],
        'the test code' => ['XTS', false],
        'gold' => ['XAU', false],
        'special drawing rights' => ['XDR', false],
        'a funds code' => ['USN', false],
        'a unit of account' => ['CLF', false],
        'a code that does not exist' => ['ZZZ', false],
        'a code in lowercase' => ['eur', false],
        'nothing' => ['', false],
    ]);

    it('still have their decimals when they are no longer in use, so that an old amount is written as it always was', function (): void {
        expect(Currencies::fractionDigits('ADP'))->toBe(0)
            ->and(Currencies::fractionDigits('EUR'))->toBe(2)
            ->and(Currencies::fractionDigits('KWD'))->toBe(3);
    });

    it('are every currency in use and only those, once each, in a picker', function (): void {
        $codes = array_column(Currencies::named('en'), 'code');

        expect($codes)->toContain('EUR', 'USD', 'JPY')
            ->not->toContain('XXX', 'XTS', 'ADP', 'FRF', 'AFA', 'XAU')
            ->and(array_filter($codes, fn (string $code): bool => ! Currencies::exists($code)))->toBe([])
            ->and(count($codes))->toBe(count(array_unique($codes)))
            ->and(count($codes))->toBeGreaterThan(100)->toBeLessThan(200);
    });

    it('are named in the language asked for', function (): void {
        $english = array_column(Currencies::named('en'), 'name', 'code');
        $french = array_column(Currencies::named('fr'), 'name', 'code');

        expect($english['EUR'])->toBe('Euro')
            ->and($french['EUR'])->toBe('euro')
            ->and($french['USD'])->toBe('dollar des États-Unis')
            ->and($french['JPY'])->toBe('yen japonais');
    });

    it('are in the order of their names in that language', function (): void {
        $names = array_column(Currencies::named('fr'), 'name');
        $collator = new Collator('fr');
        $collator->setStrength(Collator::SECONDARY);
        $sorted = $names;
        usort($sorted, fn (string $first, string $second): int => $collator->compare($first, $second));

        expect($names)->toBe($sorted);
    });

    it('are listed under their code when ICU has no name for them in a language it does not know', function (): void {
        $named = array_column(Currencies::named('zz'), 'name', 'code');

        expect($named['EUR'])->not->toBe('')
            ->and($named)->toHaveKey('USD');
    });

    it('can be listed with the ones a field already has, if ICU knows them', function (): void {
        $codes = array_column(Currencies::named('en', ['ADP', 'EUR', 'ZZZ']), 'code');

        expect($codes)->toContain('ADP', 'EUR')
            ->not->toContain('ZZZ')
            ->and(count($codes))->toBe(count(array_unique($codes)))
            ->and(array_column(Currencies::named('en', ['ADP']), 'name', 'code')['ADP'])->toBe('Andorran Peseta');
    });

    it('are refused for a new amount field when they are out of use, and accepted for the field that already has one', function (): void {
        $rule = fn (?array $previous, string $code): bool => Validator::make(
            ['currency' => $code],
            ['currency' => app(FieldTypes::class)->get('money')->optionRules($previous)['currency']],
        )->passes();

        expect($rule(null, 'EUR'))->toBeTrue()
            ->and($rule(null, 'USD'))->toBeTrue()
            ->and($rule(null, 'ADP'))->toBeFalse()
            ->and($rule(null, 'FRF'))->toBeFalse()
            ->and($rule(['currency' => 'EUR'], 'ADP'))->toBeFalse()
            ->and($rule(['currency' => 'ADP'], 'ADP'))->toBeTrue()
            ->and($rule(['currency' => 'ADP'], 'FRF'))->toBeFalse()
            ->and($rule(['currency' => 'ADP'], 'JPY'))->toBeTrue();
    });
});

describe('the options that take values away', function (): void {
    it('are the removal of a choice and a lower maximum of a rating, and nothing else', function (string $type, array $before, array $after, bool $expected): void {
        $handler = app(FieldTypes::class)->get($type);

        expect($handler->mayInvalidateValues($handler->normalizeOptions($before, null), $handler->normalizeOptions($after, null)))->toBe($expected);
    })->with([
        'rating: a lower maximum' => ['rating', ['max' => 10], ['max' => 5], true],
        'rating: a lower maximum than the default' => ['rating', [], ['max' => 3], true],
        'rating: the same maximum' => ['rating', ['max' => 5], ['max' => 5], false],
        'rating: a higher maximum' => ['rating', ['max' => 5], ['max' => 10], false],
        'number: fewer decimals' => ['number', ['decimals' => 3], ['decimals' => 0], false],
        'number: tighter bounds' => ['number', ['min' => '0', 'max' => '100'], ['min' => '10', 'max' => '20'], false],
        'money: another currency' => ['money', ['currency' => 'EUR'], ['currency' => 'JPY'], false],
        'percentage: fewer decimals' => ['percentage', ['decimals' => 4], ['decimals' => 0], false],
        'text: a shorter length' => ['text', ['max_length' => 500], ['max_length' => 5], false],
        'barcode: another format' => ['barcode', ['format' => 'any'], ['format' => 'ean13'], false],
        'date: nothing to change' => ['date', [], [], false],
    ]);

    it('are, for the choices, any choice that is gone, whatever else changes', function (string $type): void {
        $handler = app(FieldTypes::class)->get($type);
        $before = $handler->normalizeOptions(['choices' => [['label' => 'A', 'color' => 'red'], ['label' => 'B'], ['label' => 'C']]], null);
        [$a, $b, $c] = $before['choices'];
        $options = fn (array $choices): array => $handler->normalizeOptions(['choices' => $choices], $before);

        expect($handler->mayInvalidateValues($before, $options([$a, $b])))->toBeTrue()
            ->and($handler->mayInvalidateValues($before, $options([$a, $b, $c, ['label' => 'D']])))->toBeFalse()
            ->and($handler->mayInvalidateValues($before, $options([$c, $a, $b])))->toBeFalse()
            ->and($handler->mayInvalidateValues($before, $options([['id' => $a['id'], 'label' => 'Renamed', 'color' => 'blue'], $b, $c])))->toBeFalse()
            // The same label with no id is a new choice: the old one is gone.
            ->and($handler->mayInvalidateValues($before, $options([['label' => 'A'], $b, $c])))->toBeTrue();
    })->with(['select' => 'select', 'multiselect' => 'multiselect']);

    it('make a rating drop the grades above its maximum when it is read', function (): void {
        $rating = typedFieldOf('rating', ['max' => 3]);
        $present = fn (mixed $stored): mixed => app(FieldTypes::class)->get('rating')->present($stored, $rating);

        expect($present(1))->toBe(1)
            ->and($present(3))->toBe(3)
            ->and($present(4))->toBeNull()
            ->and($present(0))->toBeNull()
            ->and($present('3'))->toBeNull()
            ->and($present(null))->toBeNull();
    });
});

describe('the names of choices', function (): void {
    it('are kept in one Unicode form, and compared without the case and the way an accent is written, but with the accents', function (): void {
        $composed = "Caf\u{00E9}";
        $decomposed = "Cafe\u{0301}";
        $choices = typedFieldOf('select', ['choices' => [['label' => $decomposed], ['label' => 'Cafe']]])->options['choices'];

        expect($choices[0]['label'])->toBe($composed)
            ->and($choices[1]['label'])->toBe('Cafe')
            ->and(optionsSentTo('select', ['choices' => [['label' => $composed], ['label' => 'Cafe']]])[0])->toBe('normalized')
            ->and(optionsSentTo('select', ['choices' => [['label' => $composed], ['label' => $decomposed]]])[0])->toBe('refused');
    });
});

describe('the decimals of an amount field', function (): void {
    it('are read from ICU when the field is created, and kept in its options', function (string $currency, int $decimals): void {
        $options = app(FieldTypes::class)->get('money')->normalizeOptions(['currency' => $currency], null);

        expect($options)->toBe(['currency' => $currency, 'decimals' => $decimals]);
    })->with([['EUR', 2], ['JPY', 0], ['KWD', 3], ['USD', 2]]);

    it('stay what the field has stored while its currency stays, whatever ICU says now', function (): void {
        $money = app(FieldTypes::class)->get('money');

        // A field stored with three decimals for the euro (ICU says two): it keeps them.
        $kept = $money->normalizeOptions(['currency' => 'EUR', 'decimals' => 3], ['currency' => 'EUR', 'decimals' => 3]);

        // Nobody can send them: whatever the merged options say, the ones stored are the ones kept.
        $ignored = $money->normalizeOptions(['currency' => 'EUR', 'decimals' => 6], ['currency' => 'EUR', 'decimals' => 3]);

        expect($kept)->toBe(['currency' => 'EUR', 'decimals' => 3])
            ->and($ignored)->toBe(['currency' => 'EUR', 'decimals' => 3]);
    });

    it('are read again from ICU when the currency changes, and when the field has none stored', function (): void {
        $money = app(FieldTypes::class)->get('money');

        expect($money->normalizeOptions(['currency' => 'JPY', 'decimals' => 3], ['currency' => 'EUR', 'decimals' => 3]))->toBe(['currency' => 'JPY', 'decimals' => 0])
            ->and($money->normalizeOptions(['currency' => 'EUR'], ['currency' => 'EUR']))->toBe(['currency' => 'EUR', 'decimals' => 2])
            ->and($money->normalizeOptions([], ['currency' => 'KWD']))->toBe(['currency' => 'EUR', 'decimals' => 2]);
    });

    it('are the ones that judge and write the amounts, not ICU at the time', function (): void {
        $field = new InventoryField(['name' => 'Price', 'type' => 'money', 'options' => ['currency' => 'EUR', 'decimals' => 3]]);
        $money = app(FieldTypes::class)->get('money');
        $written = function (mixed $value) use ($money, $field): array {
            $validator = Validator::make(['value' => $value], ['value' => $money->valueRules($field)]);

            return $validator->fails() ? ['refused'] : ['stored', $money->normalize($value, $field)];
        };

        expect($written('1.234'))->toBe(['stored', '1.234'])
            ->and($written('1.2'))->toBe(['stored', '1.200'])
            ->and($written('1.2345'))->toBe(['refused']);
    });

    it('come from ICU, for a field that was stored without them', function (): void {
        $field = new InventoryField(['name' => 'Price', 'type' => 'money', 'options' => ['currency' => 'JPY']]);
        $money = app(FieldTypes::class)->get('money');

        expect($money->normalize('1500', $field))->toBe('1500')
            ->and(Validator::make(['value' => '1500.5'], ['value' => $money->valueRules($field)])->fails())->toBeTrue()
            ->and($money->normalize('12.5', new InventoryField(['name' => 'Price', 'type' => 'money', 'options' => null])))->toBe('12.50');
    });
});
