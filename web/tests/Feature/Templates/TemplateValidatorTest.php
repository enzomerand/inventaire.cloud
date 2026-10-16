<?php

declare(strict_types=1);

use App\Inventories\Templates\TemplateIcons;

require_once __DIR__.'/../../Support/templates.php';

/*
 * What the validator accepts and what it refuses in a template file. Everything is in memory (no database): the fixtures of
 * tests/Fixtures/templates are decoded, changed on purpose, and the messages are looked at.
 */

/**
 * Asserts that some content has an error that holds the text, and gives all of the errors back.
 *
 * @return list<string>
 */
function expectTemplateError(mixed $data, string $text): array
{
    $errors = templateErrors($data);

    expect(implode("\n", $errors))->toContain($text);

    return $errors;
}

describe('a valid template', function (): void {
    it('is accepted', function (string $slug): void {
        expect(templateErrors(templateData($slug), $slug))->toBe([]);
    })->with(['sample-home', 'sample-shop', 'sample-tools', 'sample-records']);

    it('is accepted with another language the application supports, once everything is written in it', function (): void {
        config(['app.locale_names' => ['fr' => 'Français', 'en' => 'English', 'de' => 'Deutsch']]);
        $data = templateData('sample-tools');

        // Not written in German yet: every place that holds a text per language is named.
        expect(templateErrors(templateWith($data, 'name.de', 'Werkzeug'), 'sample-tools'))->toContain('summary: the de text is missing.');

        $data['name']['de'] = 'Werkzeug';
        $data['summary']['de'] = 'Finden Sie jedes Werkzeug wieder.';
        $data['description']['de'] = ['Ein Werkstatt-Inventar.'];
        $data['examples']['de'] = $data['examples']['en'];

        foreach (array_keys($data['fields']) as $position) {
            $data['fields'][$position]['name']['de'] = $data['fields'][$position]['name']['en'];

            foreach (array_keys($data['fields'][$position]['options']['choices'] ?? []) as $choice) {
                $data['fields'][$position]['options']['choices'][$choice]['label']['de'] = $data['fields'][$position]['options']['choices'][$choice]['label']['en'];
            }
        }

        expect(templateErrors($data, 'sample-tools'))->toBe([]);
    });
});

describe('the file', function (): void {
    it('must be a JSON object', function (mixed $content): void {
        expect(templateErrors($content))->toBe(['The file must hold a JSON object.']);
    })->with(['a list' => [['a', 'b']], 'a text' => ['text'], 'null' => [null], 'an empty object' => [[]]]);

    it('is named with a slug', function (string $slug): void {
        expect(implode("\n", templateErrors(templateData(), $slug)))->toContain('slug');
    })->with(['capitals' => ['Shop'], 'an underscore' => ['my_shop'], 'a double hyphen' => ['a--b'], 'a leading hyphen' => ['-a'], 'too long' => [str_repeat('a', 61)], 'a dot' => ['a.b']]);

    it('refuses a key that is not part of the format', function (): void {
        expectTemplateError(templateWith(templateData(), 'colour', 'red'), 'colour: this key is not part of the format');
    });

    it('lists every problem at once', function (): void {
        $data = templateWith(templateWith(templateData(), 'category', 'nope'), 'icon', 'nope');

        expect(templateErrors($data))->toHaveCount(2);
    });

    it('is judged with messages in English whatever the language of the request', function (): void {
        $this->useLanguage('fr');

        $errors = templateErrors(templateWith(templateData(), 'fields.2.options.min', 'abc'));

        expect(implode("\n", $errors))->toContain('fields.quantity')->toContain('must')
            ->and(app()->getLocale())->toBe('fr');
    });
});

describe('the category and the icon', function (): void {
    it('must be in the lists', function (string $path, string $value): void {
        expectTemplateError(templateWith(templateData(), $path, $value), "{$path}: must be one of");
    })->with([['category', 'garden'], ['icon', 'rocket'], ['icon', '../etc']]);

    it('name icons that all exist in the package', function (string $icon): void {
        expect(file_exists(base_path("vendor/secondnetwork/blade-tabler-icons/resources/svg/{$icon}.svg")))->toBeTrue("The icon {$icon} is not in the package.");
    })->with(TemplateIcons::ALLOWED);
});

