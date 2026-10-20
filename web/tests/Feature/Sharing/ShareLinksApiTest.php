<?php

declare(strict_types=1);

use App\Inventories\Sharing\ShareLinks;
use App\Models\ShareLink;
use App\Models\User;
use App\Settings\InstanceSettings;
use App\Workspaces\WorkspaceRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/sharing.php';

uses(RefreshDatabase::class);

/**
 * The token at the end of an address the API gave.
 */
function shareLinkTokenOf(string $url): string
{
    return substr($url, strrpos($url, '/') + 1);
}

describe('listing the links', function (): void {
    it('gives those of the inventory, the newest first, with the address, the dates and the author', function (): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareLinkCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        ['link' => $first, 'token' => $firstToken] = shareLinkMake($case['inventory'], $case['owner'], 'Insurer', false, 7);
        $this->travelTo(now()->addMinute());
        ['link' => $second, 'token' => $secondToken] = shareLinkMake($case['inventory'], $case['team']['owner'], null, true, null);
        shareLinkMake($other, $case['owner'], 'Not this one');
        ShareLink::query()->whereKey($first->id)->update(['last_used_at' => now()->subMinute()]);

        $response = shareLinkCall($this, $case['owner'], 'GET', shareLinkUri($case['inventory']))->assertOk();

        expect($response->json())->toBe(['data' => [
            [
                'id' => $second->ulid,
                'label' => null,
                'url' => ShareLinks::addressOf($secondToken),
                'include_files' => true,
                'expires_at' => null,
                'last_used_at' => null,
                'created_at' => now()->toIso8601String(),
                'created_by' => ['id' => $case['owner']->ulid, 'name' => 'Owner', 'is_owner' => true],
            ],
            [
                'id' => $first->ulid,
                'label' => 'Insurer',
                'url' => ShareLinks::addressOf($firstToken),
                'include_files' => false,
                'expires_at' => now()->subMinute()->addDays(7)->toIso8601String(),
                'last_used_at' => now()->subMinute()->toIso8601String(),
                'created_at' => now()->subMinute()->toIso8601String(),
                'created_by' => ['id' => $case['owner']->ulid, 'name' => 'Owner', 'is_owner' => true],
            ],
        ]]);
    });

    it('is empty for an inventory with no link', function (): void {
        $case = shareLinkCase();

        shareLinkCall($this, $case['owner'], 'GET', shareLinkUri($case['inventory']))->assertOk()->assertExactJson(['data' => []]);
    });

    it('is never kept by a cache', function (): void {
        $case = shareLinkCase();
        shareLinkMake($case['inventory'], $case['owner']);

        $response = shareLinkCall($this, $case['owner'], 'GET', shareLinkUri($case['inventory']))->assertOk();
        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));

        expect($directives)->toContain('no-store')->toContain('private');
    });

    it('lists the links that expired, which say so by their date, and still gives their address', function (): void {
        $case = shareLinkCase();
        $expired = ShareLink::factory()->for($case['inventory'])->withToken($token = ShareLinks::newToken())->expired()->create(['created_by_id' => $case['owner']->id]);

        $data = shareLinkCall($this, $case['owner'], 'GET', shareLinkUri($case['inventory']))->assertOk()->json('data');

        expect($data)->toHaveCount(1)
            ->and($data[0]['id'])->toBe($expired->ulid)
            ->and($data[0]['expires_at'])->toBe($expired->expires_at->toIso8601String())
            ->and($data[0]['url'])->toBe(ShareLinks::addressOf($token));
    });

    it('gives no address for a link that cannot be read back, and the others as usual', function (): void {
        $case = shareLinkCase();
        ['link' => $broken] = shareLinkMake($case['inventory'], $case['owner'], 'Broken');
        shareLinkMake($case['inventory'], $case['owner'], 'Fine');
        DB::table('share_links')->where('id', $broken->id)->update(['token_encrypted' => 'garbage']);

        $data = collect(shareLinkCall($this, $case['owner'], 'GET', shareLinkUri($case['inventory']))->assertOk()->json('data'))->keyBy('label');

        expect($data['Broken']['url'])->toBeNull()->and($data['Fine']['url'])->toBeString();
    });

    it('names nobody once the account that made the link is gone', function (): void {
        $case = shareLinkCase();
        $author = User::factory()->create();
        shareLinkMake($case['inventory'], $author);
        $author->delete();

        $data = shareLinkCall($this, $case['owner'], 'GET', shareLinkUri($case['inventory']))->assertOk()->json('data');

        expect($data)->toHaveCount(1)->and($data[0]['created_by'])->toBeNull();
    });

    it('says whether the author is still an owner of the workspace: a link outlives the ownership of its author', function (): void {
        $case = shareLinkCase();
        $workspace = $case['team']['workspace'];
        $coOwner = inventoryPerson('Co-owner');
        $demoted = inventoryPerson('Demoted');
        $gone = inventoryPerson('Gone');
        $workspace->members()->attach($coOwner->id, ['role' => WorkspaceRole::Owner->value]);
        $workspace->members()->attach($demoted->id, ['role' => WorkspaceRole::Owner->value]);
        $workspace->members()->attach($gone->id, ['role' => WorkspaceRole::Owner->value]);
        shareLinkMake($case['inventory'], $case['owner'], 'By the owner');
        shareLinkMake($case['inventory'], $coOwner, 'By a co-owner');
        shareLinkMake($case['inventory'], $demoted, 'By one made an editor');
        shareLinkMake($case['inventory'], $gone, 'By one who left');
        shareLinkMake($case['inventory'], $case['team']['editor'], 'By an editor');
        $workspace->members()->updateExistingPivot($demoted->id, ['role' => WorkspaceRole::Editor->value]);
        $workspace->members()->detach($gone->id);

        $data = collect(shareLinkCall($this, $case['owner'], 'GET', shareLinkUri($case['inventory']))->assertOk()->json('data'))->keyBy('label');

        expect($data->map(fn (array $link): bool => $link['created_by']['is_owner'])->all())->toEqual([
            'By the owner' => true,
            'By a co-owner' => true,
            'By one made an editor' => false,
            'By one who left' => false,
            'By an editor' => false,
        ])
            ->and($data['By one who left']['created_by']['name'])->toBe('Gone');
    });

    it('gives neither the hash nor the encrypted token, and the token only inside the address', function (): void {
        $case = shareLinkCase();
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner']);

        $body = (string) shareLinkCall($this, $case['owner'], 'GET', shareLinkUri($case['inventory']))->assertOk()->getContent();

        expect($body)->not->toContain(hash('sha256', $token))->not->toContain('token_hash')->not->toContain('token_encrypted')
            ->and(substr_count($body, $token))->toBe(1);
    });

    it('still works while the administrator has sharing turned off, so that the owner can see and revoke what is there', function (): void {
        $case = shareLinkCase();
        shareLinkMake($case['inventory'], $case['owner']);
        app(InstanceSettings::class)->update([InstanceSettings::SHARE_LINKS_ENABLED => false]);

        $data = shareLinkCall($this, $case['owner'], 'GET', shareLinkUri($case['inventory']))->assertOk()->json('data');

        expect($data)->toHaveCount(1);
    });
});

