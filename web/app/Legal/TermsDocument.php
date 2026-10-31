<?php

declare(strict_types=1);

namespace App\Legal;

use App\Inventories\Exports\PdfLibrary;
use App\Landing\PricingPlans;
use App\Mail\SubscriptionConfirmedMail;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\App;
use RuntimeException;

/**
 * The terms of sale as a PDF, the document sent with the confirmation of a subscription ({@see SubscriptionConfirmedMail}): the French text
 * (the version that prevails, whatever the language of the person), which ends with the form of withdrawal.
 *
 * The text is the partial the terms page includes (`legal/terms-fr`), laid out by the same library every PDF of the application uses, set
 * the same way ({@see PdfLibrary}): nothing is read from outside the document, and the page is small (a few pages of text and one table,
 * far under the memory of a worker). It is written in French whatever language the request or the mail is in: the numbers and the
 * amounts in the text follow the language in force, so it is rendered in French. Anything that goes wrong is an exception: the mail that
 * attaches it fails, and is tried again, rather than going out without it.
 */
final class TermsDocument
{
    /** The name of the attachment. */
    public const FILENAME = 'CGV-inventaire-cloud.pdf';

    /**
     * The HTML the library lays out: the terms in French, as the instance holds them now.
     */
    public function html(): string
    {
        return self::inFrench(fn (): string => view('legal.terms-pdf', [
            'seller' => Seller::details(),
            'plans' => PricingPlans::current(),
            'termsVersion' => Terms::VERSION,
            'termsVersionDate' => CarbonImmutable::parse(Terms::VERSION)->settings(['locale' => 'fr'])->isoFormat('LL'),
        ])->render());
    }

    /**
     * The PDF itself, as bytes.
     *
     * @throws RuntimeException When the library produces nothing.
     */
    public function pdf(): string
    {
        return self::inFrench(function (): string {
            $dompdf = PdfLibrary::make();
            $dompdf->loadHtml($this->html(), 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            PdfLibrary::footer($dompdf, 'Conditions générales de vente - version du '.Terms::VERSION);

            $bytes = (string) $dompdf->output();

            if (! str_starts_with($bytes, '%PDF-')) {
                throw new RuntimeException('The PDF of the terms of sale could not be written.');
            }

            return $bytes;
        });
    }

    /**
     * Runs something with the application in French, and puts the language back whatever happens: the terms are French in whatever language
     * the request or the mail is, and the numbers and amounts in them follow the language in force.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function inFrench(Closure $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale('fr');

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
