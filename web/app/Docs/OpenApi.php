<?php

declare(strict_types=1);

namespace App\Docs;

use App\Support\PublicUrls;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The OpenAPI 3.1 description of the public API (`resources/openapi/v1.yaml`), written by hand and checked by the contract
 * tests (`tests/Feature/Docs`), as an array.
 *
 * The file is parsed once and the result kept in the application cache, under one key, next to the hash of the content it was
 * made from: a new version of the file has another hash, is parsed again and replaces the entry, so nothing has to be cleared
 * and nothing piles up (`inventaire:upgrade` leaves the application cache alone on purpose). A cache that cannot be reached
 * does not fail the request: the file is parsed for this process only.
 *
 * Two consumers read it: `GET /api/v1/openapi.json` ({@see self::document()}) and the page `/docs/api` (the operations, and
 * the helpers that follow a `$ref` for rendering).
 */
#[Singleton]
final class OpenApi
{
    /** The keys of a path item that are operations, in the order a page lists them. */
    public const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** The key of the cache entry: a pair `['hash' => hash of the content of the file, 'document' => the parsed file]`. */
    private const CACHE_KEY = 'openapi:v1';

    /** How many `$ref` are followed in a row before giving up (a reference to a reference to...). */
    private const MAX_REFERENCE_DEPTH = 16;

    /** @var array<string, mixed>|null The parsed file, once read by this process. */
    private ?array $parsed = null;

    /** @var string|null The hash of the content of the file the parsed document comes from. */
    private ?string $hash = null;

    /**
     * @param  string|null  $path  The file to read; the specification shipped with the application when null.
     */
    public function __construct(private readonly ?string $path = null) {}

    /**
     * The document as it is served: the specification with `servers` rewritten to the absolute address of the API on this
     * instance (built from `APP_URL`, never from the `Host` header of the request, see {@see PublicUrls}).
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        $document = $this->raw();
        $description = $document['servers'][0]['description'] ?? null;

        $document['servers'] = [
            ['url' => $this->baseUrl()] + (is_string($description) ? ['description' => $description] : []),
        ];

        return $document;
    }

    /**
     * The address of the API, absolute and without a trailing slash.
     */
    public function baseUrl(): string
    {
        return PublicUrls::to('/api/v1');
    }

    /**
     * The document as it is written in the file.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->parsed ??= $this->load();
    }

    /**
     * The hash of the content of the file, which says whether what was made of the document (the page that shows it, for one) is still
     * what the file says.
     */
    public function hash(): string
    {
        $this->raw();

        return (string) $this->hash;
    }

    /**
     * The path of the file that holds the specification.
     */
    public function path(): string
    {
        return $this->path ?? resource_path('openapi/v1.yaml');
    }

    /**
     * Every operation, in the order of the file: the path item first, then its methods in the order of {@see self::METHODS}.
     *
     * `parameters` holds the parameters of the path item and of the operation (the operation's own win when both name the
     * same one), each with its `$ref` followed.
     *
     * @return list<array{method: string, path: string, operationId: string, tag: string, ability: string|null, summary: string, operation: array<string, mixed>, parameters: list<array<string, mixed>>}>
     */
    public function operations(): array
    {
        $operations = [];

        /** @var array<string, array<string, mixed>> $paths */
        $paths = $this->raw()['paths'] ?? [];

        foreach ($paths as $path => $item) {
            foreach (self::METHODS as $method) {
                $operation = $item[$method] ?? null;

                if (! is_array($operation)) {
                    continue;
                }

                $tags = $operation['tags'] ?? [];

                $operations[] = [
                    'method' => $method,
                    'path' => (string) $path,
                    'operationId' => (string) ($operation['operationId'] ?? ''),
                    'tag' => is_array($tags) && $tags !== [] ? (string) $tags[0] : '',
                    'ability' => isset($operation['x-ability']) ? (string) $operation['x-ability'] : null,
                    'summary' => (string) ($operation['summary'] ?? ''),
                    'operation' => $operation,
                    'parameters' => $this->parametersOf($item, $operation),
                ];
            }
        }

        return $operations;
    }

    /**
     * The operations by tag, the tags in the order the document declares them (a tag nobody declared comes last).
     *
     * @return array<string, list<array{method: string, path: string, operationId: string, tag: string, ability: string|null, summary: string, operation: array<string, mixed>, parameters: list<array<string, mixed>>}>>
     */
    public function operationsByTag(): array
    {
        $grouped = [];

        foreach ($this->tags() as $tag) {
            $grouped[$tag['name']] = [];
        }

        foreach ($this->operations() as $operation) {
            $grouped[$operation['tag']][] = $operation;
        }

        return array_filter($grouped, fn (array $operations): bool => $operations !== []);
    }

