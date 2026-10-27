<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\PublicUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

require_once __DIR__.'/../../../Support/inventories.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A zone with a change of hour between the two dates of the tests: 2 March is winter time there, 1 April is summer time.
    config(['app.display_timezone' => 'Europe/Paris']);
    $this->travelTo(Carbon::parse('2026-03-02 12:30:00', 'UTC'));
});

/**
 * Makes a token the way the web front does, from a known address and browser, unless the test says otherwise.
 *
 * @param  array<string, mixed>  $body  Replaces the fields of the default request (a name and two abilities).
 * @param  array<string, string>  $headers
 */
function tokenCreationRequest(object $test, User $user, array $body = [], array $headers = []): TestResponse
{
    $test->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);

    return inventoryCall(
        $test,
        $user,
        'POST',
        '/api/v1/me/tokens',
        $body + ['name' => 'Stock script', 'abilities' => ['read', 'write']],
        $headers + ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0'],
    );
}

describe('the notice of a new token', function (): void {
    it('is sent once, after the answer, to the owner of the account', function (): void {
        $deferred = holdDeferred();
        $user = inventoryPerson('Ada');

        tokenCreationRequest($this, $user)->assertCreated();

        // The answer has gone and the mail has not: the sending waits for the end of the request.
        expect(sentMails())->toBe([])->and($deferred->count())->toBe(1);

        $deferred->release();

        $mails = sentMails();

        expect($mails)->toHaveCount(1)
            ->and($mails[0]->getTo())->toHaveCount(1)
            ->and($mails[0]->getTo()[0]->getAddress())->toBe($user->email)
            ->and($mails[0]->getSubject())->toContain((string) config('app.name'));
    });

    it('describes the token, where it came from and where to revoke it', function (): void {
        $deferred = holdDeferred();

        tokenCreationRequest($this, inventoryPerson('Ada'), ['expires_in_days' => 30])->assertCreated();
        $deferred->release();

        $mail = sentMails()[0];
        $account = PublicUrls::to('/app/account');

        expect($mail->getTextBody())
            ->toContain('Hello Ada,')
            ->toContain('Token name: Stock script')
            ->toContain('Permissions: Read, Write')
            // In the zone of the instance, named, whatever the zone the dates are stored in.
            ->toContain('Created: March 2, 2026 1:30 PM (Europe/Paris)')
            ->toContain('Expires: April 1, 2026 2:30 PM (Europe/Paris)')
            ->toContain('IP address: 203.0.113.7')
            ->toContain('Browser or application: Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0')
            ->toContain('If you did not create this token, revoke it now from your account page and sign out everywhere:')
            ->toContain($account)
            ->and($mail->getHtmlBody())
            ->toContain('Token name: Stock script')
            ->toContain('Permissions: Read, Write')
            ->toContain('Expires: April 1, 2026 2:30 PM (Europe/Paris)')
            ->toContain('IP address: 203.0.113.7')
            ->toContain('href="'.$account.'"')
            // Nothing is fetched when the mail is opened.
            ->not->toMatch('/<(img|link|script)\b|\burl\(|\bsrc=/i');
    });

    it('names the zone of its dates when the instance is on UTC too, in the text and in the HTML', function (): void {
        config(['app.display_timezone' => 'UTC']);
        $deferred = holdDeferred();

        tokenCreationRequest($this, inventoryPerson('Ada'), ['expires_in_days' => 30])->assertCreated();
        $deferred->release();

        $mail = sentMails()[0];

        expect($mail->getTextBody())
            ->toContain('Created: March 2, 2026 12:30 PM (UTC)')
            ->toContain('Expires: April 1, 2026 12:30 PM (UTC)')
            ->and($mail->getHtmlBody())->toContain('Expires: April 1, 2026 12:30 PM (UTC)');
    });

    it('says "never" for a token that does not expire', function (): void {
        $deferred = holdDeferred();

        tokenCreationRequest($this, inventoryPerson('Ada'), ['expires_in_days' => null])->assertCreated();
        $deferred->release();

        expect(sentMails()[0]->getTextBody())->toContain('Expires: Never');
    });

    it('never carries the value of the token, nor what is stored of it', function (): void {
        $deferred = holdDeferred();

        $secret = tokenCreationRequest($this, inventoryPerson('Ada'))->assertCreated()->json('data.plain_text_token');
        $stored = DB::table('personal_access_tokens')->sole();
        $deferred->release();

        $mail = sentMails()[0];

        expect($secret)->toStartWith('inv_');

        foreach ([$mail->getTextBody(), $mail->getHtmlBody(), $mail->getSubject()] as $part) {
            expect($part)->not->toContain($secret)->not->toContain($stored->id.'|')->not->toContain($stored->token)->not->toContain('plain_text_token');
        }
    });

    it('writes the text of the person as it is in the plain text, and escaped in the HTML', function (): void {
        $deferred = holdDeferred();

        tokenCreationRequest($this, inventoryPerson('Ada'), ['name' => '<b>"Stock"</b> & Co'], ['User-Agent' => 'Agent <i>1</i>'])->assertCreated();
        $deferred->release();

        $mail = sentMails()[0];

        expect($mail->getTextBody())->toContain('Token name: <b>"Stock"</b> & Co')->toContain('Browser or application: Agent <i>1</i>')->not->toContain('&amp;')->not->toContain('&quot;')
            ->and($mail->getHtmlBody())->toContain('Token name: &lt;b&gt;&quot;Stock&quot;&lt;/b&gt; &amp; Co')->toContain('Agent &lt;i&gt;1&lt;/i&gt;')->not->toContain('<b>')->not->toContain('<i>');
    });

    it('keeps the user agent short and on one line: the sender of the request cannot add lines to the mail', function (): void {
        $deferred = holdDeferred();
        $agent = "Mozilla\r\nHi\n\nIf this was you, ignore this mail ".str_repeat('x', 400);

        tokenCreationRequest($this, inventoryPerson('Ada'), ['name' => "Line one\nLine two"], ['User-Agent' => $agent])->assertCreated();
        $deferred->release();

        $text = (string) sentMails()[0]->getTextBody();

        expect($text)->toContain('Token name: Line one Line two')->toContain('Browser or application: Mozilla Hi If this was you, ignore this mail xxx')
            ->and($text)->not->toMatch('/^If this was you/m')->not->toContain(str_repeat('x', 201));

        preg_match('/^Browser or application: (.*)$/m', $text, $line);

        expect(mb_strlen($line[1]))->toBeLessThanOrEqual(203)->and($line[1])->toEndWith('...');
    });

    it('says "unknown" when the request does not tell where it comes from', function (): void {
        $deferred = holdDeferred();
        $user = inventoryPerson('Ada');
        $this->withServerVariables(['REMOTE_ADDR' => '']);

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Stock script', 'abilities' => ['read']], ['User-Agent' => ''])->assertCreated();
        $deferred->release();

        expect(sentMails()[0]->getTextBody())->toContain('IP address: Unknown')->toContain('Browser or application: Unknown');
    });
});

