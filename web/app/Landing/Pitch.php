<?php

declare(strict_types=1);

namespace App\Landing;

use App\Inventories\Fields\FieldTypes;
use App\Signatures\Documenso;

/**
 * The sentences the home page and its description in search engines share, so that the two say the same thing and neither promises what
 * the instance cannot do: a signed export only where Documenso is set up, a price or a plan only where the instance shows one (it sells
 * a plan or previews its plans).
 */
final class Pitch
{
    /**
     * The sentence under the title of the home page: signed exports only where signatures can be asked for.
     *
     * @param  PricingPlans|null  $plans  The plans of the instance; null when it shows none.
     */
    public static function lead(?PricingPlans $plans): string
    {
        $exports = Documenso::configured() ? __('signed exports') : __('exports');

        return __('Typed fields, barcode scanning, spreadsheet import and :exports.', ['exports' => $exports]).' '
            .($plans === null ? __('Free and open source.') : __('Free to start, Pro when you outgrow it.'));
    }

    /**
     * The description of the home page for search engines.
     *
     * @param  PricingPlans|null  $plans  The plans of the instance; null when it shows none.
     */
    public static function description(?PricingPlans $plans): string
    {
        $exports = Documenso::configured() ? __('signed PDF exports') : __('PDF exports');

        return __('Track stock, collections and equipment with typed fields, barcode scanning, spreadsheet import and :exports.', ['exports' => $exports]).' '
            .($plans === null ? __('Free and open source.') : __('Free plan, open source.'));
    }

    /**
     * How many types of field an inventory can have, as a phrase ("20 field types").
     */
    public static function fieldTypes(): string
    {
        return trans_choice(':count field type|:count field types', count(app(FieldTypes::class)->all()));
    }
}
