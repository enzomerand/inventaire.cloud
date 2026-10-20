<?php

declare(strict_types=1);

namespace App\Mail;

use App\Auth\TokenAbility;
use App\Support\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * The security notice a person receives when an API token is made on their account: what it is called, what it may do, when
 * it expires, where the request came from, and where to revoke it if it was not them.
 *
 * Sent once the response has gone ({@see AfterResponse}), in the language of the account, as plain text plus a simple HTML
 * version with no external image, font or tracking. It never carries the value of the token, nor its hash: whoever reads the
 * mailbox must not be able to use the token.
 *
 * The abilities, the dates and the fallbacks are turned into text when the mail is rendered, not before: the language of the
 * account is applied only while it is sent.
 */
final class ApiTokenCreatedMail extends Mailable
{
    /** Longest user agent kept, in characters: it is only there to be recognised. */
    public const USER_AGENT_MAX_LENGTH = 200;

    /**
     * Every text that came with the request is kept as plain strings and values: nothing here holds the request, which has
     * ended when the mail is sent.
     *
     * @param  string  $name  The name of the person, for the greeting.
     * @param  string  $tokenName  The name the person gave to the token.
     * @param  list<string>  $abilities  Values of {@see TokenAbility}.
     * @param  CarbonInterface  $createdAt  When the token was made.
     * @param  CarbonInterface|null  $expiresAt  When it stops working, null when it never does.
     * @param  string  $ipAddress  Where the request came from, empty when it is not known.
     * @param  string  $userAgent  What made the request, empty when it is not known; shortened here.
     * @param  string  $accountUrl  The absolute address of the account page, where tokens are revoked.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $tokenName,
        public readonly array $abilities,
        public readonly CarbonInterface $createdAt,
        public readonly ?CarbonInterface $expiresAt,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly string $accountUrl,
    ) {}

    /**
     * The subject names the application.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('New API token on your :app account', ['app' => config('app.name')]),
        );
    }

    /**
     * Both bodies: a mail client shows the HTML one, the plain text serves the others.
     *
     * The data ready for the views is made here, while the language of the account is in force.
     */
    public function content(): Content
    {
        $agent = self::oneLine($this->userAgent);

        return new Content(
            view: 'mail.api-token-created-html',
            text: 'mail.api-token-created',
            with: [
                'tokenLabel' => self::oneLine($this->tokenName),
                'abilityLabels' => implode(', ', array_map(
                    static fn (string $ability): string => TokenAbility::from($ability)->label(),
                    $this->abilities,
                )),
                'createdLabel' => self::moment($this->createdAt),
                'expiresLabel' => $this->expiresAt === null ? __('Never') : self::moment($this->expiresAt),
                'ipLabel' => $this->ipAddress === '' ? __('Unknown') : $this->ipAddress,
                'agentLabel' => $agent === '' ? __('Unknown') : Str::limit($agent, self::USER_AGENT_MAX_LENGTH),
            ],
        );
    }

    /**
     * A moment as a reader of the instance expects it: in the zone of the instance and the language of the mail, with the zone named.
     */
    private static function moment(CarbonInterface $moment): string
    {
        return DisplayTime::of($moment)->locale(app()->getLocale())->isoFormat('LLL').' ('.DisplayTime::zone().')';
    }

    /**
     * The text on one line: what the sender of a request wrote (a user agent, a name) cannot add lines to the plain-text body.
     */
    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/[\p{C}\p{Z}]+/u', ' ', $text) ?? '');
    }
}
