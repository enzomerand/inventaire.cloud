<?php

declare(strict_types=1);

use App\Inventories\Exceptions\PdfTooLargeException;
use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportGenerator;
use App\Inventories\Exports\ExportScope;
use App\Inventories\Exports\ExportStatus;
use App\Inventories\Exports\PdfColumns;
use App\Inventories\Exports\PdfWriter;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/exports.php';

/*
 * The PDF of an export: a real PDF with its pages, the page laid out as a table up to 8 fields and as cards beyond, the values
 * written the way the language writes them, and a limit on the items since the library lays the whole document out in memory.
 */

uses(RefreshDatabase::class);

/**
 * The HTML the PDF library lays out for an export (the page itself is compressed in the PDF, and cannot be read back).
 *
 * @param  array<string, mixed>  $attributes
 */
function pdfHtml(Inventory $inventory, User $user, array $attributes = []): string
{
    $export = exportAsked($inventory, $user, ExportFormat::Pdf, $attributes);
    $previous = app()->getLocale();
    app()->setLocale($export->locale);

    try {
        $text = (new PdfWriter)->html(app(ExportGenerator::class)->context($export));
    } finally {
        app()->setLocale($previous);
    }

    // The spaces ICU puts in numbers and amounts are not breaking: one kind is enough to compare.
    return str_replace(["\u{202F}", "\u{00A0}"], ' ', $text);
}

/**
 * The number of pages of a PDF.
 */
function pdfPages(string $pdf): int
{
    return preg_match_all('/\/Type\s*\/Page(?![a-z])/', $pdf);
}

/**
 * An inventory of four fields (a table), with a few items.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory}
 */
function pdfTableCase(int $items = 3): array
{
    attachmentDisk();
    config(['app.display_timezone' => 'Europe/Paris']);
    $team = inventoryTeam();
    $inventory = inventoryIn($team['workspace'], 'Tools');
    $name = fieldIn($inventory, 'text', 'Name');
    $price = fieldIn($inventory, 'money', 'Price', ['currency' => 'EUR']);
    $bought = fieldIn($inventory, 'date', 'Bought');
    $photos = fieldIn($inventory, 'photo', 'Photos', ['max_files' => 3]);

    for ($i = 1; $i <= $items; $i++) {
        $item = itemIn($inventory, [$name->ulid => "Tool {$i}", $price->ulid => (string) ($i * 1234.5), $bought->ulid => '2026-03-0'.min($i, 9)]);

        if ($i === 1) {
            attachmentTo($inventory, $item, attachmentImage('a.png'), $team['owner'], $photos);
            attachmentTo($inventory, $item, attachmentImage('b.png'), $team['owner'], $photos);
        }
    }

    return ['team' => $team, 'inventory' => $inventory];
}

