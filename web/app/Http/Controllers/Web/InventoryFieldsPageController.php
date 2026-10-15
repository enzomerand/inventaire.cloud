<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Inventories\Fields\Currencies;
use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Models\Inventory;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * `/app/w/{workspace}/inventories/{inventory}/fields`: the editor of the fields of an inventory, for the people who may
 * manage them (editors and owners: a viewer who is a member of the workspace is refused with a 403, somebody who is not
 * a member is told the inventory does not exist).
 *
 * The page hands the scripts what the forms offer and that the API does not list in the language of the person: the
 * types of field and the currencies, with their names.
 */
final class InventoryFieldsPageController extends Controller
{
    public function __construct(private readonly FieldTypes $types) {}

    /**
     * Shows the field editor of the inventory.
     */
    public function __invoke(Workspace $workspace, Inventory $inventory): View
    {
        Gate::authorize('manageFields', $inventory);

        // The currencies the fields already have are listed too, even one that went out of use since: the picker must be
        // able to show the currency a field is in.
        $inUse = [];

        foreach ($inventory->fields()->where('type', 'money')->get() as $field) {
            $currency = $field->option('currency');

            if (is_string($currency)) {
                $inUse[] = $currency;
            }
        }

        return view('app.inventory-fields', [
            'workspace' => $workspace,
            'inventory' => $inventory,
            'activeWorkspace' => $workspace->ulid,
            'types' => array_map(fn (FieldType $type): array => ['type' => $type->key(), 'label' => $type->label()], $this->types->all()),
            'currencies' => Currencies::named(app()->getLocale(), $inUse),
        ]);
    }
}
