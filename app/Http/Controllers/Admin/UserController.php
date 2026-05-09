<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->withCount('transcriptionFiles as audio_usage')
            ->orderByRaw("CASE WHEN approval_status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'approval_status' => $user->approval_status,
                'approved_at' => $user->approved_at?->toIso8601String(),
                'audio_limit' => $user->audio_limit,
                'audio_usage' => (int) $user->audio_usage,
                'created_at' => $user->created_at?->toIso8601String(),
                'is_self' => $user->id === $request->user()->id,
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
        ]);
    }

    public function approve(Request $request, User $user): RedirectResponse
    {
        $user->forceFill([
            'approval_status' => 'approved',
            'approved_at' => now(),
        ])->save();

        return back()->with('success', "Cuenta de {$user->name} aprobada.");
    }

    public function revoke(Request $request, User $user): RedirectResponse
    {
        $this->guardLastAdmin($user);

        $user->forceFill([
            'approval_status' => 'pending',
            'approved_at' => null,
        ])->save();

        return back()->with('success', "Cuenta de {$user->name} marcada como pendiente.");
    }

    public function updateLimit(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'audio_limit' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $user->forceFill([
            'audio_limit' => $validated['audio_limit'] ?? null,
        ])->save();

        return back()->with('success', "Límite actualizado para {$user->name}.");
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:admin,user'],
        ]);

        if ($validated['role'] === 'user') {
            $this->guardLastAdmin($user);
        }

        $user->forceFill([
            'role' => $validated['role'],
            // Promover a admin implica aprobado y sin límite.
            'approval_status' => $validated['role'] === 'admin' ? 'approved' : $user->approval_status,
            'approved_at' => $validated['role'] === 'admin' ? ($user->approved_at ?? now()) : $user->approved_at,
            'audio_limit' => $validated['role'] === 'admin' ? null : ($user->audio_limit ?? 10),
        ])->save();

        return back()->with('success', "Rol de {$user->name} actualizado a {$validated['role']}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['user' => 'No podés eliminarte a vos mismo.']);
        }

        $this->guardLastAdmin($user);

        $name = $user->name;
        $user->delete();

        return back()->with('success', "Usuario {$name} eliminado.");
    }

    private function guardLastAdmin(User $user): void
    {
        if ($user->role !== 'admin') {
            return;
        }

        $remainingAdmins = User::query()
            ->where('role', 'admin')
            ->where('id', '!=', $user->id)
            ->count();

        if ($remainingAdmins === 0) {
            throw ValidationException::withMessages([
                'user' => 'No podés dejar el sistema sin admins. Promové a otro usuario primero.',
            ]);
        }
    }
}