    /**
     * The tags the document declares, in order.
     *
     * @return list<array{name: string, description: string}>
     */
    public function tags(): array
    {
        $tags = [];

        foreach ($this->raw()['tags'] ?? [] as $tag) {
            if (is_array($tag) && isset($tag['name'])) {
                $tags[] = ['name' => (string) $tag['name'], 'description' => (string) ($tag['description'] ?? '')];
            }
        }

        return $tags;
    }

    /**
     * The schemas of `components.schemas`, by name, in the order of the file.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        /** @var array<string, array<string, mixed>> */
        return $this->raw()['components']['schemas'] ?? [];
    }

    /**
     * The responses of an operation as `[status, response]`, each response with its `$ref` followed.
     *
     * @param  array<string, mixed>  $operation
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public function responses(array $operation): array
    {
        $responses = [];

        foreach ($operation['responses'] ?? [] as $status => $response) {
            if (is_array($response)) {
                $responses[] = [(string) $status, $this->resolve($response)];
            }
        }

        return $responses;
    }

    /**
     * What a node is once its `$ref` is followed (`#/components/...` only: the document refers to nothing outside itself).
     * What the node says next to the `$ref` (a `description`, in a Schema Object) wins over what the target says.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function resolve(array $node): array
    {
        for ($depth = 0; isset($node['$ref']) && is_string($node['$ref']); $depth++) {
            if ($depth >= self::MAX_REFERENCE_DEPTH) {
                throw new RuntimeException('The reference '.$node['$ref'].' is too deep, or loops.');
            }

            $siblings = $node;
            unset($siblings['$ref']);

            $node = $siblings + $this->target($node['$ref']);
        }

        return $node;
    }

    /**
     * The name of the schema a node refers to (`Workspace` for `#/components/schemas/Workspace`), null for a node that is
     * not a reference to a schema.
     *
     * @param  array<string, mixed>  $node
     */
    public function schemaName(array $node): ?string
    {
        $ref = $node['$ref'] ?? null;

        if (is_string($ref) && str_starts_with($ref, '#/components/schemas/')) {
            return substr($ref, strlen('#/components/schemas/'));
        }

        return null;
    }

    /**
     * The node a JSON pointer of the document (`#/components/schemas/Error`) points to.
     *
     * @return array<string, mixed>
     */
    private function target(string $ref): array
    {
        if (! str_starts_with($ref, '#/')) {
            throw new RuntimeException('Only references inside the document are supported: '.$ref);
        }

        $node = $this->raw();

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new RuntimeException('The reference '.$ref.' points to nothing.');
            }

            $node = $node[$segment];
        }

        if (! is_array($node)) {
            throw new RuntimeException('The reference '.$ref.' does not point to an object.');
        }

        return $node;
    }

    /**
     * The parameters of an operation: those of its path item, then its own, each resolved.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $operation
     * @return list<array<string, mixed>>
     */
    private function parametersOf(array $item, array $operation): array
    {
        $parameters = [];

        foreach ([$item['parameters'] ?? [], $operation['parameters'] ?? []] as $list) {
            foreach ($list as $parameter) {
                if (! is_array($parameter)) {
                    continue;
                }

                $parameter = $this->resolve($parameter);
                $parameters[($parameter['in'] ?? '').':'.($parameter['name'] ?? '')] = $parameter;
            }
        }

        return array_values($parameters);
    }

    /**
     * Reads the file, or finds what was made of this very content in the cache.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $content = @file_get_contents($this->path());

        if ($content === false) {
            throw new RuntimeException('The OpenAPI document cannot be read: '.$this->path());
        }

        $hash = $this->hash = hash('xxh128', $content);

        try {
            $cached = Cache::get(self::CACHE_KEY);
        } catch (Throwable $exception) {
            $this->cacheUnusable($exception);
            $cached = null;
        }

        // The entry of another version of the file (or a damaged one) is not this content's: parse again and replace it.
        if (is_array($cached) && ($cached['hash'] ?? null) === $hash && is_array($cached['document'] ?? null)) {
            /** @var array<string, mixed> $document */
            $document = $cached['document'];

            return $document;
        }

        $parsed = Yaml::parse($content);

        if (! is_array($parsed)) {
            throw new RuntimeException('The OpenAPI document is not a mapping: '.$this->path());
        }

        try {
            Cache::forever(self::CACHE_KEY, ['hash' => $hash, 'document' => $parsed]);
        } catch (Throwable $exception) {
            $this->cacheUnusable($exception);
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * Says that the cache cannot be used: the document is then parsed by each process, which is only slower.
     */
    private function cacheUnusable(Throwable $exception): void
    {
        Log::warning('The cache of the OpenAPI document cannot be used: it is parsed again by each process until it can.', ['exception' => $exception]);
    }
}