describe('making a link', function (): void {
    it('answers 201 with the link and its address, built from APP_URL, and keeps only the hash', function (): void {
        $this->travelTo(now()->startOfSecond());
        config(['app.url' => 'https://shared.example.test']);
        $case = shareLinkCase();

        $response = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']), ['label' => 'Insurer', 'include_files' => false, 'expires_in_days' => 90])->assertCreated();
        $link = ShareLink::query()->sole();
        $token = shareLinkTokenOf($response->json('data.url'));

        expect($response->json())->toBe(['data' => [
            'id' => $link->ulid,
            'label' => 'Insurer',
            'url' => "https://shared.example.test/s/{$token}",
            'include_files' => false,
            'expires_at' => now()->addDays(90)->toIso8601String(),
            'last_used_at' => null,
            'created_at' => now()->toIso8601String(),
            'created_by' => ['id' => $case['owner']->ulid, 'name' => 'Owner', 'is_owner' => true],
        ]])
            ->and($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
            ->and($link->token_hash)->toBe(hash('sha256', $token))
            ->and(json_encode(DB::table('share_links')->get()))->not->toContain($token)
            ->and(app(ShareLinks::class)->resolve($token)?->is($link))->toBeTrue();
    });

    it('is never kept by a cache', function (): void {
        $case = shareLinkCase();

        $response = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']))->assertCreated();
        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));

        expect($directives)->toContain('no-store')->toContain('private');
    });

    it('gives the same address afterwards, in the list', function (): void {
        $case = shareLinkCase();

        $url = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']))->assertCreated()->json('data.url');
        $listed = shareLinkCall($this, $case['owner'], 'GET', shareLinkUri($case['inventory']))->assertOk()->json('data.0.url');

        expect($listed)->toBe($url);
    });

    it('lasts 30 days and shows the files unless the request says otherwise', function (): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareLinkCase();

        $data = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']))->assertCreated()->json('data');

        expect($data['label'])->toBeNull()
            ->and($data['include_files'])->toBeTrue()
            ->and($data['expires_at'])->toBe(now()->addDays(30)->toIso8601String());
    });

    it('lets the owner choose a lifetime of 7, 30, 90 or 365 days, or none', function (?int $days): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareLinkCase();

        $data = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']), ['expires_in_days' => $days])->assertCreated()->json('data');

        expect($data['expires_at'])->toBe($days === null ? null : now()->addDays($days)->toIso8601String());
    })->with([7, 30, 90, 365, null]);

    it('takes a label with spaces around it as the label without them, and an empty one as none', function (): void {
        $case = shareLinkCase();

        $spaced = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']), ['label' => "  Insurer \u{200B}"])->assertCreated();
        $empty = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']), ['label' => '   '])->assertCreated();

        expect($spaced->json('data.label'))->toBe('Insurer')->and($empty->json('data.label'))->toBeNull();
    });

    it('refuses a mistake in the request with 422 under the key it is about, and makes nothing', function (array $body, string $key): void {
        $case = shareLinkCase();

        $response = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']), $body)->assertUnprocessable();

        expect($response->json('errors'))->toHaveKey($key)
            ->and(ShareLink::query()->count())->toBe(0);
    })->with([
        'a label of 81 characters' => [['label' => str_repeat('a', 81)], 'label'],
        'a label on two lines' => [['label' => "One\nTwo"], 'label'],
        'a label that is a list' => [['label' => ['x']], 'label'],
        'a text direction control in the label' => [['label' => "A\u{202E}B"], 'label'],
        'include_files that is not a boolean' => [['include_files' => 'maybe'], 'include_files'],
        'include_files that is null' => [['include_files' => null], 'include_files'],
        'a lifetime that is not offered' => [['expires_in_days' => 14], 'expires_in_days'],
        'a lifetime that is a word' => [['expires_in_days' => 'forever'], 'expires_in_days'],
        'a lifetime of zero' => [['expires_in_days' => 0], 'expires_in_days'],
        'a lifetime that is an empty text, not the null that means for ever' => [['expires_in_days' => ''], 'expires_in_days'],
    ]);

    it('counts the characters of the label, not the bytes', function (): void {
        $case = shareLinkCase();

        shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']), ['label' => str_repeat('é', 80)])->assertCreated();
    });

    it('answers 409 with the limit when the inventory has as many links that work as it may, the expired ones not counting', function (): void {
        config(['inventories.max_share_links' => 2]);
        $case = shareLinkCase();
        shareLinkMake($case['inventory'], $case['owner']);
        ShareLink::factory()->for($case['inventory'])->expired()->create();
        shareLinkMake($case['inventory'], $case['owner']);

        $response = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']))->assertStatus(409);

        expect($response->json('code'))->toBe('share_link_limit_reached')
            ->and($response->json('limit'))->toBe(2)
            ->and($response->json('message'))->toBe('An inventory can have 2 share links at a time: delete one to make another.')
            ->and(ShareLink::query()->count())->toBe(3);

        // Another inventory has its own count.
        shareLinkCall($this, $case['owner'], 'POST', shareLinkUri(inventoryIn($case['team']['workspace'], 'Other')))->assertCreated();
    });

    it('answers 409 share_links_disabled while the administrator has sharing turned off, and makes nothing', function (): void {
        $case = shareLinkCase();
        app(InstanceSettings::class)->update([InstanceSettings::SHARE_LINKS_ENABLED => false]);

        $response = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']))->assertStatus(409);

        expect($response->json('code'))->toBe('share_links_disabled')
            ->and($response->json())->not->toHaveKey('limit')
            ->and(ShareLink::query()->count())->toBe(0);
    });

    it('answers 429 beyond the links a person may make in an hour, whatever they make them through, and for them alone', function (): void {
        config(['inventories.share_link_creations_per_hour' => 2, 'inventories.max_share_links' => 50]);
        $case = shareLinkCase();
        $other = shareLinkCase();

        shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']))->assertCreated();
        shareLinkTokenCall($this, $case['owner'], ['manage'], 'POST', shareLinkUri($case['inventory']))->assertCreated();
        $refused = shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']))->assertStatus(429);

        expect($refused->json('code'))->toBe('too_many_requests')
            ->and((int) $refused->headers->get('Retry-After'))->toBeBetween(1, 3600)
            ->and($refused->headers->get('X-RateLimit-Limit'))->toBe('2')
            ->and(ShareLink::query()->count())->toBe(2);

        shareLinkTokenCall($this, $case['owner'], ['manage'], 'POST', shareLinkUri($case['inventory']))->assertStatus(429);
        shareLinkCall($this, $other['owner'], 'POST', shareLinkUri($other['inventory']))->assertCreated();

        $this->travel(61)->minutes();

        shareLinkCall($this, $case['owner'], 'POST', shareLinkUri($case['inventory']))->assertCreated();
    });

    it('does not use up the links a person may make when the token may not manage', function (): void {
        config(['inventories.share_link_creations_per_hour' => 1]);
        $case = shareLinkCase();

        shareLinkTokenCall($this, $case['owner'], ['read', 'write'], 'POST', shareLinkUri($case['inventory']))->assertForbidden();
        shareLinkTokenCall($this, $case['owner'], ['manage'], 'POST', shareLinkUri($case['inventory']))->assertCreated();
        shareLinkTokenCall($this, $case['owner'], ['manage'], 'POST', shareLinkUri($case['inventory']))->assertStatus(429);
    });
});

