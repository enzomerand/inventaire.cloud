<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The exports that expired are deleted with their files, and the ones whose worker was lost are given up on.
// The lock of a run that was killed is forgotten after 30 minutes (the default is 24 hours: no pruning for a day).
Schedule::command('inventaire:prune-exports')->hourly()->withoutOverlapping(30);

// The imports that expired (the reports nobody read, the files sent and never started) are deleted with their files, and the ones
// whose worker was lost are given up on.
Schedule::command('inventaire:prune-imports')->hourly()->withoutOverlapping(30);

// The invitations to join a workspace that lapsed are deleted. They are invisible and refused as soon as they lapse: this only clears the table.
Schedule::command('inventaire:prune-invitations')->hourly()->withoutOverlapping(30);

// The API tokens that expired are deleted a day later. They stop working the moment they expire (and the list of tokens hides them):
// this only clears the table. The lock of a run that was killed is forgotten after 30 minutes, as for the exports.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping(30);

// The official versions whose worker was lost are given up on: a PDF that was never written, a request for signatures that was never
// sent. The lock of a run that was killed is forgotten after 15 minutes (the default is 24 hours: no maintenance for a day).
Schedule::command('inventaire:sync-versions')->everyTenMinutes()->withoutOverlapping(15);
