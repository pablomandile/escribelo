<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

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
