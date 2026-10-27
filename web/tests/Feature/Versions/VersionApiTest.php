<?php

declare(strict_types=1);

use App\Inventories\Versions\SignatureError;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\SignerStatus;
use App\Inventories\Versions\VersionError;
use App\Inventories\Versions\VersionStatus;
use App\Jobs\GenerateOfficialVersion;
use App\Jobs\PurgeStoredFiles;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/tokens.php';
require_once __DIR__.'/../../Support/versions.php';

/*
 * The API of the official versions: freezing an inventory, the limits and the mistakes of the request, the list and the reading of the
 * versions, the deletion, and who may do what (the role of the member, the ability of the token, the pace of a person).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * Freezes an inventory the way the web front does.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function versionApiPost(object $test, ?User $as, Inventory $inventory, array $data = [], array $headers = []): TestResponse
{
    return inventoryCall($test, $as, 'POST', versionApiUri($inventory), $data, $headers);
}

/**
 * A version with its PDF written, frozen by the owner unless said otherwise (the caller has faked the queue).
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory}  $case
 * @param  list<array{name: string, email: string}>  $signers
 */
function versionApiDone(array $case, ?User $by = null, ?string $label = null, array $signers = []): InventoryVersion
{
    return versionRun(versionFreeze($case['inventory'], $by ?? $case['team']['owner'], $label, $signers));
}

/**
 * The keys of a version as the API gives it, in order.
 */
const VERSION_API_KEYS = ['id', 'number', 'label', 'status', 'error', 'frozen_at', 'created_at', 'author', 'items_count', 'fields_count', 'snapshot', 'pdf', 'signature', 'abilities'];

describe('without signing in', function (): void {
    it('answers 401 in JSON on every endpoint', function (string $method, string $path): void {
        Queue::fake();
        $case = versionTableCase();
        $version = versionApiDone($case);
        $uri = str_replace(['{inventory}', '{version}'], [$case['inventory']->ulid, $version->ulid], $path);

        $this->call($method, $uri)->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    })->with([
        ['GET', '/api/v1/inventories/{inventory}/versions'],
        ['POST', '/api/v1/inventories/{inventory}/versions'],
        ['GET', '/api/v1/inventories/{inventory}/versions/{version}'],
        ['GET', '/api/v1/inventories/{inventory}/versions/{version}/pdf'],
        ['GET', '/api/v1/inventories/{inventory}/versions/{version}/snapshot'],
        ['DELETE', '/api/v1/inventories/{inventory}/versions/{version}'],
    ]);
});

