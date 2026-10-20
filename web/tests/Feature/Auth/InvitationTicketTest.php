<?php

declare(strict_types=1);

use App\Models\LoginCode;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Settings\InstanceSettings;
use App\Workspaces\Invitations;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 * While registration is closed, an invitation that waits for an address is its ticket: the address is given its sign-in code and the right
 * code creates the account. Nothing a client sees tells an invited address from one that is not (see ClosedRegistrationTest for the rest).
 */

beforeEach(function (): void {
    app(InstanceSettings::class)->update([InstanceSettings::REGISTRATION_OPEN => false]);
});

/**
 * Makes an invitation wait for an address, without sending any mail.
 *
 * @param  array<string, mixed>  $state
 */
function ticketFor(string $email, array $state = []): WorkspaceInvitation
{
    return WorkspaceInvitation::factory()->create($state + ['email' => $email]);
}

/**
 * What a client sees of an answer that does not depend on the address: its status, headers (cookies and the date aside) and body once the
 * address in it is replaced.
 *
 * @return array{status: int, headers: array<string, list<string|null>>, cookies: list<string>, body: string}
 */
function ticketSees(TestResponse $response, string $email): array
{
    $headers = $response->headers->allPreserveCase();
    $cookies = array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());
    unset($headers['Set-Cookie'], $headers['Date']);
    ksort($headers);

    return ['status' => $response->getStatusCode(), 'headers' => $headers, 'cookies' => $cookies, 'body' => str_replace($email, 'ADDRESS', (string) $response->getContent())];
}

describe('asking for a code', function (): void {
    it('gives an address that an invitation waits for its code, whatever its spelling', function (): void {
        ticketFor('new@example.test');

        askForCode($this, ' NEW@Example.test ')->assertOk();

        expect(sentMails())->toHaveCount(1)
            ->and(sentMails()[0]->getTo()[0]->getAddress())->toBe('new@example.test')
            ->and(lastSentCode())->toMatch('/^\d{6}$/');
    });

    it('still sends nothing to an address that no invitation waits for', function (): void {
        ticketFor('new@example.test');

        askForCode($this, 'other@example.test')->assertOk();

        expect(sentMails())->toBe([]);
    });

    it('sends nothing once the invitation expired, though it was not pruned', function (): void {
        ticketFor('new@example.test', ['expires_at' => now()->subSecond()]);

        askForCode($this, 'new@example.test')->assertOk();

        expect(sentMails())->toBe([]);
    });

    it('sends nothing once the invitation was withdrawn', function (): void {
        ticketFor('new@example.test')->delete();

        askForCode($this, 'new@example.test')->assertOk();

        expect(sentMails())->toBe([]);
    });

    it('answers an invited address exactly like one that is not: status, headers and body', function (): void {
        ticketFor('invited@example.test');

        $invited = askForCode($this, 'invited@example.test');
        $this->flushSession();
        $other = askForCode($this, 'other@example.test');

        expect($invited->getStatusCode())->toBe(200)
            ->and(ticketSees($other, 'other@example.test'))->toBe(ticketSees($invited, 'invited@example.test'));
    });

    it('does the same work before the answer for both, and looks the invitation up after it, and only then', function (): void {
        ticketFor('invited@example.test');
        $deferred = holdDeferred();

        $invited = statementsOf(fn () => askForCode($this, 'invited@example.test')->assertOk());
        $this->flushSession();
        $other = statementsOf(fn () => askForCode($this, 'other@example.test')->assertOk());
        $after = statementsOf(fn () => $deferred->release());

        expect($other)->toBe($invited)
            ->and(implode("\n", $invited))->not->toContain('workspace_invitations')
            ->and(implode("\n", $after))->toContain('workspace_invitations')
            ->and(sentMails())->toHaveCount(1);
    });

    it('keeps the code of an invited address whose mail failed, like that of any other address', function (): void {
        ticketFor('invited@example.test');
        config([
            'mail.default' => 'unreachable',
            'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1],
        ]);
        Log::spy();

        askForCode($this, 'invited@example.test')->assertOk();
        $this->flushSession();
        askForCode($this, 'other@example.test')->assertOk();

        expect(LoginCode::query()->count())->toBe(2);
    });

    it('does not change a thing for an address that has an account', function (): void {
        User::factory()->create(['email' => 'ada@example.test']);
        ticketFor('ada@example.test');

        askForCode($this, 'ada@example.test')->assertOk();

        expect(sentMails())->toHaveCount(1);
    });
});

