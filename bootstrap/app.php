<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // El ValidatePostSize de fábrica corre en el stack global, antes de que
        // haya sesión, así que sólo puede tirar un 413 pelado (la página cruda
        // de Symfony, que Inertia muestra en un modal a pantalla completa). Lo
        // movemos al final del grupo `web` —ver HandlePostTooLarge— para poder
        // responder con un redirect + withErrors que la UI sepa mostrar.
        $middleware->remove(\Illuminate\Http\Middleware\ValidatePostSize::class);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\HandlePostTooLarge::class,
        ]);

        // El worker no tiene sesion ni cookie: se autentica con el Bearer token
        // compartido, asi que el token CSRF no aplica.
        $middleware->validateCsrfTokens(except: [
            'worker/announce',
        ]);

        $middleware->alias([
            'approved' => \App\Http\Middleware\EnsureUserApproved::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
