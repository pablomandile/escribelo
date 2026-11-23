<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reset nocturno de la demo (solo con APP_DEMO=true; el comando además se niega
// sin el flag). Schedule::call y no Schedule::command: el command lanza otro PHP
// con proc_open y en Hostinger, a las hh:00, eso falla con "posix_spawn() failed".
// Hora de Argentina: el server está en UTC.
Schedule::call(fn () => Artisan::call('escribelo:demo-reset'))
    ->name('escribelo-demo-reset')
    ->dailyAt(config('demo.reset_at', '04:07'))
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->when(fn () => (bool) config('demo.enabled'));
