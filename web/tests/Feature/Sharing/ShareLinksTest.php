<?php

declare(strict_types=1);

use App\Inventories\Exceptions\ShareLinkLimitReachedException;
use App\Inventories\Exceptions\ShareLinksDisabledException;
use App\Inventories\Sharing\ShareLinks;
use App\Models\ShareLink;
use App\Settings\InstanceSettings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/sharing.php';

uses(RefreshDatabase::class);

describe('making a link', function (): void {
    it('keeps the hash of the token and the token encrypted, and never the token itself', function (): void {
        $case = shareLinkCase();

        ['link' => $link, 'token' => $token] = shareLinkMake($case['inventory'], $case['owner']);
        $row = DB::table('share_links')->sole();

        expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
            ->and($row->token_hash)->toBe(hash('sha256', $token))
            ->and($row->token_encrypted)->not->toContain($token)
            ->and(Crypt::decryptString($row->token_encrypted))->toBe($token)
            // Nowhere in the row, whatever the column.
            ->and(json_encode($row))->not->toContain($token)
            ->and(json_encode($link->toArray()))->not->toContain($token)->not->toContain('token_hash')->not->toContain('token_encrypted');
    });

    it('gives each link a token of its own, 256 bits of randomness', function (): void {
        $case = shareLinkCase();
        $tokens = [];

        foreach (range(1, 5) as $unused) {
            $tokens[] = shareLinkMake($case['inventory'], $case['owner'])['token'];
        }

        expect(array_unique($tokens))->toHaveCount(5)
            // 32 bytes in base64url: 43 characters, the last one carrying 2 bits.
            ->and(strlen(rtrim(strtr($tokens[0], '-_', '+/').'=', '=')))->toBe(43)
            ->and(strlen((string) base64_decode(strtr($tokens[0], '-_', '+/').'=', true)))->toBe(32);
    });

    it('keeps its author, its label, whether it shows the files and when it expires', function (): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareLinkCase();

        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner'], 'Insurer', false, 90);
        $row = ShareLink::query()->sole();

        expect($row->inventory_id)->toBe($case['inventory']->id)
            ->and($row->created_by_id)->toBe($case['owner']->id)
            ->and($row->label)->toBe('Insurer')
            ->and($row->include_files)->toBeFalse()
            ->and($row->expires_at->toIso8601String())->toBe(now()->addDays(90)->toIso8601String())
            ->and($row->last_used_at)->toBeNull()
            ->and($link->ulid)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
            ->and($link->creator->is($case['owner']))->toBeTrue();
    });

    it('has no label when none is given, and never expires when no lifetime is given', function (): void {
        $case = shareLinkCase();

        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner'], null, true, null);

        expect($link->label)->toBeNull()
            ->and($link->expires_at)->toBeNull();
    });

    it('is refused while the administrator has sharing turned off, and writes nothing', function (): void {
        $case = shareLinkCase();
        app(InstanceSettings::class)->update([InstanceSettings::SHARE_LINKS_ENABLED => false]);

        expect(fn () => shareLinkMake($case['inventory'], $case['owner']))->toThrow(ShareLinksDisabledException::class)
            ->and(ShareLink::query()->count())->toBe(0);
    });

    it('is refused beyond the links an inventory may have, and not for another inventory', function (): void {
        config(['inventories.max_share_links' => 2]);
        $case = shareLinkCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        shareLinkMake($case['inventory'], $case['owner']);
        shareLinkMake($case['inventory'], $case['owner']);

        try {
            shareLinkMake($case['inventory'], $case['owner']);
            $limit = null;
        } catch (ShareLinkLimitReachedException $reached) {
            $limit = $reached->limit;
        }

        expect($limit)->toBe(2)
            ->and(ShareLink::query()->where('inventory_id', $case['inventory']->id)->count())->toBe(2);

        shareLinkMake($other, $case['owner']);

        expect(ShareLink::query()->where('inventory_id', $other->id)->count())->toBe(1);
    });

    it('stops counting a link the moment its time is up', function (): void {
        config(['inventories.max_share_links' => 1]);
        $case = shareLinkCase();
        $link = ShareLink::factory()->for($case['inventory'])->create(['expires_at' => now()->addMinute()]);

        expect(fn () => shareLinkMake($case['inventory'], $case['owner']))->toThrow(ShareLinkLimitReachedException::class);

        $link->forceFill(['expires_at' => now()])->save();
        shareLinkMake($case['inventory'], $case['owner']);

        expect(ShareLink::query()->where('inventory_id', $case['inventory']->id)->count())->toBe(2);
    });

    it('leaves room again when a link is revoked', function (): void {
        config(['inventories.max_share_links' => 1]);
        $case = shareLinkCase();
        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner']);

        expect(fn () => shareLinkMake($case['inventory'], $case['owner']))->toThrow(ShareLinkLimitReachedException::class);

        app(ShareLinks::class)->revoke($case['inventory'], $link);
        shareLinkMake($case['inventory'], $case['owner']);

        expect(ShareLink::query()->count())->toBe(1);
    });

    it('locks the account of the author, then the inventory, before it counts', function (): void {
        $case = shareLinkCase();

        $statements = statementsOf(fn () => shareLinkMake($case['inventory'], $case['owner']));

        $locks = array_values(array_filter($statements, fn (string $statement): bool => str_contains(strtolower($statement), ' for update')));
        $count = array_keys(array_filter($statements, fn (string $statement): bool => str_contains(strtolower($statement), 'count(*)') && str_contains($statement, 'share_links')));

        expect($locks)->toHaveCount(2)
            ->and($locks[0])->toContain('users')
            ->and($locks[1])->toContain('inventories')
            ->and($count)->toHaveCount(1)
            ->and($count[0])->toBeGreaterThan(array_search($locks[1], $statements, true));
    });

    it('fails like a missing route binding when the inventory was deleted meanwhile', function (): void {
        $case = shareLinkCase();
        $case['inventory']->delete();

        expect(fn () => shareLinkMake($case['inventory'], $case['owner']))->toThrow(ModelNotFoundException::class);
    });
});

