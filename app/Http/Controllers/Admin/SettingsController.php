<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\Worker\CloudflareTunnelManager;
use App\Services\Worker\QueueWorkerManager;
use App\Services\Worker\WorkerProcessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Process\Process;

class SettingsController extends Controller
{
    public function __construct(
        private readonly WorkerProcessManager $worker,
        private readonly CloudflareTunnelManager $tunnel,
        private readonly QueueWorkerManager $queue,
    ) {
    }

    public function edit(): Response
    {
        $mode = escribelo_mode();
        $remoteHealth = $this->probeRemoteWorker();

        return Inertia::render('Admin/Settings', [
            'mode' => $mode,
            'whisperTimeout' => [
                'seconds' => AppSetting::whisperTimeout(),
                'env_default' => (int) config('transcription.timeout', 14400),
                'overridden' => AppSetting::get('whisper_timeout') !== null,
            ],
            'gpu' => $this->detectLocalGpu(),
            'remoteWorker' => [
                'configured' => (bool) config('services.remote_worker.base_url'),
                'base_url' => config('services.remote_worker.base_url'),
                'health' => $remoteHealth,
                'process' => $this->worker->status(),
            ],
            'cloudflared' => $this->tunnel->status(),
            'queueWorker' => $this->queue->status(),
        ]);
    }

    public function updateWhisperTimeout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        AppSetting::set('whisper_timeout', $validated['minutes'] * 60);

        return back()->with('success', "Timeout actualizado a {$validated['minutes']} minutos.");
    }

    public function updateMode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:local,host'],
        ]);

        AppSetting::set('mode', $validated['mode']);

        return back()->with('success', "Modo cambiado a {$validated['mode']}.");
    }

    public function refreshGpu(): RedirectResponse
    {
        \Illuminate\Support\Facades\Cache::forget('gpu_detect');
        return back()->with('success', 'Detección de GPU refrescada.');
    }

    /**
     * Detecta GPU disponible en la PC local (importa ctranslate2 y consulta nvidia-smi).
     * Cacheado 60s — no cambia entre transcripciones, pero queremos invalidación rápida
     * si recién se instaló driver/CUDA.
     */
    private function detectLocalGpu(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('gpu_detect', 60, function () {
            $python = (string) config('transcription.python', 'python');
            // Además de detectar CUDA, intentamos cargar cublas via ctypes para verificar
            // que CUDA Toolkit (no solo el driver) esté disponible.
            $script = "import json,sys,ctypes,os,glob\n"
                ."info={'available':False,'device_count':0,'name':None,'compute_type':'int8','cuda_runtime':False,'error':None}\n"
                ."if os.name=='nt':\n"
                ."    cdirs=[]\n"
                ."    cp=os.environ.get('CUDA_PATH')\n"
                ."    if cp: cdirs.append(os.path.join(cp,'bin'))\n"
                ."    cdirs.extend(glob.glob(r'C:\\Program Files\\NVIDIA GPU Computing Toolkit\\CUDA\\v*\\bin'))\n"
                ."    for d in cdirs:\n"
                ."        if os.path.isdir(d):\n"
                ."            try: os.add_dll_directory(d)\n"
                ."            except OSError: pass\n"
                ."try:\n"
                ."    import ctranslate2\n"
                ."    n=ctranslate2.get_cuda_device_count()\n"
                ."    info['device_count']=n\n"
                ."    if n>0:\n"
                ."        try:\n"
                ."            ctypes.CDLL('cublas64_12.dll' if sys.platform=='win32' else 'libcublas.so.12')\n"
                ."            info['cuda_runtime']=True\n"
                ."            info['available']=True\n"
                ."            info['compute_type']='float16'\n"
                ."        except OSError:\n"
                ."            info['available']=False\n"
                ."            info['error']='cublas64_12.dll no encontrada — instalá CUDA Toolkit 12.x para acelerar con GPU'\n"
                ."        try:\n"
                ."            import subprocess\n"
                ."            r=subprocess.run(['nvidia-smi','--query-gpu=name','--format=csv,noheader'],capture_output=True,text=True,timeout=2)\n"
                ."            if r.returncode==0:\n"
                ."                info['name']=r.stdout.strip().splitlines()[0]\n"
                ."        except Exception:\n"
                ."            pass\n"
                ."except Exception as e:\n"
                ."    info['error']=str(e)\n"
                ."print(json.dumps(info))\n";

            try {
                $process = new Process([$python, '-c', $script], null, $this->pythonEnv());
                $process->setTimeout(8);
                $process->run();

                if (! $process->isSuccessful()) {
                    return [
                        'available' => false,
                        'error' => trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'python_failed',
                    ];
                }

                $decoded = json_decode(trim($process->getOutput()), true);
                return is_array($decoded) ? $decoded : ['available' => false, 'error' => 'invalid_json'];
            } catch (\Throwable $e) {
                return ['available' => false, 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * Env vars para que Python encuentre los paquetes user-installed
     * cuando el spawn viene desde Apache/PHP-FPM (que puede correr como otro usuario
     * y no heredar APPDATA del usuario interactivo).
     */
    private function pythonEnv(): array
    {
        $appData = getenv('APPDATA') ?: ($_SERVER['APPDATA'] ?? '');
        $userProfile = getenv('USERPROFILE') ?: ($_SERVER['USERPROFILE'] ?? '');

        // Si APPDATA no está disponible (caso de Apache como service), construir desde USERPROFILE.
        if ($appData === '' && $userProfile !== '') {
            $appData = $userProfile.'\\AppData\\Roaming';
        }
        if ($appData === '') {
            // Último recurso: hardcodear el del usuario actual del sistema operativo.
            $appData = 'C:\\Users\\'.(getenv('USERNAME') ?: 'pghm').'\\AppData\\Roaming';
        }

        return [
            'APPDATA' => $appData,
            'USERPROFILE' => $userProfile ?: 'C:\\Users\\'.(getenv('USERNAME') ?: 'pghm'),
            'PYTHONUSERBASE' => $appData.'\\Python',
            'PATH' => (string) (getenv('PATH') ?: ($_SERVER['PATH'] ?? '')),
            'SYSTEMROOT' => (string) (getenv('SYSTEMROOT') ?: 'C:\\Windows'),
            'PYTHONIOENCODING' => 'utf-8',
        ];
    }

    private function probeRemoteWorker(): array
    {
        $baseUrl = config('services.remote_worker.base_url');
        $token = config('services.remote_worker.token');
        $timeout = (int) config('services.remote_worker.health_timeout', 5);

        if (! $baseUrl) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        try {
            $start = microtime(true);
            $response = Http::withToken($token)
                ->timeout($timeout)
                ->get(rtrim($baseUrl, '/').'/health');

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'reason' => 'http_'.$response->status(),
                    'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                ];
            }

            return [
                'ok' => true,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'payload' => $response->json(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'unreachable', 'message' => $e->getMessage()];
        }
    }
}
