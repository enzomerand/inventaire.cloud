<?php

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use App\Installer\Requirement;
use App\Installer\RequirementsChecker;
use Illuminate\Foundation\Application;

/**
 * A checker whose view of the server can be faked.
 *
 * @param  list<string>  $missingExtensions
 */
function checker(object $test, array $missingExtensions = [], ?string $phpVersion = null, ?EnvironmentFile $environment = null): RequirementsChecker
{
    return new RequirementsChecker(
        app(Application::class),
        $environment ?? new EnvironmentFile($test->sandbox->root.'/.env', base_path('.env.example')),
        fn (string $name): bool => ! in_array($name, $missingExtensions, true) && extension_loaded($name),
        $phpVersion,
    );
}

/**
 * The requirement with the given id.
 *
 * @param  list<Requirement>  $requirements
 */
function requirement(array $requirements, string $id): Requirement
{
    foreach ($requirements as $requirement) {
        if ($requirement->id === $id) {
            return $requirement;
        }
    }

    throw new RuntimeException("No requirement {$id}.");
}

it('passes on a server that meets everything', function (): void {
    $requirements = checker($this)->check();

    expect(RequirementsChecker::passed($requirements))->toBeTrue();
});

it('takes the PHP version from composer.json', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    preg_match('/\d+(?:\.\d+)*/', $composer['require']['php'], $match);

    expect(checker($this)->minimumPhpVersion())->toBe($match[0])
        ->and(requirement(checker($this)->check(), 'php')->label)->toContain($match[0]);
});

it('fails when PHP is too old', function (): void {
    $php = requirement(checker($this, phpVersion: '8.2.30')->check(), 'php');

    expect($php->ok)->toBeFalse()->and($php->detail)->toContain('8.2.30');
    expect(requirement(checker($this, phpVersion: '8.3.0')->check(), 'php')->ok)->toBeTrue();
});

it('lists the extension that is missing', function (): void {
    $requirements = checker($this, ['intl'])->check();

    expect(requirement($requirements, 'ext-intl')->ok)->toBeFalse()
        ->and(requirement($requirements, 'ext-intl')->detail)->toContain('intl')
        ->and(requirement($requirements, 'ext-mbstring')->ok)->toBeTrue()
        ->and(RequirementsChecker::passed($requirements))->toBeFalse();
});

it('accepts either database driver but needs one', function (): void {
    expect(requirement(checker($this, ['pdo_pgsql'])->check(), 'database-driver')->ok)->toBeTrue()
        ->and(requirement(checker($this, ['pdo_mysql'])->check(), 'database-driver')->ok)->toBeTrue()
        ->and(requirement(checker($this, ['pdo_pgsql', 'pdo_mysql'])->check(), 'database-driver')->ok)->toBeFalse();
});

it('accepts either image library but needs one', function (): void {
    expect(requirement(checker($this, ['gd'])->check(), 'image-library')->ok)->toBe(extension_loaded('imagick'))
        ->and(requirement(checker($this, ['gd', 'imagick'])->check(), 'image-library')->ok)->toBeFalse();
});

it('reports an .env file that cannot be written', function (): void {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('File permissions do not restrict root.');
    }

    $path = $this->sandbox->root.'/locked.env';
    file_put_contents($path, 'A=1');
    chmod($path, 0444);

    $env = requirement(checker($this, environment: new EnvironmentFile($path, $path))->check(), 'env');

    expect($env->ok)->toBeFalse()->and($env->detail)->toContain('locked.env');
});

it('serializes for the browser', function (): void {
    expect(requirement(checker($this)->check(), 'php')->toArray())->toHaveKeys(['id', 'label', 'ok', 'detail']);
});
