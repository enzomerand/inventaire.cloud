<?php

declare(strict_types=1);

namespace App\Inventories\Templates;

use Collator;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * The templates the instance offers: one JSON file each, in the folder `inventories.templates_path`
 * (`inventory-templates/<slug>.json` by default).
 *
 * There is no table: the files are the catalogue, and a contribution is a pull request on one file. They are read the
 * first time the catalogue is asked for something and kept for the rest of the process (a folder of a few dozen small
 * files costs nothing to read), then read again if the configured folder changes.
 *
 * A file that cannot be used is left out and logged as a warning, never a failure of the page that asked: it is not
 * valid JSON, it is too big, or {@see TemplateValidator} found something wrong with it. The other templates are not
 * affected. A folder that does not exist is an empty catalogue, logged once.
 */
final class TemplateCatalogue
{
    /** Biggest file read, in bytes: a template is a few kilobytes of text. */
    public const MAX_FILE_SIZE = 262144;

    /** The folder the templates were read from, or null while nothing was read. */
    private ?string $loadedFrom = null;

    /** @var array<string, Template> */
    private array $templates = [];

    /**
     * Gets the validator every file goes through.
     */
    public function __construct(private readonly TemplateValidator $validator) {}

    /**
     * Every template, by slug.
     *
     * @return list<Template>
     */
    public function all(): array
    {
        $this->load();

        return array_values($this->templates);
    }

    /**
     * The template with this slug, or null when there is none.
     */
    public function find(string $slug): ?Template
    {
        $this->load();

        return $this->templates[$slug] ?? null;
    }

    /**
     * The templates by category, in the order of {@see TemplateCategories::KEYS} (a category with no template is left out),
     * each category sorted by name in the language, the accents counted after the letters and numbers as numbers.
     *
     * @return array<string, list<Template>>
     */
    public function grouped(string $locale): array
    {
        $collator = new Collator($locale);
        $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        $collator->setStrength(Collator::SECONDARY);

        $groups = [];

        foreach (TemplateCategories::KEYS as $category) {
            $templates = array_values(array_filter($this->all(), fn (Template $template): bool => $template->category === $category));

            if ($templates === []) {
                continue;
            }

            usort($templates, fn (Template $first, Template $second): int => $collator->compare($first->name($locale), $second->name($locale)) ?: strcmp($first->slug, $second->slug));
            $groups[$category] = $templates;
        }

        return $groups;
    }

    /**
     * Forgets what was read: the next question reads the folder again.
     */
    public function flush(): void
    {
        $this->loadedFrom = null;
        $this->templates = [];
    }

    /**
     * Reads the folder, unless it was read already.
     */
    private function load(): void
    {
        $path = config()->string('inventories.templates_path');

        if ($this->loadedFrom === $path) {
            return;
        }

        $this->loadedFrom = $path;
        $this->templates = [];

        $files = is_dir($path) ? scandir($path) : false;

        if ($files === false) {
            Log::warning('The folder of the inventory templates cannot be read: no template is offered.', ['path' => $path]);

            return;
        }

        sort($files);

        foreach ($files as $file) {
            if (! str_ends_with($file, '.json') || ! is_file($path.'/'.$file)) {
                continue;
            }

            $template = $this->read($path.'/'.$file, substr($file, 0, -5));

            if ($template !== null) {
                $this->templates[$template->slug] = $template;
            }
        }
    }

    /**
     * Reads and judges one file; null, and a warning, when it cannot be used.
     */
    private function read(string $file, string $slug): ?Template
    {
        $size = filesize($file);
        $json = $size !== false && $size <= self::MAX_FILE_SIZE ? file_get_contents($file) : false;

        if ($json === false) {
            Log::warning('An inventory template is ignored: the file cannot be read or is too big.', ['file' => $file, 'limit' => self::MAX_FILE_SIZE]);

            return null;
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Log::warning('An inventory template is ignored: the file is not valid JSON.', ['file' => $file, 'error' => $exception->getMessage()]);

            return null;
        }

        $errors = $this->validator->validate($slug, $data);

        if ($errors !== []) {
            Log::warning('An inventory template is ignored: the file is not valid.', ['file' => $file, 'errors' => $errors]);

            return null;
        }

        return is_array($data) ? Template::fromArray($slug, $data) : null;
    }
}
