<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * La misma URL de Inertia devuelve dos cuerpos según el header X-Inertia:
     * HTML para una navegación, JSON para un XHR. Lo único que los distingue en
     * la caché es `Vary: X-Inertia`, que el CDN de Hostinger borra al comprimir
     * con brotli. Sumado a que `no-cache` (el default) permite GUARDAR y sólo
     * obliga a revalidar —y una navegación de historial saltea la revalidación—,
     * al restaurar una pestaña descartada puede aparecer el JSON crudo en
     * pantalla. El fix: `no-store` SÓLO en la respuesta XHR (nunca en el HTML,
     * que mataría el back/forward cache de Chrome).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);

        // Se declara igual aunque el CDN lo borre: es lo correcto y lo respeta
        // cualquier intermediario que no lo pise.
        $response->headers->set('Vary', Header::INERTIA.', Accept-Encoding');

        if ($request->header(Header::INERTIA)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? array_merge($user->only([
                    'id', 'name', 'email', 'role', 'approval_status',
                ]), [
                    'is_admin' => $user->isAdmin(),
                    'is_approved' => $user->isApproved(),
                    'audio_limit' => $user->audio_limit,
                    'audio_usage' => $user->audioUsage(),
                    'theme' => $user->getSetting('theme') === 'dark' ? 'dark' : 'light',
                    'notify_on_complete' => (bool) $user->getSetting('notify_on_complete'),
                ]) : null,
            ],
            'appMode' => fn () => escribelo_mode(),
            'flash' => [
                'success' => fn () => $request->session()->get('status') ?? $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
