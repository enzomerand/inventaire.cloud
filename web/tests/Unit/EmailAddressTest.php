<?php

declare(strict_types=1);

use App\Auth\EmailAddress;

describe('the canonical form of an address', function (): void {
    it('is trimmed and in lowercase', function (): void {
        expect(EmailAddress::normalize("  Ada.Lovelace@Example.COM \n"))->toBe('ada.lovelace@example.com');
    });

    it('writes the domain in ASCII (punycode)', function (string $given, string $canonical): void {
        expect(EmailAddress::normalize($given))->toBe($canonical);
    })->with([
        'an accent' => ['john.doe@gmäil.com', 'john.doe@xn--gmil-moa.com'],
        'capitals and accents' => [' John.Doe@GMÄIL.com ', 'john.doe@xn--gmil-moa.com'],
        'a non-Latin domain' => ['ada@пример.рф', 'ada@xn--e1afmkfd.xn--p1ai'],
        'full-width letters, which are the ASCII ones for DNS' => ['ada@ｇｍａｉｌ.com', 'ada@gmail.com'],
        'a domain already in punycode' => ['ada@xn--gmil-moa.com', 'ada@xn--gmil-moa.com'],
    ]);

    it('never confuses a lookalike domain with the real one', function (): void {
        expect(EmailAddress::normalize('john.doe@gmäil.com'))->not->toBe(EmailAddress::normalize('john.doe@gmail.com'));
    });

    it('does not change when it is normalized again', function (string $given): void {
        $once = EmailAddress::normalize($given);

        expect(EmailAddress::normalize($once))->toBe($once);
    })->with(['john.doe@gmäil.com', 'ADA@Example.com', 'ada@ｇｍａｉｌ.com', 'jöhn@bücher.de', 'nope', 'ada@']);

    it('leaves what it cannot convert as typed, in lowercase, for the rules to refuse', function (string $given, string $kept): void {
        expect(EmailAddress::normalize($given))->toBe($kept);
    })->with([
        'an accent in the local part' => ['Jöhn@Example.com', 'jöhn@example.com'],
        'no @' => ['Not-An-Address', 'not-an-address'],
        'no domain' => ['ADA@', 'ada@'],
        'a domain that is not valid in any form' => ['ada@-bäd-.com', 'ada@-bäd-.com'],
    ]);

    it('only trims what is too long to be an address, since it is refused whatever its form', function (): void {
        $long = str_repeat('A', 250).'@GMÄIL.com';

        expect(EmailAddress::normalize("  {$long}\n"))->toBe($long)
            ->and(EmailAddress::isTooLong($long))->toBeTrue();
    });

    it('converts an address up to the longest there is', function (): void {
        $address = str_repeat('A', 64).'@'.str_repeat('B', 60).'.'.str_repeat('C', 60).'.'.str_repeat('D', 60).'.COMXYZ';

        expect(strlen($address))->toBe(EmailAddress::MAX_LENGTH)
            ->and(EmailAddress::isTooLong($address))->toBeFalse()
            ->and(EmailAddress::normalize($address))->toBe(strtolower($address))
            ->and(EmailAddress::isTooLong($address.'x'))->toBeTrue();
    });

    it('judges the part after the last @', function (): void {
        expect(EmailAddress::normalize('"a@b"@gmäil.com'))->toBe('"a@b"@xn--gmil-moa.com');
    });
});
