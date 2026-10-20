<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\PublicUrls;
use App\Workspaces\Exceptions\MembershipRefused;
use App\Workspaces\Invitations;
use App\Workspaces\Members;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A zone where the expiry, at the end of a day in UTC, falls on the next day: the date in the mail is the instance's.
    config(['app.display_timezone' => 'Europe/Paris']);
    $this->travelTo(Carbon::parse('2026-03-02 23:30:00', 'UTC'));
});

/**
 * An owner and a workspace called "Shop", the way a request in the given language would find them.
 *
 * @return array{owner: User, workspace: Workspace}
 */
function mailWorkspace(string $inviter = 'Ada Lovelace', string $workspace = 'Shop', string $language = 'en'): array
{
    app()->setLocale($language);
    $owner = User::factory()->withPersonalWorkspace()->create(['name' => $inviter]);

    return ['owner' => $owner, 'workspace' => app(Workspaces::class)->create($owner, $workspace)];
}

describe('the mail of an invitation', function (): void {
    it('goes to the invited address once the response has gone, and to nobody else', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace();

        app(Invitations::class)->invite($owner, $workspace, ' Nouveau@Example.test ', WorkspaceRole::Editor);

        // The answer has gone and the mail has not: the sending waits for the end of the request.
        expect(sentMails())->toBe([])->and($deferred->count())->toBe(1);

        $deferred->release();

        $mails = sentMails();

        expect($mails)->toHaveCount(1)
            ->and($mails[0]->getTo())->toHaveCount(1)
            ->and($mails[0]->getTo()[0]->getAddress())->toBe('nouveau@example.test')
            ->and($mails[0]->getCc())->toBe([])
            ->and($mails[0]->getBcc())->toBe([]);
    });

    it('says who invited, to which workspace, with which role, until when, and where to answer', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace();
        $page = PublicUrls::to('/app/invitations');

        app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor);
        $deferred->release();
        $mail = sentMails()[0];

        expect($mail->getTextBody())
            ->toContain('Hello,')
            ->toContain('Ada Lovelace invited you to join the workspace "Shop" on '.config('app.name').' with the role Editor.')
            ->toContain('sign in with this email address')
            // Two weeks later, in the zone of the instance: past midnight there.
            ->toContain('The invitation is valid until March 17, 2026.')
            ->toContain($page)
            ->and($mail->getHtmlBody())
            ->toContain('Ada Lovelace invited you to join the workspace &quot;Shop&quot;')
            ->toContain('with the role Editor.')
            ->toContain('The invitation is valid until March 17, 2026.')
            ->toContain('href="'.$page.'"')
            // Nothing is fetched when the mail is opened.
            ->not->toMatch('/<(img|link|script)\b|\burl\(|\bsrc=/i');
    });

    it('names a viewer as a viewer', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace();

        app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Viewer);
        $deferred->release();

        expect(sentMails()[0]->getTextBody())->toContain('with the role Viewer.');
    });

    it('carries no secret and only one link, to the page of invitations: the invitation is bound to the address', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace();

        $invitation = app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor);
        $deferred->release();
        $mail = sentMails()[0];

        preg_match_all('~https?://\S+~', (string) $mail->getTextBody(), $links);
        preg_match_all('~(?:href|src|action)="([^"]*)"~', (string) $mail->getHtmlBody(), $htmlLinks);

        expect($links[0])->toBe([PublicUrls::to('/app/invitations')])
            ->and($htmlLinks[1])->toBe([PublicUrls::to('/app/invitations')])
            ->and($mail->getTextBody().$mail->getHtmlBody())->not->toContain($invitation->ulid)->not->toContain((string) $workspace->ulid);
    });

    it('has a subject of its own that holds nothing a user wrote', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace('Ada Lovelace', 'Secret Shop');

        app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor);
        $deferred->release();
        $subject = sentMails()[0]->getSubject();

        expect($subject)->toBe('You have been invited to a workspace on '.config('app.name'))
            ->not->toContain('Ada')->not->toContain('Secret');
    });

    it('reduces what users wrote to one line, without control or direction characters, and escapes it in the HTML', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace("Eve\r\nBcc: victim@example.test\u{202E}evil", "Shop\nSecond line\u{2066}");

        app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor);
        $deferred->release();
        $text = (string) sentMails()[0]->getTextBody();

        expect($text)
            ->toContain('Eve Bcc: victim@example.test evil invited you to join the workspace "Shop Second line" on')
            ->not->toContain("\u{202E}")
            ->not->toContain("\u{2066}")
            ->not->toContain("Eve\r")
            ->not->toContain("Eve\n");
    });

    it('escapes markup in the HTML body and prints it as typed in the plain text one', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace('<b>Mallory</b>', 'Shop <script>x</script>');

        app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor);
        $deferred->release();
        $mail = sentMails()[0];

        expect($mail->getHtmlBody())->toContain('&lt;b&gt;Mallory&lt;/b&gt;')->not->toContain('<b>Mallory')->not->toContain('<script>')
            ->and($mail->getTextBody())->toContain('<b>Mallory</b> invited you');
    });
});

