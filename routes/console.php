<?php

use Illuminate\Support\Facades\Schedule;

// Planification (cron : * * * * * php artisan schedule:run)
Schedule::command('donations:prenotify')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('donations:charge-due')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
Schedule::command('donations:retry-failed')->dailyAt('09:00')->withoutOverlapping()->onOneServer();
Schedule::command('donations:remind-installments')->dailyAt('10:00')->withoutOverlapping()->onOneServer();
Schedule::command('payments:replay-events --failed')->hourly()->withoutOverlapping();
Schedule::command('ledger:verify')->dailyAt('03:00');
// Reçus fiscaux de l'année écoulée : mi-janvier (les SEPA de fin décembre sont alors confirmés).
Schedule::command('tax-receipts:issue')->yearlyOn(1, 15, '06:00')->withoutOverlapping()->onOneServer();
