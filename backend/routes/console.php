<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 10: fail analysis runs stuck in QUEUED or RUNNING (crashed worker,
// lost job). Run by the scheduler service (`php artisan schedule:work`).
Schedule::command('analysis:fail-stale')->everyFiveMinutes()->withoutOverlapping();

// Phase 15: fail AI assessments stuck in QUEUED or RUNNING.
Schedule::command('assessment:fail-stale')->everyFiveMinutes()->withoutOverlapping();

// Phase 16: end challenge evaluations stuck in QUEUED or RUNNING.
Schedule::command('challenge:fail-stale')->everyFiveMinutes()->withoutOverlapping();

// Phase 23: record the end of subscriptions canceled at period end.
Schedule::command('billing:expire-subscriptions')->hourly()->withoutOverlapping();
