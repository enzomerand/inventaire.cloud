<?php

declare(strict_types=1);

namespace App\Docs;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\InlinesOnly\InlinesOnlyExtension;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\MarkdownConverter;

/**
 * Everything the page `/docs/api` shows, drawn from the OpenAPI document and ready for the template: the introduction, then
 * the operations under their tags, then the schemas.
 *
 * The texts of the document (in Markdown, written in English and translated by {@see OpenApiTranslation} where a translation exists) are
 * rendered here, with HTML in them removed and unsafe links refused:
 * the file is ours, but the rule is the same for any text that reaches a page. They come out as {@see HtmlString}, which the
 * template writes as they are; everything else is plain text, which the template escapes.
 *
 * @phpstan-import-type Token from SchemaDescriber
 * @phpstan-import-type Row from SchemaDescriber
 *
 * @phpstan-type RenderedRow array{name: string, type: list<Token>, required: bool, description: HtmlString, details: list<string>}
 * @phpstan-type Body array{mediaType: string, type: list<Token>, rows: list<RenderedRow>}
 */
final class ApiReference
{
    /** What the Markdown converters are told: no raw HTML, and no `javascript:` links. */
    private const MARKDOWN = ['html_input' => 'strip', 'allow_unsafe_links' => false];

    /** The converter of whole texts, made when first needed (`Str::markdown()` builds one for each text, which a page of 700 texts feels). */
    private ?GithubFlavoredMarkdownConverter $blocks = null;

    /** The converter of the texts that stay inside a line. */
    private ?MarkdownConverter $inlines = null;

    /**
     * Gets the document and the helpers that describe its schemas and write its commands.
     *
     * @param  OpenApi|null  $source  The document as the file writes it, when `$api` is a translation of it: the anchors of the
     *                                introduction are made from its headings, so that a link to one leads to it in every language.
     */
    public function __construct(
        private readonly OpenApi $api,
        private readonly SchemaDescriber $schemas,
        private readonly CurlExample $curl,
        private readonly ?OpenApi $source = null,
    ) {}

    /**
     * The same page drawn from the document read in a language ({@see OpenApi::forLocale()}): the texts are those of its
     * translation, when it has one.
     */
    public function forLocale(string $language): self
    {
        $api = $this->api->forLocale($language);
        $schemas = new SchemaDescriber($api);

        return new self($api, $schemas, new CurlExample($api, $schemas), $this->api);
    }

    /**
     * The whole page.
     *
     * @return array{
     *     version: string,
     *     language: string,
     *     baseUrl: string,
     *     intro: HtmlString,
     *     introHeadings: list<array{id: string, text: string}>,
     *     tags: list<array{id: string, name: string, description: HtmlString, operations: list<array<string, mixed>>}>,
     *     schemas: list<array<string, mixed>>
     * }
     */
    public function page(): array
    {
        $document = $this->api->raw();
        $info = is_array($document['info'] ?? null) ? $document['info'] : [];
        $original = ($this->source ?? $this->api)->raw()['info']['description'] ?? '';
        [$intro, $headings] = $this->introduction((string) ($info['description'] ?? ''), is_string($original) ? $original : '');

        $tags = [];
        $descriptions = array_column($this->api->tags(), 'description', 'name');

        foreach ($this->api->operationsByTag() as $name => $operations) {
            $tags[] = [
                'id' => 'tag-'.Str::slug($name),
                'name' => $name,
                'description' => $this->inline($descriptions[$name] ?? ''),
                'operations' => array_map($this->operation(...), $operations),
            ];
        }

        $schemas = [];

        foreach ($this->api->schemas() as $name => $schema) {
            $description = $this->schemas->describe((string) $name, $schema);

            $schemas[] = [
                'id' => self::schemaId((string) $name),
                'documentation' => $this->markdown($description['description']),
                'rows' => $this->rendered($description['rows']),
                'variants' => array_map(fn (array $variant): array => [
                    'documentation' => $this->markdown($variant['description']),
                    'rows' => $this->rendered($variant['rows']),
                ] + $variant, $description['variants']),
            ] + $description;
        }

        return [
            'version' => (string) ($info['version'] ?? ''),
            'language' => $this->api->language(),
            'baseUrl' => $this->api->baseUrl(),
            'intro' => $intro,
            'introHeadings' => $headings,
            'tags' => $tags,
            'schemas' => $schemas,
        ];
    }

    /**
     * The anchor of a schema.
     */
    public static function schemaId(string $name): string
    {
        return 'schema-'.$name;
    }

    /**
     * What the page shows of one operation.
     *
     * @param  array{method: string, path: string, operationId: string, ability: string|null, summary: string, operation: array<string, mixed>, parameters: list<array<string, mixed>>}  $entry
     * @return array{id: string, method: string, path: string, summary: string, ability: string|null, description: HtmlString|null, parameters: list<array<string, mixed>>, body: array{required: bool, contents: list<Body>}|null, responses: list<array<string, mixed>>, curl: string}
     */
    private function operation(array $entry): array
    {
        $operation = $entry['operation'];
        $description = $operation['description'] ?? null;

        return [
            'id' => $entry['operationId'],
            'method' => strtoupper($entry['method']),
            'path' => $entry['path'],
            'summary' => $entry['summary'],
            'ability' => $entry['ability'],
            'description' => is_string($description) && $description !== '' ? $this->markdown($description) : null,
            'parameters' => array_map($this->parameter(...), $entry['parameters']),
            'body' => $this->requestBody($operation),
            'responses' => $this->responses($operation),
            'curl' => $this->curl->for($entry),
        ];
    }