describe('typing the code back', function (): void {
    it('creates the account of an invited address and signs it in, the invitation still waiting for its answer', function (): void {
        $invitation = ticketFor('new@example.test');
        $code = receiveCode($this, 'new@example.test');

        typeCode($this, 'new@example.test', $code, ['name' => 'Grace Hopper'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Grace Hopper')
            ->assertJsonPath('data.email', 'new@example.test');

        $user = User::query()->where('email', 'new@example.test')->sole();

        expect($user->workspaces()->count())->toBe(1)
            ->and(app(Invitations::class)->pendingFor($user)->pluck('id')->all())->toBe([$invitation->id]);
        $this->assertAuthenticatedAs($user, 'web');
    });

    it('then lets the new account accept the invitation and land in the workspace', function (): void {
        $owner = User::factory()->withPersonalWorkspace()->create();
        $workspace = app(Workspaces::class)->create($owner, 'Shop');
        $invitation = app(Invitations::class)->invite($owner, $workspace, 'new@example.test', WorkspaceRole::Editor);
        $code = receiveCode($this, 'new@example.test');
        typeCode($this, 'new@example.test', $code, ['name' => 'Grace Hopper'])->assertCreated();
        $user = User::query()->where('email', 'new@example.test')->sole();

        $joined = app(Invitations::class)->accept($user, $invitation);

        expect($joined->is($workspace))->toBeTrue()
            ->and(app(Workspaces::class)->roleOf($user, $workspace))->toBe(WorkspaceRole::Editor)
            ->and(WorkspaceInvitation::query()->count())->toBe(0);
    });

    it('asks for the name first, like any new address, and keeps the code until it comes', function (): void {
        ticketFor('new@example.test');
        $code = receiveCode($this, 'new@example.test');

        typeCode($this, 'new@example.test', $code)->assertUnprocessable()->assertJsonPath('code', 'name_required');

        expect(User::query()->count())->toBe(0)->and(LoginCode::query()->count())->toBe(1);
    });

    it('refuses the right code, as a wrong one, once the invitation expired or was withdrawn', function (string $how): void {
        $invitation = ticketFor('new@example.test');
        $code = receiveCode($this, 'new@example.test');
        $this->flushSession();

        if ($how === 'expired') {
            $invitation->forceFill(['expires_at' => now()->subSecond()])->save();
        } else {
            $invitation->delete();
        }

        typeCode($this, 'new@example.test', $code, ['name' => 'Grace Hopper'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'login_code_wrong');

        expect(User::query()->count())->toBe(0);
    })->with(['expired' => ['expired'], 'withdrawn' => ['withdrawn']]);

    it('answers a wrong code for an invited address exactly as for an address with an account and for any other, attempt after attempt', function (): void {
        User::factory()->create(['email' => 'ada@example.test']);
        $real = receiveCode($this, 'ada@example.test');
        $this->flushSession();
        ticketFor('invited@example.test');
        askForCode($this, 'invited@example.test')->assertOk();
        $this->flushSession();
        askForCode($this, 'other@example.test')->assertOk();
        $this->flushSession();

        $seen = ['ada@example.test' => [], 'invited@example.test' => [], 'other@example.test' => []];

        foreach (range(1, 6) as $_) {
            foreach (array_keys($seen) as $email) {
                $seen[$email][] = ticketSees(typeCode($this, $email, wrongCode($real)), $email);
                $this->flushSession();
            }
        }

        expect($seen['invited@example.test'])->toBe($seen['ada@example.test'])
            ->and($seen['other@example.test'])->toBe($seen['ada@example.test'])
            ->and(json_decode($seen['ada@example.test'][0]['body'], true)['code'])->toBe('login_code_wrong')
            ->and(json_decode($seen['ada@example.test'][4]['body'], true)['code'])->toBe('login_code_exhausted');
    });

    it('does the same work whether the address has an account, an invitation or neither', function (): void {
        User::factory()->create(['email' => 'ada@example.test']);
        ticketFor('invited@example.test');
        receiveCode($this, 'ada@example.test');
        $this->flushSession();
        askForCode($this, 'invited@example.test');
        $this->flushSession();
        askForCode($this, 'other@example.test');
        $this->flushSession();

        $known = statementsOf(fn () => typeCode($this, 'ada@example.test', '000000'));
        $this->flushSession();
        $invited = statementsOf(fn () => typeCode($this, 'invited@example.test', '000000'));
        $this->flushSession();
        $other = statementsOf(fn () => typeCode($this, 'other@example.test', '000000'));

        expect($invited)->toBe($known)->and($other)->toBe($known);
    });

    it('is not needed while registration is open: the invitation changes nothing then', function (): void {
        app(InstanceSettings::class)->update([InstanceSettings::REGISTRATION_OPEN => true]);
        ticketFor('new@example.test');
        $code = receiveCode($this, 'new@example.test');

        typeCode($this, 'new@example.test', $code, ['name' => 'Grace Hopper'])->assertCreated();

        expect(User::query()->where('email', 'new@example.test')->exists())->toBeTrue();
    });
});