describe('the address of a link', function (): void {
    it('is the page of the token under APP_URL, whatever the host of the request', function (): void {
        config(['app.url' => 'https://shared.example.test/']);
        $case = shareLinkCase();
        ['link' => $link, 'token' => $token] = shareLinkMake($case['inventory'], $case['owner']);

        expect(app(ShareLinks::class)->urlOf($link))->toBe("https://shared.example.test/s/{$token}")
            ->and(ShareLinks::addressOf($token))->toBe("https://shared.example.test/s/{$token}")
            ->and(ShareLinks::PAGE_PATH)->toBe('/s/{token}');
    });

    it('is read back from a link loaded again, which has the encrypted token only', function (): void {
        $case = shareLinkCase();
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner']);

        $loaded = ShareLink::query()->sole();

        expect(app(ShareLinks::class)->urlOf($loaded))->toBe(ShareLinks::addressOf($token));
    });

    it('is null when the ciphertext cannot be read: broken, or made with another application key', function (string $kind): void {
        $case = shareLinkCase();
        ['link' => $link, 'token' => $token] = shareLinkMake($case['inventory'], $case['owner']);

        $broken = match ($kind) {
            'garbage' => 'not a payload',
            'cut' => substr((string) $link->token_encrypted, 0, 40),
            'another key' => (new Encrypter(random_bytes(32), config()->string('app.cipher')))->encryptString($token),
        };
        DB::table('share_links')->where('id', $link->id)->update(['token_encrypted' => $broken]);

        expect(app(ShareLinks::class)->urlOf($link->fresh()))->toBeNull();
    })->with(['garbage', 'cut', 'another key']);

    it('is null when the ciphertext decrypts to something that is not the token of that link', function (): void {
        $case = shareLinkCase();
        ['link' => $first] = shareLinkMake($case['inventory'], $case['owner']);
        ['link' => $second] = shareLinkMake($case['inventory'], $case['owner']);
        // The ciphertext of one row copied into the other.
        DB::table('share_links')->where('id', $first->id)->update(['token_encrypted' => $second->token_encrypted]);
        $text = Crypt::encryptString('hello');
        DB::table('share_links')->where('id', $second->id)->update(['token_encrypted' => $text]);

        expect(app(ShareLinks::class)->urlOf($first->fresh()))->toBeNull()
            ->and(app(ShareLinks::class)->urlOf($second->fresh()))->toBeNull();
    });

    it('still opens by its token when its address cannot be read back: the hash is what is looked up', function (): void {
        $case = shareLinkCase();
        ['link' => $link, 'token' => $token] = shareLinkMake($case['inventory'], $case['owner']);
        DB::table('share_links')->where('id', $link->id)->update(['token_encrypted' => 'garbage']);

        expect(app(ShareLinks::class)->resolve($token)?->is($link))->toBeTrue();
    });
});

