<?php

declare(strict_types=1);

use App\Billing\BillingSync;
use App\Inventories\Exceptions\ItemLimitReachedException;
use App\Inventories\Items;
use App\Jobs\AnalyzePhotos;
use App\Models\BillingAccount;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Models\Item;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\PhotoEntry\PhotoEntry;
use App\Settings\InstanceSettings;
use App\Workspaces\Exceptions\MembershipRefused;
use App\Workspaces\Invitations;
use App\Workspaces\WorkspaceRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/attachments.php';
require_once __DIR__.'/../../Support/versions.php';
require_once __DIR__.'/../../Support/sharing.php';
require_once __DIR__.'/../../Support/photo-entry.php';

/*
 * The plan of a workspace sets what it may hold: with billing on, a Free workspace is refused at the Free limit (the same code and the same
 * `limit` as ever), and a Pro one is allowed on up to the Pro limit; the limits of the instance (`inventories.*`, `workspaces.max_members`)
 * are not looked at. The limits are made small here to keep the tests short. With billing off nothing changes: that is every other test.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
    billingOn();
    config([
        'billing.plans.free.limits' => ['inventories' => 2, 'items' => 2, 'storage_bytes' => 1000, 'members' => 3, 'share_links' => 2],
        'billing.plans.pro.limits' => ['inventories' => 4, 'items' => 4, 'storage_bytes' => 100000, 'members' => 5, 'share_links' => 4],
        // The settings of the instance, which a plan replaces: if any call site still read them, one of these would refuse first.
        'inventories.max_per_workspace' => 1,
        'inventories.max_items' => 1,
        'inventories.storage_per_workspace' => 1,
        'inventories.max_share_links' => 1,
        'workspaces.max_members' => 1,
    ]);
});

/**
 * The statuses of the subscription that are on the Free plan and on the Pro plan, with the limit of the plan they give for a quantity
 * (Free: 2 of the things counted by tens, Pro: 4).
 *
 * @return array<string, array{0: string|null, 1: int}>
 */
function planStatuses(int $free = 2, int $pro = 4): array
{
    return [
        'no subscription' => [null, $free],
        'canceled' => ['canceled', $free],
        'unpaid' => ['unpaid', $free],
        'incomplete' => ['incomplete', $free],
        'active' => ['active', $pro],
        'trialing' => ['trialing', $pro],
        'past due' => ['past_due', $pro],
    ];
}

/**
 * A team in a workspace on the plan a subscription status gives (none for a workspace that never paid).
 *
 * @return array{owner: User, editor: User, viewer: User, stranger: User, workspace: Workspace}
 */
function planTeam(?string $status): array
{
    $team = inventoryTeam();

    if ($status !== null) {
        BillingAccount::factory()->for($team['workspace'])->subscribed($status)->create();
    }

    return $team;
}

/**
 * The flag the page of an inventory hands its script.
 */
function planPhotoFlag(object $test, array $team): string
{
    $inventory = inventoryIn($team['workspace']->fresh());
    $page = $test->actingAs($team['owner'])->get(route('app.inventory', ['workspace' => $team['workspace']->ulid, 'inventory' => $inventory->ulid]))->assertOk();
    preg_match('/\sdata-photo-entry="(\d)"/', $page->getContent(), $match);

    return $match[1] ?? '';
}

describe('inventories', function (): void {
    it('are refused beyond the limit of the plan, with the limit of the plan', function (?string $status, int $limit): void {
        $team = planTeam($status);
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/inventories";

        foreach (range(1, $limit) as $number) {
            inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => "Inventory {$number}"])->assertCreated();
        }

        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'One too many'])
            ->assertStatus(409)
            ->assertExactJson(['message' => "A workspace cannot hold more than {$limit} inventories.", 'code' => 'inventory_limit_reached', 'limit' => $limit]);
        expect(Inventory::query()->where('workspace_id', $team['workspace']->id)->count())->toBe($limit);
    })->with(planStatuses());

    it('are counted for each workspace: a Free one does not take the room of a Pro one', function (): void {
        $free = planTeam(null);
        $pro = planTeam('active');

        foreach (range(1, 2) as $number) {
            inventoryIn($free['workspace'], "Free {$number}");
        }

        foreach (range(1, 3) as $number) {
            inventoryIn($pro['workspace'], "Pro {$number}");
        }

        inventoryCall($this, $free['owner'], 'POST', "/api/v1/workspaces/{$free['workspace']->ulid}/inventories", ['name' => 'Refused'])->assertStatus(409)->assertJsonPath('limit', 2);
        inventoryCall($this, $pro['owner'], 'POST', "/api/v1/workspaces/{$pro['workspace']->ulid}/inventories", ['name' => 'Allowed'])->assertCreated();
    });

    it('go back to the limit of Free when the subscription ends, without deleting what is there', function (): void {
        $team = planTeam('active');
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/inventories";

        foreach (range(1, 3) as $number) {
            inventoryIn($team['workspace'], "Inventory {$number}");
        }

        BillingAccount::query()->where('workspace_id', $team['workspace']->id)->firstOrFail()->update(['status' => 'canceled']);

        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'Refused'])->assertStatus(409)->assertJsonPath('limit', 2);
        expect(Inventory::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(3);
    });
});

