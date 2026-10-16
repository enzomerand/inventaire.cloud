<?php

declare(strict_types=1);

namespace App\Inventories\Templates;

use App\Services\LocaleResolver;
use Collator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * The templates the instance offers: one JSON file each, in the folder `inventories.templates_path`
 * (`inventory-templates/<slug>.json` by default).
 *
 * There is no table: the files are the catalogue, and a contribution is a pull request on one file. A file whose name
 * starts with `_` or `.` is not a template (a draft, a note, a hidden file) and is not even looked at.
 *
 * Judging the files (decoding them, building the fields of each language, validating the examples) costs more than
 * reading them, so the verdict is kept in the application cache for a day: the decoded templates that were accepted, as plain
 * arrays (the files that were left out are logged when the verdict is made, and are not kept). The key of the entry is a hash of what the
 * verdict depends on: the name and the content of every file, {@see TemplateValidator::VERSION} (the rules that judged
 * them) and the languages of the application. Editing a file, adding one, or a new version of the rules therefore needs no
 * invalidation: the next request finds no entry under the new key and judges again (the old entries expire by themselves),
 * and only `inventaire:upgrade` forgets the entry ({@see self::forget()}), for what the key cannot see (the registry of
 * the types of field, say). The templates themselves are rebuilt from the arrays at the first question of each process and
 * kept in memory for the rest of it, then read again if the configured folder changes.
 *
 * A file that cannot be used is left out and logged as a warning, never a failure of the page that asked: it is not
 * valid JSON, it is too big, it needs a newer version of the application, or {@see TemplateValidator} found something
 * wrong with it. The other templates are not affected. The warning is written when the verdict is made, not each time it
 * is read from the cache. A folder that does not exist is an empty catalogue, logged once per process.
 */
final class TemplateCatalogue
{
    /** Biggest file read, in bytes: a template is a few kilobytes of text. */
    public const MAX_FILE_SIZE = 262144;

    /** How long the verdict on the files is kept, in seconds. */
    public const CACHE_TTL = 86400;

    /** The start of the key of the cache entry, with the form of what is kept: change it with the form. */
    private const CACHE_KEY_PREFIX = 'inventory-templates:v2:';

    /** The folder the templates were read from, or null while nothing was read. */
    private ?string $loadedFrom = null;

    /** @var array<string, Template> */
    private array $templates = [];

    /**
     * Gets the validator every file goes through and the resolver that knows the supported languages.
     */
    public function __construct(
        private readonly TemplateValidator $validator,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * The slug a file of the folder is the template of, or null when it is not a template: not a `.json` file, or a name
     * that starts with `_` or `.`. Whether the slug is a valid one is for the validator to say.
     */
    public static function slugOf(string $file): ?string
    {
        if (! str_ends_with($file, '.json') || str_starts_with($file, '_') || str_starts_with($file, '.')) {
            return null;
        }

        return substr($file, 0, -5);
    }

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
     * Forgets what was read: the next question reads the folder again (the cache entry is kept).
     */
    public function flush(): void
    {
        $this->loadedFrom = null;
        $this->templates = [];
    }

    /**
     * Forgets what was read and the verdict kept in the cache for the files as they are now: the next question judges
     * them again. `inventaire:upgrade` does it, because the new version of the code may judge differently without
     * {@see TemplateValidator::VERSION} having told.
     */
    public function forget(): void
    {
        $files = $this->readFolder(config()->string('inventories.templates_path'));

        if ($files !== null) {
            Cache::forget($this->cacheKey($files));
        }

        $this->flush();
    }

    /**
     * Reads the folder and asks the cache for the verdict, unless the folder was read already.
     */
    private function load(): void
    {
        $path = config()->string('inventories.templates_path');

        if ($this->loadedFrom === $path) {
            return;
        }

        $this->flush();
        $this->loadedFrom = $path;

        $files = $this->readFolder($path);

        if ($files === null) {
            Log::warning('The folder of the inventory templates cannot be read: no template is offered.', ['path' => $path]);

            return;
        }

        /** @var array<string, array<string, mixed>> $verdict */
        $verdict = Cache::remember($this->cacheKey($files), self::CACHE_TTL, fn (): array => $this->judge($path, $files));

        foreach ($verdict as $slug => $data) {
            $this->templates[(string) $slug] = Template::fromArray((string) $slug, $data);
        }
    }

    /**
     * What the folder holds: the size and the content of each file that is a template, by file name, in the order of the
     * names. A file that is too big or cannot be read has no content.
     *
     * @return array<string, array{size: int|false, content: string|false}>|null Null when the folder cannot be read.
     */
    private function readFolder(string $path): ?array
    {
        $names = is_dir($path) ? scandir($path) : false;

        if ($names === false) {
            return null;
        }

        sort($names);

        $files = [];

        foreach ($names as $name) {
            if (self::slugOf($name) === null || ! is_file($path.'/'.$name)) {
                continue;
            }

            $size = filesize($path.'/'.$name);
            $files[$name] = ['size' => $size, 'content' => $size !== false && $size <= self::MAX_FILE_SIZE ? @file_get_contents($path.'/'.$name) : false];
        }

        return $files;
    }

    /**
     * The key of the cache entry: a hash of everything the verdict on the files depends on.
     *
     * The settings that do not change a verdict are not in it (`inventories.max_fields` and `app.default_locale` are not
     * looked at when a file is judged, so changing them must not judge again).
     *
     * @param  array<string, array{size: int|false, content: string|false}>  $files
     */
    private function cacheKey(array $files): string
    {
        $hash = hash_init('xxh128');

        hash_update($hash, 'validator:'.TemplateValidator::VERSION."\0locales:".implode(',', $this->locales->supported())."\0");

        foreach ($files as $name => $file) {
            // Lengths first, so that no content can be taken for the start of the next file.
            $content = $file['content'] === false ? '' : $file['content'];
            hash_update($hash, strlen($name).':'.$name.'|'.($file['size'] === false ? '-' : $file['size']).'|'.($file['content'] === false ? 'unread' : 'read').'|'.strlen($content).':'.$content."\0");
        }

        return self::CACHE_KEY_PREFIX.hash_final($hash);
    }

    /**
     * Judges every file: the templates that can be used, as the decoded content of their file. Each file that is left out is
     * logged here as a warning, which is to say once for each verdict, not each time it is read.
     *
     * @param  array<string, array{size: int|false, content: string|false}>  $files
     * @return array<string, array<string, mixed>>
     */
    private function judge(string $path, array $files): array
    {
        $templates = [];

        foreach ($files as $name => $file) {
            $slug = (string) self::slugOf($name);
            $location = $path.'/'.$name;

            if ($file['content'] === false) {
                Log::warning('An inventory template is ignored: the file cannot be read or is too big.', ['file' => $location, 'limit' => self::MAX_FILE_SIZE]);

                continue;
            }

            try {
                $data = json_decode($file['content'], true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                Log::warning('An inventory template is ignored: the file is not valid JSON.', ['file' => $location, 'error' => $exception->getMessage()]);

                continue;
            }

            $errors = $this->validator->validate($slug, $data);

            if ($errors !== []) {
                Log::warning('An inventory template is ignored: the file is not valid.', ['file' => $location, 'errors' => $errors]);
            } elseif (is_array($data)) {
                $templates[$slug] = $data;
            }
        }

        return $templates;
    }
}
