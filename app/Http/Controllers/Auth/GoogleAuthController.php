<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Inicia el handshake con Google. Socialite arma la URL con
     * client_id/scope/redirect_uri y nos redirige al login de Google.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Google nos manda de vuelta acá con un ?code=... en la query.
     * Socialite hace el intercambio code → access_token → user info por nosotros.
     *
     * Regla de las 3 ramas:
     *   1) Existe user con este google_id  → login directo.
     *   2) Existe user con este email      → vincular (guardar google_id) y login.
     *   3) No existe                        → crear como 'pending' (igual que email signup).
     */
    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Caso típico: el usuario canceló en Google, o redirect_uri_mismatch.
            Log::warning('Google OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect()->route('login')->withErrors([
                'email' => 'No se pudo completar el login con Google. Probá de nuevo.',
            ]);
        }

        $googleId = $googleUser->getId();
        $email = $googleUser->getEmail();
        $name = $googleUser->getName() ?: ($googleUser->getNickname() ?: $email);

        // Rama 1: ya tenemos a este usuario por su google_id.
        $user = User::query()->where('google_id', $googleId)->first();

        if (! $user && $email) {
            // Rama 2: existe por email — lo vinculamos a esta cuenta de Google.
            $existing = User::query()->where('email', $email)->first();
            if ($existing) {
                $existing->forceFill([
                    'google_id' => $googleId,
                    'email_verified_at' => $existing->email_verified_at ?? now(),
                ])->save();
                $user = $existing;
            }
        }

        if (! $user) {
            // Rama 3: usuario nuevo. Mismas reglas que el signup por email
            // (primer user del sistema → admin auto-aprobado; resto → pending).
            $isFirstUser = User::query()->count() === 0;

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'google_id' => $googleId,
                'password' => null, // sin password local; siempre entra por Google
                'email_verified_at' => now(), // Google ya verificó el email
                'role' => $isFirstUser ? 'admin' : 'user',
                'approval_status' => $isFirstUser ? 'approved' : 'pending',
                'approved_at' => $isFirstUser ? now() : null,
                'audio_limit' => $isFirstUser ? null : 10,
            ]);

            event(new Registered($user));
        }

        Auth::login($user, remember: true);

        // El middleware EnsureUserApproved se encarga de redirigir a /account/pending
        // si el usuario está esperando aprobación.
        return redirect()->intended(route('dashboard', absolute: false));
    }
}
