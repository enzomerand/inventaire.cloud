<?php

declare(strict_types=1);

namespace App\Landing;

use App\Billing\Billing;
use App\Billing\Plan;
use App\Legal\Seller;
use App\PhotoEntry\PhotoEntry;
use App\Signatures\Documenso;
use Illuminate\Support\Number;

/**
 * What the public pages tell about the plans of an instance that sells them: what Pro costs (a month and, when the instance has a yearly
 * Price, a year), and what each plan allows. Nothing is written in a page: the amounts come from {@see Billing::display()} and the limits
 * from {@see Plan}, that is from `config/billing.php`, so an instance that sets other numbers tells its visitors those.
 *
 * An instance that neither sells nor previews has no plans to show: {@see self::shown()} is false, and the pages that talk about plans (the
 * pricing section of the home page, `/pricing`, the terms of sale) do not exist there. An instance that **previews** its plans
 * (`BILLING_PRICING_PREVIEW`) shows them, VAT included, with a Pro button that cannot be used yet: {@see self::selling()} is false and
 * nothing can be bought. Only the amounts and limits are shown: the application never says "unlimited", since every quota it has is
 * finite. Every amount is VAT-included (TTC): the pages say so next to it.
 *
 * The values are read when the object is built (once per request), never kept between requests.
 */
