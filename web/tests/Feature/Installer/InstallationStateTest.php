<?php

declare(strict_types=1);

use App\Installer\InstallationState;
use App\Installer\InstallStep;

/**
 * A state object over a fresh directory of the sandbox.
 */
function freshState(object $test): InstallationState
{
    return new InstallationState($test->sandbox->root.'/fresh-state');
}

it('is not installed until the marker is written, then stays installed', function (): void {
    $state = freshState($this);

    expect($state->isInstalled())->toBeFalse();

    $state->markInstalled();

    expect($state->isInstalled())->toBeTrue()
        ->and(new InstallationState($state->directory()))->isInstalled()->toBeTrue()
        ->and(fileperms($state->path('installed.json')) & 0777)->toBe(0640)
        ->and(fileperms($state->directory()) & 0777)->toBe(0750);
});

it('notices a marker created by another process', function (): void {
    $state = freshState($this);
    expect($state->isInstalled())->toBeFalse();

    mkdir($state->directory(), 0700, true);
    file_put_contents($state->path('installed.json'), '{}');

    expect($state->isInstalled())->toBeTrue();
});

it('records the progress of each step so the wizard can resume', function (): void {
    $state = freshState($this);

    expect($state->progress())->toBe([
        'database' => 'pending', 'application' => 'pending', 'mail' => 'pending', 'storage' => 'pending', 'documenso' => 'pending', 'administrator' => 'pending',
    ]);

    $state->record(InstallStep::Database);
    $state->record(InstallStep::Documenso, InstallationState::STATUS_SKIPPED);

    $resumed = new InstallationState($state->directory());

    expect($resumed->status(InstallStep::Database))->toBe('done')
        ->and($resumed->status(InstallStep::Documenso))->toBe('skipped')
        ->and($resumed->isSettled(InstallStep::Documenso))->toBeTrue()
        ->and($resumed->isSettled(InstallStep::Mail))->toBeFalse()
        ->and($resumed->missingRequiredSteps())->toBe([InstallStep::Application, InstallStep::Mail, InstallStep::Storage, InstallStep::Administrator]);
});

it('ignores a corrupted or tampered progress file', function (): void {
    $state = freshState($this);
    mkdir($state->directory(), 0700, true);

    file_put_contents($state->path('progress.json'), 'not json');
    expect($state->status(InstallStep::Database))->toBe('pending');

    file_put_contents($state->path('progress.json'), '{"steps":{"database":"bogus","mail":"done","unknown":"done"}}');
    expect($state->status(InstallStep::Database))->toBe('pending')->and($state->status(InstallStep::Mail))->toBe('done');
});

it('forgets the progress', function (): void {
    $state = freshState($this);
    $state->record(InstallStep::Database);

    $state->forgetProgress();

    expect($state->status(InstallStep::Database))->toBe('pending');
});

it('runs a callback under an exclusive lock and returns its value', function (): void {
    $state = freshState($this);

    expect($state->exclusively(fn (): string => 'done'))->toBe('done')->and(is_file($state->path('.lock')))->toBeTrue();
});