describe('resolving a token', function (): void {
    it('gives the link, with its inventory loaded, for a token that opens one', function (): void {
        $case = shareLinkCase();
        ['link' => $link, 'token' => $token] = shareLinkMake($case['inventory'], $case['owner'], 'Insurer', false);

        $found = app(ShareLinks::class)->resolve($token);

        expect($found?->is($link))->toBeTrue()
            ->and($found->relationLoaded('inventory'))->toBeTrue()
            ->and($found->inventory->is($case['inventory']))->toBeTrue()
            ->and($found->include_files)->toBeFalse()
            ->and($found->label)->toBe('Insurer');
    });

    it('opens a link that never expires and one that is not expired yet, up to the second', function (): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareLinkCase();
        ['token' => $forever] = shareLinkMake($case['inventory'], $case['owner'], null, true, null);
        ['token' => $week] = shareLinkMake($case['inventory'], $case['owner'], null, true, 7);

        $this->travelTo(now()->addDays(7)->subSecond());

        expect(app(ShareLinks::class)->resolve($forever))->not->toBeNull()
            ->and(app(ShareLinks::class)->resolve($week))->not->toBeNull();

        $this->travelTo(now()->addSecond());

        expect(app(ShareLinks::class)->resolve($week))->toBeNull()
            ->and(app(ShareLinks::class)->resolve($forever))->not->toBeNull();
    });

    it('gives nothing for a token that opens nothing, however it fails', function (string $token): void {
        $case = shareLinkCase();
        ['token' => $real] = shareLinkMake($case['inventory'], $case['owner']);

        expect(app(ShareLinks::class)->resolve($token === 'REAL+' ? $real.'+' : $token))->toBeNull();
    })->with([
        'empty' => [''],
        'unknown' => [str_repeat('A', 43)],
        'a real shape, made up' => ['abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ'],
        'too short' => [str_repeat('a', 42)],
        'too long' => [str_repeat('a', 44)],
        'with padding' => [str_repeat('a', 42).'='],
        'with a plus' => [str_repeat('a', 42).'+'],
        'with a slash' => [str_repeat('a', 42).'/'],
        'with a space' => [str_repeat('a', 42).' '],
        'with a trailing newline' => [str_repeat('a', 43)."\n"],
        'accented' => [str_repeat('é', 43)],
        'the token and one more character' => ['REAL+'],
    ]);

    it('does not open a link with the letters of its token in another case', function (): void {
        $case = shareLinkCase();
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner']);

        $swapped = strtr($token, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');

        expect($swapped)->not->toBe($token)
            ->and(app(ShareLinks::class)->resolve($swapped))->toBeNull()
            ->and(app(ShareLinks::class)->resolve($token))->not->toBeNull();
    });

    it('gives nothing for a link that expired, and the same for one that was revoked', function (): void {
        $case = shareLinkCase();
        ShareLink::factory()->for($case['inventory'])->withToken($expiredToken = ShareLinks::newToken())->expired()->create();
        ['link' => $revoked, 'token' => $revokedToken] = shareLinkMake($case['inventory'], $case['owner']);
        app(ShareLinks::class)->revoke($case['inventory'], $revoked);

        expect(app(ShareLinks::class)->resolve($expiredToken))->toBeNull()
            ->and(app(ShareLinks::class)->resolve($revokedToken))->toBeNull();
    });

    it('gives nothing while the administrator has sharing turned off, and the links work again once it is on', function (): void {
        $case = shareLinkCase();
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner']);

        app(InstanceSettings::class)->update([InstanceSettings::SHARE_LINKS_ENABLED => false]);

        expect(app(ShareLinks::class)->resolve($token))->toBeNull()
            // Kept, not deleted.
            ->and(ShareLink::query()->count())->toBe(1);

        app(InstanceSettings::class)->update([InstanceSettings::SHARE_LINKS_ENABLED => true]);

        expect(app(ShareLinks::class)->resolve($token))->not->toBeNull();
    });

    it('looks the link up by the hash alone: the token never reaches the database', function (): void {
        $case = shareLinkCase();
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner']);
        $bindings = [];
        DB::listen(function ($query) use (&$bindings): void {
            $bindings = [...$bindings, ...array_map(strval(...), array_filter($query->bindings, is_scalar(...)))];
        });

        app(ShareLinks::class)->resolve($token);

        expect($bindings)->toContain(hash('sha256', $token))->not->toContain($token);
    });

    it('does not even ask the database about a text that is not shaped like a token', function (): void {
        $statements = statementsOf(fn () => app(ShareLinks::class)->resolve('nope'));

        expect($statements)->toBe([]);
    });
});

describe('noting that a link was used', function (): void {
    it('writes the moment the first time, and at most once an hour after that', function (): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareLinkCase();
        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner']);
        $service = app(ShareLinks::class);
        $first = now()->toIso8601String();

        expect($service->touch($link))->toBeTrue()
            ->and(ShareLink::query()->sole()->last_used_at?->toIso8601String())->toBe($first);

        $this->travelTo(now()->addMinutes(59));

        expect($service->touch($link->fresh()))->toBeFalse()
            ->and(ShareLink::query()->sole()->last_used_at?->toIso8601String())->toBe($first);

        $this->travelTo(now()->addMinute());

        expect($service->touch($link->fresh()))->toBeTrue()
            ->and(ShareLink::query()->sole()->last_used_at?->toIso8601String())->toBe(now()->toIso8601String());
    });

    it('is one conditional statement that asks no lock, and does not move the date the link was changed', function (): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareLinkCase();
        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner']);
        $updated = ShareLink::query()->sole()->updated_at?->toIso8601String();
        $this->travelTo(now()->addDay());

        $statements = statementsOf(fn () => app(ShareLinks::class)->touch($link));

        expect($statements)->toHaveCount(1)
            ->and(strtolower($statements[0]))->toStartWith('update')->toContain('last_used_at')->not->toContain('updated_at')->not->toContain('for update')
            ->and(ShareLink::query()->sole()->updated_at?->toIso8601String())->toBe($updated);
    });

    it('writes nothing for a link that was deleted meanwhile', function (): void {
        $case = shareLinkCase();
        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner']);
        $link->delete();

        expect(app(ShareLinks::class)->touch($link))->toBeFalse();
    });
});

