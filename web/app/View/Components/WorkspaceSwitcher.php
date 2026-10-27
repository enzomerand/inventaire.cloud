<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\User;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The workspace switcher of the header: the workspaces of the signed-in user, the current one marked.
 *
 * A page that belongs to a workspace (`/app/w/{workspace}`) says which one: that workspace is marked as the current one
 * on that page, whichever the account has chosen, since it is the one the person is looking at. Elsewhere the one the
 * account has chosen is marked.
 *
 * The list is handed to the browser as JSON in the markup, where the Alpine component seeds the shared store with
 * it (see resources/js/components/workspace-switcher.js); the name of the current workspace is also in the markup
 * as it is, so that the button reads right before any script ran. A workspace that belongs to somebody else comes with
 * the name of its owner, which the list shows under its name: every account starts with a workspace of the same name.
 */
class WorkspaceSwitcher extends Component
{
    /**
     * @param  Workspaces  $workspaces  Where the workspaces of the user are read from.
     * @param  string|null  $active  The ULID of the workspace the page belongs to, or null for a page that belongs to none.
     */
    public function __construct(private readonly Workspaces $workspaces, private readonly ?string $active = null) {}

    /**
     * The workspaces of the signed-in user, in the shape the scripts use (see stores/workspaces.js).
     *
     * @return list<array{id: string, name: string, role: string, current: bool, owner: string|null}>
     */
    public function items(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $memberships = $this->workspaces->memberships($user);
        $active = collect($memberships)->contains(fn ($membership): bool => $membership->workspace->ulid === $this->active)
            ? $this->active
            : null;

        // Who owns the workspaces of other people, so that two with the same name ("Personal space") can be told apart.
        $owners = $this->workspaces->ownerNames(array_values(array_map(
            fn ($membership): int => $membership->workspace->getKey(),
            array_filter($memberships, fn ($membership): bool => $membership->role !== WorkspaceRole::Owner),
        )));

        return array_map(fn ($membership): array => [
            'id' => $membership->workspace->ulid,
            'name' => $membership->workspace->name,
            'role' => $membership->role->value,
            'current' => $active === null ? $membership->current : $membership->workspace->ulid === $active,
            'owner' => $owners[$membership->workspace->getKey()] ?? null,
        ], $memberships);
    }

    /**
     * Renders the switcher.
     */
    public function render(): View
    {
        return view('components.workspace-switcher');
    }
}
