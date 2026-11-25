<?php

use App\Services\Memberships;
use App\Services\RewardLedger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Loyalty housekeeping: expire points past their date and memberships past their end date.
Artisan::command('loyalty:expire', function (RewardLedger $rewards, Memberships $memberships) {
    $points = $rewards->expire();
    $members = $memberships->expireDue();
    $this->info("Expired {$points} points and {$members} memberships.");
})->purpose('Expire reward points and memberships that are past their date');

Schedule::command('loyalty:expire')->dailyAt('00:15');
