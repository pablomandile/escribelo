<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'settings' => [
                'backup_on_replace' => (bool) $user->getSetting('backup_on_replace'),
                'transcription_provider' => $user->getSetting('transcription_provider') ?? 'local',
                'summary_provider' => $user->getSetting('summary_provider') ?? 'groq',
            ],
            'providerInfo' => [
                'groq_configured' => (bool) config('services.groq.key'),
                'ollama_model' => config('services.ollama.summary_model'),
                'ollama_base_url' => config('services.ollama.base_url'),
            ],
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'backup_on_replace' => ['required', 'boolean'],
            'transcription_provider' => ['required', 'string', 'in:local,groq'],
            'summary_provider' => ['required', 'string', 'in:groq,ollama'],
        ]);

        $user = $request->user();
        $settings = array_merge($user->settings ?? [], [
            'backup_on_replace' => (bool) $validated['backup_on_replace'],
            'transcription_provider' => $validated['transcription_provider'],
            'summary_provider' => $validated['summary_provider'],
        ]);

        $user->forceFill(['settings' => $settings])->save();

        return Redirect::route('profile.edit')->with('status', 'Configuración guardada.');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
