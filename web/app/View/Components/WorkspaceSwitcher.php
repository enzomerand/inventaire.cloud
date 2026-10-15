<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\User;
use App\Workspaces\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The workspace switcher of the header: the workspaces of the signed-in user, the current one marked.
 *
 * The list is handed to the browser as JSON in the markup, where the Alpine component seeds the shared store with
 * it (see resources/js/components/workspace-switcher.js); the name of the current workspace is also in the markup
 * as it is, so that the button reads right before any script ran.
 */
class WorkspaceSwitcher extends Component
{
    /**
     * @param  Workspaces  $workspaces  Where the workspaces of the user are read from.
     */
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * The workspaces of the signed-in user, in the shape the scripts use (see stores/workspaces.js).
     *
     * @return list<array{id: string, name: string, role: string, current: bool}>
     */
    public function items(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return array_map(fn ($membership): array => [
            'id' => $membership->workspace->ulid,
            'name' => $membership->workspace->name,
            'role' => $membership->role->value,
            'current' => $membership->current,
        ], $this->workspaces->memberships($user));
    }

    /**
     * Renders the switcher.
     */
    public function render(): View
    {
        return view('components.workspace-switcher');
    }
}
