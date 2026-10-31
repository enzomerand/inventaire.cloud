<?php

declare(strict_types=1);

use App\Billing\EnvironmentSetting;

/*
|--------------------------------------------------------------------------
| Legal identity of the seller
|--------------------------------------------------------------------------
|
| Who sells the Pro plan: the terms of sale (`/terms`) print these details,
| and an instance sells nothing until every required one is filled in
| (`App\Legal\Seller::complete()`), since a sale without the seller's
| identity has no valid terms. Only the capital is optional (a sole trader
| has none).
|
| A variable left blank counts as not set (`App\Billing\EnvironmentSetting`).
| The values are printed as they are written here: in French, the language of
| the contract, since the French terms are the ones that prevail.
|
*/

return [

    'seller' => [
        // The name of the seller (a company name, or the name of the person who trades alone).
        'name' => EnvironmentSetting::text(env('LEGAL_NAME'), ''),

        // The legal form ("SAS", "EURL", "Entrepreneur individuel"...).
        'form' => EnvironmentSetting::text(env('LEGAL_FORM'), ''),

        // The share capital ("1 000 euros"); optional.
        'capital' => EnvironmentSetting::text(env('LEGAL_CAPITAL'), ''),

        // The registered office, on one line.
        'address' => EnvironmentSetting::text(env('LEGAL_ADDRESS'), ''),

        // The registration ("RCS Nantes 123 456 789", or the SIREN of a sole trader).
        'registration' => EnvironmentSetting::text(env('LEGAL_REGISTRATION'), ''),

        // The intra-Community VAT number ("FR12345678901").
        'vat_number' => EnvironmentSetting::text(env('LEGAL_VAT_NUMBER'), ''),

        // Where customers write to the seller.
        'email' => EnvironmentSetting::text(env('LEGAL_EMAIL'), ''),
        'phone' => EnvironmentSetting::text(env('LEGAL_PHONE'), ''),

        // The consumer mediator the seller belongs to (compulsory for a seller who sells to consumers).
        'mediator_name' => EnvironmentSetting::text(env('LEGAL_MEDIATOR_NAME'), ''),
        'mediator_address' => EnvironmentSetting::text(env('LEGAL_MEDIATOR_ADDRESS'), ''),
        'mediator_url' => EnvironmentSetting::text(env('LEGAL_MEDIATOR_URL'), ''),
    ],

];
