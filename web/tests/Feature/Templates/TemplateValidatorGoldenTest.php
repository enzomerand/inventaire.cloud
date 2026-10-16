<?php

declare(strict_types=1);

use App\Inventories\Fields\FieldTypes;
use App\Inventories\Templates\TemplateValidator;

require_once __DIR__.'/../../Support/templates.php';

/*
 * The golden verdicts: a fixed set of template files, valid and not, each with the exact list of problems the validator
 * finds in it, hashed into one digest. The catalogue keeps its verdict on the files in the cache under a key that holds
 * TemplateValidator::VERSION, so anything that makes the validator judge a file differently (a rule added, removed or
 * changed, a limit moved, a message, the rules of the options of a type of field, the way an example is checked) leaves
 * the cached verdicts of the previous version in place until the entry expires, unless the version is bumped.
 *
 * This test fails whenever a verdict comes out different. When it does, that was intended: bump TemplateValidator::VERSION
 * and then update the expectation (version and digest) here, in the same change. The cases are built from a small template
 * of this file, not from the fixtures, so that editing a fixture never changes the digest.
 */

/**
 * A small template that is valid: the base of the cases.
 *
 * @return array<string, mixed>
 */
function goldenTemplate(): array
{
    return [
        'category' => 'home',
        'icon' => 'home',
        'name' => ['fr' => 'Maison', 'en' => 'House'],
        'summary' => ['fr' => 'Les affaires de la maison.', 'en' => 'The things of the house.'],
        'description' => ['fr' => ['Un paragraphe.'], 'en' => ['A paragraph.']],
        'fields' => [
            ['key' => 'name', 'type' => 'text', 'required' => true, 'name' => ['fr' => 'Nom', 'en' => 'Name']],
            ['key' => 'room', 'type' => 'select', 'name' => ['fr' => 'Pièce', 'en' => 'Room'], 'options' => ['choices' => [
                ['key' => 'kitchen', 'label' => ['fr' => 'Cuisine', 'en' => 'Kitchen'], 'color' => 'blue'],
                ['key' => 'garage', 'label' => ['fr' => 'Garage', 'en' => 'Garage']],
            ]]],
            ['key' => 'price', 'type' => 'money', 'name' => ['fr' => 'Prix', 'en' => 'Price'], 'options' => ['currency' => 'EUR']],
        ],
        'examples' => [
            'fr' => [['name' => 'Four', 'room' => 'kitchen', 'price' => '899.00'], ['name' => 'Perceuse', 'room' => 'garage']],
            'en' => [['name' => 'Oven', 'room' => 'kitchen', 'price' => '899.00'], ['name' => 'Drill', 'room' => 'garage']],
        ],
    ];
}

/**
 * The template of the cases with other fields (and examples that fit them, one row in each language to make two).
 *
 * @param  list<array<string, mixed>>  $fields
 * @param  array<string, mixed>  $row
 * @return array<string, mixed>
 */
function goldenWith(array $fields, array $row): array
{
    $data = goldenTemplate();
    $data['fields'] = $fields;
    $data['examples'] = ['fr' => [$row, $row], 'en' => [$row, $row]];

    return $data;
}

/**
 * A field of a type with options, and the value an example row gives it.
 *
 * @return array<string, array{options: array<string, mixed>, value: mixed}>
 */
function goldenTypes(): array
{
    $choices = [['key' => 'one', 'label' => ['fr' => 'Un', 'en' => 'One']], ['key' => 'two', 'label' => ['fr' => 'Deux', 'en' => 'Two']]];

    return [
        'text' => ['options' => ['max_length' => 50], 'value' => 'Hello'],
        'textarea' => ['options' => [], 'value' => 'Hello'],
        'phone' => ['options' => [], 'value' => '+33 1 23 45 67 89'],
        'url' => ['options' => [], 'value' => 'https://example.test/'],
        'email' => ['options' => [], 'value' => 'jane@example.test'],
        'select' => ['options' => ['choices' => $choices], 'value' => 'one'],
        'multiselect' => ['options' => ['choices' => $choices], 'value' => ['one', 'two']],
        'barcode' => ['options' => ['format' => 'ean13'], 'value' => '3017620422003'],
        'tags' => ['options' => [], 'value' => ['a', 'b']],
        'number' => ['options' => ['decimals' => 2, 'min' => '0', 'max' => '100', 'unit' => 'kg'], 'value' => '12.50'],
        'money' => ['options' => ['currency' => 'EUR'], 'value' => '19.90'],
        'percentage' => ['options' => ['decimals' => 1], 'value' => '7.5'],
        'rating' => ['options' => ['max' => 5], 'value' => 4],
        'boolean' => ['options' => [], 'value' => true],
        'date' => ['options' => [], 'value' => '2026-10-15'],
        'datetime' => ['options' => [], 'value' => '2026-10-15T12:30:00Z'],
        'time' => ['options' => [], 'value' => '08:05'],
        'color' => ['options' => [], 'value' => '#aabbcc'],
        'file' => ['options' => [], 'value' => null],
        'photo' => ['options' => [], 'value' => null],
    ];
}