describe('the texts', function (): void {
    it('are given in French and English', function (string $path): void {
        expectTemplateError(templateWithout(templateData(), $path), 'the fr text is missing');
    })->with(['name.fr', 'summary.fr', 'fields.1.name.fr', 'fields.5.options.choices.0.label.fr']);

    it('are given in no language the application does not support', function (): void {
        expectTemplateError(templateWith(templateData(), 'name.de', 'Lager'), '"de" is not a language the application supports');
    });

    it('must not be empty, padded, too long, on several lines or marked up', function (string $text, string $problem): void {
        foreach (['name.fr', 'summary.en', 'description.fr.0', 'fields.1.name.en', 'fields.5.options.choices.1.label.en'] as $path) {
            expectTemplateError(templateWith(templateData(), $path, $text), $problem);
        }
    })->with([
        'empty' => ['', 'must not be empty'],
        'spaces around' => [' text', 'must not start or end with a space'],
        'a line break' => ["two\nlines", 'must be on one line'],
        'a tag' => ['Hello <b>you</b>', 'no HTML'],
        'an entity' => ['Tom &amp; Jerry', 'no HTML'],
        'bold' => ['Very **bold**', 'no Markdown'],
        'a link' => ['See [here](https://example.com)', 'no Markdown'],
        'a heading' => ['# Title', 'no Markdown'],
        'a list item' => ['- one', 'no Markdown'],
        'code' => ['Use `this`', 'no Markdown'],
    ]);

    it('have a length limit', function (string $path, int $limit): void {
        expectTemplateError(templateWith(templateData(), $path, str_repeat('é', $limit + 1)), "must be at most {$limit} characters");
        expect(templateErrors(templateWith(templateData(), $path, str_repeat('é', $limit))))->toBe([]);
    })->with([['name.fr', 120], ['summary.en', 160], ['description.fr.0', 2000], ['fields.1.name.fr', 120], ['fields.5.options.choices.0.label.fr', 100]]);

    it('allow quotes, accents and symbols', function (): void {
        $data = templateWith(templateData(), 'name.fr', 'Stock d’épicerie « bio » & vrac < 5 kg');

        expect(templateErrors($data))->toBe([]);
    });

    it('make a description of 1 to 10 paragraphs', function (): void {
        expectTemplateError(templateWith(templateData(), 'description.fr', []), 'description.fr: must be a list of 1 to 10 paragraphs');
        expectTemplateError(templateWith(templateData(), 'description.en', array_fill(0, 11, 'Text')), 'description.en: must be a list of 1 to 10 paragraphs');
        expectTemplateError(templateWith(templateData(), 'description.en', 'A text'), 'description.en: must be a list');
        expectTemplateError(templateWith(templateData(), 'description.en.0', 12), 'description.en[0]: must be a text');
    });
});

