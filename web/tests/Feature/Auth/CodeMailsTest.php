<?php

declare(strict_types=1);

use App\Installer\Mail\SetupCodeMail;
use App\Mail\LoginCodeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class);

/**
 * An application name with every character HTML treats specially, and the apostrophe the French texts are full of.
 */
const CODE_MAIL_APP_NAME = "L'Atelier & <Co>";

/**
 * Sends a mailable through the application's mailer, as production does, and returns what a mail client receives.
 */
function sendCodeMail(object $test, string $locale, Mailable $mailable): Email
{
    $test->useLanguage($locale);
    config(['app.name' => CODE_MAIL_APP_NAME]);

    Mail::to('ada@example.com')->send($mailable);

    return sentMails()[0];
}

/**
 * What a person reads in an HTML mail: the tags dropped, then the entities decoded once, the way a browser does.
 */
function readAsHtml(string $html): string
{
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

describe('the sign-in code mail', function (): void {
    it('escapes the HTML part once and keeps the text part free of entities', function (string $locale): void {
        $mail = sendCodeMail($this, $locale, new LoginCodeMail('123456', 10));
        $html = (string) $mail->getHtmlBody();
        $text = (string) $mail->getTextBody();

        // One level: the name's own characters are escaped, and that escaping is not escaped again.
        expect($html)->toContain('L&#039;Atelier &amp; &lt;Co&gt;')
            ->not->toContain('&amp;#039;')->not->toContain('&amp;amp;')->not->toContain('&amp;lt;')->not->toContain('&amp;gt;')
            ->and($html)->not->toContain('<Co>')
            // The text is for a person, not a browser: what it says is what is written.
            ->and($text)->toContain(CODE_MAIL_APP_NAME)
            ->not->toContain('&#039;')->not->toContain('&amp;')->not->toContain('&lt;')
            ->and($mail->getSubject())->toContain(CODE_MAIL_APP_NAME)->not->toContain('&amp;')->not->toContain('&#039;');

        // Whatever the language, the HTML part reads like the text part once a browser has decoded it.
        $read = readAsHtml($html);

        foreach (array_slice(array_values(array_filter(array_map('trim', explode("\n", $text)))), 1) as $line) {
            expect($read)->toContain($line);
        }
    })->with(['french' => ['fr'], 'english' => ['en']]);

    it('writes the French apostrophes as one entity in the HTML part and as themselves in the text part', function (): void {
        $mail = sendCodeMail($this, 'fr', new LoginCodeMail('123456', 10));

        expect($mail->getHtmlBody())->toContain('qu&#039;une seule fois')->toContain('n&#039;avez')->not->toContain('&amp;#039;')
            ->and($mail->getTextBody())->toContain("qu'une seule fois")->toContain("n'avez");
    });

    it('reaches the person escaped once when asked for through the sign-in request itself', function (): void {
        askForCode($this, 'ada@example.com', ['Accept-Language' => 'fr'])->assertOk();

        expect(sentMails()[0]->getHtmlBody())->toContain('n&#039;avez')->not->toContain('&amp;#039;')
            ->and(sentMails()[0]->getTextBody())->toContain("n'avez")->not->toContain('&#039;');
    });

    it('stays plain where there is nothing to escape', function (): void {
        config(['app.name' => 'inventaire.cloud']);
        $this->useLanguage('en');

        Mail::to('ada@example.com')->send(new LoginCodeMail('123456', 10));

        expect(sentMails()[0]->getHtmlBody())->not->toContain('&')
            ->and(sentMails()[0]->getTextBody())->not->toContain('&');
    });
});

describe('the installer\'s setup code mail', function (): void {
    it('is plain text with no entity, in either language', function (string $locale): void {
        $mail = sendCodeMail($this, $locale, new SetupCodeMail('654321', 15, 'hello@example.test', CODE_MAIL_APP_NAME));
        $text = (string) $mail->getTextBody();

        expect($mail->getHtmlBody())->toBeNull()
            ->and($text)->toContain('654321')->not->toContain('&#039;')->not->toContain('&amp;')->not->toContain('&lt;')
            ->and($mail->getSubject())->toContain(CODE_MAIL_APP_NAME)->not->toContain('&amp;')->not->toContain('&#039;');
    })->with(['french' => ['fr'], 'english' => ['en']]);

    it('writes the French apostrophes as themselves', function (): void {
        $mail = sendCodeMail($this, 'fr', new SetupCodeMail('654321', 15, 'hello@example.test', 'Inventaire'));

        expect($mail->getTextBody())->toContain("l'assistant")->toContain("n'avez")->not->toContain('&#039;');
    });
});
