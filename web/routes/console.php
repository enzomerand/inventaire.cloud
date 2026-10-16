<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The exports that expired are deleted with their files, and the ones whose worker was lost are given up on.
Schedule::command('inventaire:prune-exports')->hourly()->withoutOverlapping();