describe('the file', function (): void {
    it('is a PDF with at least a page, written in A4 landscape', function (): void {
        $case = pdfTableCase();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Pdf));
        $pdf = (string) file_get_contents(exportLocalPath($export));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and(substr($pdf, 0, 5))->toBe('%PDF-')
            ->and(rtrim($pdf))->toEndWith('%%EOF')
            ->and(pdfPages($pdf))->toBe(1)
            // 842 x 595 points: A4 turned on its side.
            ->and($pdf)->toContain('/MediaBox [0.000 0.000 841.890 595.280]')
            ->and($pdf)->toContain('DejaVuSans')
            ->and($export->mime)->toBe('application/pdf')
            ->and($export->file_name)->toEndWith('.pdf')
            ->and($export->items_count)->toBe(3);
    });

    it('has more pages with more items', function (): void {
        $case = pdfTableCase(90);
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Pdf));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and(pdfPages((string) file_get_contents(exportLocalPath($export))))->toBeGreaterThan(2)
            ->and($export->items_count)->toBe(90);
    });

    it('is a PDF for an inventory without items', function (): void {
        $case = pdfTableCase(0);
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Pdf));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and(pdfPages((string) file_get_contents(exportLocalPath($export))))->toBe(1)
            ->and($export->items_count)->toBe(0);
    });

    it('writes its fonts cache under storage, and nothing in the code of the application', function (): void {
        $fonts = base_path('vendor/dompdf/dompdf/lib/fonts');
        $before = scandir($fonts);
        $case = pdfTableCase();

        exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Pdf));

        expect(scandir($fonts))->toBe($before)
            ->and(glob(storage_path('framework/dompdf/*.json')))->not->toBe([]);
    });

    it('refuses an inventory that grew past the items a PDF may hold since it was asked for, tells to filter the view, and keeps no file', function (): void {
        $case = pdfTableCase(3);
        config(['inventories.pdf_max_items' => 2]);
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Pdf));

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and($export->error)->toBe(ExportError::PdfTooLarge->value)
            ->and($export->failure())->toBe(ExportError::PdfTooLarge)
            ->and($export->failure()?->message())->toContain('Filtrez la vue')
            ->and($export->path)->toBeNull()
            ->and(array_filter(storedFiles(), fn (string $file): bool => str_contains($file, '/exports/')))->toBe([]);
    });

    it('does not take a PDF that is too large for a failure of the server: nothing in the log as an error', function (): void {
        Log::spy();
        $case = pdfTableCase(3);
        config(['inventories.pdf_max_items' => 2]);

        exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Pdf));

        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'too large'))->once();
    });

    it('refuses the HTML the library could not lay out in the memory of a worker, before it reads a byte of it', function (): void {
        // The limits come from measures of the memory of the library (see PdfWriter): a table of 8 fields, 1 MB; cards, 4 MiB.
        PdfWriter::guard(str_repeat('a', PdfWriter::MAX_TABLE_HTML_BYTES), 8);
        PdfWriter::guard(str_repeat('a', PdfWriter::MAX_CARDS_HTML_BYTES), 9);

        expect(fn () => PdfWriter::guard(str_repeat('a', PdfWriter::MAX_TABLE_HTML_BYTES + 1), 8))->toThrow(PdfTooLargeException::class)
            ->and(fn () => PdfWriter::guard(str_repeat('a', PdfWriter::MAX_TABLE_HTML_BYTES + 1), 1))->toThrow(PdfTooLargeException::class)
            ->and(fn () => PdfWriter::guard(str_repeat('a', PdfWriter::MAX_CARDS_HTML_BYTES + 1), 9))->toThrow(PdfTooLargeException::class)
            ->and(fn () => PdfWriter::guard(str_repeat('a', PdfWriter::MAX_CARDS_HTML_BYTES + 1), 100))->toThrow(PdfTooLargeException::class)
            // Cards are allowed more than a table: their memory does not grow as fast with the text.
            ->and(PdfWriter::MAX_CARDS_HTML_BYTES)->toBeGreaterThan(PdfWriter::MAX_TABLE_HTML_BYTES);
    });

    it('fails with the error of a PDF too large when the library gets more HTML than it may, with nothing kept', function (): void {
        // 8 text fields with 600 items: the cells are cut at 120 characters, which is about 1.4 KB an item of HTML.
        attachmentDisk();
        config(['inventories.pdf_max_items' => 5000]);
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Big');
        $fields = [];

        for ($number = 1; $number <= 8; $number++) {
            $fields[] = fieldIn($inventory, 'textarea', "Notes {$number}");
        }

        $text = str_repeat('x', 150);
        $values = collect($fields)->mapWithKeys(fn (InventoryField $field): array => [$field->ulid => $text])->all();

        for ($number = 1; $number <= 800; $number++) {
            Item::factory()->create(['inventory_id' => $inventory->getKey(), 'values' => $values, 'search_text' => '']);
        }

        $export = exportRun(exportAsked($inventory, $team['owner'], ExportFormat::Pdf));

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and($export->error)->toBe(ExportError::PdfTooLarge->value)
            ->and($export->path)->toBeNull()
            ->and(array_filter(storedFiles(), fn (string $file): bool => str_contains($file, '/exports/')))->toBe([]);
    });

    it('limits the items in proportion to the fields beyond 8, since memory goes with the number of values', function (): void {
        config(['inventories.pdf_max_items' => 500]);

        expect(PdfWriter::maxItems(1))->toBe(500)
            ->and(PdfWriter::maxItems(8))->toBe(500)
            ->and(PdfWriter::maxItems(12))->toBe(333)
            ->and(PdfWriter::maxItems(20))->toBe(200)
            ->and(PdfWriter::maxItems(100))->toBe(40)
            ->and(PdfWriter::maxItems(5000))->toBe(1);
    });
});