/**
 * Every case, by name: the content of a file and the name it has.
 *
 * @return array<string, array{0: string, 1: mixed}>
 */
function goldenCases(): array
{
    $base = goldenTemplate();
    $cases = ['the base' => ['house', $base]];
    $with = fn (string $path, mixed $value): array => templateWith($base, $path, $value);
    $without = fn (string $path): array => templateWithout($base, $path);

    // The name of the file, and what the file holds.
    foreach (['Capitals' => 'House', 'an underscore' => 'my_house', 'a double hyphen' => 'a--b', 'too long' => str_repeat('a', 61)] as $label => $slug) {
        $cases["slug: {$label}"] = [$slug, $base];
    }

    foreach (['a list' => ['a', 'b'], 'a text' => 'text', 'null' => null, 'an empty object' => []] as $label => $content) {
        $cases["content: {$label}"] = ['house', $content];
    }

    // The top level, the format.
    foreach (['an unknown key' => ['colour', 'red'], 'a schema' => ['$schema', 'https://example.test/schema.json'], 'an x- key' => ['x-notes', ['a']], 'a capital X- key' => ['X-notes', 'a'],
        'format 1' => ['format', 1], 'format 2' => ['format', 2], 'format 0' => ['format', 0], 'format text' => ['format', '1'], 'format null' => ['format', null],
        'category' => ['category', 'garden'], 'icon' => ['icon', 'rocket'], 'category not text' => ['category', 7]] as $label => [$key, $value]) {
        $cases["top: {$label}"] = ['house', $with($key, $value)];
    }

    foreach (['category', 'icon', 'name', 'summary', 'description', 'fields', 'examples'] as $key) {
        $cases["top: no {$key}"] = ['house', $without($key)];
    }

    // The texts.
    $texts = [
        'empty' => '', 'a space around' => ' House', 'two lines' => "House\nhouse", 'too long' => str_repeat('a', 121), 'html' => '<b>House</b>',
        'an entity' => 'House &amp; home', 'a comment' => 'House <!-- x -->', 'bold' => '**House**', 'a link' => '[House](https://example.test)',
        'a heading' => '# House', 'a list item' => '- House', 'a quote' => '> House', 'code' => '`House`', 'not normalized' => "Cafe\u{0301}",
        'a text direction control' => "Ho\u{202E}use", 'not a text' => 7,
    ];

    foreach ($texts as $label => $text) {
        $cases["name: {$label}"] = ['house', $with('name.en', $text)];
        $cases["field name: {$label}"] = ['house', $with('fields.0.name.fr', $text)];
    }

    $cases['name: no french'] = ['house', $without('name.fr')];
    $cases['name: another language'] = ['house', $with('name.xx', 'Haus')];
    $cases['summary: 160'] = ['house', $with('summary.en', str_repeat('a', 160))];
    $cases['summary: 161'] = ['house', $with('summary.en', str_repeat('a', 161))];
    $cases['summary: a list'] = ['house', $with('summary', ['a'])];

    // The description.
    foreach (['empty' => [], 'ten' => array_fill(0, 10, 'Text.'), 'eleven' => array_fill(0, 11, 'Text.'), 'long' => [str_repeat('a', 2001)], 'not a list' => ['a' => 'Text.'], 'not texts' => [1],
        'html' => ['<p>Text.</p>'], 'a line break' => ["Line\nbreak"]] as $label => $paragraphs) {
        $cases["description: {$label}"] = ['house', $with('description.en', $paragraphs)];
    }

    $cases['description: not an object'] = ['house', $with('description', ['A paragraph.'])];

    // The fields.
    $cases['fields: none'] = ['house', $with('fields', [])];
    $cases['fields: an object'] = ['house', $with('fields', ['a' => 'b'])];
    $cases['fields: 100'] = ['house', array_merge($base, ['fields' => array_map(fn (int $number): array => ['key' => "f{$number}", 'type' => 'text', 'name' => ['fr' => "Champ {$number}", 'en' => "Field {$number}"]], range(1, 100))], ['examples' => ['fr' => [['f1' => 'a'], ['f1' => 'b']], 'en' => [['f1' => 'a'], ['f1' => 'b']]]])];
    $cases['fields: 101'] = ['house', array_merge($cases['fields: 100'][1], ['fields' => array_map(fn (int $number): array => ['key' => "f{$number}", 'type' => 'text', 'name' => ['fr' => "Champ {$number}", 'en' => "Field {$number}"]], range(1, 101))])];
    $cases['fields: not an object'] = ['house', $with('fields.1', 'text')];
    $cases['fields: unknown key'] = ['house', $with('fields.0.default', 'x')];
    $cases['fields: an x- key'] = ['house', $with('fields.0.x-note', 'x')];
    $cases['fields: duplicate key'] = ['house', $with('fields.1.key', 'name')];
    $cases['fields: duplicate name in a language'] = ['house', $with('fields.1.name.en', 'NAME')];
    $cases['fields: same name, another accent'] = ['house', $with('fields.1.name.fr', 'Nom')];
    $cases['fields: key capitals'] = ['house', $with('fields.0.key', 'Name')];
    $cases['fields: key too long'] = ['house', $with('fields.0.key', str_repeat('a', 61))];
    $cases['fields: no key'] = ['house', $without('fields.0.key')];
    $cases['fields: unknown type'] = ['house', $with('fields.0.type', 'geolocation')];
    $cases['fields: no type'] = ['house', $without('fields.0.type')];
    $cases['fields: required text'] = ['house', $with('fields.0.required', 'yes')];
    $cases['fields: options not an object'] = ['house', $with('fields.0.options', ['a'])];
    $cases['fields: an option the type does not have'] = ['house', $with('fields.0.options.currency', 'EUR')];

    // The choices.
    $cases['choices: none'] = ['house', $without('fields.1.options')];
    $cases['choices: empty'] = ['house', $with('fields.1.options.choices', [])];
    $cases['choices: not a list'] = ['house', $with('fields.1.options.choices', ['a' => 'b'])];
    $cases['choices: not an object'] = ['house', $with('fields.1.options.choices.0', 'kitchen')];
    $cases['choices: duplicate key'] = ['house', $with('fields.1.options.choices.1.key', 'kitchen')];
    $cases['choices: duplicate label'] = ['house', $with('fields.1.options.choices.1.label.en', 'KITCHEN')];
    $cases['choices: key capitals'] = ['house', $with('fields.1.options.choices.0.key', 'Kitchen')];
    $cases['choices: unknown key'] = ['house', $with('fields.1.options.choices.0.icon', 'x')];
    $cases['choices: color'] = ['house', $with('fields.1.options.choices.0.color', 'chartreuse')];
    $cases['choices: color not text'] = ['house', $with('fields.1.options.choices.0.color', 1)];
    $cases['choices: no label in a language'] = ['house', $without('fields.1.options.choices.0.label.fr')];
    $cases['choices: 101'] = ['house', $with('fields.1.options.choices', array_map(fn (int $number): array => ['key' => "c{$number}", 'label' => ['fr' => "Choix {$number}", 'en' => "Choice {$number}"]], range(1, 101)))];

    // The examples.
    $cases['examples: one row'] = ['house', $with('examples.en', [['name' => 'Oven']])];
    $cases['examples: six rows'] = ['house', $with('examples.en', array_fill(0, 6, ['name' => 'Oven']))];
    $cases['examples: no french'] = ['house', $without('examples.fr')];
    $cases['examples: an empty row'] = ['house', $with('examples.en.0', [])];
    $cases['examples: a row that is not an object'] = ['house', $with('examples.en.0', 'Oven')];
    $cases['examples: an unknown field'] = ['house', $with('examples.en.0.colour', 'red')];
    $cases['examples: an unknown choice'] = ['house', $with('examples.en.0.room', 'attic')];
    $cases['examples: a choice that is not a text'] = ['house', $with('examples.en.0.room', 3)];
    $cases['examples: a required field missing'] = ['house', $with('examples.en.0', ['room' => 'kitchen'])];
    $cases['examples: an amount with a wrong decimal'] = ['house', $with('examples.en.0.price', '899.5')];
    $cases['examples: an amount that is a number'] = ['house', $with('examples.en.0.price', 899.0)];
    $cases['examples: an amount that is not'] = ['house', $with('examples.en.0.price', 'cheap')];
    $cases['examples: another language'] = ['house', $with('examples.xx', [['name' => 'Ofen'], ['name' => 'Bohrer']])];

    // Every type of field, with options that are right and with options that are not.
    foreach (goldenTypes() as $type => ['options' => $options, 'value' => $value]) {
        $field = ['key' => 'f', 'type' => $type, 'name' => ['fr' => 'Champ', 'en' => 'Field']] + ($options === [] ? [] : ['options' => $options]);
        // A field filled by uploading files has no example: the rows are about the text next to it.
        $text = ['key' => 'x', 'type' => 'text', 'name' => ['fr' => 'X', 'en' => 'X']];
        $row = $value === null ? ['x' => 'a'] : ['f' => $value, 'x' => 'a'];
        $cases["type {$type}: right"] = ['house', goldenWith([$field, $text], $row)];
        $cases["type {$type}: required"] = ['house', goldenWith([$field + ['required' => true], $text], $row)];
        $cases["type {$type}: a wrong value"] = ['house', goldenWith([$field, $text], ['f' => $value === null ? 'a' : ['wrong' => 1], 'x' => 'a'])];
    }

    foreach ([
        'text: a length of 0' => ['text', ['max_length' => 0]], 'text: too long' => ['text', ['max_length' => 100000]],
        'number: decimals' => ['number', ['decimals' => 99]], 'number: bounds' => ['number', ['min' => '10', 'max' => '1']], 'number: a bound that is not a number' => ['number', ['min' => 'a']],
        'number: a long unit' => ['number', ['unit' => str_repeat('a', 50)]], 'money: a currency' => ['money', ['currency' => 'ZZZ']], 'money: no currency' => ['money', []],
        'percentage: decimals' => ['percentage', ['decimals' => 99]], 'rating: a maximum' => ['rating', ['max' => 99]], 'rating: a maximum of 1' => ['rating', ['max' => 1]],
        'barcode: a format' => ['barcode', ['format' => 'nope']], 'select: a text' => ['select', ['choices' => 'a']],
    ] as $label => [$type, $options]) {
        $cases["options {$label}"] = ['house', goldenWith([['key' => 'f', 'type' => $type, 'name' => ['fr' => 'Champ', 'en' => 'Field']] + ($options === [] ? [] : ['options' => $options])], ['f' => 'a'])];
    }

    return $cases;
}

