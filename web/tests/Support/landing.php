<?php

declare(strict_types=1);

// Helpers shared by the tests of the sales pages (the home page and the pricing page). Each test file loads them with require_once, so a
// file can be run alone. Their names all start with "landing" so that they never collide with the helpers of tests/Pest.php or of other
// test files.

use Illuminate\Testing\TestResponse;

require_once __DIR__.'/templates.php';

/**
 * The markup of a page without what is not its own copy: the pictures (`data-ui`: the example rows of the templates, which are made of
 * whatever the template files say, a "MacBook Pro" included) and the messages the layout hands to the scripts of every page of the
 * application (`#client-messages`, which holds the strings of the billing page whether the instance sells a plan or not). What is left is
 * the markup, the links and the structured data the sales pages write.
 */
function landingMarkup(TestResponse $response): string
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    libxml_clear_errors();

    $remove = [];

    foreach ((new DOMXPath($dom))->query('//*[@data-ui] | //script[@id="client-messages"]') ?: [] as $node) {
        $remove[] = $node;
    }

    foreach ($remove as $node) {
        $node->parentNode?->removeChild($node);
    }

    return (string) $dom->saveHTML();
}

/**
 * The text a visitor reads on a page, apart from the pictures ({@see landingMarkup()}): scripts, styles and comments dropped, tags
 * removed, entities decoded, spaces (the non-breaking ones too) squeezed.
 */
function landingText(TestResponse $response): string
{
    $html = (string) preg_replace('#<(script|style)\b.*?</\1>#si', ' ', landingMarkup($response));
    $text = html_entity_decode(strip_tags((string) preg_replace('/<!--.*?-->/s', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
}

/**
 * The structured data of a page, decoded, one entry for each script (an invalid one fails the test).
 *
 * @return list<array<string, mixed>>
 */
function landingJsonLd(TestResponse $response): array
{
    $found = [];

    foreach (templateDom($response)->query('//script[@type="application/ld+json"]') ?: [] as $script) {
        $found[] = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
    }

    return $found;
}

/**
 * The structured data of a page of one `@type`.
 *
 * @return array<string, mixed>
 */
function landingJsonLdOf(TestResponse $response, string $type): array
{
    foreach (landingJsonLd($response) as $data) {
        if (($data['@type'] ?? null) === $type) {
            return $data;
        }
    }

    throw new RuntimeException("No structured data of type {$type}.");
}

/**
 * A text with its spaces squeezed the way {@see landingText()} does, for comparing with what a page shows.
 */
function landingSqueeze(string $text): string
{
    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
}
