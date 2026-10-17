<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Turns the Schema Objects of the OpenAPI document into what the page `/docs/api` draws: the type of a value as a short
 * label (with a link to the schema it names), the facts about it ("One of: ...", "Default: ..."), and the table of the
 * properties of an object.
 *
 * Only what the document uses is understood: `$ref` to a schema, `allOf` / `oneOf` / `anyOf`, `type` (a word or a list of
 * words), `enum`, `items`, `properties`, `additionalProperties`. A schema that is none of these is `any`.
 *
 * A type is a list of tokens, so that the template can link the names of schemas without building HTML here:
 * `['text' => 'Workspace', 'schema' => 'Workspace']` is a link to the schema of that name, `['text' => 'array of ', 'schema' => null]`
 * is plain text.
 *
 * @phpstan-type Token array{text: string, schema: string|null}
 * @phpstan-type Row array{name: string, type: list<Token>, required: bool, description: string, details: list<string>}
 */
final class SchemaDescriber
{
    /** The types a schema can name that need no explanation. */
    private const PLAIN_TYPES = ['string', 'integer', 'number', 'boolean', 'null'];

    /**
     * Gets the document, to follow the references.
     */
    public function __construct(private readonly OpenApi $api) {}

    /**
     * The type of the values a schema accepts.
     *
     * @param  array<string, mixed>  $schema
     * @return list<Token>
     */
    public function type(array $schema): array
    {
        $name = $this->api->schemaName($schema);

        if ($name !== null) {
            return [['text' => $name, 'schema' => $name]];
        }

        $schema = $this->api->resolve($schema);

        // "allOf: [ref]" is how a reference carries a default or a description: the type is the one of the named schemas
        // (the anonymous parts only add facts, such as the properties a response always holds).
        if (is_array($schema['allOf'] ?? null)) {
            $members = $this->schemasOf($schema['allOf']);
            $named = array_values(array_filter($members, fn (array $member): bool => $this->api->schemaName($member) !== null));

            return $this->join($named !== [] ? $named : $members, ' & ');
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) {
                return $this->join($this->schemasOf($schema[$keyword]), ' | ');
            }
        }

        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            return $this->join(array_map(fn (mixed $word): array => ['type' => $word] + $schema, array_values($type)), ' | ', true);
        }

        if (is_string($type)) {
            return $this->word($type, $schema);
        }

        if (is_array($schema['enum'] ?? null)) {
            return [['text' => 'string', 'schema' => null]];
        }

        return [['text' => isset($schema['properties']) ? 'object' : 'any', 'schema' => null]];
    }

    /**
     * What else a person should know about the values of a schema: the values it allows, its default, its limits, an example.
     *
     * A schema reached by name (`$ref`) gives its allowed values and default only: its limits and example are on its own
     * entry in the list of schemas, and repeating them at every use would drown what is specific to the place.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function details(array $schema): array
    {
        $brief = $this->api->schemaName($schema) !== null;
        $resolved = $this->api->resolve($schema);
        $details = [];

        $enum = $this->enumOf($resolved);

        if ($enum !== null) {
            $details[] = __('One of: :values', ['values' => implode(', ', array_map($this->scalar(...), $enum))]);
        }

        if (array_key_exists('const', $resolved)) {
            $details[] = __('Always: :value', ['value' => $this->scalar($resolved['const'])]);
        }

        if (array_key_exists('default', $resolved)) {
            $details[] = __('Default: :value', ['value' => $this->scalar($resolved['default'])]);
        }

        if (! $brief) {
            array_push($details, ...$this->limits($resolved));
        }

        $items = $resolved['items'] ?? null;

        if (is_array($items) && ($itemEnum = $this->enumOf($this->api->resolve($items))) !== null) {
            $details[] = __('Each item is one of: :values', ['values' => implode(', ', array_map($this->scalar(...), $itemEnum))]);
        }

        if (! $brief && is_array($resolved['examples'] ?? null) && $resolved['examples'] !== []) {
            $details[] = __('Example: :value', ['value' => $this->scalar(array_values($resolved['examples'])[0])]);
        }

        return $details;
    }

    /**
     * The description of a schema: its own, else the one of the schema it names.
     *
     * @param  array<string, mixed>  $schema
     */
    public function description(array $schema): string
    {
        if (isset($schema['description']) && is_string($schema['description'])) {
            return $schema['description'];
        }

        $resolved = $this->api->resolve($schema);

        if (isset($resolved['description']) && is_string($resolved['description'])) {
            return $resolved['description'];
        }

        // "allOf: [ref]": the description of the schema it names.
        foreach ($this->schemasOf(is_array($resolved['allOf'] ?? null) ? $resolved['allOf'] : []) as $member) {
            if ($this->api->schemaName($member) !== null && ($text = $this->description($member)) !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * The table of the properties of an object schema, the ones of the schemas it is made of (`allOf`) included.
     *
     * A property that is an object (or a list of objects) written in place has its own properties listed under it, one level
     * down, as `parent.child` (`parent[].child`); one that names a schema is a link to it instead.
     *
     * @param  array<string, mixed>  $schema
     * @return list<Row>
     */
    public function rows(array $schema): array
    {
        return $this->rowsOf($schema, '', false);
    }

    /**
     * Everything the list of schemas says about one schema.
     *
     * - `extends`: the schemas it is made of;
     * - `rows`: its properties (those of `extends` included);
     * - `variants`: for a schema that is one of several (`oneOf` / `anyOf`) of objects, each with its title and its properties.
     *
     * @param  array<string, mixed>  $schema
     * @return array{name: string, description: string, type: list<Token>, details: list<string>, extends: list<string>, rows: list<Row>, variants: list<array{title: string, type: list<Token>, description: string, rows: list<Row>}>}
     */
    public function describe(string $name, array $schema): array
    {
        $extends = [];

        foreach ($this->schemasOf(is_array($schema['allOf'] ?? null) ? $schema['allOf'] : []) as $member) {
            $parent = $this->api->schemaName($member);

            if ($parent !== null) {
                $extends[] = $parent;
            }
        }

        $variants = [];

        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach ($this->schemasOf(is_array($schema[$keyword] ?? null) ? $schema[$keyword] : []) as $variant) {
                $resolved = $this->api->resolve($variant);

                $variants[] = [
                    'title' => is_string($resolved['title'] ?? null) ? $resolved['title'] : '',
                    'type' => $this->type($variant),
                    'description' => $this->description($variant),
                    'rows' => $this->rows($variant),
                ];
            }
        }

        return [
            'name' => $name,
            'description' => $this->description($schema),
            'type' => $this->type($schema),
            'details' => $this->details($schema),
            'extends' => $extends,
            'rows' => $this->rows($schema),
            'variants' => $variants,
        ];
    }

    /**
     * The rows of the properties of a schema, the names starting with a prefix.
     *
     * @param  array<string, mixed>  $schema
     * @param  bool  $inlineOnly  Whether to leave out the schemas that are named (a link tells where they are).
     * @return list<Row>
     */
    private function rowsOf(array $schema, string $prefix, bool $inlineOnly): array
    {
        $flat = $this->flatten($schema, $inlineOnly);
        $rows = [];

        foreach ($flat['properties'] as $name => $property) {
            $property = is_array($property) ? $property : [];
            $label = $prefix.$name;

            $rows[] = [
                'name' => $label,
                'type' => $this->type($property),
                'required' => in_array((string) $name, $flat['required'], true),
                'description' => $this->description($property),
                'details' => $this->details($property),
            ];

            // One level only: what is written in place inside a property that is not itself written in place inside another.
            if (! $inlineOnly) {
                [$inner, $list] = $this->innerSchema($property);

                if ($inner !== null) {
                    array_push($rows, ...$this->rowsOf($inner, $label.($list ? '[].' : '.'), true));
                }
            }
        }

        return $rows;
    }

    /**
     * The properties and the required names of an object schema, the parts of `allOf` merged (the first part first).
     *
     * @param  array<string, mixed>  $schema
     * @param  bool  $inlineOnly  Whether to leave out the schemas that are named (a link tells where they are).
     * @return array{properties: array<string, mixed>, required: list<string>}
     */
    public function flatten(array $schema, bool $inlineOnly): array
    {
        if ($inlineOnly && $this->api->schemaName($schema) !== null) {
            return ['properties' => [], 'required' => []];
        }

        $schema = $this->api->resolve($schema);
        $properties = [];
        $required = [];

        foreach ($this->schemasOf(is_array($schema['allOf'] ?? null) ? $schema['allOf'] : []) as $member) {
            $part = $this->flatten($member, $inlineOnly);
            $properties = array_merge($properties, $part['properties']);
            $required = array_merge($required, $part['required']);
        }

        if (is_array($schema['properties'] ?? null)) {
            $properties = array_merge($properties, $schema['properties']);
        }

        if (is_array($schema['required'] ?? null)) {
            $required = array_merge($required, array_map('strval', $schema['required']));
        }

        return ['properties' => $properties, 'required' => array_values($required)];
    }

    /**
     * The schema written in place inside a property, whose properties are worth listing under it: the property itself when
     * it is an object, the items when it is a list of objects. Null when there is none.
     *
     * @param  array<string, mixed>  $property
     * @return array{0: array<string, mixed>|null, 1: bool} The schema, and whether the property is a list of them.
     */
    private function innerSchema(array $property): array
    {
        if ($this->api->schemaName($property) !== null) {
            return [null, false];
        }

        $resolved = $this->api->resolve($property);

        if ($this->hasProperties($resolved)) {
            return [$resolved, false];
        }

        $items = $resolved['items'] ?? null;

        if (is_array($items) && $this->api->schemaName($items) === null && $this->hasProperties($this->api->resolve($items))) {
            return [$items, true];
        }

        return [null, false];
    }

    /**
     * Whether a schema written in place has properties of its own to list (in itself or in a part of its `allOf`).
     *
     * @param  array<string, mixed>  $schema
     */
    private function hasProperties(array $schema): bool
    {
        return $this->flatten($schema, true)['properties'] !== [];
    }

    /**
     * The tokens of several schemas one after the other, a glue between them.
     *
     * @param  list<array<string, mixed>>  $members
     * @param  bool  $words  Whether the members are the words of a `type` list (each one the whole schema with one word), so a member that is `array` shows its items.
     * @return list<Token>
     */
    private function join(array $members, string $glue, bool $words = false): array
    {
        $tokens = [];

        foreach ($members as $index => $member) {
            if ($index > 0) {
                $tokens[] = ['text' => $glue, 'schema' => null];
            }

            array_push($tokens, ...($words ? $this->word((string) $member['type'], $member) : $this->type($member)));
        }

        return $tokens;
    }

    /**
     * The tokens of one word of `type`, with what completes it: the items of an array, the values of a map, the format.
     *
     * @param  array<string, mixed>  $schema  The schema the word is the type of.
     * @return list<Token>
     */
    private function word(string $word, array $schema): array
    {
        if ($word === 'array') {
            $items = $schema['items'] ?? null;

            return is_array($items)
                ? [['text' => 'array of ', 'schema' => null], ...$this->type($items)]
                : [['text' => 'array', 'schema' => null]];
        }

        if ($word === 'object') {
            $values = $schema['additionalProperties'] ?? null;

            return is_array($values)
                ? [['text' => 'map of ', 'schema' => null], ...$this->type($values)]
                : [['text' => 'object', 'schema' => null]];
        }

        $format = in_array($word, self::PLAIN_TYPES, true) && is_string($schema['format'] ?? null) ? ' ('.$schema['format'].')' : '';

        return [['text' => $word.$format, 'schema' => null]];
    }

    /**
     * The limits of a schema, written for a person.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function limits(array $schema): array
    {
        $limits = [];

        if (array_key_exists('minimum', $schema)) {
            $limits[] = __('Minimum: :value', ['value' => $this->scalar($schema['minimum'])]);
        }

        if (array_key_exists('maximum', $schema)) {
            $limits[] = __('Maximum: :value', ['value' => $this->scalar($schema['maximum'])]);
        }

        if (array_key_exists('minLength', $schema)) {
            $limits[] = __('Minimum length: :value', ['value' => $this->scalar($schema['minLength'])]);
        }

        if (array_key_exists('maxLength', $schema)) {
            $limits[] = __('Maximum length: :value', ['value' => $this->scalar($schema['maxLength'])]);
        }

        if (array_key_exists('minItems', $schema)) {
            $limits[] = __('Minimum items: :value', ['value' => $this->scalar($schema['minItems'])]);
        }

        if (array_key_exists('maxItems', $schema)) {
            $limits[] = __('Maximum items: :value', ['value' => $this->scalar($schema['maxItems'])]);
        }

        if (array_key_exists('pattern', $schema)) {
            $limits[] = __('Pattern: :value', ['value' => $this->scalar($schema['pattern'])]);
        }

        return $limits;
    }

    /**
     * The values a schema allows, listed in `enum`, or in the single schema it is made of by `allOf`; null when it lists none.
     *
     * @param  array<string, mixed>  $schema  A schema whose `$ref` is already followed.
     * @return list<mixed>|null
     */
    private function enumOf(array $schema): ?array
    {
        if (is_array($schema['enum'] ?? null)) {
            return array_values($schema['enum']);
        }

        $members = $this->schemasOf(is_array($schema['allOf'] ?? null) ? $schema['allOf'] : []);

        return count($members) === 1 ? $this->enumOf($this->api->resolve($members[0])) : null;
    }

    /**
     * The entries of a list of schemas that are schemas (arrays), nothing else.
     *
     * @param  array<array-key, mixed>  $list
     * @return list<array<string, mixed>>
     */
    private function schemasOf(array $list): array
    {
        /** @var list<array<string, mixed>> */
        return array_values(array_filter($list, is_array(...)));
    }

    /**
     * A value of the document as it is shown in a sentence: a string as it is, anything else as JSON.
     */
    private function scalar(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