describe('revoking a link', function (): void {
    it('deletes it, and only a link of that inventory', function (): void {
        $case = shareLinkCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        ['link' => $mine] = shareLinkMake($case['inventory'], $case['owner']);
        ['link' => $theirs] = shareLinkMake($other, $case['owner']);

        expect(app(ShareLinks::class)->revoke($case['inventory'], $theirs))->toBeFalse()
            ->and(ShareLink::query()->count())->toBe(2)
            ->and(app(ShareLinks::class)->revoke($case['inventory'], $mine))->toBeTrue()
            ->and(app(ShareLinks::class)->revoke($case['inventory'], $mine))->toBeFalse()
            ->and(ShareLink::query()->pluck('id')->all())->toBe([$theirs->id]);
    });
});

describe('listing the links', function (): void {
    it('gives those of the inventory, the newest first, the expired ones included, each with its author', function (): void {
        $case = shareLinkCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        ['link' => $first] = shareLinkMake($case['inventory'], $case['owner']);
        $expired = ShareLink::factory()->for($case['inventory'])->expired()->create(['created_by_id' => $case['owner']->id]);
        shareLinkMake($other, $case['owner']);

        $links = app(ShareLinks::class)->list($case['inventory']);

        expect($links->pluck('id')->all())->toBe([$expired->id, $first->id])
            ->and($links->every(fn (ShareLink $link): bool => $link->relationLoaded('creator')))->toBeTrue();
    });
});

describe('pruning the links', function (): void {
    it('deletes those that expired more than 30 days ago, and keeps the others', function (): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareLinkCase();
        $inventory = $case['inventory'];
        $long = ShareLink::factory()->for($inventory)->create(['expires_at' => now()->subDays(30)->subSecond()]);
        $longer = ShareLink::factory()->for($inventory)->create(['expires_at' => now()->subYear()]);
        $recent = ShareLink::factory()->for($inventory)->create(['expires_at' => now()->subDays(30)]);
        $expiredToday = ShareLink::factory()->for($inventory)->expired()->create();
        $active = ShareLink::factory()->for($inventory)->create(['expires_at' => now()->addDay()]);
        $forever = ShareLink::factory()->for($inventory)->create(['expires_at' => null]);

        $deleted = app(ShareLinks::class)->prune();

        expect($deleted)->toBe(2)
            ->and(ShareLink::query()->orderBy('id')->pluck('id')->all())->toBe([$recent->id, $expiredToday->id, $active->id, $forever->id])
            ->and(ShareLink::query()->whereKey([$long->id, $longer->id])->count())->toBe(0);
    });

    it('goes through as many as there are, a few hundred at a time', function (): void {
        $case = shareLinkCase();
        $rows = [];

        foreach (range(1, 1100) as $number) {
            $rows[] = [
                'ulid' => strtolower((string) Str::ulid()),
                'inventory_id' => $case['inventory']->id,
                'token_hash' => hash('sha256', "token {$number}"),
                'token_encrypted' => 'x',
                'include_files' => true,
                'expires_at' => now()->subDays(40),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('share_links')->insert($chunk);
        }

        expect(app(ShareLinks::class)->prune())->toBe(1100)
            ->and(ShareLink::query()->count())->toBe(0)
            ->and(app(ShareLinks::class)->prune())->toBe(0);
    });

    it('is the command inventaire:prune-share-links, which says how many it deleted', function (): void {
        $case = shareLinkCase();
        ShareLink::factory()->for($case['inventory'])->create(['expires_at' => now()->subDays(31)]);
        ShareLink::factory()->for($case['inventory'])->create(['expires_at' => now()->addDay()]);

        $this->artisan('inventaire:prune-share-links')->expectsOutputToContain('1 expired share link(s) deleted.')->assertSuccessful();

        expect(ShareLink::query()->count())->toBe(1);
    });

    it('is scheduled every day, one run at a time', function (): void {
        $this->artisan('inventaire:prune-share-links')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'inventaire:prune-share-links'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 0 * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->expiresAt)->toBe(30);
    });
});