describe('freezing an inventory', function (): void {
    it('answers 202 with the version, pending, and queues the job that writes the PDF', function (): void {
        Queue::fake();
        $case = versionTableCase();

        $response = versionApiPost($this, $case['team']['owner'], $case['inventory'], ['label' => '  Year end  '])->assertStatus(202);
        $version = InventoryVersion::query()->sole();

        expect(array_keys($response->json('data')))->toBe(VERSION_API_KEYS)
            ->and($response->json('data'))->toBe([
                'id' => $version->ulid,
                'number' => 1,
                'label' => 'Year end',
                'status' => 'pending',
                'error' => null,
                'frozen_at' => $version->frozen_at->toIso8601String(),
                'created_at' => $version->created_at->toIso8601String(),
                'author' => ['name' => 'Owner'],
                'items_count' => 3,
                'fields_count' => 4,
                'snapshot' => [
                    'sha256' => $version->snapshot_sha256,
                    'size' => $version->snapshot_size,
                    // Not before the version is completed: the download answers 409 until then.
                    'download_url' => null,
                ],
                'pdf' => null,
                'signature' => null,
                'abilities' => ['delete' => true, 'cancel_signature' => false, 'retry_signature' => false],
            ])
            ->and($version->user_id)->toBe($case['team']['owner']->id)
            ->and($version->inventory_id)->toBe($case['inventory']->id)
            ->and($version->locale)->toBe('en')
            ->and($version->disk)->toBe(ATTACHMENT_TEST_DISK);

        // On the queue of the exports: a worker listens to it after the default one.
        Queue::assertPushed(GenerateOfficialVersion::class, fn (GenerateOfficialVersion $job): bool => $job->version->is($version));
        Queue::assertPushed(GenerateOfficialVersion::class, 1);
        Queue::assertPushedOn('exports', GenerateOfficialVersion::class);
    });

    it('takes no body at all, and numbers the versions of each inventory apart', function (): void {
        Queue::fake();
        $case = versionTableCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        $owner = $case['team']['owner'];

        $first = versionApiPost($this, $owner, $case['inventory'])->assertStatus(202);
        $second = versionApiPost($this, $owner, $case['inventory'], ['label' => null, 'signers' => null])->assertStatus(202);
        $elsewhere = versionApiPost($this, $owner, $other)->assertStatus(202);

        expect($first->json('data.number'))->toBe(1)
            ->and($first->json('data.label'))->toBeNull()
            ->and($second->json('data.number'))->toBe(2)
            ->and($elsewhere->json('data.number'))->toBe(1)
            ->and($elsewhere->json('data.items_count'))->toBe(0);
    });

    it('runs through to a completed version with its PDF when the queue works', function (): void {
        $case = versionTableCase();

        $created = versionApiPost($this, $case['team']['editor'], $case['inventory'], ['label' => 'Year end'])->assertStatus(202);
        $shown = inventoryCall($this, $case['team']['viewer'], 'GET', versionApiUri($case['inventory'], InventoryVersion::query()->sole()))->assertOk();
        $version = InventoryVersion::query()->sole();

        // The queue of the tests is synchronous: the PDF is written by the time the answer is given.
        expect($created->json('data.status'))->toBe('pending')
            ->and($version->status)->toBe(VersionStatus::Completed)
            ->and($shown->json('data.status'))->toBe('completed')
            ->and($shown->json('data.pdf'))->toBe([
                'sha256' => $version->pdf_sha256,
                'size' => $version->pdf_size,
                'pages' => $version->pdf_pages,
                'download_url' => versionApiUri($case['inventory'], $version, '/pdf'),
            ])
            ->and($shown->json('data.author'))->toBe(['name' => 'Editor']);
    });

    it('is written in the language of the request', function (): void {
        Queue::fake();
        $case = versionTableCase();

        versionApiPost($this, $case['team']['owner'], $case['inventory'], [], ['Accept-Language' => 'fr'])->assertStatus(202);

        expect(InventoryVersion::query()->sole()->locale)->toBe('fr');
    });

    it('is open to editors and owners, refused to a viewer without a code, and a 404 for a stranger', function (): void {
        Queue::fake();
        $case = versionTableCase();

        versionApiPost($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
        versionApiPost($this, $case['team']['editor'], $case['inventory'])->assertStatus(202);
        $viewer = versionApiPost($this, $case['team']['viewer'], $case['inventory'])->assertForbidden();
        versionApiPost($this, $case['team']['stranger'], $case['inventory'])->assertNotFound();

        expect($viewer->json())->toBe(['message' => 'You are not allowed to freeze this inventory as an official version.'])
            ->and(InventoryVersion::query()->orderBy('number')->pluck('user_id')->all())->toBe([$case['team']['owner']->id, $case['team']['editor']->id]);
    });

    it('asks for signatures when the instance can send them, with every signer pending', function (): void {
        Queue::fake();
        versionDocumenso();
        $case = versionTableCase();
        $signers = [['name' => '  Ada Lovelace ', 'email' => 'Ada@Example.TEST'], ['name' => 'Grace Hopper', 'email' => 'grace@example.test']];

        $response = versionApiPost($this, $case['team']['owner'], $case['inventory'], ['signers' => $signers])->assertStatus(202);
        $version = InventoryVersion::query()->sole();

        expect($response->json('data.signature'))->toBe([
            'status' => 'requested',
            'error' => null,
            'requested_at' => null,
            'completed_at' => null,
            'signers' => [
                ['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'status' => 'pending', 'signed_at' => null, 'rejection_reason' => null],
                ['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'status' => 'pending', 'signed_at' => null, 'rejection_reason' => null],
            ],
            'signed_pdf' => null,
        ])
            ->and($version->signature_status)->toBe(SignatureStatus::Requested)
            ->and(array_column($version->signers ?? [], 'recipient_id'))->toBe([null, null]);
    });

    it('is written whole or not at all: a refused request leaves no row, no file and no job', function (): void {
        Queue::fake();
        $case = versionTableCase();
        $files = storedFiles();

        versionApiPost($this, $case['team']['owner'], $case['inventory'], ['signers' => versionSigners(1)])->assertStatus(409);
        versionApiPost($this, $case['team']['owner'], $case['inventory'], ['label' => str_repeat('x', 121)])->assertStatus(422);

        expect(InventoryVersion::query()->count())->toBe(0)
            ->and(storedFiles())->toBe($files);
        Queue::assertNothingPushed();
    });
});

describe('the mistakes of the request', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        versionDocumenso();
    });

    it('are reported under the key of the fault', function (array $data, string $key): void {
        $case = versionTableCase();

        $response = versionApiPost($this, $case['team']['owner'], $case['inventory'], $data)->assertStatus(422);

        expect($response->json('errors'))->toHaveKey($key)
            ->and(InventoryVersion::query()->count())->toBe(0);
    })->with([
        'a label that is too long' => [['label' => str_repeat('x', 121)], 'label'],
        'a label with a line break' => [['label' => "Year\nend"], 'label'],
        'a label that is not a text' => [['label' => ['a']], 'label'],
        'signers that are not a list' => [['signers' => 'ada@example.test'], 'signers'],
        'an empty list of signers' => [['signers' => []], 'signers'],
        'a signer that is not an object' => [['signers' => ['ada@example.test']], 'signers.0'],
        'a signer without a name' => [['signers' => [['email' => 'ada@example.test']]], 'signers.0.name'],
        'a signer with an empty name' => [['signers' => [['name' => ' ', 'email' => 'ada@example.test']]], 'signers.0.name'],
        'a signer without an address' => [['signers' => [['name' => 'Ada']]], 'signers.0.email'],
        'a signer with an address that is none' => [['signers' => [['name' => 'Ada', 'email' => 'not an address']]], 'signers.0.email'],
        'a name that is not a text' => [['signers' => [['name' => ['Ada'], 'email' => 'ada@example.test']]], 'signers.0.name'],
        'twice the same address, whatever the case' => [['signers' => [['name' => 'Ada', 'email' => 'ada@example.test'], ['name' => 'Ada again', 'email' => 'ADA@example.test']]], 'signers.1.email'],
    ]);

    it('names the signer in the messages, in the language of the request, never by its key', function (): void {
        $case = versionTableCase();
        $data = ['signers' => [['name' => 'Ada', 'email' => 'ada@example.test'], ['name' => ' ', 'email' => 'not an address']]];

        $english = versionApiPost($this, $case['team']['owner'], $case['inventory'], $data)->assertStatus(422)->json('errors');
        $french = versionApiPost($this, $case['team']['owner'], $case['inventory'], $data, ['Accept-Language' => 'fr'])->assertStatus(422)->json('errors');

        expect($english['signers.1.name'][0])->toContain('name of signer 2')
            ->and($english['signers.1.email'][0])->toContain('email address of signer 2')
            ->and($french['signers.1.name'][0])->toBe('Le champ nom du signataire 2 est obligatoire.')
            ->and($french['signers.1.email'][0])->toContain('adresse e-mail du signataire 2')
            ->and(implode(' ', array_merge(...array_values($french))))->not->toContain('signers.');
    });

    it('refuse more signers than the instance allows, and never more than ten', function (): void {
        $case = versionTableCase();

        config(['inventories.max_signers' => 2]);
        $tooMany = versionApiPost($this, $case['team']['owner'], $case['inventory'], ['signers' => versionSigners(3)])->assertStatus(422);

        config(['inventories.max_signers' => 50]);
        $beyondTen = versionApiPost($this, $case['team']['owner'], $case['inventory'], ['signers' => versionSigners(11)])->assertStatus(422);
        $ten = versionApiPost($this, $case['team']['owner'], $case['inventory'], ['signers' => versionSigners(10)])->assertStatus(202);

        expect($tooMany->json('errors'))->toHaveKey('signers')
            ->and($beyondTen->json('errors'))->toHaveKey('signers')
            ->and($ten->json('data.signature.signers'))->toHaveCount(10);
    });

    it('say the message of the duplicate in the language of the request', function (): void {
        $case = versionTableCase();
        $signers = [['name' => 'Ada', 'email' => 'ada@example.test'], ['name' => 'Ada again', 'email' => 'ada@example.test']];

        $response = versionApiPost($this, $case['team']['owner'], $case['inventory'], ['signers' => $signers], ['Accept-Language' => 'fr'])->assertStatus(422);

        expect($response->json('errors')['signers.1.email'])->toBe(['Chaque signataire doit avoir une adresse e-mail différente.']);
    });
});