describe('revoking a link', function (): void {
    it('answers 204, deletes the link, and its address stops working at once', function (): void {
        $case = shareLinkCase();
        ['link' => $link, 'token' => $token] = shareLinkMake($case['inventory'], $case['owner']);
        ['link' => $kept, 'token' => $keptToken] = shareLinkMake($case['inventory'], $case['owner']);

        shareLinkCall($this, $case['owner'], 'DELETE', shareLinkUri($case['inventory'], $link))->assertNoContent();

        expect(ShareLink::query()->pluck('id')->all())->toBe([$kept->id])
            ->and(app(ShareLinks::class)->resolve($token))->toBeNull()
            ->and(app(ShareLinks::class)->resolve($keptToken))->not->toBeNull();

        shareLinkCall($this, $case['owner'], 'DELETE', shareLinkUri($case['inventory'], $link))->assertNotFound();
    });

    it('answers 404 for a link of another inventory, even of the same workspace, and for one that does not exist', function (): void {
        $case = shareLinkCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        ['link' => $theirs] = shareLinkMake($other, $case['owner']);

        shareLinkCall($this, $case['owner'], 'DELETE', shareLinkUri($case['inventory'], $theirs))->assertNotFound();
        shareLinkCall($this, $case['owner'], 'DELETE', shareLinkUri($case['inventory']).'/01j0000000000000000000000x')->assertNotFound();

        expect(ShareLink::query()->count())->toBe(1);
    });

    it('works with a token that may manage', function (): void {
        $case = shareLinkCase();
        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner']);

        shareLinkTokenCall($this, $case['owner'], ['manage'], 'DELETE', shareLinkUri($case['inventory'], $link))->assertNoContent();

        expect(ShareLink::query()->count())->toBe(0);
    });
});

