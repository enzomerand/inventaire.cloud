<?php

declare(strict_types=1);

use App\Inventories\Versions\OfficialVersions;
use App\Inventories\Versions\SignatureStatus;
use App\Jobs\RequestSignatures;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Models\User;
use App\Signatures\SignatureQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/versions.php';

/*
 * The requests for signatures a workspace may make in a day: Documenso emails every signer, at addresses the person types, so the
 * instance must not be a relay for whoever can edit a workspace. A version frozen with signers and a failed request sent again count;
 * anything else does not. Beyond the limit the API answers 429 with Retry-After, as it does for every pace it holds.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->useLanguage('en');
    versionDocumenso();
});

/**
 * Freezes an inventory with signers through the API, as a person of the workspace.
 */
function quotaFreeze(object $test, ?User $as, Inventory $inventory, int $signers = 1): TestResponse
{
    return inventoryCall($test, $as, 'POST', "/api/v1/inventories/{$inventory->ulid}/versions", ['signers' => versionSigners($signers)]);
}

/**
 * A completed version whose request failed, which may be sent again.
 */
function quotaFailed(Inventory $inventory, User $owner): InventoryVersion
{
    $version = versionRun(versionFreeze($inventory, $owner));
    $version->forceFill(['signature_status' => SignatureStatus::Failed, 'signers' => [['name' => 'Ada', 'email' => 'ada@example.test', 'status' => 'pending', 'signed_at' => null, 'rejection_reason' => null, 'recipient_id' => null]]])->save();

    return $version;
}

describe('the limit', function (): void {
    it('is 20 a day by default, set by INVENTORIES_SIGNATURE_REQUESTS_PER_DAY', function (): void {
        expect(config('inventories.signature_requests_per_workspace_per_day'))->toBe(20)
            ->and((string) file_get_contents(config_path('inventories.php')))->toContain('INVENTORIES_SIGNATURE_REQUESTS_PER_DAY')->toContain('signature_requests_per_workspace_per_day');
    });

    it('is the one of the workspace: whoever asks, and each workspace has its own', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 2]);
        $case = versionTableCase();
        $other = inventoryTeam();
        $elsewhere = inventoryIn($other['workspace'], 'Elsewhere');

        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
        quotaFreeze($this, $case['team']['editor'], $case['inventory'])->assertStatus(202);
        $refused = quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(429);

        expect($refused->json('code'))->toBe('too_many_requests')
            ->and($refused->json('message'))->toBe('Too many attempts. Please wait a moment before trying again.')
            ->and($refused->headers->get('Content-Type'))->toContain('application/json')
            ->and(InventoryVersion::query()->count())->toBe(2);

        // Another workspace has its own count.
        quotaFreeze($this, $other['owner'], $elsewhere)->assertStatus(202);
    });

    it('says how long to wait, in the headers every other 429 of the API has, and never more than a day', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 1]);
        $case = versionTableCase();

        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
        $refused = quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(429);

        expect((int) $refused->headers->get('Retry-After'))->toBeBetween(86300, 86400)
            ->and($refused->headers->get('X-RateLimit-Limit'))->toBe('1')
            ->and($refused->headers->get('X-RateLimit-Remaining'))->toBe('0')
            ->and((int) $refused->headers->get('X-RateLimit-Reset'))->toBeGreaterThan(time() + 86000);
    });

    it('starts again a day after the first request of the window', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 1]);
        $case = versionTableCase();

        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(429);

        $this->travel(23)->hours();
        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(429);

        $this->travel(2)->hours();
        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
    });

    it('counts a version frozen with signers, not one frozen with none', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 1]);
        $case = versionTableCase();

        foreach (range(1, 3) as $ignored) {
            inventoryCall($this, $case['team']['owner'], 'POST', "/api/v1/inventories/{$case['inventory']->ulid}/versions")->assertStatus(202);
        }

        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(429);

        // Nobody is asked to sign: nothing is sent, so nothing is held back.
        inventoryCall($this, $case['team']['owner'], 'POST', "/api/v1/inventories/{$case['inventory']->ulid}/versions")->assertStatus(202);
    });

    it('does not count a request that was refused for another reason', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 1, 'inventories.max_versions_per_inventory' => 1]);
        $case = versionTableCase();
        inventoryCall($this, $case['team']['owner'], 'POST', "/api/v1/inventories/{$case['inventory']->ulid}/versions")->assertStatus(202);

        // The inventory is full (409), the signer is no address (422): neither sent anything.
        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(409);
        inventoryCall($this, $case['team']['owner'], 'POST', "/api/v1/inventories/{$case['inventory']->ulid}/versions", ['signers' => [['name' => 'Ada', 'email' => 'nope']]])->assertStatus(422);

        config(['inventories.max_versions_per_inventory' => 5]);

        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
    });

    it('is told before the freeze, so that nothing is frozen, stored or queued when it is reached', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 1]);
        $case = versionTableCase();
        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
        $files = storedFiles();
        Queue::fake();

        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(429);

        expect(InventoryVersion::query()->count())->toBe(1)
            ->and(storedFiles())->toBe($files)
            ->and($case['inventory']->fresh()->last_version_number)->toBe(1);
        Queue::assertNothingPushed();
    });

    it('is an exception the application renders as any other pace: the service throws it', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 1]);
        $case = versionTableCase();

        app(OfficialVersions::class)->create($case['inventory'], $case['team']['owner'], null, versionSigners(1));

        try {
            app(OfficialVersions::class)->create($case['inventory'], $case['team']['owner'], null, versionSigners(1));
            $this->fail('The limit was not enforced.');
        } catch (ThrottleRequestsException $exception) {
            expect($exception->getHeaders()['X-RateLimit-Limit'])->toBe(1)
                ->and($exception->getHeaders()['Retry-After'])->toBeBetween(86300, 86400);
        }
    });

    it('keeps its count in the rate limiter of the application, for 24 hours, by workspace', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 5]);
        $quota = app(SignatureQuota::class);

        $quota->spend(7);
        $quota->spend(7);

        expect(RateLimiter::attempts('signature-requests:7'))->toBe(2)
            ->and(RateLimiter::attempts('signature-requests:8'))->toBe(0)
            ->and(RateLimiter::availableIn('signature-requests:7'))->toBeBetween(86300, 86400);
    });
});

