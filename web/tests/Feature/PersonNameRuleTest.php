<?php

declare(strict_types=1);

use App\Rules\PersonName;
use Illuminate\Support\Facades\Validator;

/**
 * Whether a name passes the rules a name must pass.
 */
function nameIsValid(mixed $name): bool
{
    return Validator::make(['name' => $name], ['name' => PersonName::rules()])->passes();
}

describe('the rules of a person\'s name', function (): void {
    it('refuse a control character inside the name, line breaks included', function (string $name): void {
        expect(nameIsValid($name))->toBeFalse();
    })->with('names with a control character');

    it('refuse text that is not valid UTF-8', function (): void {
        expect(nameIsValid("Ada \xC3\x28 Lovelace"))->toBeFalse();
    });

    it('accept unusual names', function (string $name): void {
        expect(nameIsValid($name))->toBeTrue();
    })->with('unusual names that are fine');

    it('are the same as before for the rest: a name is required, text, and at most 255 characters', function (): void {
        expect(nameIsValid('Ada'))->toBeTrue()
            ->and(nameIsValid(str_repeat('é', 255)))->toBeTrue()
            ->and(nameIsValid(str_repeat('é', 256)))->toBeFalse()
            ->and(nameIsValid(''))->toBeFalse()
            ->and(nameIsValid(null))->toBeFalse()
            ->and(nameIsValid(['Ada']))->toBeFalse();
    });

    it('say in the language of the request what is wrong', function (): void {
        $this->useLanguage('fr');

        $errors = Validator::make(['name' => "Ada\nLovelace"], ['name' => PersonName::rules()])->errors()->get('name');

        expect($errors)->toHaveCount(1)->and($errors[0])->toContain('saut de ligne');
    });

    it('say that a text direction control is what is wrong, in the language of the request', function (): void {
        $this->useLanguage('fr');

        $errors = Validator::make(['name' => "Ada \u{202E}ecnerwoL"], ['name' => PersonName::rules()])->errors()->get('name');

        expect($errors)->toHaveCount(1)->and($errors[0])->toContain('sens de lecture');
    });

    it('keep the formatting characters that names need: joiners and direction marks', function (): void {
        // Only the embeddings, overrides and isolates are refused, not every character of the "format" category.
        expect(nameIsValid("\u{1F469}\u{200D}\u{1F4BB}"))->toBeTrue()
            ->and(nameIsValid("می\u{200C}خواهم"))->toBeTrue()
            ->and(nameIsValid("Ada \u{200E}\u{200F}\u{061C}Lovelace"))->toBeTrue();
    });

    it('trim the spaces, invisible characters and control characters at both ends only', function (): void {
        expect(PersonName::trim("\u{A0} Ada Lovelace \u{200B}\n"))->toBe('Ada Lovelace')
            ->and(PersonName::trim("Ada\nLovelace"))->toBe("Ada\nLovelace");
    });
});
