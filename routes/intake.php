<?php

declare(strict_types=1);

use App\Http\Controllers\IntakeController;
use Illuminate\Support\Facades\Route;

// Website forms, outside the web group (no session, CSRF or Inertia); GuardIntakeRequests has already run.

Route::post('/intake/{key}', IntakeController::class)
    ->middleware(['not-in-demo', 'throttle:intake'])
    ->name('intake.store');