final readonly class PricingPlans
{
    /**
     * @param  string  $currency  The ISO code of the currency (lowercase), as `config/billing.php` has it.
     * @param  int  $month  What Pro costs a month, in the smallest unit of the currency (cents).
     * @param  int|null  $year  What Pro costs a year, in cents; null when the instance has no yearly Price.
     * @param  array{free: array<string, int>, pro: array<string, int>}  $limits  The numeric limits of each plan (see {@see Plan::LIMITS}).
     * @param  array{free: bool, pro: bool}  $photoEntry  Whether the plan includes the photo entry.
     * @param  bool  $photoEntryOffered  Whether the instance offers the photo entry at all (the administrator's switch and the API key).
     * @param  bool  $signatures  Whether the instance can ask for signatures (Documenso is set up).
     * @param  bool  $automaticTax  Whether Stripe Checkout works the tax out.
     * @param  bool  $selling  Whether Pro can be bought ({@see self::selling()}); false when the plans are only previewed.
     */
    public function __construct(
        public string $currency,
        public int $month,
        public ?int $year,
        public array $limits,
        public array $photoEntry,
        public bool $photoEntryOffered,
        public bool $signatures,
        public bool $automaticTax,
        public bool $selling = true,
    ) {}

    /**
     * Whether the instance sells a plan: workspaces can buy Pro ({@see Billing::enabled()}), the page has an amount to show for it, and the
     * legal identity of the seller is complete ({@see Seller::complete()}: a sale needs valid terms). The one answer that opens the checkout
     * and lists the pages in the sitemap. It reads the configuration only, so the layout may ask it on every page.
     */
    public static function selling(): bool
    {
        return Billing::enabled() && Billing::display()['month'] > 0 && Seller::complete();
    }

    /**
     * Whether the pages that talk about plans exist: the instance sells ({@see self::selling()}), or previews its plans
     * ({@see Billing::pricingPreview()}) and has an amount to show. A preview shows pages only; it never opens a checkout.
     */
    public static function shown(): bool
    {
        return self::selling() || (Billing::pricingPreview() && Billing::display()['month'] > 0);
    }

    /**
     * The plans of the instance as it is configured now.
     */
    public static function current(): self
    {
        $display = Billing::display();
        $limits = ['free' => [], 'pro' => []];

        // Without Stripe there is no yearly Price to look at: a preview shows the yearly amount of the configuration when it has one.
        $year = Billing::enabled() ? $display['year'] : config()->integer('billing.amounts.year');

        foreach (Plan::LIMITS as $limit) {
            $limits['free'][$limit] = Plan::Free->limit($limit);
            $limits['pro'][$limit] = Plan::Pro->limit($limit);
        }

        return new self(
            currency: $display['currency'],
            month: $display['month'],
            year: $year !== null && $year > 0 ? $year : null,
            limits: $limits,
            photoEntry: ['free' => Plan::Free->allows('photo_entry'), 'pro' => Plan::Pro->allows('photo_entry')],
            photoEntryOffered: app(PhotoEntry::class)->availableOnInstance(),
            signatures: Documenso::configured(),
            automaticTax: Billing::automaticTax(),
            selling: self::selling(),
        );
    }

    /**
     * A limit of a plan.
     *
     * @param  string  $plan  `free` or `pro`.
     * @param  string  $limit  One of {@see Plan::LIMITS}.
     */
    public function limit(string $plan, string $limit): int
    {
        return $this->limits[$plan === 'pro' ? 'pro' : 'free'][$limit] ?? 0;
    }

    /**
     * Whether the page speaks of the photo entry: the instance offers it and at least one plan includes it.
     */
    public function mentionsPhotoEntry(): bool
    {
        return $this->photoEntryOffered && ($this->photoEntry['free'] || $this->photoEntry['pro']);
    }

    /**
     * Whether a plan includes the photo entry, as far as the page may say so (the instance offers it and the plan includes it).
     *
     * @param  string  $plan  `free` or `pro`.
     */
    public function includesPhotoEntry(string $plan): bool
    {
        return $this->photoEntryOffered && $this->photoEntry[$plan === 'pro' ? 'pro' : 'free'];
    }

    /**
     * An amount in the currency of the plans, in the language of the page ("6 €", "€6.50").
     *
     * The amounts of the application are whole numbers in the smallest unit of the currency (cents), the way Stripe and `config/billing.php`
     * hold them. The page shows them without the cents when there are none ("6 €", "€6") and with two decimals otherwise ("6,50 €",
     * "€6.50"), written the way the language writes a price: the sign before or after the number, the decimal comma or point, and a
     * no-break space where the language puts one, so that a price never wraps between its number and its sign.
     *
     * @param  int  $cents  The amount in the smallest unit of the currency.
     */
    public function price(int $cents): string
    {
        $decimals = $cents % 100 === 0 ? 0 : 2;
        $currency = strtoupper($this->currency);
        $formatted = Number::currency($cents / 100, $currency, app()->getLocale(), $decimals);

        // The formatter refuses a currency it does not know: the code and the amount are still a price.
        return $formatted === false ? number_format($cents / 100, $decimals, '.', '').' '.$currency : $formatted;
    }

    /**
     * What Pro costs a month.
     */
    public function monthlyPrice(): string
    {
        return $this->price($this->month);
    }

    /**
     * What Pro costs a year; null when there is no yearly Price.
     */
    public function yearlyPrice(): ?string
    {
        return $this->year === null ? null : $this->price($this->year);
    }

    /**
     * What a month of the yearly plan comes to (a twelfth of the year, rounded to the cent); null when there is no yearly Price.
     */
    public function yearlyPricePerMonth(): ?string
    {
        return $this->year === null ? null : $this->price((int) round($this->year / 12));
    }

    /**
     * What paying for a year saves compared with twelve months, in the language of the page; null when there is no yearly Price or it is
     * not cheaper.
     */
    public function saving(): ?string
    {
        $saving = $this->year === null ? 0 : 12 * $this->month - $this->year;

        return $saving > 0 ? $this->price($saving) : null;
    }

    /**
     * The inventories a plan allows, as a phrase ("3 inventories").
     *
     * @param  string  $plan  `free` or `pro`.
     */
    public function inventories(string $plan): string
    {
        $count = $this->limit($plan, 'inventories');

        return trans_choice(':count inventory|:count inventories', $count, ['count' => $this->number($count)]);
    }

    /**
     * The items one inventory of a plan may hold, as a phrase ("500 items").
     *
     * @param  string  $plan  `free` or `pro`.
     */
    public function items(string $plan): string
    {
        $count = $this->limit($plan, 'items');

        return trans_choice(':count item|:count items', $count, ['count' => $this->number($count)]);
    }

    /**
     * The members a workspace of a plan may have, as a phrase ("3 members").
     *
     * @param  string  $plan  `free` or `pro`.
     */
    public function members(string $plan): string
    {
        $count = $this->limit($plan, 'members');

        return trans_choice(':count member|:count members', $count, ['count' => $this->number($count)]);
    }

    /**
     * The share links one inventory of a plan may have, as a phrase ("2 share links").
     *
     * @param  string  $plan  `free` or `pro`.
     */
    public function shareLinks(string $plan): string
    {
        $count = $this->limit($plan, 'share_links');

        return trans_choice(':count share link|:count share links', $count, ['count' => $this->number($count)]);
    }

    /**
     * A count written the way the language writes a number ("10 000", "10,000").
     */
    public function number(int $count): string
    {
        // The formatter refuses a language it does not know: the digits are still a number.
        $formatted = Number::format($count, locale: app()->getLocale());

        return $formatted === false ? (string) $count : $formatted;
    }

    /**
     * The decimal text of the price for an interval, the way structured data writes it ("6.00"), whatever the language.
     *
     * @param  int  $cents  The amount in the smallest unit of the currency.
     */
    public static function decimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
