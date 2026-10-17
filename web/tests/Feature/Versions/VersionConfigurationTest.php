<?php

declare(strict_types=1);

use App\Inventories\Versions\OfficialVersions;
use App\Jobs\PurgeStoredFiles;
use App\Signatures\Documenso;

/*
 * The settings of the official versions and of Documenso: what the instance can do with them, with nothing set and with everything.
 */

describe('Documenso', function (): void {
    it('is not configured by default, and its webhook is closed', function (): void {
        config(['services.documenso' => ['url' => null, 'api_key' => null, 'webhook_secret' => null]]);

        expect(Documenso::configured())->toBeFalse()
            ->and(Documenso::webhookEnabled())->toBeFalse();
    });

    it('can ask for signatures once it has an address and a token, and not before', function (array $settings, bool $expected): void {
        config(['services.documenso' => $settings + ['url' => null, 'api_key' => null, 'webhook_secret' => null]]);

        expect(Documenso::configured())->toBe($expected);
    })->with([
        'nothing' => [[], false],
        'an address only' => [['url' => 'https://sign.example.test'], false],
        'a token only' => [['api_key' => 'token'], false],
        'blanks' => [['url' => '  ', 'api_key' => ''], false],
        'both' => [['url' => 'https://sign.example.test', 'api_key' => 'token'], true],
    ]);

    it('opens its webhook with a secret, whatever else is set', function (): void {
        config(['services.documenso' => ['url' => null, 'api_key' => null, 'webhook_secret' => 'secret']]);

        expect(Documenso::webhookEnabled())->toBeTrue()
            ->and(Documenso::configured())->toBeFalse();

        config(['services.documenso.webhook_secret' => '   ']);

        expect(Documenso::webhookEnabled())->toBeFalse();
    });

    it('works out the root of the API of the second version whichever way the address was typed', function (string $url): void {
        config(['services.documenso' => ['url' => $url, 'api_key' => 'token', 'webhook_secret' => null]]);

        expect(Documenso::apiBaseUrl())->toBe('https://sign.example.test/api/v2');
    })->with([
        'https://sign.example.test',
        'https://sign.example.test/',
        ' https://sign.example.test ',
        'https://sign.example.test/api',
        'https://sign.example.test/api/v1',
        'https://sign.example.test/api/v2/',
    ]);

    it('is read in the configuration of the services, with the three variables of the environment', function (): void {
        $services = (string) file_get_contents(config_path('services.php'));

        expect($services)->toContain("env('DOCUMENSO_URL')")->toContain("env('DOCUMENSO_API_KEY')")->toContain("env('DOCUMENSO_WEBHOOK_SECRET')");
    });

    it('is documented in both example environment files', function (string $file): void {
        expect((string) file_get_contents(base_path($file)))->toContain('DOCUMENSO_URL=')->toContain('DOCUMENSO_API_KEY=')->toContain('DOCUMENSO_WEBHOOK_SECRET=');
    })->with(['.env.example', 'docker/.env.example']);
});

describe('the quotas of the versions', function (): void {
    it('are 200 versions to an inventory, 10 signers, 10 versions an hour, 64 MB of data to a snapshot, 100 MB to a signed PDF and 20 requests for signatures a day to a workspace', function (): void {
        expect(config('inventories.max_versions_per_inventory'))->toBe(200)
            ->and(config('inventories.max_signers'))->toBe(10)
            ->and(config('inventories.versions_per_hour'))->toBe(10)
            ->and(config('inventories.max_snapshot_bytes'))->toBe(64 * 1048576)
            ->and(config('inventories.max_signed_pdf_bytes'))->toBe(100 * 1048576)
            ->and(config('inventories.signature_requests_per_workspace_per_day'))->toBe(20);
    });

    it('never give more signers than the page of signatures holds, and at least one', function (): void {
        config(['inventories.max_signers' => 25]);

        expect(OfficialVersions::maxSigners())->toBe(10);

        config(['inventories.max_signers' => 4]);

        expect(OfficialVersions::maxSigners())->toBe(4);

        config(['inventories.max_signers' => 0]);

        expect(OfficialVersions::maxSigners())->toBe(1);
    });

    it('are written in the environment variables the configuration names', function (): void {
        $config = (string) file_get_contents(config_path('inventories.php'));

        expect($config)->toContain('INVENTORIES_MAX_VERSIONS')->toContain('INVENTORIES_MAX_SIGNERS')->toContain('INVENTORIES_VERSIONS_PER_HOUR')->toContain('INVENTORIES_MAX_SNAPSHOT_MB')->toContain('INVENTORIES_MAX_SIGNED_PDF_MB')->toContain('INVENTORIES_SIGNATURE_REQUESTS_PER_DAY');
    });
});

describe('the purge of the files', function (): void {
    it('accepts the folder of an official version, and nothing deeper or shorter that is not a known folder', function (): void {
        $version = 'workspaces/01abc/inventories/01def/versions/01ghi';

        expect(new PurgeStoredFiles('local', [], [$version]))->toBeInstanceOf(PurgeStoredFiles::class)
            ->and(fn () => new PurgeStoredFiles('local', [], ['workspaces/01abc/inventories/01def/versions']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new PurgeStoredFiles('local', [], ["{$version}/snapshot.json"]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new PurgeStoredFiles('local', [], ['versions/01ghi']))->toThrow(InvalidArgumentException::class);
    });
});
