<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Worker\QueueWorkerManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function __construct(private readonly QueueWorkerManager $manager)
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
            return back()->with('success', $result['message'] ?? 'Worker iniciado.');
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo iniciar el worker: '.$e->getMessage());
        }
    }

    public function stop(Request $request): RedirectResponse
    {
        try {
            $result = $this->manager->stop();
            return back()->with('success', $result['message'] ?? 'Worker detenido.');
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo detener: '.$e->getMessage());
        }
    }

    public function restart(Request $request): RedirectResponse
    {
        try {
            $this->manager->restart();
            return back()->with('success', 'Queue worker reiniciado.');
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo reiniciar: '.$e->getMessage());
        }
    }
}
