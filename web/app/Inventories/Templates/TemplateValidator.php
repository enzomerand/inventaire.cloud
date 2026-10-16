<?php

declare(strict_types=1);

namespace App\Inventories\Templates;

use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\DecimalType;
use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\ItemValues;
use App\Models\InventoryField;
use App\Rules\SingleLineText;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Judges the content of a template file the way the application judges the same inventory made by hand.
 *
 * The file is the contract of the catalogue:
 *
 * ```
 * {
 *   "format": 1,                              optional, 1 by default: the version of this format the file is written in
 *   "category": "home",                       one of {@see TemplateCategories::KEYS}
 *   "icon": "home",                           one of {@see TemplateIcons::ALLOWED}
 *   "name":        { "fr": "...", "en": "..." },
 *   "summary":     { "fr": "...", "en": "..." },     at most 160 characters
 *   "description": { "fr": ["paragraph", ...], "en": [...] },
 *   "fields": [
 *     { "key": "room", "type": "select", "name": { "fr": "...", "en": "..." }, "required": false,
 *       "options": { "choices": [ { "key": "kitchen", "label": { "fr": "...", "en": "..." }, "color": "blue" } ] } },
 *     { "key": "price", "type": "money", "name": { ... }, "options": { "currency": "EUR" } }
 *   ],
 *   "examples": { "fr": [ { "room": "kitchen", "price": "899.00" } ], "en": [ ... ] }
 * }
 * ```
 *
 * A file may also carry a `$schema` key (an editor's link to a JSON Schema) and keys that start with `x-` (a contributor's own
 * notes): they are not part of the format and are ignored. A `format` higher than {@see self::FORMAT} is a file written for
 * a newer version of the application, which cannot judge it: it is refused with a message that says so, and the catalogue
 * leaves it out.
 *
 * The file name (without `.json`) is the slug. Every text is written in French and English at least (and in no language the
 * application does not support), is plain text on one line (no HTML, no Markdown), and every field has the same keys in
 * every language. What is judged is not only the shape: the fields of each language are built and go through the rules of
 * the options of their type and through the normalization a field created by hand goes through, a name may not be taken
 * twice in a language, and the example rows are validated, in each language, by {@see ItemValues} against the fields built
 * (a choice is named by its `key`, numbers are decimal texts, a field filled by uploading files has no example).
 *
 * The messages are in English whatever the language of the request: they are for the people who write templates and for
 * the log.
 */
final class TemplateValidator
{
    /**
     * The version of the rules this class applies. Bump it whenever a file that was judged one way would be judged another
     * (a rule added, removed or changed, a limit moved, a message that says something else, or the rules of the options
     * of a type of field), together with the golden test (tests/Feature/Templates/TemplateValidatorGoldenTest.php), which
     * fails whenever that happens.
     *
     * The catalogue keeps its verdict on the files in the cache under a key that holds this number: without it, a file
     * judged valid by the previous rules would stay in the catalogue, for a day, after the new rules refused it.
     */
    public const VERSION = 1;

    /** The newest version of the file format this version of the application reads (the `format` key of a file). */
    public const FORMAT = 1;

    /** What a slug looks like. */
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /** Longest slug. */
    public const SLUG_MAX_LENGTH = 60;

    /** What the key of a field or of a choice looks like. */
    public const KEY_PATTERN = '/^[a-z0-9_]+$/D';

    /** Longest key of a field or of a choice. */
    public const KEY_MAX_LENGTH = 60;

    /** Longest name of a template or of a field, as for an inventory. */
    public const NAME_MAX_LENGTH = 120;

    /** Longest summary: it is the description of the page for search engines. */
    public const SUMMARY_MAX_LENGTH = 160;

    /** Most paragraphs in a description. */
    public const MAX_PARAGRAPHS = 10;

    /** Longest paragraph. */
    public const PARAGRAPH_MAX_LENGTH = 2000;

    /** Most fields in a template: the default `inventories.max_fields` quota. */
    public const MAX_FIELDS = 100;

    /** Fewest example rows in a language. */
    public const MIN_EXAMPLES = 2;

