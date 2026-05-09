<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function pending(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->isApproved()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Account/Pending', [
            'name' => $user?->name,
            'email' => $user?->email,
        ]);
    }
}
