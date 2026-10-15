<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Inventories\Fields\FieldTypes;
use App\Models\Inventory;
use App\Models\InventoryField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds fields for tests and local seeding: a text field unless told otherwise.
 *
 * It writes the row directly, without the checks the services make (a name already taken in the inventory, the quota):
 * tests that need those go through `App\Inventories\InventoryFields`. The options of a given type are normalized by
 * the type, so a field built here holds them in the canonical form a real one does.
 *
 * @extends Factory<InventoryField>
 */
class InventoryFieldFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'type' => 'text',
            'options' => ['max_length' => 255],
            'required' => false,
            'position' => 0,
        ];
    }

    /**
     * Makes the field of a registry type, with options as a person would send them (defaults are filled in).
     *
     * @param  array<string, mixed>  $options
     */
    public function ofType(string $type, array $options = []): static
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'options' => app(FieldTypes::class)->get($type)->normalizeOptions($options, null),
        ]);
    }

    /**
     * Makes the field required: an item cannot be created without a value for it.
     */
    public function required(): static
    {
        return $this->state(fn (): array => ['required' => true]);
    }
}
