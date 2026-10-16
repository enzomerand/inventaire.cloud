<?php

declare(strict_types=1);

namespace App\Inventories\Templates;

/**
 * An inventory template: a name, a description, the fields of the inventory it creates and a few example rows, all
 * written in several languages.
 *
 * A template is read from one JSON file of the catalogue ({@see TemplateCatalogue}) after {@see TemplateValidator} accepted
 * it, and never changes. The file is the contract; its format is described by the validator. Every text is available in
 * the languages of the file (French and English at least); asking for another language gives the language of the instance,
 * then English ({@see self::localeFor()}), so that a language added to the application never leaves a template blank.
 */
final readonly class Template
{
    /** The languages every template is written in. */
    public const REQUIRED_LOCALES = ['fr', 'en'];

    /**
     * @param  string  $slug  The name of the file without `.json`: the word in the URLs.
     * @param  string  $category  A key of {@see TemplateCategories}.
     * @param  string  $icon  A name of {@see TemplateIcons}.
     * @param  array<string, string>  $names  By language.
     * @param  array<string, string>  $summaries  By language.
     * @param  array<string, list<string>>  $descriptions  Paragraphs, by language.
     * @param  list<array{key: string, type: string, required: bool, names: array<string, string>, options: array<string, mixed>, choices: list<array{key: string, labels: array<string, string>, color: string|null}>}>  $fields
     * @param  array<string, list<array<string, mixed>>>  $examples  Rows keyed by field key, by language.
     */
    public function __construct(
        public string $slug,
        public string $category,
        public string $icon,
        private array $names,
        private array $summaries,
        private array $descriptions,
        private array $fields,
        private array $examples,
    ) {}

    /**
     * Builds the template from the content of its file, which {@see TemplateValidator} accepted: nothing is checked again.
     *
     * @param  array<string, mixed>  $data  The decoded file.
     */
    public static function fromArray(string $slug, array $data): self
    {
        $fields = [];

        foreach (self::list($data['fields'] ?? null) as $field) {
            $options = self::map($field['options'] ?? null);
            $choices = [];

            foreach (self::list($options['choices'] ?? null) as $choice) {
                $color = $choice['color'] ?? null;
                $choices[] = ['key' => self::text($choice['key'] ?? null), 'labels' => self::texts($choice['label'] ?? null), 'color' => is_string($color) ? $color : null];
            }

            unset($options['choices']);

            $fields[] = [
                'key' => self::text($field['key'] ?? null),
                'type' => self::text($field['type'] ?? null),
                'required' => ($field['required'] ?? false) === true,
                'names' => self::texts($field['name'] ?? null),
                'options' => $options,
                'choices' => $choices,
            ];
        }

        $descriptions = [];

        foreach (self::map($data['description'] ?? null) as $locale => $paragraphs) {
            $descriptions[(string) $locale] = array_values(array_map(self::text(...), self::map($paragraphs)));
        }

        $examples = [];

        foreach (self::map($data['examples'] ?? null) as $locale => $rows) {
            $examples[(string) $locale] = array_map(self::map(...), self::list($rows));
        }

        return new self(
            $slug,
            self::text($data['category'] ?? null),
            self::text($data['icon'] ?? null),
            self::texts($data['name'] ?? null),
            self::texts($data['summary'] ?? null),
            $descriptions,
            $fields,
            $examples,
        );
    }

    /**
     * The languages the template is written in.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return array_map(strval(...), array_keys($this->names));
    }

    /**
     * The language the texts come in when this one is asked for: this one if the template has it, else the language of the
     * instance, else English.
     */
    public function localeFor(string $locale): string
    {
        foreach ([$locale, config()->string('app.default_locale'), 'en'] as $candidate) {
            if (isset($this->names[$candidate])) {
                return $candidate;
            }
        }

        return array_key_first($this->names) ?? 'en';
    }

    /**
     * The name of the inventory the template makes.
     */
    public function name(string $locale): string
    {
        return $this->names[$this->localeFor($locale)] ?? $this->slug;
    }

    /**
     * The one-sentence summary: the text of the card and the description of the page.
     */
    public function summary(string $locale): string
    {
        return $this->summaries[$this->localeFor($locale)] ?? '';
    }

    /**
     * The paragraphs of the description.
     *
     * @return list<string>
     */
    public function description(string $locale): array
    {
        return $this->descriptions[$this->localeFor($locale)] ?? [];
    }

    /**
     * How many fields the inventory has.
     */
    public function fieldsCount(): int
    {
        return count($this->fields);
    }

    /**
     * The fields, in order, with their names and choices in the language.
     *
     * @return list<TemplateField>
     */
    public function fields(string $locale): array
    {
        $locale = $this->localeFor($locale);

        return array_map(fn (array $field): TemplateField => new TemplateField(
            $field['key'],
            $field['type'],
            $field['names'][$locale] ?? $field['key'],
            $field['required'],
            $field['options'],
            array_map(fn (array $choice): array => ['key' => $choice['key'], 'label' => $choice['labels'][$locale] ?? $choice['key'], 'color' => $choice['color']], $field['choices']),
        ), $this->fields);
    }

    /**
     * The example rows in the language, as the API writes values: choices by key, numbers as decimal texts. They are never
     * stored in an inventory.
     *
     * @return list<array<string, mixed>>
     */
    public function examples(string $locale): array
    {
        return $this->examples[$this->localeFor($locale)] ?? [];
    }

    /**
     * An array from decoded JSON, or an empty one for anything else.
     *
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * The items of a decoded JSON list that are objects (the validator made sure all of them are).
     *
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        $items = [];

        foreach (self::map($value) as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A string, or an empty one for anything else.
     */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * An object of texts by language.
     *
     * @return array<string, string>
     */
    private static function texts(mixed $value): array
    {
        $texts = [];

        foreach (self::map($value) as $locale => $text) {
            $texts[(string) $locale] = self::text($text);
        }

        return $texts;
    }
}
