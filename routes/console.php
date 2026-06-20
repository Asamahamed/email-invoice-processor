<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule email processing to run every 5 minutes
Schedule::command('emails:process')->everyFiveMinutes();

// Schedule exchange rate refresh to run daily
Schedule::command('exchange:refresh')->daily();

