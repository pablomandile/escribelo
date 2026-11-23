<?php

/*
|--------------------------------------------------------------------------
| Modo demo (escribelo.pablomandile.com.ar)
|--------------------------------------------------------------------------
|
| Con APP_DEMO=true la app muestra contenido pregrabado (database/demo) con una
| cuenta compartida, no deja procesar ni modificar nada, y se resetea cada
| noche. El mismo código corre en la productiva con el flag apagado: todo lo
| de la demo actúa SOLO si está prendido.
|
*/

return [
    'enabled' => (bool) env('APP_DEMO', false),
    'email' => env('DEMO_EMAIL', 'demo@pablomandile.com.ar'),
    'password' => env('DEMO_PASSWORD', 'demo1234'),
    // Hora de Argentina (el server está en UTC). No en punto: a las hh:00
    // arrancan los cron de todas las apps de la cuenta.
    'reset_at' => env('DEMO_RESET_AT', '04:07'),
];
