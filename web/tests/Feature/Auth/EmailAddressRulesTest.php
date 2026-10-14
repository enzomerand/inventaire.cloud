<?php

declare(strict_types=1);

use App\Auth\EmailAddress;
use Illuminate\Support\Facades\Validator;

/**
 * The messages the rules of an address give for it, once normalized as the requests do.
 *
 * @return list<string>
 */
function addressErrors(string $address): array
{
    return Validator::make(['email' => EmailAddress::normalize($address)], ['email' => EmailAddress::rules()])->errors()->get('email');
}

describe('the rules of an address', function (): void {
    it('accept an ordinary address and one whose domain was converted', function (string $address): void {
        expect(addressErrors($address))->toBe([]);
    })->with(['ada@example.com', ' Ada.Lovelace+tag@Example.COM ', 'john.doe@gmäil.com', 'ada@пример.рф']);

    it('refuse an accent or a non-Latin letter before the @, in the language of the request', function (string $language, string $message): void {
        $this->useLanguage($language);

        expect(addressErrors('jöhn@example.com'))->toBe([$message]);
    })->with([
        'english' => ['en', 'The email must not contain accents or other special letters before the @ sign.'],
        'french' => ['fr', "Le champ adresse e-mail ne doit pas contenir d'accents ni d'autres lettres spéciales avant le signe @."],
    ]);

    it('refuse a domain that cannot be written in ASCII', function (): void {
        $this->useLanguage('en');

        expect(addressErrors('ada@-bäd-.com'))->toContain('The email field must be a valid email address.');
    });

    it('refuse what is not an address, without a second complaint about accents', function (): void {
        $this->useLanguage('en');

        expect(addressErrors('not-an-address'))->toBe(['The email field must be a valid email address.']);
    });

    it('refuse an address of more than 255 characters', function (): void {
        expect(addressErrors(str_repeat('a', 250).'@example.com'))->not->toBe([]);
    });
});
