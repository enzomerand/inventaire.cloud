<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\User;
use App\Workspaces\Invitations;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The link to the page of invitations in the header, with how many are waiting: shown to a signed-in person who has some.
 *
 * It costs one count on `workspace_invitations (email, expires_at)`, and only on the pages of the app (`app.*`): the public pages
 * (home, templates, documentation) are cached by browsers and search engines, say nothing about a person, and never look it up.
 * Without an invitation waiting nothing is rendered. The script keeps the figure true when the person answers one
 * (see resources/js/components/invitations-link.js).
 */
class InvitationsLink extends Component
{
    /** How many invitations wait for the address of the signed-in account (0 on a page that is not one of the app). */
    public readonly int $waiting;

    /**
     * Counts the invitations that wait for the signed-in account, on a page of the app.
     */
    public function __construct(Invitations $invitations)
    {
        $user = auth()->user();

        $this->waiting = $user instanceof User && request()->routeIs('app.*')
            ? $invitations->countPendingFor($user->email)
            : 0;
    }

    /**
     * Whether the link is rendered at all: only when an invitation waits.
     */
    public function shouldRender(): bool
    {
        return $this->waiting > 0;
    }

    /**
     * Renders the link.
     */
    public function render(): View
    {
        return view('components.invitations-link');
    }
}