describe('the limits', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('answers 409 version_limit_reached once the inventory holds as many versions as allowed, and says how many', function (): void {
        config(['inventories.max_versions_per_inventory' => 2]);
        $case = versionTableCase();
        $owner = $case['team']['owner'];

        versionApiPost($this, $owner, $case['inventory'])->assertStatus(202);
        versionApiPost($this, $owner, $case['inventory'])->assertStatus(202);
        $refused = versionApiPost($this, $owner, $case['inventory'])->assertStatus(409);

        expect($refused->json())->toBe(['message' => 'An inventory cannot hold more than 2 official versions: delete one to freeze it again.', 'code' => 'version_limit_reached', 'limit' => 2])
            ->and(InventoryVersion::query()->count())->toBe(2);

        // The quota is the inventory's own, and deleting a version frees room.
        versionApiPost($this, $owner, inventoryIn($case['team']['workspace'], 'Other'))->assertStatus(202);
        inventoryCall($this, $owner, 'DELETE', versionApiUri($case['inventory'], InventoryVersion::query()->where('inventory_id', $case['inventory']->id)->firstOrFail()))->assertNoContent();
        versionApiPost($this, $owner, $case['inventory'])->assertStatus(202);
    });

    it('answers 409 storage_limit_reached for a workspace whose files already weigh its quota', function (): void {
        config(['inventories.storage_per_workspace' => 1000]);
        $case = versionTableCase();
        $case['team']['workspace']->forceFill(['storage_used' => 1000])->save();

        $refused = versionApiPost($this, $case['team']['owner'], $case['inventory'])->assertStatus(409);

        expect($refused->json('code'))->toBe('storage_limit_reached')
            ->and($refused->json('limit'))->toBe(1000)
            ->and($refused->json('message'))->toBe('The storage of this workspace is full: its files cannot weigh more than 1 KB in all.')
            ->and(InventoryVersion::query()->count())->toBe(0);
    });

    it('answers 409 signature_unavailable when signers are given and the instance has no signature service', function (): void {
        $case = versionTableCase();

        $refused = versionApiPost($this, $case['team']['owner'], $case['inventory'], ['signers' => versionSigners(2)])->assertStatus(409);

        expect($refused->json())->toBe(['message' => 'Signatures cannot be asked for: this instance has no signature service configured.', 'code' => 'signature_unavailable'])
            ->and(InventoryVersion::query()->count())->toBe(0);

        // Without signers it needs none.
        versionApiPost($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
    });

    it('answers 422 pdf_too_large for an inventory with more items than a PDF may hold, as an export does, and says how many it may', function (): void {
        config(['inventories.pdf_max_items' => 2]);
        $case = versionTableCase(3);

        $refused = versionApiPost($this, $case['team']['owner'], $case['inventory'])->assertStatus(422);

        expect($refused->json('code'))->toBe('pdf_too_large')
            ->and($refused->json('limit'))->toBe(2)
            ->and($refused->json('message'))->toBe('A version of this inventory cannot hold more than 2 items, which is all a PDF can hold.')
            ->and($refused->json('errors'))->toBe(['inventory' => [$refused->json('message')]])
            ->and(InventoryVersion::query()->count())->toBe(0);
    });

    it('answers 422 pdf_too_large, with no limit of items, for an inventory whose data is too heavy for a snapshot, and keeps nothing', function (): void {
        $case = versionTableCase(3);
        config(['inventories.max_snapshot_bytes' => 700]);
        $files = storedFiles();

        $refused = versionApiPost($this, $case['team']['owner'], $case['inventory'])->assertStatus(422);

        expect($refused->json('code'))->toBe('pdf_too_large')
            ->and($refused->json())->not->toHaveKey('limit')
            ->and($refused->json('message'))->toBe('The data of this inventory is too large to be frozen as an official version: it cannot weigh more than 700 B.')
            ->and($refused->json('errors'))->toBe(['inventory' => [$refused->json('message')]])
            ->and(InventoryVersion::query()->count())->toBe(0)
            ->and(storedFiles())->toBe($files);
    });

    it('answers 429 beyond the versions a person may freeze in an hour, for that person only, and starts again an hour later', function (): void {
        config(['inventories.versions_per_hour' => 2]);
        $case = versionTableCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');

        versionApiPost($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
        versionApiPost($this, $case['team']['owner'], $other)->assertStatus(202);
        $refused = versionApiPost($this, $case['team']['owner'], $case['inventory'])->assertStatus(429);

        expect($refused->json('code'))->toBe('too_many_requests')
            ->and((int) $refused->headers->get('Retry-After'))->toBeBetween(1, 3600)
            ->and($refused->headers->get('X-RateLimit-Limit'))->toBe('2')
            ->and(InventoryVersion::query()->count())->toBe(2);

        // Another person has their own count.
        versionApiPost($this, $case['team']['editor'], $case['inventory'])->assertStatus(202);

        $this->travel(61)->minutes();

        versionApiPost($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
    });

    it('counts the versions of a person whatever they freeze through, the web front or a token', function (): void {
        config(['inventories.versions_per_hour' => 2]);
        $case = versionTableCase();
        $owner = $case['team']['owner'];

        versionApiPost($this, $owner, $case['inventory'])->assertStatus(202);
        tokenCall($this, tokenFor($owner, ['write']), 'POST', versionApiUri($case['inventory']))->assertStatus(202);
        tokenCall($this, tokenFor($owner, ['write']), 'POST', versionApiUri($case['inventory']))->assertStatus(429);
        versionApiPost($this, $owner, $case['inventory'])->assertStatus(429);
    });
});

describe('the address of the data', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('is given only once the version is completed, as the download itself is: null while it is pending, running or failed', function (VersionStatus $status, bool $given): void {
        $case = versionTableCase();
        $version = InventoryVersion::factory()->create(['inventory_id' => $case['inventory']->id, 'status' => $status, 'snapshot_path' => 'versions/snapshot.json']);

        $shown = inventoryCall($this, $case['team']['viewer'], 'GET', versionApiUri($case['inventory'], $version))->assertOk();
        $listed = inventoryCall($this, $case['team']['viewer'], 'GET', versionApiUri($case['inventory']))->assertOk();
        $address = versionApiUri($case['inventory'], $version, '/snapshot');

        expect($shown->json('data.snapshot.download_url'))->toBe($given ? $address : null)
            ->and($listed->json('data.0.snapshot.download_url'))->toBe($given ? $address : null)
            // The size and the SHA-256 are the freeze's, and are given whatever the state.
            ->and($shown->json('data.snapshot.sha256'))->toBe($version->snapshot_sha256)
            ->and($shown->json('data.snapshot.size'))->toBe($version->snapshot_size);

        if (! $given) {
            inventoryCall($this, $case['team']['viewer'], 'GET', $address)->assertStatus(409)->assertJsonPath('code', 'version_not_ready');
        }
    })->with([
        'pending' => [VersionStatus::Pending, false],
        'running' => [VersionStatus::Running, false],
        'failed' => [VersionStatus::Failed, false],
        'completed' => [VersionStatus::Completed, true],
    ]);

    it('is an address that works once the version is completed', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));

        $shown = inventoryCall($this, $case['team']['viewer'], 'GET', versionApiUri($case['inventory'], $version))->assertOk();

        expect($shown->json('data.snapshot.download_url'))->toBe(versionApiUri($case['inventory'], $version, '/snapshot'));
        attachmentGet($this, $case['team']['viewer'], $shown->json('data.snapshot.download_url'))->assertOk();
    });
});