    /** Most example rows in a language. */
    public const MAX_EXAMPLES = 5;

    /** The keys a file may have. */
    private const TOP_KEYS = ['format', 'category', 'icon', 'name', 'summary', 'description', 'fields', 'examples'];

    /** The keys a field may have. */
    private const FIELD_KEYS = ['key', 'type', 'name', 'required', 'options'];

    /** The keys a choice may have. */
    private const CHOICE_KEYS = ['key', 'label', 'color'];

    /** What HTML looks like: a tag, a comment, an entity. */
    private const HTML_PATTERN = '/<\/?[a-z][a-z0-9-]*(?:\s[^>]*)?>|<!--|&(?:[a-z][a-z0-9]*|#[0-9]+|#x[0-9a-f]+);/i';

    /** What Markdown looks like: emphasis, code, a link, or a heading, a list item or a quote at the start. */
    private const MARKDOWN_PATTERN = '/\*\*|__|`|~~|\[[^\]]*\]\([^)]*\)|^\s{0,3}(?:#{1,6}\s|[-*+]\s|\d+[.)]\s|>)/';

    /**
     * Gets the registry of field types and the checker of item values.
     */
    public function __construct(
        private readonly FieldTypes $types,
        private readonly ItemValues $values,
    ) {}

    /**
     * Everything that is wrong with a template file, one message each; an empty list for a template that can be used.
     *
     * @param  string  $slug  The name of the file without its extension.
     * @param  mixed  $data  The decoded JSON (anything: the file may hold anything).
     * @return list<string>
     */
    public function validate(string $slug, mixed $data): array
    {
        $errors = [];

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > self::SLUG_MAX_LENGTH) {
            $errors[] = 'The file name must be a slug of at most '.self::SLUG_MAX_LENGTH.' characters: lowercase letters and digits, separated by single hyphens.';
        }

        if (! $this->isObject($data) || $data === []) {
            return [...$errors, 'The file must hold a JSON object.'];
        }

        $format = $this->checkFormat($data, $errors);

        if ($format > self::FORMAT) {
            // The rules of a newer format are unknown here: judging the rest with these ones would only invent errors.
            return $errors;
        }

        foreach (array_diff(array_map(strval(...), array_keys($data)), self::TOP_KEYS) as $key) {
            if ($key !== '$schema' && ! str_starts_with($key, 'x-')) {
                $errors[] = "{$key}: this key is not part of the format.";
            }
        }

        $this->checkCategoryAndIcon($data, $errors);

        $locales = $this->localesOf($data, $errors);

        $this->checkLocalized($errors, $data['name'] ?? null, 'name', $locales, fn (string $text): ?string => $this->problemWithText($text, self::NAME_MAX_LENGTH));
        $this->checkLocalized($errors, $data['summary'] ?? null, 'summary', $locales, fn (string $text): ?string => $this->problemWithText($text, self::SUMMARY_MAX_LENGTH));
        $this->checkDescription($data['description'] ?? null, $locales, $errors);
        $this->checkFields($data['fields'] ?? null, $locales, $errors);
        $this->checkExamples($data['examples'] ?? null, $locales, $errors);

        // The shape is right: the fields can be built, and judged the way a real creation judges them.
        if ($errors === []) {
            $this->inEnglish(function () use ($slug, $data, &$errors): void {
                $this->checkBuilt(Template::fromArray($slug, $data), $errors);
            });
        }

