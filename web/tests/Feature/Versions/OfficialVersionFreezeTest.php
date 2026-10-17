<?php

declare(strict_types=1);

use App\Inventories\Exceptions\ExportRefusedException;
use App\Inventories\Exceptions\SignatureUnavailableException;
use App\Inventories\Exceptions\StorageLimitReachedException;
use App\Inventories\Exceptions\VersionLimitReachedException;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\SignerStatus;
use App\Inventories\Versions\VersionError;
use App\Inventories\Versions\VersionStatus;
use App\Jobs\GenerateOfficialVersion;
use App\Jobs\PurgeStoredFiles;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/versions.php';

/*
 * Freezing an inventory as an official version: the snapshot is the state at the click, written under the lock of the inventory with
 * the SHA-256 of its bytes, the version is numbered inventory by inventory, and the quotas and the people to sign are judged before
 * anything is stored.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

describe('the snapshot', function (): void {
    it('holds the inventory, its fields and its items, and its SHA-256 is that of the bytes of the file', function (): void {
        $case = exportFixture();
        $version = versionFreeze($case['inventory'], $case['team']['owner'], 'Year end');

        $bytes = versionFile($version, $version->snapshot_path);
        $snapshot = versionSnapshot($version);

        expect($version->status)->toBe(VersionStatus::Pending)
            ->and($version->number)->toBe(1)
            ->and($version->label)->toBe('Year end')
            ->and($version->snapshot_sha256)->toBe(hash('sha256', $bytes))
            ->and($version->snapshot_size)->toBe(strlen($bytes))
            ->and($version->snapshot_path)->toBe("workspaces/{$case['team']['workspace']->ulid}/inventories/{$case['inventory']->ulid}/versions/{$version->ulid}/snapshot.json")
            ->and($version->directory())->toBe("workspaces/{$case['team']['workspace']->ulid}/inventories/{$case['inventory']->ulid}/versions/{$version->ulid}")
            ->and($version->items_count)->toBe(3)
            ->and($version->fields_count)->toBe(20)
            ->and($version->author_name)->toBe('Owner')
            ->and($version->user_id)->toBe($case['team']['owner']->id)
            ->and($version->counted_bytes)->toBe(0)
            ->and($version->signature_status)->toBeNull()
            ->and($version->signers)->toBeNull()
            ->and($snapshot['format'])->toBe('inventaire.cloud/official-version')
            ->and($snapshot['format_version'])->toBe(1)
            ->and($snapshot['version'])->toBe(['id' => $version->ulid, 'number' => 1, 'label' => 'Year end', 'frozen_at' => $version->frozen_at->utc()->toIso8601String()])
            ->and($snapshot['workspace'])->toBe(['id' => $case['team']['workspace']->ulid, 'name' => 'Shop'])
            ->and($snapshot['inventory'])->toBe(['id' => $case['inventory']->ulid, 'name' => 'Workshop', 'description' => null])
            ->and($snapshot['author'])->toBe(['name' => 'Owner'])
            ->and($snapshot['fields'])->toHaveCount(20)
            ->and($snapshot['fields'][0])->toBe(['id' => $case['fields']['name']->ulid, 'name' => 'Name', 'type' => 'text', 'position' => 0, 'required' => false, 'options' => $snapshot['fields'][0]['options']])
            ->and($snapshot['items'])->toHaveCount(3);
    });

    it('holds the stored values of the items, keyed by the ULID of the field', function (): void {
        $case = exportFixture();
        $snapshot = versionSnapshot(versionFreeze($case['inventory'], $case['team']['owner']));
        $drill = collect($snapshot['items'])->firstWhere('id', $case['items']['drill']->ulid);

        expect($drill['values'][$case['fields']['name']->ulid])->toBe('Drill 18V')
            ->and($drill['values'][$case['fields']['quantity']->ulid])->toBe('2.50')
            ->and($drill['values'][$case['fields']['stock']->ulid])->toBeTrue()
            ->and($drill['values'][$case['fields']['grade']->ulid])->toBe(4)
            ->and($drill['created_at'])->toBe($case['items']['drill']->created_at->utc()->toIso8601String())
            ->and($drill['updated_at'])->toBe($case['items']['drill']->updated_at->utc()->toIso8601String());
    });

    it('lists the items in the order of their id, and writes an item with nothing as an empty object', function (): void {
        $case = exportFixture();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        $snapshot = versionSnapshot($version);

        expect(array_column($snapshot['items'], 'id'))->toBe([$case['items']['drill']->ulid, $case['items']['formulas']->ulid, $case['items']['empty']->ulid])
            ->and(versionFile($version, $version->snapshot_path))->toContain('"values":{}');
    });

    it('sorts the keys of the values and of the options, whatever order the engine stores them in', function (): void {
        $case = exportFixture();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        $text = versionFile($version, $version->snapshot_path);
        $drill = collect(versionSnapshot($version)['items'])->firstWhere('id', $case['items']['drill']->ulid);

        expect(array_keys($drill['values']))->toBe(collect(array_keys($drill['values']))->sort()->values()->all());

        foreach (json_decode($text, true)['fields'] as $field) {
            expect(array_keys($field['options']))->toBe(collect(array_keys($field['options']))->sort()->values()->all());
        }
    });

    it('is the same text for the same inventory frozen twice, but for the version and the date', function (): void {
        $case = exportFixture();
        $first = versionFreeze($case['inventory'], $case['team']['owner']);
        $second = versionFreeze($case['inventory'], $case['team']['owner']);

        $normalise = fn (InventoryVersion $version): string => preg_replace('/"version":\{[^}]*\}/', '"version":{}', versionFile($version, $version->snapshot_path));

        expect($normalise($first))->toBe($normalise($second))
            ->and($first->snapshot_sha256)->not->toBe($second->snapshot_sha256);
    });

    it('is written as it is read: unescaped slashes and letters, no compression', function (): void {
        $case = exportFixture();
        itemIn($case['inventory'], [$case['fields']['name']->ulid => 'Pelle à neige / 2€', $case['fields']['website']->ulid => 'https://example.com/a/b'], $case['team']['owner']);
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        $text = versionFile($version, $version->snapshot_path);

        expect($text)->toContain('Pelle à neige / 2€')
            ->and($text)->toContain('https://example.com/a/b')
            ->and(str_starts_with($text, '{"format":'))->toBeTrue()
            ->and(substr($text, 0, 2))->not->toBe("\x1f\x8b");
    });

    it('lists the files of the items without their contents', function (): void {
        $case = exportFixture();
        $photo = attachmentTo($case['inventory'], $case['items']['drill'], attachmentImage('front.png'), $case['team']['owner'], $case['fields']['photos']);
        $loose = attachmentTo($case['inventory'], $case['items']['drill'], attachmentPdf('manual.pdf'), $case['team']['owner']);
        $snapshot = versionSnapshot(versionFreeze($case['inventory'], $case['team']['owner']));

        expect($snapshot['attachments'])->toBe([
            ['id' => $photo->ulid, 'item_id' => $case['items']['drill']->ulid, 'field_id' => $case['fields']['photos']->ulid, 'name' => 'front.png', 'mime' => 'image/png', 'size' => $photo->size],
            ['id' => $loose->ulid, 'item_id' => $case['items']['drill']->ulid, 'field_id' => null, 'name' => 'manual.pdf', 'mime' => 'application/pdf', 'size' => $loose->size],
        ]);
    });

    it('does not hold what happened after it was frozen', function (): void {
        $case = exportFixture();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        itemIn($case['inventory'], [$case['fields']['name']->ulid => 'Added later'], $case['team']['owner']);

        expect(versionSnapshot($version)['items'])->toHaveCount(3)
            ->and(versionFile($version, $version->snapshot_path))->not->toContain('Added later');
    });

    it('is written for an inventory with no field and no item', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Empty');
        $version = versionFreeze($inventory, $team['owner']);
        $snapshot = versionSnapshot($version);

        expect($snapshot['fields'])->toBe([])
            ->and($snapshot['items'])->toBe([])
            ->and($snapshot['attachments'])->toBe([])
            ->and($version->items_count)->toBe(0)
            ->and($version->fields_count)->toBe(0);
    });
});

describe('the number and the row', function (): void {
    it('numbers the versions of an inventory one after the other, and each inventory apart', function (): void {
        $case = exportFixture();
        $other = inventoryIn($case['team']['workspace'], 'Other');

        $first = versionFreeze($case['inventory'], $case['team']['owner']);
        $second = versionFreeze($case['inventory'], $case['team']['editor']);
        $elsewhere = versionFreeze($other, $case['team']['owner']);

        expect([$first->number, $second->number, $elsewhere->number])->toBe([1, 2, 1])
            ->and($second->author_name)->toBe('Editor');
    });

    it('takes the next number after the highest, not after the count: a deleted version leaves a hole', function (): void {
        $case = exportFixture();
        $first = versionFreeze($case['inventory'], $case['team']['owner']);
        versionFreeze($case['inventory'], $case['team']['owner']);
        $first->delete();

        expect(versionFreeze($case['inventory'], $case['team']['owner'])->number)->toBe(3);
    });

    it('keeps the language of the person and a date to the second, in UTC', function (): void {
        $case = exportFixture();
        app()->setLocale('fr');
        $this->travelTo(now()->setMicros(123456));
        $version = versionFreeze($case['inventory'], $case['team']['owner']);

        expect($version->locale)->toBe('fr')
            ->and($version->frozen_at->micro)->toBe(0)
            ->and($version->fresh()->frozen_at->equalTo($version->frozen_at))->toBeTrue()
            ->and(versionSnapshot($version)['version']['frozen_at'])->toBe($version->fresh()->frozen_at->utc()->toIso8601String());
    });

    it('queues the job that writes the PDF, on the queue of the exports, once the snapshot is on the disk', function (): void {
        $case = exportFixture();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);

        Queue::assertPushedOn('exports', GenerateOfficialVersion::class, fn (GenerateOfficialVersion $job): bool => $job->version->is($version));
        expect(Storage::disk(ATTACHMENT_TEST_DISK)->exists($version->snapshot_path))->toBeTrue()
            ->and($version->fresh()->status)->toBe(VersionStatus::Pending);
    });

    it('takes the lock of the account, then the one of the inventory, before it reads any item', function (): void {
        $case = exportFixture();
        $statements = statementsOf(fn () => versionFreeze($case['inventory'], $case['team']['owner']));

        $locks = array_values(array_filter(array_keys($statements), fn (int $at): bool => str_contains(strtolower($statements[$at]), ' for update')));
        // The first chunk of items the snapshot reads (the check before the lock only counts them).
        $read = array_values(array_filter(array_keys($statements), fn (int $at): bool => str_contains($statements[$at], 'limit 500') && str_contains($statements[$at], 'items')));

        expect($locks)->toHaveCount(2)
            ->and($statements[$locks[0]])->toContain('users')
            ->and($statements[$locks[1]])->toContain('inventories')
            ->and($read)->not->toBe([])
            ->and($locks[1])->toBeLessThan($read[0]);
    });
});

describe('the limits', function (): void {
    it('refuses a version beyond the quota of versions of an inventory', function (): void {
        config(['inventories.max_versions_per_inventory' => 2]);
        $case = exportFixture();
        versionFreeze($case['inventory'], $case['team']['owner']);
        versionFreeze($case['inventory'], $case['team']['owner']);

        expect(fn () => versionFreeze($case['inventory'], $case['team']['owner']))->toThrow(VersionLimitReachedException::class)
            ->and(InventoryVersion::query()->count())->toBe(2);

        // The quota is the inventory's own.
        expect(versionFreeze(inventoryIn($case['team']['workspace'], 'Other'), $case['team']['owner'])->number)->toBe(1);
    });

    it('says which limit was reached', function (): void {
        config(['inventories.max_versions_per_inventory' => 1]);
        $case = exportFixture();
        versionFreeze($case['inventory'], $case['team']['owner']);

        try {
            versionFreeze($case['inventory'], $case['team']['owner']);
            $this->fail('The quota was not enforced.');
        } catch (VersionLimitReachedException $limit) {
            expect($limit->limit)->toBe(1)
                ->and(VersionLimitReachedException::CODE)->toBe('version_limit_reached');
        }
    });

    it('writes nothing when it refuses: no row, no file, no job', function (): void {
        config(['inventories.max_versions_per_inventory' => 1]);
        $case = exportFixture();
        versionFreeze($case['inventory'], $case['team']['owner']);
        $files = storedFiles();
        Queue::fake();

        expect(fn () => versionFreeze($case['inventory'], $case['team']['owner']))->toThrow(VersionLimitReachedException::class)
            ->and(storedFiles())->toBe($files);
        Queue::assertNothingPushed();
    });

    it('refuses an inventory with more items than a PDF may hold, as an export does', function (): void {
        config(['inventories.pdf_max_items' => 2]);
        $case = versionTableCase(3);

        try {
            versionFreeze($case['inventory'], $case['team']['owner']);
            $this->fail('The PDF limit was not enforced.');
        } catch (ExportRefusedException $refused) {
            expect($refused->reason)->toBe(ExportRefusedException::PDF_TOO_LARGE)
                ->and($refused->limit)->toBe(2);
        }

        expect(InventoryVersion::query()->count())->toBe(0);
    });

    it('lowers the PDF limit with the number of fields, like an export', function (): void {
        config(['inventories.pdf_max_items' => 400]);
        $case = exportFixture();

        // 20 fields: 400 * 8 / 20 = 160 items.
        expect(versionFreeze($case['inventory'], $case['team']['owner'])->items_count)->toBe(3);

        config(['inventories.pdf_max_items' => 16]);

        // 20 fields: 16 * 8 / 20 = 6 items.
        foreach (range(1, 4) as $i) {
            itemIn($case['inventory'], [$case['fields']['name']->ulid => "More {$i}"], $case['team']['owner']);
        }

        expect(fn () => versionFreeze($case['inventory'], $case['team']['owner']))->toThrow(ExportRefusedException::class);
    });

    it('refuses a workspace whose files already weigh its quota', function (): void {
        config(['inventories.storage_per_workspace' => 1000]);
        $case = exportFixture();
        $case['team']['workspace']->forceFill(['storage_used' => 1000])->save();

        try {
            versionFreeze($case['inventory'], $case['team']['owner']);
            $this->fail('The storage quota was not enforced.');
        } catch (StorageLimitReachedException $limit) {
            expect($limit->limit)->toBe(1000);
        }

        $case['team']['workspace']->forceFill(['storage_used' => 999])->save();

        expect(versionFreeze($case['inventory'], $case['team']['owner'])->number)->toBe(1);
    });

    it('refuses signers when the instance has no Documenso', function (): void {
        $case = exportFixture();

        expect(fn () => versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)))->toThrow(SignatureUnavailableException::class)
            ->and(InventoryVersion::query()->count())->toBe(0);

        config(['services.documenso.url' => 'https://sign.example.test']);

        expect(fn () => versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)))->toThrow(SignatureUnavailableException::class);

        // Without signers, Documenso is not needed.
        expect(versionFreeze($case['inventory'], $case['team']['owner'])->signature_status)->toBeNull();
    });
});

describe('the people asked to sign', function (): void {
    it('are kept with a pending status, and the signature is requested', function (): void {
        versionDocumenso();
        $case = exportFixture();
        $version = versionFreeze($case['inventory'], $case['team']['owner'], null, [['name' => '  Ada Lovelace ', 'email' => ' Ada@Example.TEST ']]);

        expect($version->signature_status)->toBe(SignatureStatus::Requested)
            // MySQL and MariaDB keep the keys of a JSON object in an order of their own: compared as a set.
            ->and($version->fresh()->signers)->toEqual([[
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.test',
                'status' => SignerStatus::Pending->value,
                'signed_at' => null,
                'rejection_reason' => null,
                'recipient_id' => null,
            ]]);
    });

    it('have their addresses in the canonical form of the accounts, domain in punycode', function (): void {
        versionDocumenso();
        $case = exportFixture();
        $version = versionFreeze($case['inventory'], $case['team']['owner'], null, [['name' => 'Zoë', 'email' => 'zoe@Gmäil.test']]);

        expect($version->signers[0]['email'])->toBe('zoe@xn--gmil-moa.test')
            ->and($version->signers[0]['name'])->toBe('Zoë');
    });

    it('may not be given the same address twice, however it is written', function (): void {
        versionDocumenso();
        $case = exportFixture();

        try {
            versionFreeze($case['inventory'], $case['team']['owner'], null, [
                ['name' => 'Ada', 'email' => 'ada@example.test'],
                ['name' => 'Grace', 'email' => 'grace@example.test'],
                ['name' => 'Ada again', 'email' => ' ADA@example.test'],
            ]);
            $this->fail('The duplicate was not refused.');
        } catch (ValidationException $invalid) {
            expect(array_keys($invalid->errors()))->toBe(['signers.0.email', 'signers.2.email'])
                ->and(InventoryVersion::query()->count())->toBe(0);
        }
    });

    it('are judged by the rules of a name and of an address, each mistake under its own key', function (): void {
        versionDocumenso();
        $case = exportFixture();

        try {
            versionFreeze($case['inventory'], $case['team']['owner'], null, [
                ['name' => 'Ada', 'email' => 'ada@example.test'],
                ['name' => '', 'email' => 'not an address'],
                ['name' => "Line\nbreak", 'email' => 'grace@example.test'],
                ['email' => 'only@example.test'],
            ]);
            $this->fail('The signers were not refused.');
        } catch (ValidationException $invalid) {
            expect(array_keys($invalid->errors()))->toEqualCanonicalizing(['signers.1.name', 'signers.1.email', 'signers.2.name', 'signers.3.name']);
        }
    });

    it('are at most the number the configuration allows, and ten whatever it says', function (): void {
        versionDocumenso();
        $case = exportFixture();
        config(['inventories.max_signers' => 3]);

        expect(fn () => versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(4)))->toThrow(ValidationException::class)
            ->and(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(3))->signers)->toHaveCount(3);

        config(['inventories.max_signers' => 50]);

        expect(fn () => versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(11)))->toThrow(ValidationException::class)
            ->and(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(10))->signers)->toHaveCount(10);
    });

    it('are refused as a list of entries that are not names and addresses', function (): void {
        versionDocumenso();
        $case = exportFixture();

        expect(fn () => versionFreeze($case['inventory'], $case['team']['owner'], null, ['ada@example.test']))->toThrow(ValidationException::class)
            ->and(fn () => versionFreeze($case['inventory'], $case['team']['owner'], null, [['name' => ['Ada'], 'email' => 42]]))->toThrow(ValidationException::class);
    });
});

describe('the label', function (): void {
    it('is trimmed, and none when it is empty', function (): void {
        $case = exportFixture();

        expect(versionFreeze($case['inventory'], $case['team']['owner'], '  Q1 count  ')->label)->toBe('Q1 count')
            ->and(versionFreeze($case['inventory'], $case['team']['owner'], '   ')->label)->toBeNull()
            ->and(versionFreeze($case['inventory'], $case['team']['owner'], null)->label)->toBeNull();
    });

    it('is at most 120 characters, on one line', function (): void {
        $case = exportFixture();

        expect(versionFreeze($case['inventory'], $case['team']['owner'], str_repeat('é', 120))->label)->toBe(str_repeat('é', 120))
            ->and(fn () => versionFreeze($case['inventory'], $case['team']['owner'], str_repeat('é', 121)))->toThrow(ValidationException::class)
            ->and(fn () => versionFreeze($case['inventory'], $case['team']['owner'], "two\nlines"))->toThrow(ValidationException::class);
    });
});

describe('a disk that refuses the file', function (): void {
    it('leaves a failed version with nothing stored, and no job', function (): void {
        $case = exportFixture();
        $proxy = attachmentDiskProxy();
        $proxy->shouldReceive('put')->andReturn(false);
        Queue::fake();

        $version = versionFreeze($case['inventory'], $case['team']['owner']);

        expect($version->status)->toBe(VersionStatus::Failed)
            ->and($version->error)->toBe(VersionError::StorageFailed)
            ->and($version->fresh()->counted_bytes)->toBe(0);
        // Nothing is written, and what may have been is purged by the folder of the version.
        Queue::assertNotPushed(GenerateOfficialVersion::class);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [$version->directory()]);
    });
});

describe('the accounts and inventories that are gone', function (): void {
    it('fail like a missing route binding when the inventory was deleted', function (): void {
        $case = exportFixture();
        $inventory = Inventory::query()->findOrFail($case['inventory']->id);
        Inventory::query()->whereKey($inventory->id)->delete();

        expect(fn () => versionFreeze($inventory, $case['team']['owner']))->toThrow(ModelNotFoundException::class);
    });
});