describe('items', function (): void {
    it('are refused beyond the limit of the plan, with the limit of the plan', function (?string $status, int $limit): void {
        $team = planTeam($status);
        $inventory = inventoryIn($team['workspace']);
        $uri = "/api/v1/inventories/{$inventory->ulid}/items";

        foreach (range(1, $limit) as $ignored) {
            inventoryCall($this, $team['editor'], 'POST', $uri)->assertCreated();
        }

        inventoryCall($this, $team['owner'], 'POST', $uri)
            ->assertStatus(409)
            ->assertExactJson(['message' => "An inventory cannot hold more than {$limit} items.", 'code' => 'item_limit_reached', 'limit' => $limit]);
        expect(Item::query()->where('inventory_id', $inventory->id)->count())->toBe($limit);
    })->with(planStatuses());

    it('are added in bulk up to the limit of the plan, and none when the lot would cross it', function (?string $status, int $limit): void {
        $team = planTeam($status);
        $inventory = inventoryIn($team['workspace']);
        $fresh = fn (): Inventory => Inventory::query()->findOrFail($inventory->id);

        expect(fn () => app(Items::class)->createMany($fresh(), $fresh(), collect(), array_fill(0, $limit + 1, []), $team['owner'], $limit + 1))
            ->toThrow(fn (ItemLimitReachedException $e) => expect($e->limit)->toBe($limit));
        expect(Item::query()->where('inventory_id', $inventory->id)->count())->toBe(0);

        expect(app(Items::class)->createMany($fresh(), $fresh(), collect(), array_fill(0, $limit, []), $team['owner'], $limit))->toBe($limit)
            ->and(Item::query()->where('inventory_id', $inventory->id)->count())->toBe($limit);
    })->with(planStatuses());
});

