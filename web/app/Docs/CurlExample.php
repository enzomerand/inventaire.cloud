<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Writes the `curl` command that calls an operation, for the page `/docs/api`: the address of the instance, the method, the
 * token (unless the operation is public) and, when the operation takes a body, a skeleton of it made of its required properties.
 *
 * The identifiers of the path are uppercase placeholders (`WORKSPACE_ID`) and not `{workspace}`: `curl` reads braces as a
 * list of alternatives, so the command with a brace in it would not call the address it shows.
 */
final class CurlExample
{
    /** How deep a skeleton goes into objects and lists before leaving them empty. */
    private const MAX_DEPTH = 3;

    /** The text that stands for the value of the token. */
    private const TOKEN = 'inv_…';

    /**
     * Gets the document, to follow the references, and the describer that lists the properties of an object schema.
     */
    public function __construct(private readonly OpenApi $api, private readonly SchemaDescriber $schemas) {}

    /**
     * The command for an operation as {@see OpenApi::operations()} describes it.
     *
     * @param  array{method: string, path: string, ability: string|null, operation: array<string, mixed>, parameters: list<array<string, mixed>>}  $operation
     */
    public function for(array $operation): string
    {
        $arguments = [];

        if ($operation['method'] !== 'get') {
            $arguments[] = '-X '.strtoupper($operation['method']);
        }

        if (($operation['operation']['security'] ?? null) !== []) {
            $arguments[] = '-H "Authorization: Bearer '.self::TOKEN.'"';
        }

        $body = $operation['operation']['requestBody']['content'] ?? null;

        if (is_array($body)) {
            array_push($arguments, ...$this->body($body));
        }

        $arguments[] = $this->address($operation);

        // One line when it fits in one, else one argument a line.
        $one = 'curl '.implode(' ', $arguments);

        return mb_strlen($one) <= 100 ? $one : "curl \\\n  ".implode(" \\\n  ", $arguments);
    }

    /**
     * The address of an operation with the placeholders of its path, quoted when it has a query string.
     *
     * @param  array{path: string, parameters: list<array<string, mixed>>}  $operation
     */
    private function address(array $operation): string
    {
        $names = [];

        foreach ($operation['parameters'] as $parameter) {
            if (($parameter['in'] ?? null) === 'path' && is_string($parameter['name'] ?? null)) {
                $schema = is_array($parameter['schema'] ?? null) ? $parameter['schema'] : [];
                $names[$parameter['name']] = strtoupper($parameter['name']).($this->api->schemaName($schema) === 'Ulid' ? '_ID' : '');
            }
        }

        $path = (string) preg_replace_callback('/\{([^}]+)\}/', fn (array $match): string => $names[$match[1]] ?? strtoupper($match[1]), $operation['path']);

        return $this->api->baseUrl().$path;
    }

    /**
     * The arguments that send a body: `-d` for JSON, `-F` for a form.
     *
     * @param  array<string, mixed>  $content  The media types of the body, with their schemas.
     * @return list<string>
     */
    private function body(array $content): array
    {
        $schema = $content['application/json']['schema'] ?? null;

        if (is_array($schema)) {
            $json = json_encode($this->sample($schema, 0), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return ['-H "Content-Type: application/json"', "-d '".str_replace("'", "'\\''", $json)."'"];
        }

        $schema = $content['multipart/form-data']['schema'] ?? null;

        if (! is_array($schema)) {
            return [];
        }

        $arguments = [];
        $flat = $this->schemas->flatten($schema, false);

        foreach ($flat['properties'] as $name => $property) {
            if (! in_array((string) $name, $flat['required'], true) || ! is_array($property)) {
                continue;
            }

            $resolved = $this->api->resolve($property);
            $value = ($resolved['format'] ?? null) === 'binary' ? '@FILE' : $this->text($this->sample($property, 1));
            // One word for the shell, whatever the value holds (a quote, a `$`...), as `-d` is above.
            $arguments[] = '-F '.escapeshellarg($name.'='.$value);
        }

        return $arguments;
    }

    /**
     * A value that fits a schema, as short as can be: the example the schema gives, else its default, its first allowed value,
     * else a placeholder of its type. An object holds its required properties (the first one when none is required).
     *
     * @param  array<string, mixed>  $schema
     */
    private function sample(array $schema, int $depth): mixed
    {
        $schema = $this->api->resolve($schema);

        foreach (['example', 'const', 'default'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return $schema[$keyword];
            }
        }

        if (is_array($schema['examples'] ?? null) && $schema['examples'] !== []) {
            return array_values($schema['examples'])[0];
        }

        if (is_array($schema['enum'] ?? null) && $schema['enum'] !== []) {
            return array_values($schema['enum'])[0];
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $variant) {
                if (is_array($variant) && ($variant['type'] ?? null) !== 'null') {
                    return $this->sample($variant, $depth);
                }
            }
        }

        $type = $schema['type'] ?? null;
        $type = is_array($type) ? (array_values(array_diff($type, ['null']))[0] ?? 'null') : $type;

        if ($type === 'object' || isset($schema['properties']) || isset($schema['allOf'])) {
            return $this->object($schema, $depth);
        }

        return match ($type) {
            'array' => is_array($schema['items'] ?? null) && $depth < self::MAX_DEPTH ? [$this->sample($schema['items'], $depth + 1)] : [],
            'integer', 'number' => $schema['minimum'] ?? 1,
            'boolean' => true,
            'null' => null,
            default => $this->string($schema),
        };
    }

    /**
     * A skeleton of an object: its required properties, or its first when none is.
     *
     * @param  array<string, mixed>  $schema
     */
    private function object(array $schema, int $depth): object
    {
        $flat = $this->schemas->flatten($schema, false);
        $names = array_values(array_filter(array_keys($flat['properties']), fn (int|string $name): bool => in_array((string) $name, $flat['required'], true)));

        if ($names === [] && $flat['properties'] !== []) {
            $names = [array_key_first($flat['properties'])];
        }

        $object = [];

        foreach ($names as $name) {
            $property = $flat['properties'][$name];

            if (is_array($property) && $depth < self::MAX_DEPTH) {
                $object[(string) $name] = $this->sample($property, $depth + 1);
            }
        }

        return (object) $object;
    }

    /**
     * A placeholder string for a schema of type string: one that looks like its format.
     *
     * @param  array<string, mixed>  $schema
     */
    private function string(array $schema): string
    {
        return match ($schema['format'] ?? null) {
            'date' => '2026-01-31',
            'date-time' => '2026-01-31T09:00:00Z',
            'uri' => 'https://example.com',
            'email' => 'name@example.com',
            default => '…',
        };
    }

    /**
     * A sample as the text of a form field.
     */
    private function text(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
