<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El worker (o el script que lo arranca) avisa por dónde encontrarlo.
 *
 * Nació para el túnel rápido de Cloudflare, que sortea un hostname nuevo en cada
 * arranque: si uno se olvidaba de copiar la URL en /admin/settings, el server
 * seguía apuntando al túnel viejo —que ya no resuelve— y las transcripciones
 * quedaban en "Esperando worker" reintentando para siempre.
 *
 * Desde 2026-11 el worker se publica por un named tunnel con URL fija
 * (https://escribelo-gpu.bambudev.com.ar). escribelo-funcionando.bat la sigue
 * anunciando en cada arranque: es idempotente y corrige cualquier URL vieja
 * pegada a mano. El quick tunnel queda como plan B y usa el mismo camino.
 * Escribe el mismo AppSetting que el panel de admin.
 *
 * Auth: el mismo Bearer token que ya comparten server y worker. Quien lo tenga
 * puede redirigir a dónde va el audio, así que vale lo mismo que el token en sí:
 * si se filtra, hay que rotarlo en los dos lados.
 */
class WorkerAnnounceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.remote_worker.token');

        if ($expected === '') {
            return response()->json(['error' => 'El server no tiene REMOTE_WORKER_TOKEN configurado.'], 503);
        }

        // hash_equals evita filtrar el token por diferencia de tiempos.
        if (! hash_equals($expected, (string) $request->bearerToken())) {
            Log::warning('Announce del worker rechazado: token invalido', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Token invalido.'], 401);
        }

        $validated = $request->validate([
            // https obligatorio: el audio viaja por acá.
            'url' => ['required', 'string', 'max:255', 'url:https'],
        ]);

        $url = rtrim(trim($validated['url']), '/');
        $previous = AppSetting::remoteWorkerUrl();

        AppSetting::set('remote_worker_url', $url);

        Log::info('URL del worker actualizada por announce', [
            'anterior' => $previous,
            'nueva' => $url,
        ]);

        return response()->json([
            'ok' => true,
            'url' => $url,
            'changed' => $previous !== $url,
        ]);
    }
}
