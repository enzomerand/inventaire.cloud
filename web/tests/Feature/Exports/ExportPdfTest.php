<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportGenerator;
use App\Inventories\Exports\ExportScope;
use App\Inventories\Exports\ExportStatus;
use App\Inventories\Exports\PdfWriter;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    it('refuses an inventory with more items than a PDF may hold, and keeps no file', function (): void {
        $case = pdfTableCase(3);
        config(['inventories.pdf_max_items' => 2]);
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Pdf));

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and($export->error)->toBe(ExportError::GenerationFailed->value)
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
            ->and($html)->toContain('<th>Name</th>')
            ->and($html)->toContain('<th>Price (EUR)</th>')
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
            ->and($html)->toContain('<th>Name</th>');
    });

    it('writes them in English as an English reader reads them', function (): void {
        $case = pdfTableCase();
        $html = pdfHtml($case['inventory'], $case['team']['owner'], ['locale' => 'en']);

        expect($html)->toContain('<td class="number">€1,234.50</td>')
            ->and($html)->toContain('Mar 1, 2026')
            ->and($html)->toContain('2 photos')
            ->and($html)->toContain('3 items');
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

    it('splits a long table in several, each with its header, for the library to lay out', function (): void {
        $case = pdfTableCase(60);
        $html = pdfHtml($case['inventory'], $case['team']['owner']);

        expect(substr_count($html, '<table>'))->toBe(3)
            ->and(substr_count($html, '<th>Name</th>'))->toBe(3)
            ->and(substr_count($html, '<tr class="'))->toBe(60);
    });
});

it('holds the items of the view only for a view', function (): void {
    $case = pdfTableCase(5);
    $html = pdfHtml($case['inventory'], $case['team']['owner'], ['scope' => ExportScope::View, 'options' => ['query' => ['q' => 'Tool 3']]]);

    expect($html)->toContain('Tool 3')->and($html)->not->toContain('Tool 2')->and($html)->toContain('1 item');
});
