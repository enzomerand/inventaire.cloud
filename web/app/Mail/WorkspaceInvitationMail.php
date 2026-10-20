<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\DisplayTime;
use App\Workspaces\Invitations;
use App\Workspaces\WorkspaceRole;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The mail an invited person receives: who invited them, to which workspace, with which role, until when, and where to answer.
 *
 * The invitation is bound to the address it is mailed to and holds no secret: the mail only links to the page of invitations, where
 * the person signs in with that address (the code sent to it proves it is theirs) and accepts or declines. So forwarding the mail gives
 * nothing to whoever gets it.
 *
 * Sent once the response has gone ({@see AfterResponse}, by {@see Invitations}), as plain text plus a simple HTML version with no
 * external image, font or tracking. The subject is generic: the names of a person and of a workspace are written by users, and
 * what a user writes does not belong in a subject line. Those names are reduced to one line without control or direction characters
 * in the body, so that they cannot add lines or reorder the text.
 */
final class WorkspaceInvitationMail extends Mailable
{
    /** The path of the page where invitations are answered. */
    public const PAGE = '/app/invitations';

    /**
     * @param  string  $inviterName  The name of the person who invited.
     * @param  string  $workspaceName  The name of the workspace.
     * @param  WorkspaceRole  $role  What the invitation lets the invitee do (editor or viewer).
     * @param  CarbonInterface  $expiresAt  When the invitation lapses.
     * @param  string  $pageUrl  The absolute address of the page of invitations.
     */
    public function __construct(
        public readonly string $inviterName,
        public readonly string $workspaceName,
        public readonly WorkspaceRole $role,
        public readonly CarbonInterface $expiresAt,
        public readonly string $pageUrl,
    ) {}

    /**
     * The subject names the application, and nothing a user wrote.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to a workspace on :app', ['app' => config('app.name')]),
        );
    }

    /**
     * Both bodies: a mail client shows the HTML one, the plain text serves the others.
     *
     * The data ready for the views is made here, while the language of the mail is in force.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.workspace-invitation-html',
            text: 'mail.workspace-invitation',
            with: [
                'inviter' => self::oneLine($this->inviterName),
                'workspace' => self::oneLine($this->workspaceName),
                'roleLabel' => match ($this->role) {
                    WorkspaceRole::Owner => __('Owner'),
                    WorkspaceRole::Editor => __('Editor'),
                    WorkspaceRole::Viewer => __('Viewer'),
                },
                'expiresLabel' => DisplayTime::of($this->expiresAt)->locale(app()->getLocale())->isoFormat('LL'),
            ],
        );
    }

    /**
     * The text on one line: what a user wrote (a name) cannot add lines to the plain-text body, nor reorder what is around it with
     * direction characters (control and format characters, and every kind of space, become a single space).
     */
    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/[\p{C}\p{Z}]+/u', ' ', $text) ?? '');
    }
}
