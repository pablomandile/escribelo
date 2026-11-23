<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con APP_DEMO=true la cuenta demo es compartida por todos los visitantes: se
 * puede recorrer todo, pero nada se modifica ni se procesa (sin worker ni GPU
 * detrás). Sin el flag, este middleware no hace nada.
 */
class ProtectDemo
{
    public const MESSAGE = 'Esto es una demo: podés recorrer todo, pero subir, procesar o modificar está desactivado.';

    /** Pantallas de cuenta que en la demo no tienen sentido: van al login. */
    private const GUEST_ROUTES = [
        'register',
        'password.request',
        'password.email',
        'password.reset',
        'password.store',
        'auth.google.redirect',
        'auth.google.callback',
    ];

    /** Lo único que se puede enviar (el POST de /login no tiene nombre de ruta: va por path). */
    private const ALLOWED_WRITES = [
        'logout',
        'profile.theme',
    ];

    /** Lecturas que en una demo pública no corresponden. */
    private const BLOCKED_READS = [
        // Lista carpetas del disco del server.
        'library.browse',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled')) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        if (in_array($route, self::GUEST_ROUTES, true)) {
            return redirect()->route('login')->with('error', 'En la demo se entra con la cuenta demo.');
        }

        if (in_array($route, self::BLOCKED_READS, true)) {
            return response()->json(['message' => 'No disponible en la demo.'], 403);
        }

        $allowed = in_array($route, self::ALLOWED_WRITES, true) || $request->is('login');
        if (! $request->isMethodSafe() && ! $allowed) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => self::MESSAGE], 403);
            }

            return back(303)->with('error', self::MESSAGE);
        }

        return $next($request);
    }
}
