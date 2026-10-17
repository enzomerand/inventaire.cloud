<?php

declare(strict_types=1);

use App\Inventories\Inventories;
use App\Inventories\Versions\OfficialVersions;
use App\Inventories\Versions\SignatureStatus;
use App\Jobs\CancelDocumensoEnvelopes;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Signatures\DocumensoClient;
use App\Signatures\EnvelopeCleanup;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeDocumenso;

require_once __DIR__.'/../../Support/tokens.php';
require_once __DIR__.'/../../Support/versions.php';

/*
 * The signers of a request that is still open must not be left with a document to sign when its version, its inventory or its workspace
 * is deleted: the envelopes are cancelled at Documenso in the background, once the deletion is committed. Only the open requests: a
 * request that is over, was never sent, or has no envelope has nothing to cancel.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->useLanguage('en');
    versionDocumenso();
});

/**
 * A bare version of an inventory, in a state of its request for signatures.
 *
 * @param  array<string, mixed>  $attributes
 */
function cancelRow(Inventory $inventory, int $number, ?SignatureStatus $status, ?string $envelope, array $attributes = []): InventoryVersion
{
    return InventoryVersion::factory()->create(array_merge([
        'inventory_id' => $inventory->id,
        'number' => $number,
        'signature_status' => $status,
        'documenso_envelope_id' => $envelope,
    ], $attributes));
}

/**
 * The envelopes the jobs of the queue cancel, all together and sorted.
 *
 * @return list<string>
 */
function cancelQueued(): array
{
    $ids = Queue::pushed(CancelDocumensoEnvelopes::class)->flatMap(fn (CancelDocumensoEnvelopes $job): array => $job->envelopeIds)->all();
    sort($ids);

    return array_values($ids);
}