describe('the fields', function (): void {
    it('are a list of at least one, and at most one hundred', function (): void {
        expectTemplateError(templateWith(templateData(), 'fields', []), 'fields: must be a list of at least one field');

        $fields = [];

        for ($number = 1; $number <= 101; $number++) {
            $fields[] = ['key' => "f{$number}", 'type' => 'text', 'name' => ['fr' => "Champ {$number}", 'en' => "Field {$number}"]];
        }

        expectTemplateError(templateWith(templateData(), 'fields', $fields), 'a template may have at most 100 fields (101 found)');
    });

    it('have a key of lowercase letters, digits and underscores, that no other field has', function (): void {
        expectTemplateError(templateWith(templateData(), 'fields.0.key', 'Code-Barres'), 'fields[0].key: must be made of lowercase letters');
        expectTemplateError(templateWith(templateData(), 'fields.1.key', 'barcode'), 'fields[1].key: "barcode" is used by another field');
        expectTemplateError(templateWithout(templateData(), 'fields.0.key'), 'fields[0].key: must be made of');
    });

    it('have a type of the registry', function (): void {
        expectTemplateError(templateWith(templateData(), 'fields.0.type', 'geolocation'), 'fields.barcode.type: must be one of text, textarea');
    });

    it('refuse the keys that are not part of the format', function (): void {
        expectTemplateError(templateWith(templateData(), 'fields.0.default', 'x'), 'fields[0].default: this key is not part of the format');
    });

    it('can be required, except the ones filled by uploading files', function (): void {
        expectTemplateError(templateWith(templateData('sample-home'), 'fields.11.required', true), 'fields.photo.required: a field filled by uploading files cannot be required');
        expectTemplateError(templateWith(templateData('sample-home'), 'fields.12.required', true), 'fields.invoice.required');
        expectTemplateError(templateWith(templateData(), 'fields.0.required', 'yes'), 'fields.barcode.required: must be true or false');
    });

    it('cannot have the same name twice in a language, the case and the way an accent is written not counting', function (): void {
        $errors = expectTemplateError(templateWith(templateData(), 'fields.1.name.fr', 'CODE-BARRES'), 'fields.name.name.fr: another field already has this name in this language');
        expect(implode("\n", $errors))->not->toContain('.en:');

        // The case is folded the way Unicode does: "Straße" is "STRASSE".
        expectTemplateError(templateWith(templateWith(templateData(), 'fields.1.name.en', 'STRASSE'), 'fields.0.name.en', 'Straße'), 'fields.name.name.en: another field already has this name');
    });

    it('must be written in Unicode normalization form C', function (): void {
        expectTemplateError(templateWith(templateData(), 'fields.1.name.en', "Cafe\u{301}"), 'must not start or end with a space, and must be in Unicode normalization form C');
    });

    it('accept the same name in two languages', function (): void {
        expect(templateErrors(templateWith(templateData(), 'fields.1.name.fr', 'Name')))->toBe([]);
    });
});

describe('the options of a field', function (): void {
    it('are those of its type', function (): void {
        expectTemplateError(templateWith(templateData(), 'fields.1.options', ['currency' => 'EUR']), 'fields.name.options.currency: this type of field does not have this option');
        expectTemplateError(templateWith(templateData(), 'fields.0.options.checks', true), 'fields.barcode.options.checks: this type of field does not have this option');
        expectTemplateError(templateWith(templateData(), 'fields.1.options', 'text'), 'fields.name.options: must be an object');
    });

    it('go through the rules of their type', function (string $path, mixed $value, string $problem): void {
        expectTemplateError(templateWith(templateData('sample-home'), $path, $value), $problem);
    })->with([
        'a currency that does not exist' => ['fields.3.options.currency', 'XXX', 'currency'],
        'a rating with a maximum too high' => ['fields.6.options.max', 99, 'options.max'],
        'a number with too many decimals' => ['fields.8.options.decimals', 12, 'options.decimals'],
        'a number whose minimum is above the maximum' => ['fields.8.options', ['min' => '10', 'max' => '1'], 'maximum must not be below the minimum'],
        'a photo with too many files' => ['fields.11.options.max_files', 500, 'options.max files'],
        'a barcode in a format that does not exist' => ['fields.0.options.format', 'qr', 'format'],
    ]);

    it('are the options a real creation would normalize', function (): void {
        // Nothing is refused when the options are left out: the type fills in its defaults.
        expect(templateErrors(templateWithout(templateData(), 'fields.3.options')))->toBe([]);
    });

    it('must give choices to a select and to a multiple choice', function (): void {
        expectTemplateError(templateWithout(templateData(), 'fields.5.options'), 'fields.status.options.choices: at least one choice is required');
        expectTemplateError(templateWith(templateData(), 'fields.5.options.choices', []), 'fields.status.options.choices: must be a list of at least one choice');
        expectTemplateError(templateWith(templateData(), 'fields.5.options.choices', 'a'), 'fields.status.options.choices: must be a list');
        expectTemplateError(templateWith(templateData(), 'fields.5.options.choices.0', 'a'), 'choices[0]: must be an object');
    });

    it('give each choice a key, a label per language and a color of the palette', function (): void {
        expectTemplateError(templateWith(templateData(), 'fields.5.options.choices.1.key', 'in_stock'), 'choices[1].key: "in_stock" is used by another choice');
        expectTemplateError(templateWith(templateData(), 'fields.5.options.choices.1.key', 'Low stock'), 'choices[1].key: must be made of');
        expectTemplateError(templateWith(templateData(), 'fields.5.options.choices.1.color', 'magenta'), 'choices[1].color: must be one of gray, red');
        expectTemplateError(templateWith(templateData(), 'fields.5.options.choices.1.id', 'abcdefgh'), 'choices[1].id: this key is not part of the format');
        expectTemplateError(templateWithout(templateData(), 'fields.5.options.choices.1.label'), 'choices[1].label: must be an object with one text per language');
    });

    it('cannot give two choices the same label in a language', function (): void {
        $errors = expectTemplateError(templateWith(templateData(), 'fields.5.options.choices.1.label.fr', 'EN STOCK'), 'must not have two choices with the same label');
        expect(implode("\n", $errors))->toContain('(fr)');
    });
});

