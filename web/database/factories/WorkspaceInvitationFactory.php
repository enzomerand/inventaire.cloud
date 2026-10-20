<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Workspaces\WorkspaceRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds invitations for tests and local seeding: a pending invitation to be an editor, valid for two weeks, without a sender.
 *
 * Tests that exercise the rules create invitations through `App\Workspaces\Invitations::invite()` instead; this is for the rows that
 * only need an invitation to exist (an expired one, one past the limits, one addressed to somebody).
 *
 * @extends Factory<WorkspaceInvitation>
 */
class WorkspaceInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => WorkspaceRole::Editor->value,
            'invited_by_id' => null,
            'expires_at' => now()->addDays(14),
        ];
    }

    /**
     * Indicate that the time of the invitation is up.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => ['expires_at' => now()->subSecond()]);
    }
}
