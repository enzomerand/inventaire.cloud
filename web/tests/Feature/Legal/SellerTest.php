<?php

declare(strict_types=1);

use App\Legal\Seller;
use App\Legal\Terms;

require_once __DIR__.'/../../Support/billing.php';

/*
 * The legal identity of the seller (config/legal.php, LEGAL_*): what the terms of sale print, and whether the instance has what a sale needs.
 * Everything but the capital is required; a missing detail prints "[à compléter]" and the seller is incomplete.
 */

/** The keys of the details a sale cannot do without (every one but the capital). */
const SELLER_REQUIRED = ['name', 'form', 'address', 'registration', 'vat_number', 'email', 'phone', 'mediator_name', 'mediator_address', 'mediator_url'];

describe('the details', function (): void {
    it('are the ones of the configuration, with the capital', function (): void {
        billingSeller();

        expect(Seller::details())->toBe([
            'name' => 'Palan Test',
            'form' => 'SAS',
            'capital' => '1 000 euros',
            'address' => '1 rue de Test, 44000 Nantes',
            'registration' => 'RCS Nantes 123 456 789',
            'vat_number' => 'FR12345678901',
            'email' => 'ventes@example.test',
            'phone' => '+33 2 00 00 00 00',
            'mediator_name' => 'Médiateur Test',
            'mediator_address' => '2 rue du Médiateur, 75000 Paris',
            'mediator_url' => 'https://mediateur.example.test',
        ])->and(Seller::complete())->toBeTrue();
    });

    it('have no capital when there is none, and the seller is complete all the same', function (?string $capital): void {
        billingSeller();
        config(['legal.seller.capital' => $capital]);

        expect(Seller::details()['capital'])->toBeNull()
            ->and(Seller::complete())->toBeTrue();
    })->with(['blank' => [''], 'spaces' => ['   '], 'not set' => [null]]);

    it('print the mark of a missing detail in its place, and only there', function (string $key): void {
        billingSeller($key);

        $details = Seller::details();

        expect($details[$key])->toBe('[à compléter]')->toBe(Seller::MISSING);

        foreach (array_diff(SELLER_REQUIRED, [$key]) as $other) {
            expect($details[$other])->not->toBe('[à compléter]');
        }
    })->with(SELLER_REQUIRED);

    it('are all missing on an instance that sets nothing', function (): void {
        $details = Seller::details();

        foreach (SELLER_REQUIRED as $key) {
            expect($details[$key])->toBe('[à compléter]');
        }

        expect($details['capital'])->toBeNull()
            ->and(Seller::complete())->toBeFalse();
    });

    it('count blanks around a value as nothing, and trim the others', function (): void {
        billingSeller();
        config(['legal.seller.vat_number' => "  \t ", 'legal.seller.name' => '  Palan Test  ']);

        expect(Seller::complete())->toBeFalse()
            ->and(Seller::details()['vat_number'])->toBe('[à compléter]')
            ->and(Seller::details()['name'])->toBe('Palan Test');
    });
});

describe('the seller is complete', function (): void {
    it('only when every required detail is there', function (string $key): void {
        billingSeller();
        expect(Seller::complete())->toBeTrue();

        billingSeller($key);
        expect(Seller::complete())->toBeFalse();
    })->with(SELLER_REQUIRED);
});

describe('the configuration file', function (): void {
    it('reads a variable from the environment, and counts a blank one as not set', function (): void {
        $config = billingConfigRead(['LEGAL_NAME' => 'Palan', 'LEGAL_FORM' => '', 'LEGAL_CAPITAL' => '  ', 'LEGAL_VAT_NUMBER' => 'FR123'], 'legal.php')['seller'];

        expect($config['name'])->toBe('Palan')
            ->and($config['vat_number'])->toBe('FR123')
            ->and($config['form'])->toBe('')
            ->and($config['capital'])->toBe('')
            ->and($config['mediator_url'])->toBe('');
    });

    it('has a key for every variable of the specification', function (): void {
        $config = billingConfigRead([], 'legal.php')['seller'];

        expect(array_keys($config))->toBe(['name', 'form', 'capital', 'address', 'registration', 'vat_number', 'email', 'phone', 'mediator_name', 'mediator_address', 'mediator_url']);
    });
});

describe('the version of the terms', function (): void {
    it('is a date, shown the way each language writes one', function (): void {
        expect(Terms::VERSION)->toBe('2026-10-31');

        app()->setLocale('fr');
        expect(Terms::versionDate())->toBe('31 octobre 2026');

        app()->setLocale('en');
        expect(Terms::versionDate())->toBe('October 31, 2026');
    });
});
