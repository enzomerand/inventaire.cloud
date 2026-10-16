<?php

declare(strict_types=1);

use App\Inventories\Exceptions\ImportFailure;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Imports\ImportError;
use App\Inventories\Imports\ImportPlan;
use App\Inventories\Imports\ImportStatus;
use App\Inventories\Imports\ImportTarget;
use App\Inventories\Imports\ImportWriter;
use App\Inventories\Items;
use App\Models\Item;
use App\Models\ItemRevision;
use App\Workspaces\Locks;
use App\Workspaces\WorkspaceRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/imports.php';

/*
 * How the rows are written: all of them or none, the end of the import recorded in the same transaction as the items, the rights of the
 * person judged again under the lock, and the statements kept to a size the database accepts.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * A plan with a column for a field the inventory has and a column that creates a field, and rows for them.
 *
 * @return array{plan: ImportPlan, rows: array<int, array<string, mixed>>}
 */
function writeCase(array $case): array
{
    $types = FieldTypes::standard();
    $name = fieldIn($case['inventory'], 'text', 'Name');
    $madeUp = importField('text');
    $plan = new ImportPlan(
        [new ImportTarget(0, 'Name', $name, $types->get('text'), false), new ImportTarget(1, 'Extra', $madeUp, $types->get('text'), true)],
        ImportPlan::fingerprint($case['inventory']->fields()->get()),
    );

    return ['plan' => $plan, 'rows' => [2 => [$name->ulid => 'Drill', $madeUp->ulid => 'x'], 3 => [$name->ulid => 'Saw']]];
}

describe('the end of the import', function (): void {
    it('is recorded in the transaction of the writer: the fields and the items are taken back when it cannot be', function (): void {
        $case = importCase();
        ['plan' => $plan, 'rows' => $rows] = writeCase($case);
        $fieldsBefore = $case['inventory']->fields()->pluck('name')->all();

        expect(fn () => app(ImportWriter::class)->write($case['inventory'], $case['team']['owner'], $plan, $rows, 'abort', fn () => throw new ImportFailure(ImportError::Stale)))
            ->toThrow(ImportFailure::class)
            ->and(Item::query()->count())->toBe(0)
            ->and(ItemRevision::query()->count())->toBe(0)
            ->and($case['inventory']->refresh()->items_count)->toBe(0)
            ->and($case['inventory']->fields()->pluck('name')->all())->toBe($fieldsBefore);

        // The same call with an end that can be recorded writes everything.
        $written = app(ImportWriter::class)->write($case['inventory'], $case['team']['owner'], $plan, $rows, 'abort', fn () => null);

        expect($written->created)->toBe(2)
            ->and($written->fields)->toBe(1)
            ->and(Item::query()->count())->toBe(2)
            ->and($case['inventory']->fields()->pluck('name')->all())->toBe([...$fieldsBefore, 'Text']);
    });

    it('writes nothing when the import was given up on while it wrote', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $csv = importFile(importCsv([['Name', 'Extra'], ['a', 'b'], ['c', 'd']]));
        $import = importAsked($this, $case, $csv, [
            ['column' => 0, 'target' => 'field', 'field' => $name->ulid],
            ['column' => 1, 'target' => 'new', 'type' => 'text', 'name' => 'Extra'],
        ]);
        // The pruner takes the import for lost at the moment its first items are written.
        importAfterStatement('/^insert into [`"]items[`"]/i', fn () => DB::table('imports')->where('id', $import->id)->update(['status' => 'failed', 'error' => 'stale']));

        $import = importDone($import);

        expect($import->status)->toBe(ImportStatus::Failed)
            ->and($import->error)->toBe('stale')
            ->and($import->path)->toBeNull()
            ->and(Item::query()->count())->toBe(0)
            ->and(ItemRevision::query()->count())->toBe(0)
            ->and($case['inventory']->refresh()->items_count)->toBe(0)
            ->and($case['inventory']->fields()->pluck('name')->all())->toBe(['Name'])
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toBe([]);
    });

    it('completes the import together with its items', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile(importCsv([['Name'], ['a'], ['b']])), [['column' => 0, 'target' => 'field', 'field' => $name->ulid]]);
        $seen = [];
        // When the items are written the import is still running: it is completed by the transaction that writes them, not after it.
        importAfterStatement('/^insert into [`"]item_revisions[`"]/i', function () use ($import, &$seen): void {
            $seen = [DB::table('imports')->where('id', $import->id)->value('status'), Item::query()->count()];
        });

        $import = importDone($import);

        expect($seen)->toBe(['running', 2])
            ->and($import->status)->toBe(ImportStatus::Completed)
            ->and($import->rows_imported)->toBe(2);
    });
});

