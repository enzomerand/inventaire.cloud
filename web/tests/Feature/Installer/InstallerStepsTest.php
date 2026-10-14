<?php

declare(strict_types=1);

use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationState;
use App\Installer\InstallerSteps;
use App\Installer\InstallStep;
use Illuminate\Support\Facades\Route;

it('skips optional steps only', function (): void {
    $this->bootInstaller(installed: false);

    app(InstallerSteps::class)->skip(InstallStep::Documenso);

    expect(app(InstallationState::class)->status(InstallStep::Documenso))->toBe('skipped');
    expect(fn () => app(InstallerSteps::class)->skip(InstallStep::Database))->toThrow(InstallationException::class, 'cannot be skipped');
});

it('has an endpoint for every step of the registry and no other', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->getName() ?? '', 'install.'))->map(fn ($route): string => $route->getName());

    foreach (InstallStep::cases() as $step) {
        expect($routes->contains('install.'.$step->value))->toBeTrue("No route for {$step->value}.");
        expect($routes->contains('install.'.$step->value.'.skip'))->toBe($step->isOptional());
        expect($routes->contains('install.'.$step->value.'.verify'))->toBe($step->sendsEmail());
    }

    expect($routes->values()->all())->toEqualCanonicalizing([
        'install.show', 'install.token', 'install.requirements', 'install.finalize',
        'install.database', 'install.application', 'install.mail', 'install.mail.verify', 'install.storage', 'install.documenso', 'install.documenso.skip',
    ]);
});

it('saves every step through the one entry point', function (): void {
    expect((new ReflectionMethod(InstallerSteps::class, 'save'))->getNumberOfRequiredParameters())->toBe(2);
});
