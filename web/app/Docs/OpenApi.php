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
 *
 * The page can read the document in another language ({@see self::forLocale()}); the JSON the API serves stays English.
 */
#[Singleton]
final class OpenApi
{
    /** The keys of a path item that are operations, in the order a page lists them. */
    public const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** The language the file is written in: the one of every text of the document that has no translation. */
    public const SOURCE_LANGUAGE = 'en';

    /** The key of the cache entry: a pair `['hash' => hash of the content of the file, 'document' => the parsed file]`. */
    private const CACHE_KEY = 'openapi:v1';

    /** What a language must look like to be part of the name of a file (`fr`, `pt-BR`): nothing that could lead elsewhere. */
    private const LANGUAGE_PATTERN = '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*\z/';

    /** How many `$ref` are followed in a row before giving up (a reference to a reference to...). */
    private const MAX_REFERENCE_DEPTH = 16;

    /** @var array<string, mixed>|null The parsed file, once read by this process. */
    private ?array $parsed = null;

    /** @var string|null The hash of the content of the file the parsed document comes from. */
    private ?string $hash = null;

    /** @var array<string, array{hash: string, document: array<string, mixed>, translated: bool}> The document in each language asked for, once built by this process. */
    private array $translations = [];

    /** @var string The language of the texts of the parsed document. */
    private string $language = self::SOURCE_LANGUAGE;

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
     * The path of the file that would hold the translation of the texts into a language: next to the specification, with the
     * language before the extension (`v1.fr.yaml`). Null for a language that cannot be part of a file name.
     */
    public function translationPath(string $language): ?string
    {
        if (preg_match(self::LANGUAGE_PATTERN, $language) !== 1) {
            return null;
        }

        $path = $this->path();
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === '') {
            return $path.'.'.$language;
        }

        return substr($path, 0, -strlen($extension) - 1).'.'.$language.'.'.$extension;
    }

    /**
     * The same specification, read in a language: the specification with the texts of its translation into it
     * (`v1.<language>.yaml`, see {@see OpenApiTranslation}) in place of the English ones, or the specification itself when
     * the language is the one of the file or has no translation. {@see self::raw()}, {@see self::hash()} (the hash of both files,
     * and of the version of the rule that applies the translation), {@see self::operations()}, {@see self::resolve()} and the
     * other readers answer for that document, and {@see self::language()} says which language its texts are in. Meant for the
     * page that shows the document; the JSON the API serves comes from the shared instance, which stays English.
     */
    public function forLocale(string $language): self
    {
        $translation = $this->translation($language);

        $view = clone $this;
        $view->parsed = $translation['document'];
        $view->hash = $translation['hash'];
        $view->language = $translation['translated'] ? $language : self::SOURCE_LANGUAGE;
        $view->translations = [];

        return $view;
    }

    /**
     * The language the texts of this document are in: the one of the translation it was read with ({@see self::forLocale()}), the
     * one of the file when there is none.
     */
    public function language(): string
    {
        return $this->language;
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
     * The tags the document declares, in order. The name identifies a tag (the operations refer to it); the display name, which
     * `x-displayName` gives and a translation can change, is what a page shows (the name when there is none).
     *
     * @return list<array{name: string, displayName: string, description: string}>
     */
    public function tags(): array
    {
        $tags = [];

        foreach ($this->raw()['tags'] ?? [] as $tag) {
            if (is_array($tag) && isset($tag['name'])) {
                $tags[] = [
                    'name' => (string) $tag['name'],
                    'displayName' => (string) ($tag['x-displayName'] ?? $tag['name']),
                    'description' => (string) ($tag['description'] ?? ''),
                ];
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
        $content = $this->read($this->path(), 'document');
        $hash = $this->hash = hash('xxh128', $content);

        return $this->remembered(self::CACHE_KEY, $hash, fn (): array => $this->parse($content, $this->path(), 'document'));
    }

    /**
     * The document in a language, with the hash of the files it comes from and whether a translation was used (built once for
     * each language by a process).
     *
     * A translation that cannot be read or understood does not take the page down in its language: the warning says so, and the
     * page is the English one until the file is mended (the hash is the one of the specification alone, so it is drawn again then).
     *
     * @return array{hash: string, document: array<string, mixed>, translated: bool}
     */
    private function translation(string $language): array
    {
        if (isset($this->translations[$language])) {
            return $this->translations[$language];
        }

        $document = $this->raw();
        $fallback = ['hash' => $this->hash(), 'document' => $document, 'translated' => false];
        $path = $language === self::SOURCE_LANGUAGE ? null : $this->translationPath($language);

        if ($path === null || ! is_file($path)) {
            return $this->translations[$language] = $fallback;
        }

        try {
            $content = $this->read($path, 'translation');
            $hash = hash('xxh128', OpenApiTranslation::VERSION.'|'.$this->hash().'|'.$content);

            $localized = $this->remembered(
                self::CACHE_KEY.':'.$language,
                $hash,
                fn (): array => OpenApiTranslation::apply($document, $this->parse($content, $path, 'translation')),
            );
        } catch (Throwable $exception) {
            Log::warning('The translation of the OpenAPI document into '.$language.' cannot be used: the page is in English until it can.', ['path' => $path, 'exception' => $exception]);

            return $this->translations[$language] = $fallback;
        }

        /** @var array<string, mixed> $localized */
        return $this->translations[$language] = ['hash' => $hash, 'document' => $localized, 'translated' => true];
    }

    /**
     * The content of a file of the specification.
     *
     * @param  string  $what  What the file is, for the message.
     */
    private function read(string $path, string $what): string
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('The OpenAPI '.$what.' cannot be read: '.$path);
        }

        return $content;
    }

    /**
     * The mapping a file of the specification holds.
     *
     * @param  string  $what  What the file is, for the message.
     * @return array<string, mixed>
     */
    private function parse(string $content, string $path, string $what): array
    {
        $parsed = Yaml::parse($content);

        if (! is_array($parsed)) {
            throw new RuntimeException('The OpenAPI '.$what.' is not a mapping: '.$path);
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * What was made of this very content: taken from the cache, or built and kept there under a key of its own.
     *
     * @param  callable(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function remembered(string $key, string $hash, callable $build): array
    {
        try {
            $cached = Cache::get($key);
        } catch (Throwable $exception) {
            $this->cacheUnusable($exception);
            $cached = null;
        }

        // The entry of another version of the content (or a damaged one) is not this content's: build again and replace it.
        if (is_array($cached) && ($cached['hash'] ?? null) === $hash && is_array($cached['document'] ?? null)) {
            /** @var array<string, mixed> $document */
            $document = $cached['document'];

            return $document;
        }

        $document = $build();

        try {
            Cache::forever($key, ['hash' => $hash, 'document' => $document]);
        } catch (Throwable $exception) {
            $this->cacheUnusable($exception);
        }

        return $document;
    }

    /**
     * Says that the cache cannot be used: the document is then parsed by each process, which is only slower.
     */
    private function cacheUnusable(Throwable $exception): void
    {
        Log::warning('The cache of the OpenAPI document cannot be used: it is parsed again by each process until it can.', ['exception' => $exception]);
    }
}
