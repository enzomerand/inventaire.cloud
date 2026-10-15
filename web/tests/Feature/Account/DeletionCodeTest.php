<?php

declare(strict_types=1);

use App\Auth\AccountDeletionCodes;
use App\Models\AccountDeletionCode;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\OneTimeCode;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Asks for the code that confirms the deletion of an account, the way the web front does.
 *
 * @param  array<string, string>  $headers
 */
function askForDeletionCode(object $test, User $user, array $headers = []): TestResponse
{
    return $test->actingAs($user)->postJson('/api/v1/me/deletion-code', [], frontend($headers));
}

/**
 * Whether a code is the one pending for the user.
 */
function deletionCodeMatches(User $user, string $code): bool
{
    $row = AccountDeletionCode::query()->find($user->id);

    return $row !== null && OneTimeCode::matches($code, $row->code_hash, hash_hmac('sha256', 'account-deletion|'.$user->id, config()->string('app.key')));
}

describe('asking for the code', function (): void {
    it('mails a 6-digit code to the address of the account and says how long it is valid', function (): void {
        $user = User::factory()->speaking('en')->create(['email' => 'ada@example.com']);

        askForDeletionCode($this, $user, ['Accept-Language' => 'en'])->assertOk()->assertExactJson(['data' => ['expires_in' => 600]]);

        $mail = sentMails()[0];
        expect(sentMails())->toHaveCount(1)
            ->and($mail->getTo()[0]->getAddress())->toBe('ada@example.com')
            ->and(lastSentCode())->toMatch('/^\d{6}$/')
            ->and($mail->getSubject())->toContain(lastSentCode())->toContain('account deletion code')
            ->and($mail->getTextBody())->toContain('Your account deletion code is: '.lastSentCode())->toContain('cannot be undone')
            ->and($mail->getHtmlBody())->toContain(lastSentCode())->not->toContain('<script');
    });

    it('writes it in the language of the account, not of the request', function (): void {
        $english = User::factory()->speaking('en')->create(['email' => 'ada@example.com']);
        $french = User::factory()->speaking('fr')->create(['email' => 'grace@example.com']);

        askForDeletionCode($this, $english, ['Accept-Language' => 'fr'])->assertOk();
        $this->flushSession();
        askForDeletionCode($this, $french, ['Accept-Language' => 'en'])->assertOk();

        expect(sentMails()[0]->getTextBody())->toContain('Your account deletion code is')
            ->and(sentMails()[1]->getTextBody())->toContain('Votre code de suppression de compte est')
            ->and(sentMails()[1]->getHtmlBody())->toContain('<html lang="fr">');
    });

    it('stores a keyed hash of the code and not the code', function (): void {
        $user = User::factory()->create();

        askForDeletionCode($this, $user)->assertOk();
        $code = lastSentCode();
        $row = AccountDeletionCode::query()->sole();

        expect($row->user_id)->toBe($user->id)
            ->and($row->code_hash)->toMatch('/^[0-9a-f]{64}$/')->not->toBe($code)
            ->and($row->attempts)->toBe(0)
            ->and($row->expires_at->isBetween(now()->addMinutes(9), now()->addMinutes(10)->addSecond()))->toBeTrue()
            // A sign-in code is hashed with the application key itself: this one is not, so the two cannot stand for each other.
            ->and(OneTimeCode::matches($code, $row->code_hash, config()->string('app.key')))->toBeFalse();
    });

    it('replaces the code the account had', function (): void {
        $user = User::factory()->create();

        askForDeletionCode($this, $user)->assertOk();
        $first = lastSentCode();
        $this->flushSession();
        askForDeletionCode($this, $user)->assertOk();
        $second = lastSentCode();

        expect(AccountDeletionCode::query()->count())->toBe(1)
            ->and(deletionCodeMatches($user, $second))->toBeTrue()
            // (Two codes can be the same by chance, one time in a million.)
            ->and($first === $second || ! deletionCodeMatches($user, $first))->toBeTrue();
    });

    it('leaves the sign-in code of the same address alone', function (): void {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $signInCode = receiveCode($this, 'ada@example.com');
        $pending = LoginCode::query()->sole();

        askForDeletionCode($this, $user)->assertOk();

        expect(LoginCode::query()->sole()->code_hash)->toBe($pending->code_hash)
            ->and(LoginCode::query()->sole()->attempts)->toBe(0);

        // The sign-in code still works.
        $this->flushSession();
        typeCode($this, 'ada@example.com', $signInCode)->assertOk();
    });

    it('is throttled per user, then allowed again', function (): void {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $limit = config()->integer('auth.account_deletion.send_per_user.attempts');

        foreach (range(1, $limit) as $_) {
            askForDeletionCode($this, $user)->assertOk();
        }

        askForDeletionCode($this, $user)->assertTooManyRequests()->assertHeader('Retry-After');
        expect(sentMails())->toHaveCount($limit);

        // Somebody else is not held back.
        $this->flushSession();
        askForDeletionCode($this, $other)->assertOk();

        // Nor is the same person, ten minutes later (an hourly allowance remains).
        $this->flushSession();
        $this->travel(11)->minutes();
        askForDeletionCode($this, $user)->assertOk();
    });

    it('has an hourly allowance too', function (): void {
        $user = User::factory()->create();
        $short = config()->integer('auth.account_deletion.send_per_user.attempts');
        $hourly = config()->integer('auth.account_deletion.send_per_user_hourly.attempts');

        // Within the short allowance each time, but not within the hourly one.
        $asked = 0;

        while ($asked < $hourly) {
            foreach (range(1, min($short, $hourly - $asked)) as $_) {
                askForDeletionCode($this, $user)->assertOk();
                $this->flushSession();
                $asked++;
            }

            $this->travel(11)->minutes();
        }

        askForDeletionCode($this, $user)->assertTooManyRequests();
    });

    it('is sent after the response, not through the queue: nothing readable is kept in the jobs table', function (): void {
        config(['queue.default' => 'database']);
        $deferred = holdDeferred();
        $user = User::factory()->create();

        askForDeletionCode($this, $user)->assertOk()->assertExactJson(['data' => ['expires_in' => 600]]);

        // The answer has gone and the mail has not: the code is stored, and the sending waits for the end of the request.
        expect(sentMails())->toBe([])
            ->and(AccountDeletionCode::query()->count())->toBe(1)
            ->and($deferred->count())->toBe(1);

        $deferred->release();

        expect(sentMails())->toHaveCount(1)
            ->and(deletionCodeMatches($user, lastSentCode()))->toBeTrue()
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('drops the code, and logs the failure, when the mail cannot be sent: the answer does not depend on it', function (): void {
        config([
            'mail.default' => 'unreachable',
            'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1],
        ]);
        Log::spy();

        askForDeletionCode($this, User::factory()->create())->assertOk()->assertJsonPath('data.expires_in', 600);

        expect(AccountDeletionCode::query()->count())->toBe(0);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Could not send an account deletion code'))->once();
    });

    it('leaves a newer code alone when the mail of an older one fails late', function (): void {
        $user = User::factory()->create();
        $codes = app(AccountDeletionCodes::class);
        $first = $codes->issue($user);
        $second = $codes->issue($user);

        $codes->forget($user, $first);

        expect(AccountDeletionCode::query()->count())->toBe(1)->and(deletionCodeMatches($user, $second))->toBeTrue();

        $codes->forget($user, $second);

        expect(AccountDeletionCode::query()->count())->toBe(0);
    });

    it('is for somebody who is signed in, and for the web front', function (): void {
        $this->postJson('/api/v1/me/deletion-code', [], frontend())->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/me/deletion-code')->assertForbidden()->assertJsonPath('code', 'not_stateful');

        expect(sentMails())->toBe([]);
    });

    it('is refused, and sends nothing, to an account that could not be deleted anyway', function (): void {
        $admin = User::factory()->admin()->create();

        askForDeletionCode($this, $admin)->assertStatus(409)->assertJsonPath('code', 'last_administrator');

        expect(sentMails())->toBe([])->and(AccountDeletionCode::query()->count())->toBe(0);
    });

    it('is refused to the only owner of a workspace that other people belong to, who is told which', function (): void {
        $owner = User::factory()->withPersonalWorkspace()->create();
        $guest = User::factory()->create();
        $shared = app(Workspaces::class)->create($owner, 'Shared');
        $shared->members()->attach($guest->id, ['role' => 'viewer']);

        askForDeletionCode($this, $owner)
            ->assertStatus(409)
            ->assertJsonPath('code', 'sole_owner_of_shared_workspaces')
            ->assertJsonPath('workspaces', [['id' => $shared->ulid, 'name' => 'Shared']]);

        expect(sentMails())->toBe([]);
    });
});

describe('the mail', function (): void {
    it('escapes the HTML part once and keeps the text part free of entities', function (string $locale): void {
        config(['app.name' => "L'Atelier & <Co>"]);
        $user = User::factory()->speaking($locale)->create();

        askForDeletionCode($this, $user)->assertOk();
        $mail = sentMails()[0];

        expect($mail->getHtmlBody())->toContain('L&#039;Atelier &amp; &lt;Co&gt;')->not->toContain('&amp;#039;')->not->toContain('<Co>')
            ->and($mail->getTextBody())->toContain("L'Atelier & <Co>")->not->toContain('&#039;')->not->toContain('&amp;')
            ->and($mail->getSubject())->toContain("L'Atelier & <Co>")->not->toContain('&amp;');
    })->with(['french' => ['fr'], 'english' => ['en']]);
});