describe('the page', function (): void {
    it('has a header, and a table of the items up to 8 fields', function (): void {
        $case = pdfTableCase();
        $case['inventory']->forceFill(['description' => 'What is in the shed'])->save();
        $html = pdfHtml($case['inventory'], $case['team']['owner']);

        expect($html)->toContain('<h1>Tools</h1>')
            ->and($html)->toContain('What is in the shed')
            ->and($html)->toContain('3 items')
            ->and($html)->toMatch('#<th class="" style="width: [\d.]+%">Name</th>#')
            ->and($html)->toMatch('#<th class="number" style="width: [\d.]+%">Price \(EUR\)</th>#')
            ->and($html)->toContain('<td class="">Tool 1</td>')
            ->and($html)->not->toContain('filtered view')
            ->and($html)->not->toContain('class="card"');
    });

    it('says when it is a view of the items and not all of them', function (): void {
        $case = pdfTableCase();

        expect(pdfHtml($case['inventory'], $case['team']['owner'], ['scope' => ExportScope::View, 'options' => ['query' => ['q' => 'Tool 1']]]))
            ->toContain('filtered view');
    });

    it('writes the values in French as a French reader reads them', function (): void {
        $case = pdfTableCase();
        $html = pdfHtml($case['inventory'], $case['team']['owner'], ['locale' => 'fr']);

        expect($html)->toContain('<td class="number">1 234,50 €</td>')
            ->and($html)->toContain('1 mars 2026')
            ->and($html)->toContain('2 photos')
            ->and($html)->toContain('3 articles')
            ->and($html)->toMatch('#<th class="" style="width: [\d.]+%">Name</th>#');
    });

    it('writes them in English as an English reader reads them', function (): void {
        $case = pdfTableCase();
        $html = pdfHtml($case['inventory'], $case['team']['owner'], ['locale' => 'en']);

        expect($html)->toContain('<td class="number">€1,234.50</td>')
            ->and($html)->toContain('Mar 1, 2026')
            ->and($html)->toContain('2 photos')
            ->and($html)->toContain('3 items');
    });

    it('states the zone after the time of the export, of the moments and of the last change, in the language and the zone of the instance', function (string $zone, string $locale, string $today, string $exported, string $seen, string $changed): void {
        $this->travelTo(Carbon::parse($today, 'UTC'));
        $case = exportFixture();
        // After the fixture, which sets the zone of the other tests.
        config(['app.display_timezone' => $zone]);

        $html = pdfHtml($case['inventory'], $case['team']['owner'], ['locale' => $locale]);

        expect($html)->toContain($exported)
            // The moment of the item is in summer time in Paris, whatever the season of the export: each has its own offset.
            ->and($html)->toContain('<span class="label">Seen</span> &nbsp; '.$seen)
            ->and($html)->toContain($changed)
            // A day and a time of the day have no zone.
            ->and($html)->toContain($locale === 'fr' ? '<span class="label">Bought</span> &nbsp; 1 mars 2026<' : '<span class="label">Bought</span> &nbsp; Mar 1, 2026<')
            ->and($html)->toContain('<span class="label">Opening</span> &nbsp; 07:05<');
    })->with([
        'UTC, in English, in winter' => ['UTC', 'en', '2026-01-15 10:30:00', 'Exported on Jan 15, 2026, 10:30 AM UTC', 'Jul 1, 2026, 10:30 AM UTC', 'Modified on &nbsp; Jan 15, 2026, 10:30 AM UTC'],
        'Paris, in French, in winter' => ['Europe/Paris', 'fr', '2026-01-15 10:30:00', 'Exporté le 15 janv. 2026, 11:30 UTC+1', '1 juil. 2026, 12:30 UTC+2', 'Modifié le &nbsp; 15 janv. 2026, 11:30 UTC+1'],
        'Paris, in English, in summer' => ['Europe/Paris', 'en', '2026-07-02 08:00:00', 'Exported on Jul 2, 2026, 10:00 AM UTC+2', 'Jul 1, 2026, 12:30 PM UTC+2', 'Modified on &nbsp; Jul 2, 2026, 10:00 AM UTC+2'],
    ]);

    it('states the zone in the cells of a table too, with the offset of the day of each moment and none in the header', function (): void {
        $case = exportFixture();
        $inventory = inventoryIn($case['team']['workspace'], 'Deliveries');
        $name = fieldIn($inventory, 'text', 'Name');
        $delivered = fieldIn($inventory, 'datetime', 'Delivered');
        itemIn($inventory, [$name->ulid => 'Crate', $delivered->ulid => '2026-01-15T10:30:00Z']);
        itemIn($inventory, [$name->ulid => 'Box', $delivered->ulid => '2026-07-01T10:30:00Z']);

        $html = pdfHtml($inventory, $case['team']['owner']);

        expect($html)->toContain('>Jan 15, 2026, 11:30 AM UTC+1</td>')
            ->and($html)->toContain('>Jul 1, 2026, 12:30 PM UTC+2</td>')
            ->and($html)->toMatch('#<th class="" style="width: [\d.]+%">Delivered</th>#');
    });

    it('has a card for each item beyond 8 fields, with a label and a value for each field that has one', function (): void {
        $case = exportFixture();
        $html = pdfHtml($case['inventory'], $case['team']['owner'], ['locale' => 'fr']);

        expect($html)->toContain('class="card"')
            ->and($html)->not->toContain('<table')
            ->and(substr_count($html, 'class="card"'))->toBe(3)
            ->and($html)->toContain('<div class="title">Drill 18V</div>')
            // The first text is the title of the card, and a card with no text is just an item.
            ->and($html)->toContain('<div class="title">=1+1</div>')
            ->and($html)->toContain('<div class="title">Article</div>')
            ->and($html)->toContain('<span class="label">Quantity</span> &nbsp; 2,50 kg')
            ->and($html)->toContain('<span class="label">Price</span> &nbsp; 1 234,50 €')
            ->and($html)->toContain('<span class="label">Discount</span> &nbsp; 12,5 %')
            ->and($html)->toContain('<span class="label">In stock</span> &nbsp; Oui')
            ->and($html)->toContain('<span class="label">Grade</span> &nbsp; 4/5')
            ->and($html)->toContain('<span class="label">Opening</span> &nbsp; 07:05')
            ->and($html)->toContain('<span class="label">Seen</span> &nbsp; 1 juil. 2026')
            ->and($html)->toContain('12:30')
            ->and($html)->toContain('<span class="label">Labels</span> &nbsp; Heavy, Fragile');
    });

    it('shows how many files an item holds, not their names', function (): void {
        $case = exportFixture();
        $drill = $case['items']['drill'];
        attachmentTo($case['inventory'], $drill, attachmentImage('one.png'), $case['team']['owner'], $case['fields']['photos']);
        attachmentTo($case['inventory'], $drill, attachmentImage('two.png'), $case['team']['owner'], $case['fields']['photos']);
        attachmentTo($case['inventory'], $drill, attachmentPdf('manual.pdf'), $case['team']['owner'], $case['fields']['docs']);
        $html = pdfHtml($case['inventory'], $case['team']['owner'], ['locale' => 'fr']);

        expect($html)->toContain('<span class="label">Photos</span> &nbsp; 2 photos')
            ->and($html)->toContain('<span class="label">Docs</span> &nbsp; 1 fichier')
            ->and($html)->not->toContain('one.png')
            ->and($html)->not->toContain('manual.pdf');
    });

    it('escapes what a person typed, and cuts a very long text short', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], '<b>Bold</b> & co');
        $notes = fieldIn($inventory, 'textarea', 'Notes');
        itemIn($inventory, [$notes->ulid => '<script>alert(1)</script> '.str_repeat('long ', 200)]);

        $html = pdfHtml($inventory, $team['owner']);

        expect($html)->not->toContain('<script>')
            ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->and($html)->toContain('<h1>&lt;b&gt;Bold&lt;/b&gt; &amp; co</h1>')
            ->and($html)->toContain('…</td>')
            ->and($html)->not->toContain(str_repeat('long ', 100));
    });

    it('splits a long table in fragments with no header of their own, sharing the columns of the one header', function (): void {
        $case = pdfTableCase(60);
        $html = pdfHtml($case['inventory'], $case['team']['owner']);
        preg_match_all('#<th class="[^"]*" style="width: ([\d.]+)%">#', $html, $headers);
        preg_match_all('#<td class="[^"]*" style="width: ([\d.]+)%">#', $html, $firstRows);

        // The header table, and three fragments of 25, 25 and 10 rows.
        expect(substr_count($html, '<table>'))->toBe(4)
            ->and(substr_count($html, '<tr class="'))->toBe(60)
            ->and(substr_count($html, '<thead'))->toBe(0)
            // The columns are named once: in the header that the library repeats on every page.
            ->and(substr_count($html, '>Name</th>'))->toBe(1)
            ->and(substr_count($html, '<th '))->toBe(4)
            // The first row of each fragment carries the same percentages as the header, which add up to 100.
            ->and($headers[1])->toHaveCount(4)
            ->and($firstRows[1])->toHaveCount(12)
            ->and(array_chunk($firstRows[1], 4))->each->toBe($headers[1]);

        expect(round(array_sum(array_map('floatval', $headers[1])), 2))->toBe(100.0)
            // A fixed layout: the library does not measure the cells of every fragment.
            ->and($html)->toContain('table-layout: fixed');
    });

    it('writes the header once, in a block the library repeats on every page, and leaves room for it in the top margin', function (): void {
        $case = pdfTableCase(5);
        $html = pdfHtml($case['inventory'], $case['team']['owner']);
        preg_match('/\.head \{ position: fixed; top: -(\d+)px; left: 0; right: 0; height: (\d+)px; \}/', $html, $head);
        preg_match('/@page \{ margin: (\d+)px 32px 48px 32px; \}/', $html, $page);

        expect($head)->toHaveCount(3)
            ->and($page)->toHaveCount(2)
            // The block holds the name, the line about the export and the columns, and the margin holds the block.
            ->and(substr_count($html, '<div class="head">'))->toBe(1)
            ->and($html)->toMatch('#<div class="head">\s*<h1>Tools</h1>\s*<p class="summary">.*?5 items.*?</p>\s*<table>#s')
            ->and((int) $page[1])->toBeGreaterThan((int) $head[2])
            ->and((int) $head[1])->toBeGreaterThan((int) $head[2])
            // The same margin in the middle of every page: the block starts below the top of the page.
            ->and((int) $page[1] - (int) $head[1])->toBeGreaterThan(10);
    });

    it('has only the title and the line about the export in the header of cards', function (): void {
        $case = exportFixture();
        $html = pdfHtml($case['inventory'], $case['team']['owner']);

        expect(substr_count($html, '<div class="head">'))->toBe(1)
            ->and($html)->toMatch('#<div class="head">\s*<h1>Workshop</h1>\s*<p class="summary">.*?3 items.*?</p>\s*</div>#s')
            ->and($html)->not->toContain('<th');
    });

    it('gives each column the room for its header and its short values, then the rest to the one that holds long texts, and cuts a header that does not fit', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Wide');
        $name = fieldIn($inventory, 'text', 'Name');
        $notes = fieldIn($inventory, 'textarea', 'Notes');
        $stock = fieldIn($inventory, 'boolean', 'A very very long name of a field that holds just a yes or a no');
        itemIn($inventory, [$name->ulid => 'Drill', $notes->ulid => str_repeat('some words ', 12), $stock->ulid => true]);
        $html = pdfHtml($inventory, $team['owner']);
        preg_match_all('#<th class="[^"]*" style="width: ([\d.]+)%">#', $html, $found);
        [$nameShare, $notesShare, $stockShare] = array_map('floatval', $found[1]);

        expect($notesShare)->toBeGreaterThan($nameShare * 2)
            ->and($nameShare)->toBeGreaterThanOrEqual(4.0)
            ->and($stockShare)->toBeGreaterThan($nameShare)
            // One column does not take the page for its header: it is given 30 percent for it, and the header is cut beyond.
            ->and($stockShare)->toBeLessThan(40.0)
            ->and($nameShare + $notesShare + $stockShare)->toEqualWithDelta(100.0, 0.001)
            // The header is a line of the document, always: what does not fit its column ends with an ellipsis.
            ->and($html)->toMatch('#<th class="" style="width: [\d.]+%">A very[^<]*…</th>#')
            ->and($html)->not->toContain('a yes or a no</th>')
            // A header that fits is whole.
            ->and($html)->toMatch('#<th class="" style="width: [\d.]+%">Name</th>#')
            ->and($html)->toMatch('#<th class="" style="width: [\d.]+%">Notes</th>#');
    });

    it('does not cut the headers of a table of short values, and the shares add up to 100', function (): void {
        $case = pdfTableCase(5);
        $html = pdfHtml($case['inventory'], $case['team']['owner']);
        preg_match_all('#<th class="[^"]*" style="width: ([\d.]+)%">#', $html, $found);
        preg_match_all('#<td class="[^"]*" style="width: ([\d.]+)%">#', $html, $firstRow);

        // The four columns, in the header and again on the first row of the fragment of the rows.
        expect(array_sum(array_map('floatval', $found[1])))->toEqualWithDelta(100.0, 0.001)
            ->and($found[1])->toHaveCount(4)
            ->and($firstRow[1])->toBe($found[1])
            ->and($html)->toMatch('#>Price \(EUR\)</th>#')
            ->and($html)->toMatch('#>Bought</th>#')
            ->and($html)->toMatch('#>Photos</th>#')
            ->and($html)->not->toContain('…</th>');
    });

    it('works out the shares of the columns: the floors first, the rest in proportion to the weights, always 100 in all', function (): void {
        // 10, 10 and 20 percent are needed; 60 are left, shared 1:1:4.
        expect(PdfColumns::shares([10.0, 10.0, 20.0], [1.0, 1.0, 4.0]))->toBe([20.0, 20.0, 60.0])
            // More is needed than there is: everything is scaled down together.
            ->and(PdfColumns::shares([60.0, 60.0], [1.0, 1.0]))->toBe([50.0, 50.0])
            // Nobody under 4 percent, whatever they need.
            ->and(PdfColumns::shares([0.0, 0.0], [1.0, 1.0]))->toBe([50.0, 50.0])
            ->and(PdfColumns::shares([], []))->toBe([])
            ->and(array_sum(PdfColumns::shares([7.1, 8.3, 9.7], [1.0, 2.0, 3.0])))->toEqualWithDelta(100.0, 0.0001);
    });

    it('keeps the currency of an amount too long for a number, as the language writes it', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Rich');
        $price = fieldIn($inventory, 'money', 'Price', ['currency' => 'EUR', 'decimals' => 2]);
        $other = fieldIn($inventory, 'money', 'Dollars', ['currency' => 'USD', 'decimals' => 2]);
        itemIn($inventory, [$price->ulid => '123456789012345.67', $other->ulid => '-123456789012345.67']);

        $french = pdfHtml($inventory, $team['owner'], ['locale' => 'fr']);
        $english = pdfHtml($inventory, $team['owner'], ['locale' => 'en']);

        // Digit by digit (a float would round them), with the currency where the language puts it.
        expect($french)->toMatch('#<td class="number"[^>]*>123456789012345,67 €</td>#')
            ->and($french)->toContain('-123456789012345,67 $US')
            ->and($english)->toMatch('#<td class="number"[^>]*>€123456789012345.67</td>#')
            ->and($english)->toContain('-$123456789012345.67');
    });
});