describe('the language of the notice', function (): void {
    it('is the one of the account, not the one of the request', function (): void {
        $deferred = holdDeferred();
        $user = User::factory()->withPersonalWorkspace()->create(['name' => 'Ada', 'locale' => 'fr']);

        // The request asks for English (the default of the test client): the mail follows the account.
        tokenCreationRequest($this, $user, ['expires_in_days' => 30])->assertCreated();
        $deferred->release();

        $mail = sentMails()[0];

        expect($mail->getSubject())->toStartWith("Nouveau jeton d'API sur votre compte")
            ->and($mail->getTextBody())->toContain('Bonjour Ada,')
            ->toContain('Nom du jeton : Stock script')
            ->toContain('Permissions : Lecture, Écriture')
            ->toContain('Création : 2 mars 2026 13:30 (Europe/Paris)')
            ->toContain('Expiration : 1 avril 2026 14:30 (Europe/Paris)')
            ->toContain('Adresse IP : 203.0.113.7')
            ->toContain('déconnectez-vous partout')
            ->not->toContain('Token name')
            ->and($mail->getHtmlBody())->toContain('lang="fr"')->toContain('Permissions : Lecture, Écriture');
    });

    it('is English for an account in English, whatever the request asks for', function (): void {
        $deferred = holdDeferred();
        $user = User::factory()->withPersonalWorkspace()->create(['name' => 'Ada', 'locale' => 'en']);

        tokenCreationRequest($this, $user, [], ['Accept-Language' => 'fr'])->assertCreated();
        $deferred->release();

        $mail = sentMails()[0];

        expect($mail->getTextBody())->toContain('Hello Ada,')->toContain('Permissions: Read, Write')->toContain('Created: March 2, 2026 1:30 PM')
            ->and($mail->getHtmlBody())->toContain('lang="en"');
    });

    it('is the one of the request while the account has no language yet', function (): void {
        $deferred = holdDeferred();
        $user = User::factory()->withPersonalWorkspace()->create(['name' => 'Ada', 'locale' => null]);

        tokenCreationRequest($this, $user, [], ['Accept-Language' => 'fr'])->assertCreated();
        $deferred->release();

        expect(sentMails()[0]->getTextBody())->toContain('Bonjour Ada,')->toContain('Permissions : Lecture, Écriture');
    });
});

describe('a creation that is refused', function (): void {
    it('sends nothing when the request is wrong (422)', function (): void {
        $deferred = holdDeferred();

        tokenCreationRequest($this, inventoryPerson('Ada'), ['abilities' => []])->assertUnprocessable();
        tokenCreationRequest($this, inventoryPerson('Grace'), ['name' => ''])->assertUnprocessable();
        $deferred->release();

        expect($deferred->count())->toBe(0)->and(sentMails())->toBe([])->and(DB::table('personal_access_tokens')->count())->toBe(0);
    });

    it('sends nothing when the person has as many tokens as they may (409)', function (): void {
        config(['inventories.max_api_tokens' => 1]);
        $deferred = holdDeferred();
        $user = inventoryPerson('Ada');
        $user->createToken('old', ['read']);

        tokenCreationRequest($this, $user)->assertStatus(409)->assertJsonPath('code', 'token_limit_reached');
        $deferred->release();

        expect($deferred->count())->toBe(0)->and(sentMails())->toBe([])->and(DB::table('personal_access_tokens')->count())->toBe(1);
    });

});

describe('a mail that cannot be sent', function (): void {
    it('does not undo the creation: the token is made, the answer is 201, the failure is logged', function (): void {
        config([
            'mail.default' => 'unreachable',
            'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1],
        ]);
        Log::spy();
        $user = inventoryPerson('Ada');

        $response = tokenCreationRequest($this, $user)->assertCreated();

        expect($response->json('data.plain_text_token'))->toStartWith('inv_')
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(1);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Could not send an API token notice') && ! str_contains($message, $user->email))->once();
    });

    it('is sent once even when the database makes the creation run again', function (): void {
        $deferred = holdDeferred();
        $user = inventoryPerson('Ada');
        $tries = 0;
        PersonalAccessToken::creating(function () use (&$tries): void {
            if (++$tries === 1) {
                throw lostDeadlock();
            }
        });

        tokenCreationRequest($this, $user)->assertCreated();
        $deferred->release();

        expect($tries)->toBe(2)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(1)
            ->and(sentMails())->toHaveCount(1);
    });
});
