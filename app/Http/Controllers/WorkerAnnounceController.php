<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El worker (o el script que lo arranca) avisa por dónde encontrarlo.
 *
 * El túnel rápido de Cloudflare sortea un hostname nuevo en cada arranque, así
 * que hasta ahora había que copiar la URL a mano en /admin/settings. Si uno se
 * olvidaba, el server seguía apuntando al túnel viejo —que ya no resuelve— y
 * las transcripciones quedaban en "Esperando worker" reintentando para siempre.
 *
 * Esto lo automatiza: escribelo-funcionando.bat levanta el túnel, lee la URL y
 * la manda acá. Escribe el mismo AppSetting que el panel de admin.
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