describe('the language of the mail', function (): void {
    it('is the one of the account when the address has one, whatever the language of the request', function (string $account, string $request, string $expected): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace(language: $request);
        User::factory()->create(['email' => 'ada@example.test', 'locale' => $account]);

        app(Invitations::class)->invite($owner, $workspace, 'ada@example.test', WorkspaceRole::Editor);
        $deferred->release();
        $mail = sentMails()[0];

        expect($mail->getSubject())->toStartWith($expected);
    })->with([
        'French account, English request' => ['fr', 'en', 'Vous avez été invité'],
        'English account, French request' => ['en', 'fr', 'You have been invited'],
    ]);

    it('is the one of the request when the address has no account yet', function (string $request, string $expected): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace(language: $request);

        app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor);
        $deferred->release();
        $mail = sentMails()[0];

        expect($mail->getSubject())->toStartWith($expected);
    })->with([
        'English' => ['en', 'You have been invited'],
        'French' => ['fr', 'Vous avez été invité'],
    ]);

    it('is the one of the request when the account has not chosen a language, or one this version does not offer', function (?string $account): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace(language: 'en');
        User::factory()->create(['email' => 'ada@example.test', 'locale' => $account]);

        app(Invitations::class)->invite($owner, $workspace, 'ada@example.test', WorkspaceRole::Editor);
        $deferred->release();

        expect(sentMails()[0]->getSubject())->toStartWith('You have been invited');
    })->with(['none' => [null], 'unknown' => ['xx']]);

    it('is written in French, with the date in French, the role translated and the quotes of the language', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace(language: 'fr');

        app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Viewer);
        $deferred->release();
        $mail = sentMails()[0];

        expect($mail->getTextBody())
            ->toContain('Bonjour,')
            ->toContain('Ada Lovelace vous invite à rejoindre l\'espace de travail « Shop » sur '.config('app.name').' avec le rôle Lecteur.')
            ->toContain('L\'invitation est valable jusqu\'au 17 mars 2026.')
            ->and($mail->getHtmlBody())->toContain('lang="fr"');
    });

    it('is looked up after the response, not before', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace();
        User::factory()->create(['email' => 'ada@example.test']);

        app(Invitations::class)->invite($owner, $workspace, 'ada@example.test', WorkspaceRole::Editor);
        $after = statementsOf(fn () => $deferred->release());

        // The one lookup that is made once the response has gone: the account of the address, for the language of the mail.
        expect(array_values(array_filter($after, fn (string $statement): bool => str_contains($statement, 'users'))))->toHaveCount(1)
            ->and(sentMails())->toHaveCount(1);
    });
});

describe('sending again', function (): void {
    it('mails the same page once more, after the pause', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace();
        $invitation = app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor);
        $deferred->release();

        $this->travel(10)->minutes();
        app(Invitations::class)->resend($owner, $workspace, $invitation);
        $deferred->release();

        expect(sentMails())->toHaveCount(2)
            ->and(sentMails()[1]->getTo()[0]->getAddress())->toBe('nouveau@example.test')
            ->and(sentMails()[1]->getTextBody())->toBe(sentMails()[0]->getTextBody());
    });

    it('names the owner who sends it now', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace();
        $successor = User::factory()->withPersonalWorkspace()->create(['name' => 'Grace Hopper']);
        $workspace->members()->attach($successor->id, ['role' => 'editor']);
        $invitation = app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor);
        $deferred->release();
        app(Members::class)->transferOwnership($owner, $workspace, $successor);

        $this->travel(10)->minutes();
        app(Invitations::class)->resend($successor, $workspace, $invitation);
        $deferred->release();

        expect(sentMails()[1]->getTextBody())->toContain('Grace Hopper invited you')
            ->and(sentMails()[0]->getTextBody())->toContain('Ada Lovelace invited you');
    });

    it('sends nothing when it is refused', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace();
        $invitation = app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor);
        $deferred->release();

        // Too soon, twice invited, and a member: none of them sends a mail.
        foreach ([
            fn () => app(Invitations::class)->resend($owner, $workspace, $invitation),
            fn () => app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor),
            fn () => app(Invitations::class)->invite($owner, $workspace, $owner->email, WorkspaceRole::Editor),
        ] as $attempt) {
            try {
                $attempt();
            } catch (MembershipRefused) {
            }
        }
        $deferred->release();

        expect(sentMails())->toHaveCount(1);
    });
});

describe('a mail that cannot be sent', function (): void {
    it('is logged without the address, and the invitation is kept, to be sent again', function (): void {
        $deferred = holdDeferred();
        ['owner' => $owner, 'workspace' => $workspace] = mailWorkspace();
        config([
            'mail.default' => 'unreachable',
            'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1],
        ]);
        Log::spy();

        $invitation = app(Invitations::class)->invite($owner, $workspace, 'nouveau@example.test', WorkspaceRole::Editor);
        $deferred->release();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, 'Could not send a workspace invitation') && ! str_contains($message, '@'))
            ->once();

        config(['mail.default' => 'array']);
        $this->travel(10)->minutes();
        app(Invitations::class)->resend($owner, $workspace, $invitation);
        $deferred->release();

        expect(WorkspaceInvitation::query()->count())->toBe(1)->and(sentMails())->toHaveCount(1);
    });
});
