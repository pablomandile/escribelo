<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reemplaza a `Illuminate\Http\Middleware\ValidatePostSize` (que sacamos del
 * stack global en bootstrap/app.php) por una versión que avisa en castellano.
 *
 * El original tira PostTooLargeException, y como no hay vista de error 413 la
 * respuesta termina siendo la página cruda de Symfony ("Oops! An Error
 * Occurred"). Peor todavía: en una visita de Inertia esa respuesta no trae el
 * header `X-Inertia`, así que el cliente la muestra dentro del modal de error a
 * pantalla completa, sin ninguna pista de qué hacer.
 *
 * Corriendo al final del grupo `web` ya tenemos sesión (y el CSRF pasó vía el
 * header `X-XSRF-TOKEN`, que sobrevive aunque PHP haya descartado el body), así
 * que podemos redirigir con `withErrors()` y que la UI muestre el toast normal.
 */
class HandlePostTooLarge
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = escribelo_ini_bytes(ini_get('post_max_size'));
        $length = (int) $request->server('CONTENT_LENGTH');

        if ($max > 0 && $length > $max) {
            $message = sprintf(
                'La subida pesa %s y el servidor acepta hasta %s por vez. Subí menos archivos juntos o comprimí el audio.',
                escribelo_format_bytes($length),
                escribelo_format_bytes($max),
            );

            // La misma clave que mira cada pantalla: el dashboard lee `files`,
            // la vista de una transcripción lee `audio`.
            return back()->withErrors([
                'files' => $message,
                'files.0' => $message,
                'audio' => $message,
            ]);
        }

        return $next($request);
    }
}
