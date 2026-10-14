<?php

declare(strict_types=1);

use App\Installer\InstallStep;
use Illuminate\Support\Facades\Validator;

/**
 * Validates input against a step's rules.
 *
 * @param  array<string, mixed>  $input
 */
function validateStep(InstallStep $step, array $input): Illuminate\Validation\Validator
{
    return Validator::make($input, $step->rules(), [], $step->attributes());
}

it('describes every field once, with a label and a rule', function (InstallStep $step): void {
    $names = array_map(static fn ($field): string => $field->name, $step->fields());

    expect($names)->not->toBeEmpty()->and(array_keys($step->rules()))->toEqualCanonicalizing($names);
    foreach ($step->fields() as $field) {
        expect($field->label)->not->toBe('')->and(in_array($field->type, ['text', 'number', 'password', 'email', 'url', 'select', 'checkbox'], true))->toBeTrue();
    }
})->with(InstallStep::cases());

it('keeps required steps apart from the optional one', function (): void {
    expect(InstallStep::required())->toBe([InstallStep::Database, InstallStep::Application, InstallStep::Mail, InstallStep::Storage, InstallStep::Administrator])
        ->and(InstallStep::Documenso->isOptional())->toBeTrue();
});

it('validates the database step', function (): void {
    $valid = ['driver' => 'mariadb', 'host' => 'db', 'port' => '3306', 'database' => 'inv', 'username' => 'u', 'password' => null];

    expect(validateStep(InstallStep::Database, $valid)->passes())->toBeTrue()
        ->and(validateStep(InstallStep::Database, ['driver' => 'oracle'] + $valid)->errors()->keys())->toBe(['driver'])
        ->and(validateStep(InstallStep::Database, ['port' => '70000'] + $valid)->errors()->keys())->toBe(['port'])
        ->and(validateStep(InstallStep::Database, ['port' => 'abc', 'host' => ''] + $valid)->errors()->keys())->toEqualCanonicalizing(['port', 'host']);
});

it('validates the application step', function (): void {
    $valid = ['url' => 'https://inventory.example.com', 'locale' => 'fr', 'timezone' => 'Europe/Paris'];

    expect(validateStep(InstallStep::Application, $valid)->passes())->toBeTrue()
        ->and(validateStep(InstallStep::Application, ['url' => 'ftp://x.test'] + $valid)->errors()->keys())->toBe(['url'])
        ->and(validateStep(InstallStep::Application, ['url' => 'not a url'] + $valid)->errors()->keys())->toBe(['url'])
        ->and(validateStep(InstallStep::Application, ['locale' => 'de'] + $valid)->errors()->keys())->toBe(['locale'])
        ->and(validateStep(InstallStep::Application, ['timezone' => 'Mars/Olympus'] + $valid)->errors()->keys())->toBe(['timezone']);
});

it('validates the mail step', function (): void {
    $valid = ['host' => 'smtp.example.com', 'port' => 587, 'scheme' => null, 'username' => null, 'password' => null, 'from_address' => 'a@example.com', 'from_name' => 'Shop', 'test_recipient' => 'me@example.com'];

    expect(validateStep(InstallStep::Mail, $valid)->passes())->toBeTrue()
        ->and(validateStep(InstallStep::Mail, ['scheme' => 'tls'] + $valid)->errors()->keys())->toBe(['scheme'])
        ->and(validateStep(InstallStep::Mail, ['from_address' => 'nope', 'test_recipient' => ''] + $valid)->errors()->keys())->toEqualCanonicalizing(['from_address', 'test_recipient']);
});

it('requires the S3 details only for S3 storage', function (): void {
    $s3 = ['disk' => 's3', 'key' => null, 'secret' => null, 'region' => null, 'bucket' => null, 'endpoint' => null, 'path_style' => false];

    expect(validateStep(InstallStep::Storage, ['disk' => 'local'] + $s3)->passes())->toBeTrue()
        ->and(validateStep(InstallStep::Storage, $s3)->errors()->keys())->toEqualCanonicalizing(['key', 'secret', 'region', 'bucket'])
        ->and(validateStep(InstallStep::Storage, ['key' => 'k', 'secret' => 's', 'region' => 'r', 'bucket' => 'b', 'endpoint' => 'https://s3.example.com'] + $s3)->passes())->toBeTrue()
        ->and(validateStep(InstallStep::Storage, ['key' => 'k', 'secret' => 's', 'region' => 'r', 'bucket' => 'b', 'endpoint' => 'minio'] + $s3)->errors()->keys())->toBe(['endpoint']);
});

it('validates the administrator step', function (): void {
    expect(validateStep(InstallStep::Administrator, ['name' => 'Jane Doe', 'email' => 'jane@example.com'])->passes())->toBeTrue()
        ->and(validateStep(InstallStep::Administrator, ['name' => '', 'email' => ''])->errors()->keys())->toEqualCanonicalizing(['name', 'email'])
        ->and(validateStep(InstallStep::Administrator, ['name' => 'Jane', 'email' => 'not-an-address'])->errors()->keys())->toBe(['email'])
        ->and(InstallStep::Administrator->isOptional())->toBeFalse()
        ->and(collect(InstallStep::Administrator->fields())->pluck('envKey')->all())->toBe(['ADMIN_NAME', 'ADMIN_EMAIL']);
});

it('validates the Documenso step', function (): void {
    expect(validateStep(InstallStep::Documenso, ['url' => 'https://sign.example.com', 'api_key' => 'api_x'])->passes())->toBeTrue()
        ->and(validateStep(InstallStep::Documenso, ['url' => '', 'api_key' => ''])->errors()->keys())->toEqualCanonicalizing(['url', 'api_key']);
});

it('names the fields in validation messages', function (): void {
    $errors = validateStep(InstallStep::Database, ['driver' => 'pgsql'])->errors();

    expect($errors->first('host'))->toContain('host');
});
