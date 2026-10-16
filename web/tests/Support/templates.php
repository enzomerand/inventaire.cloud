<?php

declare(strict_types=1);

// Helpers shared by the tests of the inventory templates. Each test file loads them with require_once, so a file can be run
// alone. Their names all start with "template" so that they never collide with other helpers.

use App\Inventories\Templates\TemplateCatalogue;
use App\Inventories\Templates\TemplateValidator;
use Illuminate\Testing\TestResponse;

/**
 * Makes the catalogue read the fixtures of tests/Fixtures/templates (four small templates) instead of the shipped ones.
 */
function templateFixtures(): void
{
    templateCatalogueFrom(base_path('tests/Fixtures/templates'));
}

/**
 * Makes the catalogue read a folder, forgetting what it had read.
 */
function templateCatalogueFrom(string $path): void
{
    config(['inventories.templates_path' => $path]);
    app(TemplateCatalogue::class)->flush();
}

/**
 * A fixture, decoded, to be changed by a test.
 *
 * @return array<string, mixed>
 */
function templateData(string $slug = 'sample-shop'): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/templates/{$slug}.json")), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * What the validator finds wrong with some content.
 *
 * @return list<string>
 */
function templateErrors(mixed $data, string $slug = 'sample-shop'): array
{
    return app(TemplateValidator::class)->validate($slug, $data);
}

/**
 * Sets a value deep in a decoded template (a path with dots, numbers for list positions) and gives the template back.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function templateWith(array $data, string $path, mixed $value): array
{
    data_set($data, $path, $value);

    return $data;
}

/**
 * Removes a value deep in a decoded template and gives the template back.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function templateWithout(array $data, string $path): array
{
    $keys = explode('.', $path);
    $last = array_pop($keys);
    $cursor = &$data;

    foreach ($keys as $key) {
        $cursor = &$cursor[$key];
    }

    unset($cursor[$last]);

    return $data;
}

/**
 * Makes a fresh folder in the sandbox of the tests and gives its path; the caller removes it with templateRemoveFolder().
 */
function templateFolder(): string
{
    $path = storage_path('framework/testing/templates-'.bin2hex(random_bytes(4)));
    mkdir($path, 0777, true);

    return $path;
}

/**
 * Removes a folder made by templateFolder().
 */
function templateRemoveFolder(string $path): void
{
    foreach (scandir($path) ?: [] as $file) {
        if ($file !== '.' && $file !== '..') {
            unlink($path.'/'.$file);
        }
    }

    rmdir($path);
}

/**
 * The page of a response, ready to be queried with XPath.
 */
function templateDom(TestResponse $response): DOMXPath
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    libxml_clear_errors();

    return new DOMXPath($dom);
}

/**
 * The texts of the nodes an XPath query finds, with the spaces (the non-breaking ones too) squeezed.
 *
 * @return list<string>
 */
function templateTexts(DOMXPath $page, string $query): array
{
    $texts = [];

    foreach ($page->query($query) ?: [] as $node) {
        $texts[] = trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $node->textContent));
    }

    return $texts;
}

/**
 * The values of an attribute of the nodes an XPath query finds.
 *
 * @return list<string>
 */
function templateAttributes(DOMXPath $page, string $query, string $attribute): array
{
    $values = [];

    foreach ($page->query($query) ?: [] as $node) {
        if ($node instanceof DOMElement) {
            $values[] = $node->getAttribute($attribute);
        }
    }

    return $values;
}
