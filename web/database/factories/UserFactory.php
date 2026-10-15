<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Workspaces\Workspaces;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds users for tests and local seeding.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'locale' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user administers the instance.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }

    /**
     * Give the account its personal workspace, as signing up does.
     *
     * The factory otherwise builds a bare user (as accounts that predate workspaces were), which is what most
     * tests want: they ask for the workspace when they need one.
     */
    public function withPersonalWorkspace(): static
    {
        return $this->afterCreating(function (User $user): void {
            app(Workspaces::class)->createPersonal($user);
        });
    }

    /**
     * Indicate the language the account follows.
     */
    public function speaking(string $locale): static
    {
        return $this->state(fn (array $attributes) => [
            'locale' => $locale,
        ]);
    }
}
