<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Inventories\Sharing\ShareLinks;
use App\Models\Inventory;
use App\Models\ShareLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds share links for tests and local seeding: a link that shows the files, never expires and has never been used, with a token of
 * its own (stored hashed and encrypted, as {@see ShareLinks} stores it). Without an inventory given (`->for($inventory)`), it builds a
 * bare one.
 *
 * The token itself is not kept on the model: a test that needs it gives one (`->withToken($token)`) or makes the link through
 * {@see ShareLinks::create()}, which hands it back.
 *
 * @extends Factory<ShareLink>
 */
class ShareLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_id' => Inventory::factory(),
            'created_by_id' => null,
            'label' => null,
            'include_files' => true,
            'expires_at' => null,
        ] + app(ShareLinks::class)->secretsOf(ShareLinks::newToken());
    }

    /**
     * Indicate the token of the link (a well-formed one, see {@see ShareLinks::isWellFormed()}).
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (): array => app(ShareLinks::class)->secretsOf($token));
    }

    /**
     * Indicate that the time of the link is up.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subSecond()]);
    }
}
