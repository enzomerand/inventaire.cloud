<?php

declare(strict_types=1);

namespace App\Landing;

use App\Signatures\Documenso;

/**
 * The questions the public pages answer, in the language of the request.
 *
 * The same list draws the visible accordion and the `FAQPage` structured data, so that the two never disagree (search engines compare
 * them). The answers say only what the instance can do: nothing about plans where none is shown, nothing that promises a purchase where the
 * plans are only previewed, nothing about signatures where Documenso is not set up.
 */
final class Faq
{
    /**
     * The questions of the home page.
     *
     * @param  PricingPlans|null  $plans  The plans of the instance; null when it shows none.
     * @return list<array{question: string, answer: string}>
     */
    public static function home(?PricingPlans $plans): array
    {
        $questions = [[
            'question' => __('Is it really free?'),
            'answer' => $plans === null
                ? __('Yes. This instance is free to use and the code is open source (AGPL-3.0).')
                : __('You can use it free within the limits of the Free plan: :inventories of :items each. Pro lifts them. The code is open source, so you can also host it yourself.', [
                    'inventories' => $plans->inventories('free'),
                    'items' => $plans->items('free'),
                ]),
        ], [
            'question' => __('Do I need an account?'),
            'answer' => __('Yes, with your e-mail only. We send you a 6-digit code: no password to remember.'),
        ], [
            'question' => __('Can I bring my spreadsheet?'),
            'answer' => __('Yes. Upload an XLSX, ODS or CSV file: it is analysed before anything is imported. You can export back to XLSX, ODS, CSV or PDF at any time.'),
        ], [
            'question' => __('Does it work on my phone?'),
            'answer' => __('Yes. Install it from your browser like an app and scan barcodes with the camera.'),
        ]];

        if (Documenso::configured()) {
            $questions[] = [
                'question' => __('What is a signed export?'),
                'answer' => __('A frozen, dated version of an inventory as a PDF, sent for e-signature by e-mail. The signed copy is stored with the inventory.'),
            ];
        }

        $questions[] = [
            'question' => __('Who can see my data?'),
            'answer' => __('Only the people you invite or share a link with. There is no tracker and no third-party script or font. You can export or delete everything from your account.'),
        ];
        $questions[] = [
            'question' => __('Can I host it myself?'),
            'answer' => __('Yes. It is AGPL-3.0 with a Docker image, and runs on PostgreSQL, MySQL or MariaDB. See the source code for the setup.'),
        ];

        return $questions;
    }

    /**
     * The questions about billing, on the pricing page. Where the plans are only previewed (nothing can be bought yet) the page answers
     * about the VAT and says that Pro is not on sale: it promises neither a payment page, an invoice nor a cancellation. The answer about
     * the VAT mentions the reverse charge only where Stripe Tax is on ({@see PricingPlans::$automaticTax}).
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function billing(PricingPlans $plans): array
    {
        // The reverse charge for a business of another EU country is something Stripe Tax applies at Checkout: without it (`STRIPE_AUTOMATIC_TAX`)
        // the invoice carries VAT whoever pays, and the page does not promise otherwise.
        $vat = [
            'question' => __('What about VAT?'),
            'answer' => __('Prices include VAT, and your invoice shows the amount.')
                .($plans->automaticTax ? ' '.__('A business established in another EU country that gives a valid VAT number can be invoiced without VAT (reverse charge).') : ''),
        ];

        if (! $plans->selling) {
            return [[
                'question' => __('Can I subscribe to Pro today?'),
                'answer' => __('Not yet. Pro is not on sale for now, and the Free plan is available. The prices shown are the ones that will apply.'),
            ], $vat];
        }

        return [[
            'question' => __('Can I cancel anytime?'),
            'answer' => __('Yes. Cancel from the customer portal. Pro stays active until the end of the period you paid for.'),
        ], [
            'question' => __('What happens to my data if I go back to Free?'),
            'answer' => __('Nothing is deleted. Above the Free limits you keep, edit and export everything, but you cannot create new inventories, items, members or share links until you are back under them.'),
        ], [
            'question' => __('Who is billed?'),
            'answer' => __('The workspace owner. The plan applies to the whole workspace, so every member benefits.'),
        ], [
            'question' => __('How do I pay?'),
            'answer' => __('On a secure Stripe page. The payment methods offered depend on your country. We never see your card number.'),
        ], [
            'question' => __('Where are my invoices?'),
            'answer' => __('In the customer portal, from "Manage the subscription" on the billing page of your workspace. You can also update your card there.'),
        ], $vat];
    }
}