    /**
     * What the page shows of a parameter.
     *
     * @param  array<string, mixed>  $parameter
     * @return array{name: string, in: string, type: list<Token>, required: bool, description: HtmlString, details: list<string>}
     */
    private function parameter(array $parameter): array
    {
        $schema = is_array($parameter['schema'] ?? null) ? $parameter['schema'] : [];

        return [
            'name' => (string) ($parameter['name'] ?? ''),
            'in' => (string) ($parameter['in'] ?? ''),
            'type' => $this->schemas->type($schema),
            'required' => ($parameter['required'] ?? false) === true,
            'description' => $this->inline((string) ($parameter['description'] ?? '')),
            'details' => $this->schemas->details($schema),
        ];
    }

    /**
     * The body an operation takes: each media type with the type of the body and the table of its properties.
     *
     * @param  array<string, mixed>  $operation
     * @return array{required: bool, contents: list<Body>}|null
     */
    private function requestBody(array $operation): ?array
    {
        $body = $operation['requestBody'] ?? null;

        if (! is_array($body)) {
            return null;
        }

        $body = $this->api->resolve($body);

        return [
            'required' => ($body['required'] ?? false) === true,
            'contents' => $this->contents($body, true),
        ];
    }

    /**
     * The answers of an operation, each with its description, the names of its headers, its body and the codes it can carry.
     *
     * @param  array<string, mixed>  $operation
     * @return list<array{status: string, description: HtmlString, headers: list<string>, contents: list<Body>, codes: list<array{code: string, description: HtmlString}>}>
     */
    private function responses(array $operation): array
    {
        $responses = [];

        foreach ($this->api->responses($operation) as [$status, $response]) {
            $headers = array_map(strval(...), array_keys(is_array($response['headers'] ?? null) ? $response['headers'] : []));
            $codes = [];

            foreach (is_array($response['x-codes'] ?? null) ? $response['x-codes'] : [] as $code) {
                if (is_array($code) && isset($code['code'])) {
                    $codes[] = ['code' => (string) $code['code'], 'description' => $this->inline((string) ($code['description'] ?? ''))];
                }
            }

            $responses[] = [
                'status' => $status,
                'description' => $this->inline((string) ($response['description'] ?? '')),
                'headers' => $headers,
                'contents' => $this->contents($response, false),
                'codes' => $codes,
            ];
        }

        return $responses;
    }

    /**
     * The media types of a request body or of a response, with the type of each and, for a body written in place, the table of
     * its properties. A body that is a named schema is a link: the schema has its own entry.
     *
     * @param  array<string, mixed>  $holder  The request body or the response.
     * @param  bool  $alwaysRows  Whether to list the properties of a named schema too.
     * @return list<Body>
     */
    private function contents(array $holder, bool $alwaysRows): array
    {
        $contents = [];

        foreach (is_array($holder['content'] ?? null) ? $holder['content'] : [] as $mediaType => $content) {
            $schema = is_array($content) && is_array($content['schema'] ?? null) ? $content['schema'] : [];
            $named = $this->api->schemaName($schema) !== null;

            $contents[] = [
                'mediaType' => (string) $mediaType,
                'type' => $this->schemas->type($schema),
                'rows' => $named && ! $alwaysRows ? [] : $this->rendered($this->schemas->rows($schema)),
            ];
        }

        return $contents;
    }

    /**
     * The rows of a table of properties with their descriptions rendered.
     *
     * @param  list<Row>  $rows
     * @return list<RenderedRow>
     */
    private function rendered(array $rows): array
    {
        return array_map(fn (array $row): array => ['description' => $this->inline($row['description'])] + $row, $rows);
    }

    /**
     * The introduction: the description of the document as HTML, and the headings of its sections, which get an anchor.
     *
     * The anchor is made from the heading of the same section in the original text, when this one is a translation of it: the
     * page is one address for every language, so `#intro-rate-limits` must lead to the same section whatever the language of
     * whoever follows the link.
     *
     * @param  string  $original  The description as the file writes it.
     * @return array{0: HtmlString, 1: list<array{id: string, text: string}>}
     */
    private function introduction(string $markdown, string $original): array
    {
        $headings = [];
        $sections = [];

        if ($original !== $markdown) {
            preg_match_all('#<h2>(.*?)</h2>#s', $this->markdown($original)->toHtml(), $titles);
            $sections = array_map($this->plainText(...), $titles[1]);
        }

        $html = (string) preg_replace_callback('#<h2>(.*?)</h2>#s', function (array $match) use (&$headings, $sections): string {
            $text = $this->plainText($match[1]);
            $id = 'intro-'.Str::slug($sections[count($headings)] ?? $text);
            $headings[] = ['id' => $id, 'text' => $text];

            return '<h2 id="'.e($id).'">'.$match[1].'</h2>';
        }, $this->markdown($markdown)->toHtml());

        return [new HtmlString($html), $headings];
    }

    /**
     * What a piece of HTML says, with no markup.
     */
    private function plainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * A text of the document with its Markdown rendered as blocks, the way {@see Str::markdown()} writes it.
     */
    private function markdown(string $text): HtmlString
    {
        $this->blocks ??= new GithubFlavoredMarkdownConverter(self::MARKDOWN);

        return new HtmlString((string) $this->blocks->convert($text));
    }

    /**
     * A short text of the document (a summary, the description of a parameter) with its Markdown rendered inside a line.
     */
    private function inline(string $text): HtmlString
    {
        if ($this->inlines === null) {
            $environment = new Environment(self::MARKDOWN);
            $environment->addExtension(new GithubFlavoredMarkdownExtension);
            $environment->addExtension(new InlinesOnlyExtension);
            $this->inlines = new MarkdownConverter($environment);
        }

        return new HtmlString((string) $this->inlines->convert($text));
    }
}
