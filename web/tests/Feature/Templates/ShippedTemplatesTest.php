<?php

declare(strict_types=1);

use App\Inventories\Templates\Template;
use App\Inventories\Templates\TemplateCatalogue;
use App\Inventories\Templates\TemplateValidator;

require_once __DIR__.'/../../Support/templates.php';

/*
 * The templates the application ships (inventory-templates/): every file is judged by the validator, exactly as the
 * catalogue judges it when the application runs, and every error is named with its file. A file that fails here is a file
 * the catalogue would silently leave out. The folder may be empty (the test then has nothing to judge).
 */

/**
 * The shipped template files, by slug.
 *
 * @return array<string, string> Path of each file.
 */
function shippedTemplateFiles(): array
{
    $files = [];

    foreach (glob(base_path('inventory-templates').'/*.json') ?: [] as $file) {
        // The catalogue does not look at a file whose name starts with an underscore: a draft is not judged here either.
        if (TemplateCatalogue::slugOf(basename($file)) !== null) {
            $files[basename($file, '.json')] = $file;
        }
    }

    ksort($files);

    return $files;
}

/**
 * The decoded content of a shipped file, or null when it is not valid JSON.
 *
 * @return array<string, mixed>|null
 */
function shippedTemplateData(string $file): ?array
{
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : null;
}

it('has only templates the validator accepts', function (): void {
    $problems = [];

    foreach (shippedTemplateFiles() as $slug => $file) {
        $data = shippedTemplateData($file);

        if ($data === null) {
            $problems[] = "inventory-templates/{$slug}.json: the file is not valid JSON ({$file}).";

            continue;
        }

        foreach (app(TemplateValidator::class)->validate($slug, $data) as $error) {
            $problems[] = "inventory-templates/{$slug}.json: {$error}";
        }
    }

    expect($problems)->toBe([], "These shipped templates are not valid:\n".implode("\n", $problems));
});

it('has no file the catalogue leaves out', function (): void {
    templateCatalogueFrom(base_path('inventory-templates'));

    $loaded = array_map(fn (Template $template): string => $template->slug, app(TemplateCatalogue::class)->all());

    expect($loaded)->toBe(array_keys(shippedTemplateFiles()));
});

it('follows the editorial guidelines of the catalogue', function (): void {
    $problems = [];

    foreach (shippedTemplateFiles() as $slug => $file) {
        $data = shippedTemplateData($file);

        if ($data === null) {
            continue;
        }

        $fields = count($data['fields'] ?? []);

        if ($fields < 6 || $fields > 14) {
            $problems[] = "inventory-templates/{$slug}.json: a template has 6 to 14 fields ({$fields} found).";
        }

        foreach ((array) ($data['description'] ?? []) as $locale => $paragraphs) {
            $count = is_array($paragraphs) ? count($paragraphs) : 0;

            if ($count < 2 || $count > 3) {
                $problems[] = "inventory-templates/{$slug}.json: the description in {$locale} has 2 to 3 paragraphs ({$count} found).";
            }
        }

        foreach ((array) ($data['examples'] ?? []) as $locale => $rows) {
            $count = is_array($rows) ? count($rows) : 0;

            if ($count !== 3) {
                $problems[] = "inventory-templates/{$slug}.json: 3 examples are expected in {$locale} ({$count} found).";
            }
        }
    }

    expect($problems)->toBe([], "These shipped templates do not follow the guidelines:\n".implode("\n", $problems));
});