describe('the list', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('is the versions of the inventory, the newest first, in the envelope of the other lists', function (): void {
        $case = versionTableCase();
        $first = versionApiDone($case, label: 'First');
        $second = versionApiDone($case, $case['team']['editor'], 'Second');
        versionApiDone(['team' => $case['team'], 'inventory' => inventoryIn($case['team']['workspace'], 'Other')]);

        $response = inventoryCall($this, $case['team']['viewer'], 'GET', versionApiUri($case['inventory']))->assertOk();

        expect(array_keys($response->json()))->toBe(['data', 'links', 'meta'])
            ->and(array_column($response->json('data'), 'id'))->toBe([$second->ulid, $first->ulid])
            ->and(array_column($response->json('data'), 'number'))->toBe([2, 1])
            ->and(array_column($response->json('data'), 'label'))->toBe(['Second', 'First'])
            ->and(array_keys($response->json('data.0')))->toBe(VERSION_API_KEYS)
            ->and($response->json('meta.total'))->toBe(2)
            ->and($response->json('meta.per_page'))->toBe(20)
            ->and($response->json('meta.current_page'))->toBe(1)
            ->and($response->json('meta.last_page'))->toBe(1);
    });

    it('is an empty page for an inventory that was never frozen', function (): void {
        $case = versionTableCase();

        $response = inventoryCall($this, $case['team']['viewer'], 'GET', versionApiUri($case['inventory']))->assertOk();

        expect($response->json('data'))->toBe([])
            ->and($response->json('meta.total'))->toBe(0)
            ->and($response->json('meta.from'))->toBeNull();
    });

    it('holds twenty versions a page, and the next page the rest', function (): void {
        $case = versionTableCase(1);

        foreach (range(1, 23) as $number) {
            InventoryVersion::factory()->create(['inventory_id' => $case['inventory']->id, 'number' => $number, 'label' => "No. {$number}", 'user_id' => $case['team']['owner']->id]);
        }

        $first = inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory']))->assertOk();
        $second = inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory']).'?page=2')->assertOk();

        expect($first->json('data'))->toHaveCount(20)
            ->and($first->json('data.0.number'))->toBe(23)
            ->and($first->json('data.19.number'))->toBe(4)
            ->and($first->json('meta.last_page'))->toBe(2)
            ->and($first->json('links.next'))->toEndWith('/versions?page=2')
            ->and(array_column($second->json('data'), 'number'))->toBe([3, 2, 1])
            ->and($second->json('links.next'))->toBeNull();
    });

    it('shows every version to every member, whoever froze it', function (): void {
        $case = versionTableCase();
        versionApiDone($case, $case['team']['editor']);

        foreach (['owner', 'editor', 'viewer'] as $role) {
            expect(inventoryCall($this, $case['team'][$role], 'GET', versionApiUri($case['inventory']))->assertOk()->json('data'))->toHaveCount(1);
        }

        inventoryCall($this, $case['team']['stranger'], 'GET', versionApiUri($case['inventory']))->assertNotFound();
    });

    it('tells each reader what they may do with the versions', function (): void {
        $case = versionTableCase();
        $version = versionApiDone($case);
        $version->forceFill(['signature_status' => SignatureStatus::Awaiting])->save();

        $abilities = fn (string $role): array => inventoryCall($this, $case['team'][$role], 'GET', versionApiUri($case['inventory']))->json('data.0.abilities');

        expect($abilities('owner'))->toBe(['delete' => true, 'cancel_signature' => true, 'retry_signature' => false])
            ->and($abilities('editor'))->toBe(['delete' => false, 'cancel_signature' => true, 'retry_signature' => false])
            ->and($abilities('viewer'))->toBe(['delete' => false, 'cancel_signature' => false, 'retry_signature' => false]);
    });
});