describe('deleting a version', function (): void {
    it('cancels its envelope when its request has not ended: asked for again, being sent, awaiting, or failed', function (SignatureStatus $status): void {
        $case = versionTableCase();
        $version = cancelRow($case['inventory'], 1, $status, 'envelope_open');

        app(OfficialVersions::class)->delete($version);

        Queue::assertPushed(CancelDocumensoEnvelopes::class, 1);
        expect(cancelQueued())->toBe(['envelope_open'])
            ->and(InventoryVersion::query()->count())->toBe(0);
    })->with([SignatureStatus::Awaiting, SignatureStatus::Sending, SignatureStatus::Requested, SignatureStatus::Failed]);

    it('has nothing to cancel when its request is over, never sent, or has no envelope', function (?SignatureStatus $status, ?string $envelope): void {
        $case = versionTableCase();
        $version = cancelRow($case['inventory'], 1, $status, $envelope);

        app(OfficialVersions::class)->delete($version);

        Queue::assertNotPushed(CancelDocumensoEnvelopes::class);
        expect(InventoryVersion::query()->count())->toBe(0);
    })->with([
        'asked for' => [SignatureStatus::Requested, null],
        'failed, no envelope' => [SignatureStatus::Failed, null],
        'being sent, no envelope yet' => [SignatureStatus::Sending, null],
        'awaiting, no envelope (cannot be)' => [SignatureStatus::Awaiting, null],
        'completed' => [SignatureStatus::Completed, 'envelope_done'],
        'rejected' => [SignatureStatus::Rejected, 'envelope_done'],
        'cancelled' => [SignatureStatus::Cancelled, 'envelope_done'],
        'no signature asked for' => [null, null],
    ]);

    it('cancels once the deletion is committed, not before, and not at all when it is rolled back', function (): void {
        $case = versionTableCase();
        $version = cancelRow($case['inventory'], 1, SignatureStatus::Awaiting, 'envelope_open');

        try {
            DB::transaction(function () use ($version): void {
                app(OfficialVersions::class)->delete($version);

                Queue::assertNotPushed(CancelDocumensoEnvelopes::class);

                throw new RuntimeException('The caller changes its mind.');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNotPushed(CancelDocumensoEnvelopes::class);
        expect(InventoryVersion::query()->count())->toBe(1);

        DB::transaction(fn () => app(OfficialVersions::class)->delete($version));

        expect(cancelQueued())->toBe(['envelope_open']);
    });

    it('is done by the API, for an owner', function (): void {
        $made = versionAwaiting(2);
        Queue::fake();

        inventoryCall($this, $made['case']['team']['owner'], 'DELETE', "/api/v1/inventories/{$made['case']['inventory']->ulid}/versions/{$made['version']->ulid}")->assertNoContent();

        expect(cancelQueued())->toBe(['envelope_test1']);
    });

    it('is not done by an editor, who cannot delete: the request stays open', function (): void {
        $made = versionAwaiting(2);
        Queue::fake();

        inventoryCall($this, $made['case']['team']['editor'], 'DELETE', "/api/v1/inventories/{$made['case']['inventory']->ulid}/versions/{$made['version']->ulid}")->assertForbidden();

        Queue::assertNotPushed(CancelDocumensoEnvelopes::class);
        expect($made['version']->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
    });

    it('has the signers told, once the job runs: the envelope is cancelled at Documenso', function (): void {
        $made = versionAwaiting(2);
        Queue::fake();
        app(OfficialVersions::class)->delete($made['version']);

        Queue::pushed(CancelDocumensoEnvelopes::class)->each(fn (CancelDocumensoEnvelopes $job) => $job->handle(app(DocumensoClient::class)));

        expect($made['fake']->count('POST /envelope/cancel'))->toBe(1)
            ->and($made['fake']->requestsOf('POST /envelope/cancel')[0]->data())->toBe(['envelopeId' => 'envelope_test1', 'reason' => 'This signature request was cancelled.'])
            ->and($made['fake']->status)->toBe('CANCELLED');
    });
});

describe('deleting an inventory', function (): void {
    it('cancels the open envelopes of all its versions, and only those', function (): void {
        $case = versionTableCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        cancelRow($case['inventory'], 1, SignatureStatus::Awaiting, 'envelope_a');
        cancelRow($case['inventory'], 2, SignatureStatus::Sending, 'envelope_b');
        cancelRow($case['inventory'], 3, SignatureStatus::Completed, 'envelope_c');
        cancelRow($case['inventory'], 4, SignatureStatus::Awaiting, null);
        cancelRow($case['inventory'], 5, null, null);
        // A request that failed may nevertheless be pending at Documenso: the answer of the call that sent it was lost.
        cancelRow($case['inventory'], 6, SignatureStatus::Failed, 'envelope_d');
        cancelRow($case['inventory'], 7, SignatureStatus::Requested, 'envelope_e');
        cancelRow($other, 1, SignatureStatus::Awaiting, 'envelope_elsewhere');

        app(Inventories::class)->delete($case['inventory']);

        expect(cancelQueued())->toBe(['envelope_a', 'envelope_b', 'envelope_d', 'envelope_e'])
            ->and(InventoryVersion::query()->pluck('documenso_envelope_id')->all())->toBe(['envelope_elsewhere']);
    });

    it('works out the envelopes before the database removes the versions, whichever way the model is deleted', function (): void {
        $case = versionTableCase();
        cancelRow($case['inventory'], 1, SignatureStatus::Awaiting, 'envelope_a');

        $case['inventory']->delete();

        expect(cancelQueued())->toBe(['envelope_a'])
            ->and(InventoryVersion::query()->count())->toBe(0);
    });

    it('cancels nothing when the deletion is rolled back', function (): void {
        $case = versionTableCase();
        cancelRow($case['inventory'], 1, SignatureStatus::Awaiting, 'envelope_a');

        try {
            DB::transaction(function () use ($case): void {
                app(Inventories::class)->delete($case['inventory']);

                throw new RuntimeException('The caller changes its mind.');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNotPushed(CancelDocumensoEnvelopes::class);
        expect(InventoryVersion::query()->count())->toBe(1);
    });

    it('is done by the API', function (): void {
        $made = versionAwaiting(1);
        Queue::fake();

        tokenCall($this, tokenFor($made['case']['team']['owner'], ['manage']), 'DELETE', "/api/v1/inventories/{$made['case']['inventory']->ulid}")->assertNoContent();

        expect(cancelQueued())->toBe(['envelope_test1']);
    });

    it('queues nothing for an inventory whose versions hold no open request', function (): void {
        $case = versionTableCase();
        versionRun(versionFreeze($case['inventory'], $case['team']['owner']));

        app(Inventories::class)->delete($case['inventory']);

        Queue::assertNotPushed(CancelDocumensoEnvelopes::class);
    });
});

describe('deleting a workspace', function (): void {
    it('cancels the open envelopes of the versions of all its inventories, and of no other workspace', function (): void {
        $case = versionTableCase();
        $second = inventoryIn($case['team']['workspace'], 'Second');
        $elsewhere = inventoryIn(app(Workspaces::class)->create($case['team']['owner'], 'Elsewhere'), 'Elsewhere');
        cancelRow($case['inventory'], 1, SignatureStatus::Awaiting, 'envelope_a');
        cancelRow($second, 1, SignatureStatus::Sending, 'envelope_b');
        cancelRow($second, 2, SignatureStatus::Cancelled, 'envelope_c');
        cancelRow($second, 3, SignatureStatus::Failed, 'envelope_d');
        cancelRow($elsewhere, 1, SignatureStatus::Awaiting, 'envelope_elsewhere');

        app(Workspaces::class)->delete($case['team']['owner'], $case['team']['workspace']);

        expect(cancelQueued())->toBe(['envelope_a', 'envelope_b', 'envelope_d'])
            ->and(InventoryVersion::query()->pluck('documenso_envelope_id')->all())->toBe(['envelope_elsewhere']);
    });

    it('is done when the model is deleted, whichever way', function (): void {
        $case = versionTableCase();
        cancelRow($case['inventory'], 1, SignatureStatus::Awaiting, 'envelope_a');

        $case['team']['workspace']->delete();

        expect(cancelQueued())->toBe(['envelope_a']);
    });

    it('is done when the account that alone owns the workspace is deleted', function (): void {
        $solo = inventoryPerson('Solo');
        $workspace = Workspace::query()->whereHas('members', fn ($members) => $members->where('users.id', $solo->id))->sole();
        $inventory = inventoryIn($workspace, 'Mine');
        cancelRow($inventory, 1, SignatureStatus::Awaiting, 'envelope_solo');
        cancelRow($inventory, 2, SignatureStatus::Completed, 'envelope_done');

        $solo->delete();

        expect(User::query()->whereKey($solo->id)->exists())->toBeFalse()
            ->and(InventoryVersion::query()->count())->toBe(0)
            ->and(cancelQueued())->toBe(['envelope_solo']);
    });

    it('cancels nothing when the deletion is rolled back', function (): void {
        $case = versionTableCase();
        cancelRow($case['inventory'], 1, SignatureStatus::Awaiting, 'envelope_a');

        try {
            DB::transaction(function () use ($case): void {
                $case['team']['workspace']->delete();

                throw new RuntimeException('The caller changes its mind.');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNotPushed(CancelDocumensoEnvelopes::class);
        expect(InventoryVersion::query()->count())->toBe(1);
    });
});

describe('the envelopes of many versions', function (): void {
    it('go to the jobs ten at a time, whichever way they were deleted', function (string $how): void {
        $case = versionTableCase();

        foreach (range(1, 25) as $number) {
            cancelRow($case['inventory'], $number, SignatureStatus::Awaiting, sprintf('envelope_%02d', $number));
        }

        match ($how) {
            'the inventory' => app(Inventories::class)->delete($case['inventory']),
            'the workspace' => app(Workspaces::class)->delete($case['team']['owner'], $case['team']['workspace']),
        };

        $jobs = Queue::pushed(CancelDocumensoEnvelopes::class);

        expect($jobs->map(fn (CancelDocumensoEnvelopes $job): int => count($job->envelopeIds))->all())->toBe([10, 10, 5])
            ->and(cancelQueued())->toHaveCount(25);
    })->with(['the inventory', 'the workspace']);

    it('go to the jobs five at a time when the drafts are deleted too: each envelope is read first, which is a second call', function (): void {
        $ids = array_map(fn (int $number): string => sprintf('envelope_%02d', $number), range(1, 12));

        app(EnvelopeCleanup::class)->cancelAfterCommit($ids, deleteDrafts: true);

        $jobs = Queue::pushed(CancelDocumensoEnvelopes::class);

        expect($jobs->map(fn (CancelDocumensoEnvelopes $job): int => count($job->envelopeIds))->all())->toBe([5, 5, 2])
            ->and($jobs->every(fn (CancelDocumensoEnvelopes $job): bool => $job->deleteDrafts))->toBeTrue()
            ->and(cancelQueued())->toBe($ids);
    });

    it('go to a job of their own when one version goes, however many it is', function (): void {
        $case = versionTableCase();
        $version = cancelRow($case['inventory'], 1, SignatureStatus::Awaiting, 'envelope_a');

        app(OfficialVersions::class)->delete($version);

        expect(Queue::pushed(CancelDocumensoEnvelopes::class)->map(fn (CancelDocumensoEnvelopes $job): array => $job->envelopeIds)->all())->toBe([['envelope_a']]);
    });

    it('are chunked by the one place that queues the cancellations', function (): void {
        $source = (string) file_get_contents(app_path('Signatures/EnvelopeCleanup.php'));

        expect(substr_count($source, 'CancelDocumensoEnvelopes::dispatch('))->toBe(1)
            ->and($source)->toContain('array_chunk($envelopeIds, self::perJob($deleteDrafts))');
    });
});

describe('the job', function (): void {
    it('is tried three times, with a growing pause', function (): void {
        $job = new CancelDocumensoEnvelopes(['envelope_a']);

        expect($job->tries)->toBe(3)->and($job->backoff())->toBe([60, 300])->and($job->queue)->toBeNull();
    });

    it('may run as long as the calls of its envelopes take at the longest, with room: ten of one call, or five of two when drafts are deleted', function (bool $deleteDrafts, int $calls): void {
        $job = new CancelDocumensoEnvelopes(['envelope_a'], null, $deleteDrafts);
        $worst = EnvelopeCleanup::perJob($deleteDrafts) * $calls * DocumensoClient::TIMEOUT;

        expect(EnvelopeCleanup::perJob($deleteDrafts))->toBe($deleteDrafts ? 5 : 10)
            ->and(DocumensoClient::TIMEOUT)->toBe(20)
            ->and($worst)->toBe(200)
            ->and($job->timeout)->toBe(300)
            ->and($job->timeout)->toBeGreaterThan($worst);
    })->with([[false, 1], [true, 2]]);

    it('makes at most two calls for an envelope, so that the bound above holds', function (): void {
        $fake = FakeDocumenso::install();
        $fake->envelopeId = 'envelope_a';
        $fake->exists = true;

        foreach (['DRAFT', 'PENDING'] as $status) {
            $fake->status = $status;
            $before = count($fake->calls);

            (new CancelDocumensoEnvelopes(['envelope_a'], null, true))->handle(app(DocumensoClient::class));

            expect(count($fake->calls) - $before)->toBeLessThanOrEqual(2);
        }
    });

    it('cancels each envelope with the reason it was given, or a general one', function (): void {
        $fake = FakeDocumenso::install();
        $fake->envelopeId = 'envelope_a';
        $fake->exists = true;
        $fake->status = 'PENDING';

        (new CancelDocumensoEnvelopes(['envelope_a', 'envelope_b'], 'The version was deleted.'))->handle(app(DocumensoClient::class));
        (new CancelDocumensoEnvelopes(['envelope_c']))->handle(app(DocumensoClient::class));

        expect(array_map(fn (Request $request): array => $request->data(), $fake->requestsOf('POST /envelope/cancel')))->toBe([
            ['envelopeId' => 'envelope_a', 'reason' => 'The version was deleted.'],
            ['envelopeId' => 'envelope_b', 'reason' => 'The version was deleted.'],
            ['envelopeId' => 'envelope_c', 'reason' => 'This signature request was cancelled.'],
        ]);
    });

    it('leaves alone an envelope that is not pending any more, and goes on with the others', function (): void {
        Log::spy();
        $fake = FakeDocumenso::install();
        $fake->status = 'PENDING';
        $fake->failNext('POST /envelope/cancel', 400)->failNext('POST /envelope/cancel', 404);

        (new CancelDocumensoEnvelopes(['envelope_a', 'envelope_b', 'envelope_c']))->handle(app(DocumensoClient::class));

        // No failure: the job is done, it asked for all three, and the last one was cancelled.
        expect($fake->count('POST /envelope/cancel'))->toBe(3)
            ->and($fake->status)->toBe('CANCELLED');
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $context['again'] === false)->twice();
    });

    it('writes an error in the log, without the token, when Documenso refuses the token or answers what it should not', function (int $status): void {
        Log::spy();
        $fake = FakeDocumenso::install();
        $fake->failNext('POST /envelope/cancel', $status);

        (new CancelDocumensoEnvelopes(['envelope_a', 'envelope_b']))->handle(app(DocumensoClient::class));

        // Not retried (nothing is thrown), the others still asked for, and somebody is told.
        expect($fake->count('POST /envelope/cancel'))->toBe(2);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['envelope'] === 'envelope_a'
            && $context['status'] === $status
            && ! str_contains((string) json_encode($context), 'api_test_key')
            && ! str_contains($message, 'api_test_key'))->once();
    })->with([
        'the token is refused' => [401],
        'forbidden' => [403],
        'a method that is not allowed' => [405],
        'gone' => [410],
    ]);

    it('writes no error in the log for what is only an envelope that is not pending any more, or gone', function (int $status): void {
        Log::spy();
        $fake = FakeDocumenso::install();
        $fake->failNext('POST /envelope/cancel', $status);

        (new CancelDocumensoEnvelopes(['envelope_a']))->handle(app(DocumensoClient::class));

        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('info')->once();
    })->with([[400], [404], [409], [422]]);

    it('still retries what is worth another try, and writes no error for it yet', function (int $status): void {
        Log::spy();
        $fake = FakeDocumenso::install();
        $fake->failNext('POST /envelope/cancel', $status);

        expect(fn () => (new CancelDocumensoEnvelopes(['envelope_a']))->handle(app(DocumensoClient::class)))->toThrow(RuntimeException::class);
        Log::shouldNotHaveReceived('error');
    })->with([[429], [500], [502], [503]]);

    it('deletes the draft it is told to, and cancels what is pending, and leaves the rest alone', function (): void {
        $fake = FakeDocumenso::install();
        $fake->other('envelope_draft', 'DRAFT', null)->other('envelope_pending', 'PENDING', null)->other('envelope_done', 'COMPLETED', null);

        (new CancelDocumensoEnvelopes(['envelope_draft', 'envelope_pending', 'envelope_done', 'envelope_gone'], null, true))->handle(app(DocumensoClient::class));

        $data = fn (string $call): array => array_map(fn (Request $request): array => $request->data(), $fake->requestsOf($call));

        expect($data('POST /envelope/delete'))->toBe([['envelopeId' => 'envelope_draft']])
            ->and(array_column($data('POST /envelope/cancel'), 'envelopeId'))->toBe(['envelope_pending', 'envelope_done'])
            ->and($fake->others['envelope_pending']['status'])->toBe('CANCELLED')
            ->and($fake->others['envelope_done']['status'])->toBe('COMPLETED')
            ->and($fake->others)->not->toHaveKey('envelope_draft');
    });

    it('does not read an envelope before it cancels it unless it was told to delete drafts', function (): void {
        $fake = FakeDocumenso::install();
        $fake->other('envelope_a', 'PENDING', null);

        (new CancelDocumensoEnvelopes(['envelope_a']))->handle(app(DocumensoClient::class));

        expect($fake->count('GET /envelope/{id}'))->toBe(0)->and($fake->count('POST /envelope/cancel'))->toBe(1);
    });

    it('fails for another try when Documenso cannot be reached, after it asked for all of them', function (?int $status): void {
        $fake = FakeDocumenso::install();
        $fake->failNext('POST /envelope/cancel', $status);

        expect(fn () => (new CancelDocumensoEnvelopes(['envelope_a', 'envelope_b']))->handle(app(DocumensoClient::class)))
            ->toThrow(RuntimeException::class, '1 envelope(s) could not be cancelled at Documenso yet.');

        expect($fake->count('POST /envelope/cancel'))->toBe(2);
    })->with([
        'a lost connection' => [null],
        'too many requests' => [429],
        'a server error' => [500],
        'unavailable' => [503],
    ]);

    it('does nothing when the instance lost its Documenso, and says so', function (): void {
        Log::spy();
        $fake = FakeDocumenso::install();
        config(['services.documenso.api_key' => '']);

        (new CancelDocumensoEnvelopes(['envelope_a']))->handle(app(DocumensoClient::class));

        expect($fake->calls)->toBe([]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['envelopes'] === ['envelope_a'])->once();
    });

    it('writes in the log what it could not cancel once the last try failed, so that somebody can do it by hand', function (): void {
        Log::spy();

        (new CancelDocumensoEnvelopes(['envelope_a', 'envelope_b']))->failed(new RuntimeException('gave up'));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['envelopes'] === ['envelope_a', 'envelope_b'] && ! str_contains((string) json_encode($context), 'api_test_key'))->once();
    });

    it('is written in the log when the queue cannot be reached, and the deletion goes through', function (): void {
        Log::spy();
        $case = versionTableCase();
        $version = cancelRow($case['inventory'], 1, SignatureStatus::Awaiting, 'envelope_open');
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is down.')));

        app(OfficialVersions::class)->delete($version);

        expect(InventoryVersion::query()->count())->toBe(0);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'cancel them in Documenso by hand') && $context['envelopes'] === ['envelope_open'])->once();
    });
});