describe('sending a request again', function (): void {
    it('counts like the first request, and is refused with a 429 beyond the limit', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 1]);
        $case = versionTableCase();
        $first = quotaFailed($case['inventory'], $case['team']['owner']);
        $second = quotaFailed($case['inventory'], $case['team']['owner']);
        Queue::fake();

        $uri = fn (InventoryVersion $version): string => "/api/v1/inventories/{$case['inventory']->ulid}/versions/{$version->ulid}/signature/retry";

        inventoryCall($this, $case['team']['owner'], 'POST', $uri($first))->assertStatus(202);
        $refused = inventoryCall($this, $case['team']['editor'], 'POST', $uri($second))->assertStatus(429);

        expect($refused->json('code'))->toBe('too_many_requests')
            ->and((int) $refused->headers->get('Retry-After'))->toBeBetween(86300, 86400)
            ->and($refused->headers->get('X-RateLimit-Limit'))->toBe('1')
            ->and($second->fresh()->signature_status)->toBe(SignatureStatus::Failed);
        Queue::assertPushed(RequestSignatures::class, 1);
    });

    it('is counted with the versions frozen with signers: they share the limit of the workspace', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 2]);
        $case = versionTableCase();
        $failed = quotaFailed($case['inventory'], $case['team']['owner']);
        Queue::fake();

        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(202);
        inventoryCall($this, $case['team']['owner'], 'POST', "/api/v1/inventories/{$case['inventory']->ulid}/versions/{$failed->ulid}/signature/retry")->assertStatus(202);
        quotaFreeze($this, $case['team']['owner'], $case['inventory'])->assertStatus(429);
    });

    it('does not count what was not sent again: a request that did not fail, an instance without Documenso', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 1]);
        $case = versionTableCase();
        $failed = quotaFailed($case['inventory'], $case['team']['owner']);
        $fine = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        Queue::fake();
        $uri = fn (InventoryVersion $version): string => "/api/v1/inventories/{$case['inventory']->ulid}/versions/{$version->ulid}/signature/retry";

        inventoryCall($this, $case['team']['owner'], 'POST', $uri($fine))->assertStatus(409)->assertJsonPath('code', 'signature_not_failed');

        config(['services.documenso.url' => null]);
        inventoryCall($this, $case['team']['owner'], 'POST', $uri($failed))->assertStatus(409)->assertJsonPath('code', 'signature_unavailable');
        versionDocumensoConfig();

        inventoryCall($this, $case['team']['owner'], 'POST', $uri($failed))->assertStatus(202);
    });

    it('is refused for a viewer as before, whatever the count', function (): void {
        config(['inventories.signature_requests_per_workspace_per_day' => 0]);
        $case = versionTableCase();
        $failed = quotaFailed($case['inventory'], $case['team']['owner']);

        inventoryCall($this, $case['team']['viewer'], 'POST', "/api/v1/inventories/{$case['inventory']->ulid}/versions/{$failed->ulid}/signature/retry")->assertForbidden();
    });
});