describe('reading a version', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('is the version, for every member', function (): void {
        $case = versionTableCase();
        $version = versionApiDone($case, label: 'Year end');

        foreach (['owner', 'editor', 'viewer'] as $role) {
            $response = inventoryCall($this, $case['team'][$role], 'GET', versionApiUri($case['inventory'], $version))->assertOk();

            expect(array_keys($response->json('data')))->toBe(VERSION_API_KEYS)
                ->and($response->json('data.id'))->toBe($version->ulid)
                ->and($response->json('data.status'))->toBe('completed')
                ->and($response->json('data.pdf.pages'))->toBe($version->pdf_pages)
                ->and($response->json('data.snapshot.sha256'))->toBe($version->snapshot_sha256);
        }
    });

    it('is a 404 for a stranger, an inventory that is not the version\'s, and a version that does not exist', function (): void {
        $case = versionTableCase();
        $version = versionApiDone($case);
        $other = inventoryIn($case['team']['workspace'], 'Other');

        inventoryCall($this, $case['team']['stranger'], 'GET', versionApiUri($case['inventory'], $version))->assertNotFound();
        inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($other, $version))->assertNotFound();
        inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory']).'/01j0000000000000000000000x')->assertNotFound();

        // And so for every other route of the version.
        foreach (['/pdf', '/snapshot'] as $suffix) {
            inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($other, $version, $suffix))->assertNotFound();
        }

        inventoryCall($this, $case['team']['owner'], 'DELETE', versionApiUri($other, $version))->assertNotFound();
        expect(InventoryVersion::query()->count())->toBe(1);
    });

    it('finds the version whatever the case of its identifier', function (): void {
        $case = versionTableCase();
        $version = versionApiDone($case);

        $response = inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory']).'/'.strtoupper($version->ulid))->assertOk();

        expect($response->json('data.id'))->toBe($version->ulid);
    });

    it('tells a failed version why, in the language of the request, and offers no file', function (): void {
        $case = versionTableCase();
        $version = InventoryVersion::factory()->create([
            'inventory_id' => $case['inventory']->id,
            'status' => VersionStatus::Failed,
            'error' => VersionError::Stale,
        ]);

        $english = inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory'], $version))->assertOk();
        $french = inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory'], $version), headers: ['Accept-Language' => 'fr'])->assertOk();

        expect($english->json('data.status'))->toBe('failed')
            ->and($english->json('data.error'))->toBe('This version did not finish and was cancelled. Delete it and freeze the inventory again.')
            ->and($french->json('data.error'))->toBe('Cette version ne s\'est pas terminée et a été annulée. Supprimez-la et figez de nouveau l\'inventaire.')
            ->and($english->json('data.pdf'))->toBeNull();
    });

    it('keeps the author\'s name when their account is gone', function (): void {
        $case = versionTableCase();
        $version = versionApiDone($case, $case['team']['editor']);
        DB::table('inventory_versions')->where('id', $version->id)->update(['user_id' => null]);

        $response = inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory'], $version))->assertOk();

        expect($response->json('data.author'))->toBe(['name' => 'Editor']);
    });

    it('tells where the signature stands, signer by signer, and only what is public about them', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $version = versionApiDone($case, signers: versionSigners(3));
        $signers = $version->signers ?? [];
        $signers[0] = ['status' => SignerStatus::Signed->value, 'signed_at' => '2026-10-15T12:30:00+00:00', 'recipient_id' => 11] + $signers[0];
        $signers[1] = ['status' => SignerStatus::Rejected->value, 'rejection_reason' => 'Not mine', 'recipient_id' => 12] + $signers[1];
        $version->forceFill([
            'signature_status' => SignatureStatus::Awaiting,
            'signature_requested_at' => '2026-10-15 10:00:00',
            'signers' => $signers,
        ])->save();

        $signature = inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory'], $version))->assertOk()->json('data.signature');

        expect($signature['status'])->toBe('awaiting')
            ->and($signature['error'])->toBeNull()
            ->and($signature['requested_at'])->toBe('2026-10-15T10:00:00+00:00')
            ->and($signature['completed_at'])->toBeNull()
            ->and($signature['signers'][0])->toBe(['name' => 'Signer 1', 'email' => 'signer1@example.test', 'status' => 'signed', 'signed_at' => '2026-10-15T12:30:00+00:00', 'rejection_reason' => null])
            ->and($signature['signers'][1])->toBe(['name' => 'Signer 2', 'email' => 'signer2@example.test', 'status' => 'rejected', 'signed_at' => null, 'rejection_reason' => 'Not mine'])
            ->and($signature['signers'][2]['status'])->toBe('pending')
            ->and($signature['signed_pdf'])->toBeNull();
    });

    it('derives the abilities on the signature from the role and the state', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $version = versionApiDone($case, signers: versionSigners(1));
        $abilities = function (string $role) use ($case, $version): array {
            return inventoryCall($this, $case['team'][$role], 'GET', versionApiUri($case['inventory'], $version))->json('data.abilities');
        };
        $set = function (SignatureStatus $status, VersionStatus $on = VersionStatus::Completed) use ($version): void {
            $version->forceFill(['signature_status' => $status, 'status' => $on])->save();
        };

        // A request that waits for its signers can be cancelled, by those who may change the inventory.
        $set(SignatureStatus::Awaiting);
        expect($abilities('editor'))->toBe(['delete' => false, 'cancel_signature' => true, 'retry_signature' => false]);

        // One that failed on a completed version can be sent again, and nothing else can.
        $set(SignatureStatus::Failed);
        expect($abilities('editor'))->toBe(['delete' => false, 'cancel_signature' => false, 'retry_signature' => true])
            ->and($abilities('owner'))->toBe(['delete' => true, 'cancel_signature' => false, 'retry_signature' => true])
            ->and($abilities('viewer'))->toBe(['delete' => false, 'cancel_signature' => false, 'retry_signature' => false]);

        $set(SignatureStatus::Failed, VersionStatus::Failed);
        expect($abilities('owner')['retry_signature'])->toBeFalse();

        foreach ([SignatureStatus::Requested, SignatureStatus::Sending, SignatureStatus::Completed, SignatureStatus::Rejected, SignatureStatus::Cancelled] as $status) {
            $set($status);
            expect($abilities('owner'))->toBe(['delete' => true, 'cancel_signature' => false, 'retry_signature' => false], $status->value);
        }
    });

    it('says why a request for signatures failed, in the language of the request', function (): void {
        versionDocumenso();
        $case = versionTableCase();
        $version = versionApiDone($case, signers: versionSigners(1));
        $version->forceFill(['signature_status' => SignatureStatus::Failed, 'signature_error' => SignatureError::DocumensoUnavailable])->save();

        $english = inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory'], $version))->json('data.signature.error');
        $french = inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory'], $version), headers: ['Accept-Language' => 'fr'])->json('data.signature.error');

        expect($english)->toBe('The signature service could not be reached. Try again later.')
            ->and($french)->toBe('Le service de signature est injoignable. Réessayez plus tard.');
    });
});

