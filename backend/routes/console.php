<?php

use Illuminate\Support\Facades\Schedule;

// Safety net for the event-driven queue: re-dispatch or flag stuck items.
// Run the scheduler with: php artisan schedule:work
Schedule::command('certificates:sweep')->everyTenMinutes()->withoutOverlapping();

// Housekeeping: remove queue failure records older than 30 days (audit_logs keep the history).
Schedule::command('queue:prune-failed --hours=720')->daily();

// Week 14: delete API tokens that expired more than 24 hours ago.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
