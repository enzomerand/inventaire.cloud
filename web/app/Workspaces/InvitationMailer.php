<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Mail\AfterResponse;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\LocaleResolver;
use App\Support\PublicUrls;
use Illuminate\Support\Facades\Mail;

/**
 * Mails an invitation to the address it is bound to, once the response has gone.
 *
 * The mail is written in the language of the account when the address already has one, else in the language of the request that
 * sends it (the inviter's): the invitee has not chosen a language yet. Whether the address has an account is looked up after the
 * answer, so that nothing the inviter sees depends on it. A mail that cannot be sent is logged ({@see AfterResponse}), and the
 * invitation is kept: the owner can send it again.
 */
final class InvitationMailer
{
    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * Schedules the mail of an invitation for after the response.
     *
     * What the mail needs is read now, as plain values: the request and the models are gone when it is sent.
     *
     * @param  User  $inviter  The person who sends it, named in the mail.
     */
    public function send(WorkspaceInvitation $invitation, User $inviter): void
    {
        $address = $invitation->email;
        $requestLocale = app()->getLocale();
        $mail = new WorkspaceInvitationMail(
            inviterName: $inviter->name,
            workspaceName: $invitation->workspace->name,
            role: $invitation->role,
            expiresAt: $invitation->expires_at,
            pageUrl: PublicUrls::to(WorkspaceInvitationMail::PAGE),
        );

        AfterResponse::run('a workspace invitation', function () use ($address, $requestLocale, $mail): void {
            $account = User::query()->where('email', $address)->first();
            $locale = $account !== null && $account->locale !== null && $this->locales->isSupported($account->locale) ? $account->locale : $requestLocale;

            Mail::to($address)->locale($locale)->send($mail);
        });
    }
}
