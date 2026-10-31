<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Billing\Currencies;
use App\Landing\PricingPlans;
use App\Support\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;

/**
 * How the mails about a contract write what they say: days, moments with their zone, amounts and the period of the subscription, in the
 * language the mail is rendered in (the one of the person, set by the queue while the mail is built).
 */
trait FormatsContractMail
{
    /**
     * A day as the reader writes it ("31 octobre 2026"), in the zone of the instance.
     */
    protected static function day(CarbonInterface $moment): string
    {
        return DisplayTime::of($moment)->locale(app()->getLocale())->isoFormat('LL');
    }

    /**
     * A moment with its time of day and the zone it is written in ("31 octobre 2026 12:21 (UTC+1)").
     */
    protected static function moment(CarbonInterface $moment): string
    {
        $local = DisplayTime::of($moment)->locale(app()->getLocale());

        return $local->isoFormat('LLL').' ('.DisplayTime::offset($local).')';
    }

    /**
     * The period of a subscription in words: "monthly" or "yearly" ("mensuel", "annuel").
     */
    protected static function periodLabel(string $interval): string
    {
        return $interval === 'year' ? __('yearly') : __('monthly');
    }

    /**
     * What Pro costs for an interval, VAT included, written with its period ("6 € incl. VAT per month"); an empty text when the instance
     * shows no such amount.
     */
    protected static function priceLabel(string $interval): string
    {
        $plans = PricingPlans::current();
        $price = $interval === 'year' ? $plans->yearlyPrice() : $plans->monthlyPrice();

        if ($price === null) {
            return '';
        }

        return $interval === 'year' ? __(':amount incl. VAT per year', ['amount' => $price]) : __(':amount incl. VAT per month', ['amount' => $price]);
    }

    /**
     * An amount in the smallest unit of a currency as the reader writes it ("12,50 €", "1 250 ¥"): the decimals of the currency are
     * respected ({@see Currencies}), and the figure and the code are written when the currency is not known to the formatter.
     */
    protected static function money(int $amount, string $currency): string
    {
        $decimals = Currencies::decimals($currency);
        $major = Currencies::major($amount, $currency);
        // No decimals for a round amount ("6 €"), all of the currency's otherwise ("5,40 €", "1,250 KD").
        $shown = $amount % (10 ** $decimals) === 0 ? 0 : $decimals;
        $formatted = Number::currency($major, strtoupper($currency), app()->getLocale(), $shown);

        return $formatted === false ? number_format($major, $shown, '.', '').' '.strtoupper($currency) : $formatted;
    }
}