describe('deleting a version', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('is a 204 that gives its bytes back to the storage of the workspace and has its files purged once it is committed', function (): void {
        $case = versionTableCase();
        $kept = versionApiDone($case);
        $gone = versionApiDone($case);
        $workspace = $case['team']['workspace'];
        $used = (int) DB::table('workspaces')->where('id', $workspace->id)->value('storage_used');

        $response = inventoryCall($this, $case['team']['owner'], 'DELETE', versionApiUri($case['inventory'], $gone))->assertNoContent();

        expect($response->getContent())->toBe('')
            ->and(InventoryVersion::query()->pluck('id')->all())->toBe([$kept->id])
            ->and((int) DB::table('workspaces')->where('id', $workspace->id)->value('storage_used'))->toBe($used - $gone->counted_bytes)
            ->and($gone->counted_bytes)->toBeGreaterThan(0);

        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [$gone->directory()]);
        inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory'], $gone))->assertNotFound();
        inventoryCall($this, $case['team']['owner'], 'GET', versionApiUri($case['inventory'], $kept))->assertOk();
    });

    it('is for owners: an editor or a viewer is refused without a code, a stranger told it is not there', function (): void {
        $case = versionTableCase();
        $version = versionApiDone($case);

        $editor = inventoryCall($this, $case['team']['editor'], 'DELETE', versionApiUri($case['inventory'], $version))->assertForbidden();
        inventoryCall($this, $case['team']['viewer'], 'DELETE', versionApiUri($case['inventory'], $version))->assertForbidden();
        inventoryCall($this, $case['team']['stranger'], 'DELETE', versionApiUri($case['inventory'], $version))->assertNotFound();

        expect($editor->json())->toBe(['message' => 'Only an owner of this workspace can delete an official version.'])
            ->and(InventoryVersion::query()->count())->toBe(1);
    });

    it('takes a version that is still being written, and one that failed', function (): void {
        $case = versionTableCase();
        $pending = versionFreeze($case['inventory'], $case['team']['owner']);
        $failed = InventoryVersion::factory()->create(['inventory_id' => $case['inventory']->id, 'number' => 2, 'status' => VersionStatus::Failed, 'error' => VersionError::GenerationFailed]);
        $used = (int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used');

        inventoryCall($this, $case['team']['owner'], 'DELETE', versionApiUri($case['inventory'], $pending))->assertNoContent();
        inventoryCall($this, $case['team']['owner'], 'DELETE', versionApiUri($case['inventory'], $failed))->assertNoContent();

        expect(InventoryVersion::query()->count())->toBe(0)
            ->and((int) DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('storage_used'))->toBe($used);
    });

    it('is a 404 the second time', function (): void {
        $case = versionTableCase();
        $version = versionApiDone($case);

        inventoryCall($this, $case['team']['owner'], 'DELETE', versionApiUri($case['inventory'], $version))->assertNoContent();
        inventoryCall($this, $case['team']['owner'], 'DELETE', versionApiUri($case['inventory'], $version))->assertNotFound();
    });

    it('numbers the next version after the highest one that remains', function (): void {
        $case = versionTableCase();
        $one = versionApiDone($case);
        $two = versionApiDone($case);
        $three = versionApiDone($case);

        inventoryCall($this, $case['team']['owner'], 'DELETE', versionApiUri($case['inventory'], $two))->assertNoContent();
        $next = versionApiPost($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);

        expect([$one->number, $three->number, $next->json('data.number')])->toBe([1, 3, 4]);
    });
});

