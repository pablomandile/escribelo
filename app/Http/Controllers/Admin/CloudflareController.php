<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Worker\CloudflareTunnelManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CloudflareController extends Controller
{
    public function __construct(private readonly CloudflareTunnelManager $manager)
    {
    }

    public function status(): JsonResponse
    {
        return response()->json($this->manager->status());
    }

    public function start(Request $request): RedirectResponse
    {
        try {
            $result = $this->manager->start();
            return back()->with('success', $result['message'] ?? 'Túnel iniciado.');
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo iniciar cloudflared: '.$e->getMessage());
        }
    }

    public function stop(Request $request): RedirectResponse
    {
        try {
            $result = $this->manager->stop();
            return back()->with('success', $result['message'] ?? 'Túnel detenido.');
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo detener cloudflared: '.$e->getMessage());
        }
    }

    public function restart(Request $request): RedirectResponse
    {
        try {
            $this->manager->restart();
            return back()->with('success', 'Túnel reiniciado.');
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo reiniciar cloudflared: '.$e->getMessage());
        }
    }
}