describe('storage', function (): void {
    it('refuses the file that would take the workspace beyond the storage of its plan, with the limit of the plan', function (?string $status, int $limit): void {
        $size = strlen("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $storage = ['free' => $size * 2 + 1, 'pro' => $size * 4 + 1];
        config(['billing.plans.free.limits.storage_bytes' => $storage['free'], 'billing.plans.pro.limits.storage_bytes' => $storage['pro']]);
        attachmentDisk();
        $team = planTeam($status);
        $inventory = inventoryIn($team['workspace']);
        $item = itemIn($inventory);
        $files = "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}/attachments";

        foreach (range(1, $limit) as $ignored) {
            attachmentUpload($this, $team['owner'], $files, attachmentPdf())->assertCreated();
        }

        $refused = attachmentUpload($this, $team['owner'], $files, attachmentPdf())->assertStatus(409);

        expect($refused->json('code'))->toBe('storage_limit_reached')
            ->and($refused->json('limit'))->toBe($limit === 2 ? $storage['free'] : $storage['pro']);
    })->with(planStatuses());

    it('refuses an official version to a workspace whose files weigh the storage of its plan, and allows it on Pro', function (): void {
        Queue::fake();
        // The inventories of the case hold three items and a photo each.
        config(['billing.plans.free.limits.items' => 10, 'billing.plans.pro.limits.items' => 10]);
        $free = versionTableCase();
        $free['team']['workspace']->forceFill(['storage_used' => 1000])->save();

        $refused = inventoryCall($this, $free['team']['owner'], 'POST', versionApiUri($free['inventory']))->assertStatus(409);

        expect($refused->json('code'))->toBe('storage_limit_reached')->and($refused->json('limit'))->toBe(1000)
            ->and(InventoryVersion::query()->count())->toBe(0);

        // The same weight on a Pro workspace is far from its storage.
        $pro = versionTableCase();
        BillingAccount::factory()->for($pro['team']['workspace'])->subscribed('active')->create();
        $pro['team']['workspace']->forceFill(['storage_used' => 1000])->save();

        inventoryCall($this, $pro['team']['owner'], 'POST', versionApiUri($pro['inventory']))->assertStatus(202);
        expect(InventoryVersion::query()->count())->toBe(1);
    });
});

describe('members', function (): void {
    it('refuse an invitation once the members and the waiting invitations fill the plan, with the limit of the plan', function (?string $status, int $limit): void {
        $team = planTeam($status);
        // The team has three members: Free has no room left, Pro has two places (a limit of five).
        $places = $limit === 2 ? 0 : 2;
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/invitations";

        for ($number = 1; $number <= $places; $number++) {
            inventoryCall($this, $team['owner'], 'POST', $uri, ['email' => "guest{$number}@example.test", 'role' => 'viewer'])->assertCreated();
        }

        $max = $limit === 2 ? 3 : 5;

        inventoryCall($this, $team['owner'], 'POST', $uri, ['email' => 'one-too-many@example.test', 'role' => 'viewer'])
            ->assertStatus(409)
            ->assertExactJson(['message' => "A workspace cannot have more than {$max} members, counting the invitations waiting for an answer.", 'code' => 'member_limit_reached', 'limit' => $max]);
    })->with(planStatuses());

    it('refuse to let an invitation in once the members fill the plan, and let it in on Pro', function (): void {
        $free = planTeam(null);
        $pro = planTeam('active');
        $pro['workspace']->members()->attach(inventoryPerson('Fourth')->id, ['role' => WorkspaceRole::Viewer->value]);

        foreach ([$free, $pro] as $team) {
            WorkspaceInvitation::factory()->create(['workspace_id' => $team['workspace']->id, 'email' => 'guest-'.$team['workspace']->id.'@example.test', 'role' => WorkspaceRole::Viewer->value]);
        }

        $guestFree = User::factory()->withPersonalWorkspace()->create(['email' => 'guest-'.$free['workspace']->id.'@example.test']);
        $guestPro = User::factory()->withPersonalWorkspace()->create(['email' => 'guest-'.$pro['workspace']->id.'@example.test']);
        $invitationFree = WorkspaceInvitation::query()->where('workspace_id', $free['workspace']->id)->firstOrFail();
        $invitationPro = WorkspaceInvitation::query()->where('workspace_id', $pro['workspace']->id)->firstOrFail();

        // Free: three members of a limit of three. Pro: four members of a limit of five.
        expect(fn () => app(Invitations::class)->accept($guestFree, $invitationFree))
            ->toThrow(fn (MembershipRefused $e) => expect($e->reason)->toBe(MembershipRefused::MEMBER_LIMIT_REACHED)->and($e->limit)->toBe(3));
        expect(app(Invitations::class)->accept($guestPro, $invitationPro)->getKey())->toBe($pro['workspace']->getKey());
    });
});

describe('share links', function (): void {
    it('are refused beyond the limit of the plan, with the limit of the plan', function (?string $status, int $limit): void {
        $team = planTeam($status);
        $inventory = inventoryIn($team['workspace'], 'Tools');

        foreach (range(1, $limit) as $ignored) {
            shareLinkMake($inventory, $team['owner']);
        }

        $response = shareLinkCall($this, $team['owner'], 'POST', shareLinkUri($inventory))->assertStatus(409);

        expect($response->json('code'))->toBe('share_link_limit_reached')->and($response->json('limit'))->toBe($limit);
    })->with(planStatuses());

    it('are announced to the sharing page with the limit of the plan', function (?string $status, int $limit): void {
        $team = planTeam($status);
        $inventory = inventoryIn($team['workspace']);
        $page = $this->actingAs($team['owner'])->get(route('app.inventory.sharing', ['workspace' => $team['workspace']->ulid, 'inventory' => $inventory->ulid]))->assertOk();

        expect(preg_match('/\sdata-limit="(\d+)"/', $page->getContent(), $match))->toBe(1)->and((int) $match[1])->toBe($limit);
    })->with(planStatuses());
});

describe('the photo entry', function (): void {
    it('is refused to a Free workspace even with the switch, the key and the consent, and allowed to a Pro one', function (?string $status, int $limit): void {
        $team = planTeam($status);
        photoTurnedOn($team['workspace']);
        $workspace = $team['workspace']->fresh();
        $pro = $limit > 2;

        expect(app(PhotoEntry::class)->enabledFor($workspace))->toBe($pro)
            ->and(planPhotoFlag($this, $team))->toBe($pro ? '1' : '0');
    })->with(planStatuses());

    it('answers photo_entry_not_in_plan to the photos of a Free workspace, and takes those of a Pro one', function (): void {
        Queue::fake();
        $free = photoCase();
        photoTurnedOn($free['team']['workspace']);
        fieldIn($free['inventory'], 'text', 'Name');
        $pro = photoCase('Pro stock');
        photoTurnedOn($pro['team']['workspace']);
        BillingAccount::factory()->for($pro['team']['workspace'])->subscribed('active')->create();
        fieldIn($pro['inventory'], 'text', 'Name');

        photoPost($this, $free['team']['editor'], $free['inventory'], [photoUpload('one.jpg')])
            ->assertStatus(409)
            ->assertJsonPath('code', 'photo_entry_not_in_plan')
            ->assertJsonPath('message', 'The plan of this workspace does not include the photo entry.');
        photoPost($this, $pro['team']['editor'], $pro['inventory'], [photoUpload('one.jpg')])->assertStatus(202);

        Queue::assertPushed(AnalyzePhotos::class, 1);
    });

    it('blames the plan only when it is the plan that refuses: the switch of the administrator and the consent of a Pro owner stay photo_entry_disabled', function (): void {
        Queue::fake();
        $free = photoCase();
        photoTurnedOn($free['team']['workspace']);
        fieldIn($free['inventory'], 'text', 'Name');
        $pro = photoCase('Pro stock');
        photoTurnedOn($pro['team']['workspace']);
        BillingAccount::factory()->for($pro['team']['workspace'])->subscribed('active')->create();
        fieldIn($pro['inventory'], 'text', 'Name');

        // The owner of a Free workspace did not consent: turning the consent on would not be enough, the plan is what is told.
        $free['team']['workspace']->forceFill(['photo_entry_enabled' => false])->save();
        photoPost($this, $free['team']['editor'], $free['inventory'], [photoUpload('one.jpg')])->assertStatus(409)->assertJsonPath('code', 'photo_entry_not_in_plan');

        // The owner of a Pro workspace did not: nothing to do with the plan.
        $pro['team']['workspace']->forceFill(['photo_entry_enabled' => false])->save();
        photoPost($this, $pro['team']['editor'], $pro['inventory'], [photoUpload('one.jpg')])->assertStatus(409)->assertJsonPath('code', 'photo_entry_disabled');

        // The administrator turned the feature off: not even Pro would help, so the plan is not blamed.
        app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => false]);
        photoPost($this, $free['team']['editor'], $free['inventory'], [photoUpload('one.jpg')])->assertStatus(409)->assertJsonPath('code', 'photo_entry_disabled');

        Queue::assertNothingPushed();
    });

    it('keeps the consent of the owner when the plan does not allow it, and offers the photo entry again as soon as the plan does', function (): void {
        $team = planTeam(null);
        photoTurnedOn($team['workspace']);
        $workspace = $team['workspace']->fresh();

        expect($workspace->photo_entry_enabled)->toBeTrue()->and(app(PhotoEntry::class)->enabledFor($workspace))->toBeFalse();

        // The owner can still withdraw it, and give it again.
        app(PhotoEntry::class)->setConsent($workspace, false);
        app(PhotoEntry::class)->setConsent($workspace, true);

        expect($workspace->fresh()->photo_entry_enabled)->toBeTrue();

        BillingAccount::factory()->for($workspace)->subscribed('active')->create();

        expect(app(PhotoEntry::class)->enabledFor($workspace->fresh()))->toBeTrue();
    });

    it('stops offering the photo entry as soon as the plan ends', function (): void {
        $team = planTeam('active');
        photoTurnedOn($team['workspace']);
        $workspace = $team['workspace']->fresh();

        expect(app(PhotoEntry::class)->enabledFor($workspace))->toBeTrue();

        BillingAccount::query()->where('workspace_id', $workspace->id)->firstOrFail()->update(['status' => 'canceled']);

        expect(app(PhotoEntry::class)->enabledFor($workspace))->toBeFalse();
    });

    it('is not taken away from an instance that sells no plan', function (): void {
        billingOff();
        $team = planTeam(null);
        photoTurnedOn($team['workspace']);

        expect(app(PhotoEntry::class)->enabledFor($team['workspace']->fresh()))->toBeTrue()
            ->and(planPhotoFlag($this, $team))->toBe('1');
    });
});

describe('a plan that changes in the middle of the process', function (): void {
    it('is followed by the very next check, when the synchronisation writes it', function (): void {
        $stripe = billingOn();
        config(['billing.plans.free.limits.inventories' => 2, 'billing.plans.pro.limits.inventories' => 4]);
        $team = inventoryTeam();
        $account = BillingAccount::factory()->for($team['workspace'])->create(['stripe_customer_id' => 'cus_flip']);
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/inventories";

        foreach (range(1, 2) as $number) {
            inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => "Inventory {$number}"])->assertCreated();
        }

        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'Refused'])->assertStatus(409)->assertJsonPath('limit', 2);

        // The checkout is paid, and the synchronisation (the webhook's job, or the page) reads it, in this very process.
        $stripe->subscribe('cus_flip', 'active');
        app(BillingSync::class)->sync($account);

        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'Allowed'])->assertCreated();

        // And the other way round.
        $stripe->setStatus(array_key_first($stripe->subscriptions), 'canceled');
        app(BillingSync::class)->sync($account->refresh());

        inventoryCall($this, $team['owner'], 'POST', $uri, ['name' => 'Refused again'])->assertStatus(409)->assertJsonPath('limit', 2);
        expect(Inventory::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(3);
    });
});