describe('who may', function (): void {
    it('is the owner of the workspace alone: an editor and a viewer are refused with 403 and a stranger is told nothing is there', function (): void {
        $case = shareLinkCase();
        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner']);

        foreach (['editor' => 403, 'viewer' => 403, 'stranger' => 404] as $who => $status) {
            $person = $case['team'][$who];

            foreach ([['GET', shareLinkUri($case['inventory']), []], ['POST', shareLinkUri($case['inventory']), []], ['DELETE', shareLinkUri($case['inventory'], $link), []]] as [$method, $uri, $data]) {
                $response = shareLinkCall($this, $person, $method, $uri, $data)->assertStatus($status);

                expect($response->json())->not->toHaveKey('url')->and($response->json())->not->toHaveKey('data', "{$who} {$method}");
            }
        }

        expect(ShareLink::query()->pluck('id')->all())->toBe([$link->id]);
    });

    it('tells a member who is not the owner what is missing, in words', function (): void {
        $case = shareLinkCase();

        $response = shareLinkCall($this, $case['team']['editor'], 'POST', shareLinkUri($case['inventory']))->assertForbidden();

        expect($response->json('message'))->toBe('Only an owner of this workspace can share an inventory.');
    });

    it('does not tell a stranger whether the body is valid: the inventory is not there for them', function (): void {
        $case = shareLinkCase();

        shareLinkCall($this, $case['team']['stranger'], 'POST', shareLinkUri($case['inventory']), ['expires_in_days' => 14])->assertNotFound();
    });

    it('keeps an owner of another workspace out', function (): void {
        $case = shareLinkCase();
        $elsewhere = shareLinkCase();
        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner']);

        shareLinkCall($this, $elsewhere['owner'], 'GET', shareLinkUri($case['inventory']))->assertNotFound();
        shareLinkCall($this, $elsewhere['owner'], 'DELETE', shareLinkUri($case['inventory'], $link))->assertNotFound();
    });

    it('asks a visitor to sign in', function (): void {
        $case = shareLinkCase();

        $this->getJson(shareLinkUri($case['inventory']))->assertUnauthorized();
        $this->postJson(shareLinkUri($case['inventory']))->assertUnauthorized();
    });

    it('needs a token that may manage on every operation, the list included, and is answered 403 with the code otherwise', function (array $abilities): void {
        $case = shareLinkCase();
        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner']);

        foreach ([['GET', shareLinkUri($case['inventory'])], ['POST', shareLinkUri($case['inventory'])], ['DELETE', shareLinkUri($case['inventory'], $link)]] as [$method, $uri]) {
            $response = shareLinkTokenCall($this, $case['owner'], $abilities, $method, $uri)->assertForbidden();

            expect($response->json('code'))->toBe('token_ability_missing');
        }

        expect(ShareLink::query()->count())->toBe(1);
    })->with([
        'read only' => [['read']],
        'write only' => [['write']],
        'read and write' => [['read', 'write']],
    ]);

    it('does not go beyond the role of the person the token belongs to', function (): void {
        $case = shareLinkCase();

        shareLinkTokenCall($this, $case['team']['editor'], ['read', 'write', 'manage'], 'POST', shareLinkUri($case['inventory']))->assertForbidden();
        shareLinkTokenCall($this, $case['team']['stranger'], ['read', 'write', 'manage'], 'GET', shareLinkUri($case['inventory']))->assertNotFound();
    });
});

describe('an inventory that goes', function (): void {
    it('takes its links with it, through the API, and no other', function (): void {
        $case = shareLinkCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner']);
        ['link' => $kept] = shareLinkMake($other, $case['owner']);

        shareLinkCall($this, $case['owner'], 'DELETE', "/api/v1/inventories/{$case['inventory']->ulid}")->assertNoContent();

        expect(ShareLink::query()->pluck('id')->all())->toBe([$kept->id])
            ->and(app(ShareLinks::class)->resolve($token))->toBeNull();
    });
});