describe('the rights of the person who started the import', function (): void {
    it('are judged again under the lock of the inventory, and refuse a person who lost them', function (): void {
        $case = importCase();
        ['plan' => $plan, 'rows' => $rows] = writeCase($case);

        expect(fn () => app(ImportWriter::class)->write($case['inventory'], $case['team']['viewer'], $plan, $rows, 'abort', fn () => null))
            ->toThrow(fn (ImportFailure $failure) => expect($failure->error)->toBe(ImportError::NotAllowed))
            ->and(Item::query()->count())->toBe(0)
            ->and($case['inventory']->fields()->pluck('name')->all())->toBe(['Name']);
    });

    it('fail the import when the role changes after the job has begun', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile(importCsv([['Name', 'Extra'], ['a', 'b']])), [
            ['column' => 0, 'target' => 'field', 'field' => $name->ulid],
            ['column' => 1, 'target' => 'new', 'type' => 'text', 'name' => 'Extra'],
        ], as: $case['team']['editor']);
        // The job checks the role when it begins, the editor is still one then; they are made a viewer as the writer takes its locks.
        importAfterStatement('/from [`"]inventories[`"].* for update/is', fn () => $case['team']['workspace']->members()->updateExistingPivot($case['team']['editor']->id, ['role' => WorkspaceRole::Viewer->value]));

        $import = importDone($import);

        expect($import->status)->toBe(ImportStatus::Failed)
            ->and($import->error)->toBe('not_allowed')
            ->and(Item::query()->count())->toBe(0)
            ->and($case['inventory']->fields()->pluck('name')->all())->toBe(['Name']);
    });
});

describe('the statements', function (): void {
    it('keep to a size the database accepts: a long text in every row does not put hundreds of them in one', function (): void {
        $case = importCase();
        $notes = fieldIn($case['inventory'], 'textarea', 'Notes');
        // 480 rows are fewer than a statement takes by count, and 4.3 MB of values are more than it takes by size.
        $rows = array_map(fn (int $number): array => [str_repeat('x', 8990).$number], range(1, 480));
        $import = importAsked($this, $case, importFile(importCsv([['Notes'], ...$rows])), [['column' => 0, 'target' => 'field', 'field' => $notes->ulid]]);
        $import->refresh();

        $statements = statementsOf(function () use (&$import): void {
            $import = importDone($import);
        });
        $into = fn (string $table): int => count(array_filter($statements, fn (string $sql): bool => preg_match('/^insert into [`"]'.$table.'[`"]/i', $sql) === 1));

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->rows_imported)->toBe(480)
            ->and(Item::query()->count())->toBe(480)
            ->and($into('items'))->toBeGreaterThanOrEqual(2)
            ->and($into('item_revisions'))->toBeGreaterThanOrEqual(2);
    });

    it('are still one for a few short rows', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $import = importAsked($this, $case, importFile(importCsv([['Name'], ['a'], ['b'], ['c']])), [['column' => 0, 'target' => 'field', 'field' => $name->ulid]]);

        $statements = statementsOf(function () use (&$import): void {
            $import = importDone($import);
        });
        $into = fn (string $table): int => count(array_filter($statements, fn (string $sql): bool => preg_match('/^insert into [`"]'.$table.'[`"]/i', $sql) === 1));

        expect([$into('items'), $into('item_revisions')])->toBe([1, 1]);
    });

    it('take the values of the items from a generator only when the number of items is given', function (): void {
        $case = importCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $values = (function () use ($name): Generator {
            yield [$name->ulid => 'a'];
        })();

        DB::transaction(function () use ($case, $values): void {
            $locked = app(Locks::class)->inventory($case['inventory']->id);
            $fields = $locked->fields()->get();

            expect(fn () => app(Items::class)->createMany($locked, $case['inventory'], $fields, $values, $case['team']['owner']))
                ->toThrow(InvalidArgumentException::class);
        });

        expect(Item::query()->count())->toBe(0);
    });
});