describe('the tokens', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('read with the read ability, write with the write ability, and each does only that', function (): void {
        $case = versionTableCase();
        $owner = $case['team']['owner'];
        $version = versionApiDone($case);
        $read = tokenFor($owner, ['read']);
        $write = tokenFor($owner, ['write']);

        foreach (['', '/'.$version->ulid, '/'.$version->ulid.'/pdf', '/'.$version->ulid.'/snapshot'] as $suffix) {
            tokenCall($this, $read, 'GET', versionApiUri($case['inventory']).$suffix)->assertOk();
            tokenCall($this, $write, 'GET', versionApiUri($case['inventory']).$suffix)->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        }

        tokenCall($this, $read, 'POST', versionApiUri($case['inventory']))->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, $read, 'DELETE', versionApiUri($case['inventory'], $version))->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, tokenFor($owner, ['manage']), 'POST', versionApiUri($case['inventory']))->assertForbidden()->assertJsonPath('code', 'token_ability_missing');

        expect(InventoryVersion::query()->count())->toBe(1);

        tokenCall($this, $write, 'POST', versionApiUri($case['inventory']), ['label' => 'From a script'])->assertStatus(202)->assertJsonPath('data.label', 'From a script');
        tokenCall($this, $write, 'DELETE', versionApiUri($case['inventory'], $version))->assertNoContent();
        expect(InventoryVersion::query()->count())->toBe(1);
    });

    it('never go beyond the role of their owner', function (): void {
        $case = versionTableCase();
        $version = versionApiDone($case);

        tokenCall($this, tokenFor($case['team']['viewer'], ['write']), 'POST', versionApiUri($case['inventory']))->assertForbidden()->assertJsonMissingPath('code');
        tokenCall($this, tokenFor($case['team']['editor'], ['write']), 'DELETE', versionApiUri($case['inventory'], $version))->assertForbidden()->assertJsonMissingPath('code');
        tokenCall($this, tokenFor($case['team']['viewer'], ['read']), 'GET', versionApiUri($case['inventory'], $version))->assertOk();
        tokenCall($this, tokenFor($case['team']['stranger'], ['read']), 'GET', versionApiUri($case['inventory'], $version))->assertNotFound();
    });

    it('do not use up the versions a person may freeze when the token may not write', function (): void {
        config(['inventories.versions_per_hour' => 1]);
        $case = versionTableCase();
        $owner = $case['team']['owner'];

        tokenCall($this, tokenFor($owner, ['read']), 'POST', versionApiUri($case['inventory']))->assertForbidden();
        tokenCall($this, tokenFor($owner, ['write']), 'POST', versionApiUri($case['inventory']))->assertStatus(202);
        tokenCall($this, tokenFor($owner, ['write']), 'POST', versionApiUri($case['inventory']))->assertStatus(429);
    });
});