describe('the examples', function (): void {
    it('are 2 to 5 rows in each language', function (): void {
        expectTemplateError(templateWith(templateData(), 'examples.fr', [templateData()['examples']['fr'][0]]), 'examples.fr: must be a list of 2 to 5 rows');
        expectTemplateError(templateWith(templateData(), 'examples.en', array_fill(0, 6, templateData()['examples']['en'][0])), 'examples.en: must be a list of 2 to 5 rows');
        expectTemplateError(templateWithout(templateData(), 'examples.en'), 'examples.en: must be a list of 2 to 5 rows');
        expectTemplateError(templateWith(templateData(), 'examples.fr.0', []), 'examples.fr[0]: must be an object');
        expectTemplateError(templateWith(templateData(), 'examples.de', []), 'language "de"');
    });

    it('are judged against the fields, in each language', function (string $path, mixed $value, string $problem): void {
        expectTemplateError(templateWith(templateData(), $path, $value), $problem);
    })->with([
        'a field that does not exist' => ['examples.fr.0.colour', 'red', 'examples.fr[0].colour: This field does not exist in this inventory.'],
        'an invalid value' => ['examples.en.1.vat', 'lots', 'examples.en[1].vat'],
        'a number as a JSON number' => ['examples.en.1.price', 3.5, 'examples.en[1].price: a number is written as a text with the decimals of the field ("3.50" expected)'],
        'an amount without its decimals' => ['examples.en.1.price', '3', 'examples.en[1].price: a number is written as a text'],
        'a number in the form of another count of decimals' => ['examples.en.0.vat', '20', 'examples.en[0].vat: a number is written as a text'],
        'too many decimals for an amount' => ['examples.fr.2.price', '6.905', 'examples.fr[2].price'],
        'a bad barcode' => ['examples.fr.0.barcode', '4006381333932', 'examples.fr[0].barcode'],
        'a choice that does not exist' => ['examples.fr.0.status', 'broken', 'examples.fr[0].status: "broken" is not the key of a choice'],
        'a choice named by a number' => ['examples.fr.0.status', 3, 'examples.fr[0].status: must name choices by their key'],
        'a bad date' => ['examples.en.2.expires_on', '31/01/2027', 'examples.en[2].expires_on'],
    ]);

    it('name a choice by its key, in the language they are written in', function (): void {
        // The example of the French side names "low", the English side must be right too.
        expect(templateErrors(templateWith(templateData(), 'examples.en.1.status', 'out')))->toBe([]);
    });

    it('must give a value to every required field', function (): void {
        expectTemplateError(templateWithout(templateData(), 'examples.fr.1.name'), 'examples.fr[1].name');
    });

    it('cannot give a value to a field filled by uploading files', function (): void {
        expectTemplateError(templateWith(templateData('sample-home'), 'examples.fr.0.photo', 'photo.jpg'), 'examples.fr[0].photo: This field is filled by uploading files.');
    });

    it('can name several choices of a multiple choice', function (): void {
        expect(templateErrors(templateData('sample-home'), 'sample-home'))->toBe([]);

        $errors = templateErrors(templateWith(templateData('sample-home'), 'examples.en.0.kinds', ['appliance', 'sofa']), 'sample-home');

        expect(implode("\n", $errors))->toContain('examples.en[0].kinds: "sofa" is not the key of a choice');
    });
});
