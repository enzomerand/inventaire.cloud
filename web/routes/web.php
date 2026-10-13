<?php

declare(strict_types=1);

use App\Http\Controllers\RefreshCsrfTokenController;
use App\Http\Controllers\UpdateLocaleController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::get('/csrf-token', RefreshCsrfTokenController::class)->name('csrf.refresh');

Route::put('/locale', UpdateLocaleController::class)->name('locale.update');
