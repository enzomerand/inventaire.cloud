<?php

declare(strict_types=1);

namespace App\Landing;

use App\Inventories\Templates\Template;
use App\Inventories\Templates\TemplateCatalogue;
use App\Inventories\Templates\TemplateField;
use App\Inventories\Templates\TemplatePresenter;
use App\Services\LocaleResolver;
use App\Support\PublicUrls;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The use cases the home page lets a visitor pick from: a few of the templates of the catalogue, each with the grid its inventory would
 * have (its own columns and example rows) and, when it has a barcode, the scan that would fill a row.
 *
 * Nothing is invented: the names, the columns and the rows are those of the template files, in the language of the page, so what the
 * page shows is what "use this template" creates. A template the catalogue does not have is left out.
 *
 * Drawing the panels costs more than a request should pay (every template is rebuilt from the verdict on the files, then its example rows
 * are written out), so they are kept in the application cache for a day, one entry for each language. The key carries
 * {@see TemplateCatalogue::fingerprint()} (the files, the rules that judge them, the languages) and the language of the instance, so an edited
 * template needs no invalidation; only `inventaire:upgrade` forgets the entries ({@see self::forget()}), for what the key cannot see (the
 * lines under the names, the way a grid is drawn). A cache that fails does not fail the page: the panels are drawn again by each request.
 */
final class UseCases
{
    /** The templates offered, in the order of the list. */
    public const SLUGS = ['shop-stock', 'it-equipment', 'equipment-lending', 'home-inventory', 'wine-cellar', 'workshop-tools'];

    /** The most columns of a grid: a phone shows them in a scroller, a desktop shows them whole. */
    private const MAX_COLUMNS = 5;

    /** The most example rows of a grid. */
    private const MAX_ROWS = 4;

    /** How long the panels are kept, in seconds. */
    private const CACHE_TTL = 86400;

    /** The start of the key of a cache entry, with the form of what is kept: change it with the form. */
    private const CACHE_KEY_PREFIX = 'landing-use-cases:v1:';

