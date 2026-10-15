<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds workspaces for tests and local seeding: a bare workspace, without members.
 *
 * Tests that need people in it create it through `App\Workspaces\Workspaces::create()` (which makes its creator the
 * owner) instead; this is for the rows that only need a workspace to hang from.
 *
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }
}