describe('the files on the disk', function (): void {
    it('are where the version says, under the folder of its inventory', function (): void {
        Queue::fake();
        $case = versionTableCase();
        $version = versionApiDone($case);
        $folder = "workspaces/{$case['team']['workspace']->ulid}/inventories/{$case['inventory']->ulid}/versions/{$version->ulid}";

        expect(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles($folder))->toBe(["{$folder}/official.pdf", "{$folder}/snapshot.json"]);
    });
});

describe('the purge of the files', function (): void {
    it('takes the files of the version away when the queue works, and leaves the other versions\' alone', function (): void {
        $case = versionTableCase();
        $first = versionFreeze($case['inventory'], $case['team']['owner'])->fresh();
        $second = versionFreeze($case['inventory'], $case['team']['owner'])->fresh();

        // The queue of the tests is synchronous: both PDFs are written, and the purge runs as soon as the deletion is committed.
        expect($first?->status)->toBe(VersionStatus::Completed);

        inventoryCall($this, $case['team']['owner'], 'DELETE', versionApiUri($case['inventory'], $first))->assertNoContent();

        $files = storedFiles();

        expect(array_filter($files, fn (string $file): bool => str_contains($file, (string) $first?->ulid)))->toBe([])
            ->and($files)->toContain((string) $second?->snapshot_path, (string) $second?->pdf_path);
    });
});