describe('the pages of the file', function (): void {
    it('show the header once on every page, with the rows below it', function (): void {
        $text = shell_exec('command -v pdftotext');

        if (! is_string($text) || trim($text) === '') {
            $this->markTestSkipped('pdftotext (poppler) is needed to read the pages back.');
        }

        $case = pdfTableCase(90);
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Pdf));
        $pages = explode("\f", (string) shell_exec('pdftotext -layout '.escapeshellarg(exportLocalPath($export)).' -'));
        $pages = array_values(array_filter($pages, fn (string $page): bool => trim($page) !== ''));

        expect($export->status)->toBe(ExportStatus::Completed, (string) $export->error)
            ->and(count($pages))->toBeGreaterThan(2);

        $rows = 0;

        foreach ($pages as $number => $page) {
            // The name of the inventory, the line about the export and the header of the columns: once, at the top of the page.
            expect(substr_count($page, 'Price (EUR)'))->toBe(1, 'page '.($number + 1))
                ->and(substr_count($page, '90 items'))->toBe(1)
                ->and(strpos($page, 'Tools'))->toBeLessThan(strpos($page, 'Price (EUR)'))
                ->and(strpos($page, 'Price (EUR)'))->toBeLessThan((int) strpos($page, 'Tool '));
            $rows += preg_match_all('/Tool \d+/', $page);
        }

        // No row lost, and none under the header twice.
        expect($rows)->toBe(90);
    });
});

it('holds the items of the view only for a view', function (): void {
    $case = pdfTableCase(5);
    $html = pdfHtml($case['inventory'], $case['team']['owner'], ['scope' => ExportScope::View, 'options' => ['query' => ['q' => 'Tool 3']]]);

    expect($html)->toContain('Tool 3')->and($html)->not->toContain('Tool 2')->and($html)->toContain('1 item');
});