        return $errors;
    }

    /**
     * The version of the format a decoded file is written in: the whole number of 1 or more it says, 1 when it does not say,
     * and null for anything else (which {@see self::validate()} refuses).
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function formatOf(array $data): ?int
    {
        if (! array_key_exists('format', $data)) {
            return 1;
        }

        return is_int($data['format']) && $data['format'] >= 1 ? $data['format'] : null;
    }

    /**
     * Checks the format of the file; gives the number it is written in (the first when it says nonsense).
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $errors
     */
    private function checkFormat(array $data, array &$errors): int
    {
        $format = self::formatOf($data);

        if ($format === null) {
            $errors[] = 'format: must be a whole number, 1 or more.';

            return 1;
        }

        if ($format > self::FORMAT) {
            $errors[] = 'format: this file needs a newer inventaire.cloud (format '.$format.'; this version reads up to format '.self::FORMAT.').';
        }

        return $format;
    }

    /**
     * Checks the category and the icon.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $errors
     */
    private function checkCategoryAndIcon(array $data, array &$errors): void
    {
        $category = $data['category'] ?? null;

        if (! is_string($category) || ! TemplateCategories::exists($category)) {
            $errors[] = 'category: must be one of '.implode(', ', TemplateCategories::KEYS).'.';
        }

        $icon = $data['icon'] ?? null;

        if (! is_string($icon) || ! TemplateIcons::exists($icon)) {
            $errors[] = 'icon: must be one of the icons listed in '.TemplateIcons::class.'.';
        }
    }

    /**
     * The languages the file is written in: French and English, and any other supported language its `name` has. A language
     * the application does not support is an error.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function localesOf(array $data, array &$errors): array
    {
        $supported = array_map(strval(...), array_keys(config()->array('app.locale_names')));
        $locales = Template::REQUIRED_LOCALES;
        $name = $data['name'] ?? null;

        if (is_array($name) && $this->isObject($name)) {
            foreach (array_keys($name) as $locale) {
                $locale = (string) $locale;

                if (! in_array($locale, $supported, true)) {
                    $errors[] = "name: \"{$locale}\" is not a language the application supports (".implode(', ', $supported).').';
                } elseif (! in_array($locale, $locales, true)) {
                    $locales[] = $locale;
                }
            }
        }

        return $locales;
    }

    /**
     * Checks an object that holds one text per language: all of the languages, and no other.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $locales
     * @param  Closure(string): ?string  $problemWith  What is wrong with one text, or null.
     */
    private function checkLocalized(array &$errors, mixed $value, string $where, array $locales, Closure $problemWith): void
    {
        if (! is_array($value) || $value === [] || ! $this->isObject($value)) {
            $errors[] = "{$where}: must be an object with one text per language (".implode(', ', $locales).').';

            return;
        }

        foreach ($locales as $locale) {
            if (! array_key_exists($locale, $value)) {
                $errors[] = "{$where}: the {$locale} text is missing.";
            } elseif (! is_string($value[$locale])) {
                $errors[] = "{$where}.{$locale}: must be a text.";
            } elseif (($problem = $problemWith($value[$locale])) !== null) {
                $errors[] = "{$where}.{$locale}: {$problem}";
            }
        }

        foreach (array_diff(array_map(strval(...), array_keys($value)), $locales) as $locale) {
            $errors[] = "{$where}: the language \"{$locale}\" is not one of the template's languages (".implode(', ', $locales).').';
        }
    }

    /**
     * Checks the description: a list of paragraphs in each language.
     *
     * @param  list<string>  $locales
     * @param  list<string>  $errors
     */
    private function checkDescription(mixed $description, array $locales, array &$errors): void
    {
        if (! is_array($description) || $description === [] || ! $this->isObject($description)) {
            $errors[] = 'description: must be an object with a list of paragraphs per language ('.implode(', ', $locales).').';

            return;
        }

        foreach ($locales as $locale) {
            $paragraphs = $description[$locale] ?? null;

            if (! is_array($paragraphs) || $paragraphs === [] || ! array_is_list($paragraphs) || count($paragraphs) > self::MAX_PARAGRAPHS) {
                $errors[] = "description.{$locale}: must be a list of 1 to ".self::MAX_PARAGRAPHS.' paragraphs.';

                continue;
            }

            foreach ($paragraphs as $number => $paragraph) {
                $problem = is_string($paragraph) ? $this->problemWithText($paragraph, self::PARAGRAPH_MAX_LENGTH) : 'must be a text.';

                if ($problem !== null) {
                    $errors[] = "description.{$locale}[{$number}]: {$problem}";
                }
            }
        }

        foreach (array_diff(array_map(strval(...), array_keys($description)), $locales) as $locale) {
            $errors[] = "description: the language \"{$locale}\" is not one of the template's languages (".implode(', ', $locales).').';
        }
    }

    /**
     * Checks the shape of the fields: keys, types, names, options and choices. What the options mean is judged later.
     *
     * @param  list<string>  $locales
     * @param  list<string>  $errors
     */
    private function checkFields(mixed $fields, array $locales, array &$errors): void
    {
        if (! is_array($fields) || $fields === [] || ! array_is_list($fields)) {
            $errors[] = 'fields: must be a list of at least one field.';

            return;
        }

        if (count($fields) > self::MAX_FIELDS) {
            $errors[] = 'fields: a template may have at most '.self::MAX_FIELDS.' fields ('.count($fields).' found).';
        }

        $keys = [];

        foreach ($fields as $position => $field) {
            $where = "fields[{$position}]";

            if (! is_array($field) || $field === [] || ! $this->isObject($field)) {
                $errors[] = "{$where}: must be an object.";

                continue;
            }

            foreach (array_diff(array_map(strval(...), array_keys($field)), self::FIELD_KEYS) as $key) {
                $errors[] = "{$where}.{$key}: this key is not part of the format.";
            }

            $key = $field['key'] ?? null;

            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1 || strlen($key) > self::KEY_MAX_LENGTH) {
                $errors[] = "{$where}.key: must be made of lowercase letters, digits and underscores, at most ".self::KEY_MAX_LENGTH.' characters.';
            } elseif (in_array($key, $keys, true)) {
                $errors[] = "{$where}.key: \"{$key}\" is used by another field.";
            } else {
                $keys[] = $key;
                $where = "fields.{$key}";
            }

            $this->checkLocalized($errors, $field['name'] ?? null, "{$where}.name", $locales, fn (string $text): ?string => $this->problemWithText($text, self::NAME_MAX_LENGTH));

            $typeKey = $field['type'] ?? null;
            $type = is_string($typeKey) ? $this->types->find($typeKey) : null;

            if ($type === null) {
                $errors[] = "{$where}.type: must be one of ".implode(', ', $this->types->keys()).'.';
            }

            $required = $field['required'] ?? false;

            if (! is_bool($required)) {
                $errors[] = "{$where}.required: must be true or false.";
            } elseif ($required && $type?->filledByUpload() === true) {
                $errors[] = "{$where}.required: a field filled by uploading files cannot be required.";
            }

            if (array_key_exists('options', $field)) {
                $this->checkOptions($field['options'], $type, $locales, $where, $errors);
            } elseif ($type instanceof ChoiceType) {
                $errors[] = "{$where}.options.choices: at least one choice is required.";
            }
        }
    }

    /**
     * Checks the options of a field: only the ones its type has, and, for a select or a multiple choice, the choices.
     *
     * @param  list<string>  $locales
     * @param  list<string>  $errors
     */
    private function checkOptions(mixed $options, ?FieldType $type, array $locales, string $where, array &$errors): void
    {
        if (! is_array($options) || ($options !== [] && ! $this->isObject($options))) {
            $errors[] = "{$where}.options: must be an object.";

            return;
        }

        if ($type === null) {
            return;
        }

        $known = array_map(fn (string $option): string => explode('.', $option)[0], array_keys($type->optionRules()));

        foreach (array_diff(array_map(strval(...), array_keys($options)), $known) as $option) {
            $errors[] = "{$where}.options.{$option}: this type of field does not have this option.";
        }

        if (! $type instanceof ChoiceType) {
            return;
        }

        $choices = $options['choices'] ?? null;

        if (! is_array($choices) || $choices === [] || ! array_is_list($choices)) {
            $errors[] = "{$where}.options.choices: must be a list of at least one choice.";

            return;
        }

        if (count($choices) > ChoiceType::MAX_CHOICES) {
            $errors[] = "{$where}.options.choices: at most ".ChoiceType::MAX_CHOICES.' choices.';
        }

        $keys = [];

        foreach ($choices as $position => $choice) {
            $at = "{$where}.options.choices[{$position}]";

            if (! is_array($choice) || $choice === [] || ! $this->isObject($choice)) {
                $errors[] = "{$at}: must be an object.";

                continue;
            }

            foreach (array_diff(array_map(strval(...), array_keys($choice)), self::CHOICE_KEYS) as $key) {
                $errors[] = "{$at}.{$key}: this key is not part of the format.";
            }

            $key = $choice['key'] ?? null;

            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1 || strlen($key) > self::KEY_MAX_LENGTH) {
                $errors[] = "{$at}.key: must be made of lowercase letters, digits and underscores, at most ".self::KEY_MAX_LENGTH.' characters.';
            } elseif (in_array($key, $keys, true)) {
                $errors[] = "{$at}.key: \"{$key}\" is used by another choice of the field.";
            } else {
                $keys[] = $key;
            }

            $this->checkLocalized($errors, $choice['label'] ?? null, "{$at}.label", $locales, fn (string $text): ?string => $this->problemWithText($text, ChoiceType::LABEL_MAX_LENGTH));

            if (array_key_exists('color', $choice) && (! is_string($choice['color']) || ! in_array($choice['color'], ChoiceType::COLORS, true))) {
                $errors[] = "{$at}.color: must be one of ".implode(', ', ChoiceType::COLORS).'.';
            }
        }
    }

    /**
     * Checks the shape of the examples: a few rows, each an object, in each language.
     *
     * @param  list<string>  $locales
     * @param  list<string>  $errors
     */
    private function checkExamples(mixed $examples, array $locales, array &$errors): void
    {
        if (! is_array($examples) || $examples === [] || ! $this->isObject($examples)) {
            $errors[] = 'examples: must be an object with a list of rows per language ('.implode(', ', $locales).').';

            return;
        }

        foreach ($locales as $locale) {
            $rows = $examples[$locale] ?? null;

            if (! is_array($rows) || ! array_is_list($rows) || count($rows) < self::MIN_EXAMPLES || count($rows) > self::MAX_EXAMPLES) {
                $errors[] = "examples.{$locale}: must be a list of ".self::MIN_EXAMPLES.' to '.self::MAX_EXAMPLES.' rows.';

                continue;
            }

            foreach ($rows as $number => $row) {
                if (! is_array($row) || $row === [] || ! $this->isObject($row)) {
                    $errors[] = "examples.{$locale}[{$number}]: must be an object with the value of at least one field.";
                }
            }
        }

        foreach (array_diff(array_map(strval(...), array_keys($examples)), $locales) as $locale) {
            $errors[] = "examples: the language \"{$locale}\" is not one of the template's languages (".implode(', ', $locales).').';
        }
    }

    /**
     * Judges the fields of each language as a real creation does, then the example rows against them.
     *
     * @param  list<string>  $errors
     */
    private function checkBuilt(Template $template, array &$errors): void
    {
        foreach ($template->locales() as $locale) {
            $models = [];
            $ids = [];
            $decimals = [];
            $names = [];
            $broken = false;

            foreach ($template->fields($locale) as $field) {
                $where = "fields.{$field->key}";
                $type = $this->types->get($field->type);
                $folded = SingleLineText::fold($field->name);

                if (isset($names[$folded])) {
                    $errors[] = "{$where}.name.{$locale}: another field already has this name in this language.";
                    $broken = true;
                }

                $names[$folded] = true;
                $options = $field->creationOptions();
                $rules = [];

                foreach ($type->optionRules() as $option => $optionRules) {
                    $rules["options.{$option}"] = $optionRules;
                }

                $validator = Validator::make(['options' => $options], $rules);

                if ($validator->fails()) {
                    foreach ($validator->errors()->all() as $message) {
                        $errors[] = "{$where} ({$locale}): {$message}";
                    }

                    $broken = true;

                    continue;
                }

                try {
                    $normalized = $type->normalizeOptions($options, null);
                } catch (ValidationException $failure) {
                    foreach ($failure->errors() as $messages) {
                        foreach ($messages as $message) {
                            $errors[] = "{$where} ({$locale}): {$message}";
                        }
                    }

                    $broken = true;

                    continue;
                }

                foreach ($field->choices as $position => $choice) {
                    $id = $normalized['choices'][$position]['id'] ?? null;

                    if (is_string($id)) {
                        $ids[$field->key][$choice['key']] = $id;
                    }
                }

                if ($type instanceof DecimalType) {
                    $decimals[] = $field->key;
                }

                $model = new InventoryField(['name' => $field->name, 'type' => $field->type, 'options' => $normalized === [] ? null : $normalized, 'required' => $field->required]);
                $model->ulid = $field->key;
                $models[] = $model;
            }

            if (! $broken) {
                $this->checkExampleValues($template, $locale, collect($models), $ids, $decimals, $errors);
            }
        }
    }

    /**
     * Judges the example rows of a language against the fields built for it.
     *
     * @param  Collection<int, InventoryField>  $models  The fields, whose ULID is their key.
     * @param  array<string, array<string, string>>  $ids  The id of each choice, by field key and choice key.
     * @param  list<string>  $decimals  The keys of the fields whose values are decimal texts (numbers, amounts, percentages).
     * @param  list<string>  $errors
     */
    private function checkExampleValues(Template $template, string $locale, Collection $models, array $ids, array $decimals, array &$errors): void
    {
        foreach ($template->examples($locale) as $number => $row) {
            $where = "examples.{$locale}[{$number}]";
            $input = [];

            foreach ($row as $key => $value) {
                $key = (string) $key;

                if (! isset($ids[$key])) {
                    $input[$key] = $value;

                    continue;
                }

                $unknown = false;
                $mapped = [];

                foreach (is_array($value) ? $value : [$value] as $choiceKey) {
                    if (is_string($choiceKey) && isset($ids[$key][$choiceKey])) {
                        $mapped[] = $ids[$key][$choiceKey];
                    } else {
                        $unknown = true;
                        $errors[] = "{$where}.{$key}: ".(is_string($choiceKey) ? "\"{$choiceKey}\" is not the key of a choice of the field." : 'must name choices by their key.');
                    }
                }

                if (! $unknown) {
                    $input[$key] = is_array($value) ? $mapped : ($mapped[0] ?? null);
                }
            }

            if (count($input) !== count($row)) {
                continue;
            }

            try {
                $stored = $this->values->apply($models, $input, [], true);

                // A decimal is written as the API writes it: a text with exactly the decimals of the field.
                foreach ($decimals as $key) {
                    if (array_key_exists($key, $input) && $input[$key] !== ($stored[$key] ?? null)) {
                        $errors[] = "{$where}.{$key}: a number is written as a text with the decimals of the field (\"".(is_scalar($stored[$key] ?? null) ? $stored[$key] : '').'" expected).';
                    }
                }
            } catch (ValidationException $failure) {
                foreach ($failure->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = "{$where}.".preg_replace('/^values\./', '', $field).": {$message}";
                    }
                }
            }
        }
    }

    /**
     * What is wrong with a text of the file, or null: it must be plain text on one line, trimmed, not empty, not too long.
     */
    private function problemWithText(string $text, int $max): ?string
    {
        if ($text === '') {
            return 'must not be empty.';
        }

        if (SingleLineText::normalize($text) !== $text) {
            return 'must not start or end with a space, and must be in Unicode normalization form C.';
        }

        if (mb_strlen($text) > $max) {
            return "must be at most {$max} characters (".mb_strlen($text).' found).';
        }

        if (SingleLineText::problem($text) !== null) {
            return 'must be on one line, without control characters or text direction controls.';
        }

        if (preg_match(self::HTML_PATTERN, $text) === 1) {
            return 'must be plain text: no HTML.';
        }

        if (preg_match(self::MARKDOWN_PATTERN, $text) === 1) {
            return 'must be plain text: no Markdown.';
        }

        return null;
    }

    /**
     * Whether a decoded JSON value is an object (an empty one decodes to an empty array, which counts).
     */
    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * Runs the work with English messages, so that what is judged is worded the same whatever the language of the request.
     *
     * @param  Closure(): void  $work
     */
    private function inEnglish(Closure $work): void
    {
        $previous = app()->getLocale();
        app()->setLocale('en');

        try {
            $work();
        } finally {
            app()->setLocale($previous);
        }
    }
}