/**
 * What the validator says of every case, by case.
 *
 * @return array<string, list<string>>
 */
function goldenVerdicts(): array
{
    return array_map(fn (array $case): array => app(TemplateValidator::class)->validate($case[0], $case[1]), goldenCases());
}

describe('the golden verdicts', function (): void {
    it('know every type of field the application has: a new type must be placed in this test', function (): void {
        expect(app(FieldTypes::class)->keys())->toEqualCanonicalizing(array_keys(goldenTypes()));
    });

    it('accept the base and the right fields, and refuse the cases that are wrong', function (): void {
        $verdicts = goldenVerdicts();

        expect($verdicts['the base'])->toBe([])
            ->and($verdicts['top: a schema'])->toBe([])
            ->and($verdicts['top: format 2'])->not->toBe([])
            ->and(array_filter($verdicts, fn (array $errors, string $case): bool => str_ends_with($case, ': right') && $errors !== [], ARRAY_FILTER_USE_BOTH))->toBe([]);
    });

    it('are the verdicts of the version of the rules they were recorded with: bump TemplateValidator::VERSION when this fails', function (): void {
        $digest = hash('xxh128', json_encode(goldenVerdicts(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Intended? Bump TemplateValidator::VERSION, then record the new version and digest here.
        expect([TemplateValidator::VERSION, $digest])->toBe([1, 'a7fcfe362e88a2754a49a3aac5dae69f']);
    });
});