    /**
     * Gets the catalogue, the presenter that turns the example rows of a template into cells, and the resolver that knows the languages.
     */
    public function __construct(
        private readonly TemplateCatalogue $catalogue,
        private readonly TemplatePresenter $presenter,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * The use cases that can be shown, in the language: for each, the template (`slug`, `name`, `fields`, `url`), the line that says what
     * it is for (`hook`), its grid (`columns`, `rows` of cells as {@see TemplatePresenter::examples()} writes them) and the barcode a
     * scan would read (`scan`: the row that has it and its value, or null when the grid has no barcode). `$locale` is the language of the
     * application: the lines under the names are translated with it.
     *
     * @return list<array{slug: string, name: string, hook: string, fields: int, url: string, columns: list<string>, rows: list<list<array<string, mixed>>>, scan: array{row: int, value: string}|null}>
     */
    public function panels(string $locale): array
    {
        $drawn = null;

        try {
            /** @var list<array{slug: string, name: string, hook: string, fields: int, url: string, columns: list<string>, rows: list<list<array<string, mixed>>>, scan: array{row: int, value: string}|null}> */
            return Cache::remember($this->cacheKey($locale), self::CACHE_TTL, function () use ($locale, &$drawn): array {
                $drawn = $this->draw($locale);

                return $drawn;
            });
        } catch (Throwable $exception) {
            Log::warning('The cache of the use cases of the home page cannot be used: they are drawn again by each request until it can.', ['exception' => $exception]);

            return $drawn ?? $this->draw($locale);
        }
    }

    /**
     * The key of the cache entry of the panels in a language.
     */
    public function cacheKey(string $locale): string
    {
        return self::CACHE_KEY_PREFIX.$locale.':'.config()->string('app.default_locale').':'.$this->catalogue->fingerprint();
    }

    /**
     * Forgets the panels kept in the cache, in every language, for the templates as they are now: the next request draws them again.
     * `inventaire:upgrade` does it, because the new version of the code may draw them differently without the key having told.
     */
    public function forget(): void
    {
        foreach ($this->locales->supported() as $locale) {
            Cache::forget($this->cacheKey($locale));
        }
    }

    /**
     * Draws the panels from the templates of the catalogue.
     *
     * @return list<array{slug: string, name: string, hook: string, fields: int, url: string, columns: list<string>, rows: list<list<array<string, mixed>>>, scan: array{row: int, value: string}|null}>
     */
    private function draw(string $locale): array
    {
        $panels = [];

        foreach (self::SLUGS as $slug) {
            $template = $this->catalogue->find($slug);

            if ($template === null) {
                continue;
            }

            $examples = $this->presenter->examples($template, $locale);
            $keep = $this->columnsToKeep($examples['rows'], $this->columnsWithoutMoney($template, $locale, $examples['columns']));
            $rows = array_map(
                fn (array $row): array => array_values(array_intersect_key($row, array_flip($keep))),
                array_slice($examples['rows'], 0, self::MAX_ROWS),
            );

            $panels[] = [
                'slug' => $slug,
                'name' => $template->name($locale),
                'hook' => self::hook($slug, $template->summary($locale)),
                'fields' => $template->fieldsCount(),
                'url' => PublicUrls::templatePath($slug, $locale),
                'columns' => array_values(array_intersect_key($examples['columns'], array_flip($keep))),
                'rows' => $rows,
                'scan' => $this->scan($rows),
            ];
        }

        return $panels;
    }

    /**
     * The line under the name of a use case: what the template is for. The summary of the template is the fallback of a template that
     * has no line of its own.
     */
    private static function hook(string $slug, string $summary): string
    {
        return match ($slug) {
            'shop-stock' => __('EAN barcodes, cost and selling price, VAT, minimum stock.'),
            'it-equipment' => __('Serial numbers, assignee, warranty end, total spent.'),
            'equipment-lending' => __('Borrower, due date, deposit, photos.'),
            'home-inventory' => __('Room by room, with invoices, for insurance or a move.'),
            'wine-cellar' => __('Vintage, drinking window, bottles, label photo.'),
            'workshop-tools' => __('Serial number, storage spot, who borrowed what.'),
            default => $summary,
        };
    }

    /**
     * The positions of the columns an example grid may show: all of them but the amounts of money. The grid is a picture of what an
     * inventory looks like, and an amount in it (a cost, a selling price) would read as a price of this service, on a page that may have
     * none to tell.
     *
     * The columns of the examples are the fields of the template that at least one example row has a value for, in order
     * ({@see TemplatePresenter::examples()}); the type of a column is the one of that field.
     *
     * @param  list<string>  $columns  The names of the columns of the examples.
     * @return list<int>
     */
    private function columnsWithoutMoney(Template $template, string $locale, array $columns): array
    {
        $examples = $template->examples($locale);
        $fields = array_values(array_filter(
            $template->fields($locale),
            fn (TemplateField $field): bool => array_filter($examples, fn (array $row): bool => array_key_exists($field->key, $row)) !== [],
        ));

        // The columns could not be matched with the fields: show them all rather than guess.
        if (count($fields) !== count($columns)) {
            return array_keys($columns);
        }

        return array_keys(array_filter($fields, fn (TemplateField $field): bool => $field->type !== 'money'));
    }

    /**
     * The columns to show: the first one (the name), then one of each kind of cell (a code, a rating, badges, a plain value) so the grid
     * shows several types of field, then the others in order, up to the most a grid has.
     *
     * @param  list<list<array<string, mixed>>>  $rows  The example rows, all columns.
     * @param  list<int>  $allowed  The positions of the columns that may be shown.
     * @return list<int> The positions of the columns, in order.
     */
    private function columnsToKeep(array $rows, array $allowed): array
    {
        $kind = function (int $column) use ($rows): string {
            foreach ($rows as $row) {
                $found = $row[$column]['kind'] ?? 'empty';

                if ($found !== 'empty') {
                    return is_string($found) ? $found : 'text';
                }
            }

            return 'empty';
        };

        $keep = $allowed === [] ? [] : [$allowed[0]];
        $seen = $keep === [] ? [] : [$kind($keep[0])];

        foreach (array_slice($allowed, 1) as $column) {
            if (count($keep) < self::MAX_COLUMNS && $kind($column) !== 'empty' && ! in_array($kind($column), $seen, true)) {
                $keep[] = $column;
                $seen[] = $kind($column);
            }
        }

        foreach (array_slice($allowed, 1) as $column) {
            if (count($keep) < self::MAX_COLUMNS && ! in_array($column, $keep, true)) {
                $keep[] = $column;
            }
        }

        sort($keep);

        return $keep;
    }

    /**
     * The first row that has a barcode and the barcode, which is what the scan on the phone would read; null when the grid has none.
     *
     * @param  list<list<array<string, mixed>>>  $rows  The rows of the grid.
     * @return array{row: int, value: string}|null
     */
    private function scan(array $rows): ?array
    {
        foreach ($rows as $position => $row) {
            foreach ($row as $cell) {
                if (($cell['kind'] ?? null) === 'mono' && is_string($cell['text'] ?? null) && $cell['text'] !== '') {
                    return ['row' => $position, 'value' => $cell['text']];
                }
            }
        }

        return null;
    }
}
