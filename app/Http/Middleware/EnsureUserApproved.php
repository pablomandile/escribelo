<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isApproved()) {
            $allowed = ['account.pending', 'logout'];
            $current = optional($request->route())->getName();

            if (! in_array($current, $allowed, true)) {
                return redirect()->route('account.pending');
            }
        }

        return $next($request);
    }
}
