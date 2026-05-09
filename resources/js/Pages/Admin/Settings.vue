<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';

const toast = useToast();
const { open: openConfirm } = useConfirm();

const props = defineProps({
    mode: { type: String, default: 'local' },
    whisperTimeout: { type: Object, default: () => ({ seconds: 1800, env_default: 1800, overridden: false }) },
    gpu: { type: Object, default: () => ({ available: false }) },
    remoteWorker: { type: Object, default: () => ({}) },
    cloudflared: { type: Object, default: () => ({}) },
    queueWorker: { type: Object, default: () => ({}) },
});

const selected = ref(props.mode);
const busy = ref(false);

const submitMode = () => {
    router.patch(route('admin.settings.mode'), { mode: selected.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Modo guardado.'),
        onError: () => toast.error('No se pudo guardar.'),
    });
};

const healthBadge = computed(() => {
    const h = props.remoteWorker.health;
    if (! h) return { label: 'Desconocido', color: 'bg-gray-100 text-gray-600' };
    if (h.ok) return { label: `Online (${h.latency_ms} ms)`, color: 'bg-emerald-100 text-emerald-700' };
    if (h.reason === 'not_configured') return { label: 'Sin configurar', color: 'bg-gray-100 text-gray-600' };
    return { label: 'Offline', color: 'bg-rose-100 text-rose-700' };
});

const proc = computed(() => props.remoteWorker.process || {});
const procRunning = computed(() => !! proc.value.running);

const cf = computed(() => props.cloudflared || {});
const cfRunning = computed(() => !! cf.value.running);

const qw = computed(() => props.queueWorker || {});
const qwRunning = computed(() => !! qw.value.running);

const startQueue = () => {
    busy.value = true;
    router.post(route('admin.queue.start'), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo iniciar el queue worker.'),
        onFinish: () => { busy.value = false; },
    });
};
const stopQueue = async () => {
    const ok = await openConfirm({
        title: 'Detener queue worker',
        message: 'Si hay un job en proceso (transcripción o resumen), se va a interrumpir. ¿Continuar?',
        confirmText: 'Detener',
        cancelText: 'Cancelar',
        danger: true,
    });
    if (! ok) return;
    busy.value = true;
    router.post(route('admin.queue.stop'), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo detener.'),
        onFinish: () => { busy.value = false; },
    });
};
const restartQueue = () => {
    busy.value = true;
    router.post(route('admin.queue.restart'), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo reiniciar.'),
        onFinish: () => { busy.value = false; },
    });
};

const timeoutMinutes = ref(Math.round((props.whisperTimeout?.seconds || 1800) / 60));
const timeoutHumanized = computed(() => {
    const m = Number(timeoutMinutes.value) || 0;
    const h = Math.floor(m / 60);
    const mins = m % 60;
    if (h && mins) return `${h} h ${mins} min`;
    if (h) return `${h} h`;
    return `${mins} min`;
});

const refreshGpuDetection = () => {
    router.post(route('admin.settings.refreshGpu'), {}, {
        preserveScroll: true,
        onSuccess: () => toast.success('Detección refrescada.'),
        onError: () => toast.error('No se pudo refrescar.'),
    });
};

const submitTimeout = () => {
    const minutes = Number(timeoutMinutes.value);
    if (! Number.isInteger(minutes) || minutes < 1 || minutes > 1440) {
        toast.error('Ingresá un valor entre 1 y 1440 minutos.');
        return;
    }
    router.patch(route('admin.settings.whisperTimeout'), { minutes }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Timeout actualizado.'),
        onError: () => toast.error('No se pudo guardar el timeout.'),
    });
};

const presetTimeouts = [
    { label: '30 min', minutes: 30 },
    { label: '1 h', minutes: 60 },
    { label: '2 h', minutes: 120 },
    { label: '4 h', minutes: 240 },
    { label: '8 h', minutes: 480 },
];

const startWorker = () => {
    busy.value = true;
    router.post(route('admin.worker.start'), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo iniciar el worker.'),
        onFinish: () => { busy.value = false; },
    });
};

const stopWorker = async () => {
    const ok = await openConfirm({
        title: 'Detener worker',
        message: 'Si hay transcripciones en progreso se cancelarán. ¿Continuar?',
        confirmText: 'Detener',
        cancelText: 'Cancelar',
        danger: true,
    });
    if (! ok) return;
    busy.value = true;
    router.post(route('admin.worker.stop'), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo detener el worker.'),
        onFinish: () => { busy.value = false; },
    });
};

const restartWorker = async () => {
    const ok = await openConfirm({
        title: 'Reiniciar worker',
        message: 'Se va a detener y volver a levantar. ¿Continuar?',
        confirmText: 'Reiniciar',
        cancelText: 'Cancelar',
        danger: false,
    });
    if (! ok) return;
    busy.value = true;
    router.post(route('admin.worker.restart'), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo reiniciar el worker.'),
        onFinish: () => { busy.value = false; },
    });
};

const startTunnel = () => {
    busy.value = true;
    router.post(route('admin.cloudflared.start'), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo iniciar el túnel.'),
        onFinish: () => { busy.value = false; },
    });
};

const stopTunnel = async () => {
    const ok = await openConfirm({
        title: 'Detener túnel',
        message: 'Tu PC dejará de ser alcanzable desde internet vía Cloudflare. Las transcripciones en curso del hosting van a quedar en "Esperando worker". ¿Continuar?',
        confirmText: 'Detener',
        cancelText: 'Cancelar',
        danger: true,
    });
    if (! ok) return;
    busy.value = true;
    router.post(route('admin.cloudflared.stop'), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo detener el túnel.'),
        onFinish: () => { busy.value = false; },
    });
};

const restartTunnel = async () => {
    const ok = await openConfirm({
        title: 'Reiniciar túnel',
        message: 'Se va a desconectar y reconectar a Cloudflare. ¿Continuar?',
        confirmText: 'Reiniciar',
        cancelText: 'Cancelar',
        danger: false,
    });
    if (! ok) return;
    busy.value = true;
    router.post(route('admin.cloudflared.restart'), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo reiniciar el túnel.'),
        onFinish: () => { busy.value = false; },
    });
};

// Auto-refresh de la página cuando hay actividad — sin polling ruidoso, sólo
// periodicidad baja para mantener el badge de health al día.
let refreshTimer = null;
onMounted(() => {
    refreshTimer = setInterval(() => {
        router.reload({
            only: ['remoteWorker', 'cloudflared', 'queueWorker'],
            preserveUrl: true,
            preserveScroll: true,
            preserveState: true,
        });
    }, 8000);
});
onBeforeUnmount(() => {
    if (refreshTimer) clearInterval(refreshTimer);
});
</script>

<template>
    <Head title="Configuración de Escríbelo" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-900 dark:text-gray-100 sm:text-2xl">
                    Configuración de Escríbelo
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Definí dónde corren los modelos de transcripción y resumen.
                </p>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <!-- Mode switch -->
            <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Modo de procesamiento</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Esta configuración aplica a todos los usuarios.
                </p>

                <div class="mt-5 grid gap-3 md:grid-cols-2">
                    <label
                        class="cursor-pointer rounded-xl border-2 p-4 transition"
                        :class="selected === 'local' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'"
                    >
                        <input v-model="selected" type="radio" value="local" class="sr-only" />
                        <div class="flex items-center gap-2">
                            <span class="text-2xl">🖥️</span>
                            <span class="text-base font-bold text-gray-900 dark:text-gray-100">Local</span>
                        </div>
                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                            Whisper y Ollama corren como subprocesos en el mismo
                            servidor de la app. Ideal para desarrollo o si el
                            server tiene GPU.
                        </p>
                    </label>

                    <label
                        class="cursor-pointer rounded-xl border-2 p-4 transition"
                        :class="selected === 'host' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'"
                    >
                        <input v-model="selected" type="radio" value="host" class="sr-only" />
                        <div class="flex items-center gap-2">
                            <span class="text-2xl">☁️</span>
                            <span class="text-base font-bold text-gray-900 dark:text-gray-100">Host (worker remoto)</span>
                        </div>
                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                            Las peticiones se delegan a una PC con GPU expuesta
                            vía Cloudflare Tunnel. Ideal para hosting compartido
                            sin GPU.
                        </p>
                    </label>
                </div>

                <div class="mt-6 flex justify-end">
                    <button
                        type="button"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
                        :disabled="selected === mode"
                        :class="{ 'opacity-50 cursor-not-allowed': selected === mode }"
                        @click="submitMode"
                    >
                        Guardar cambio
                    </button>
                </div>
            </section>

            <!-- Queue Worker (Laravel) -->
            <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-2xl">⚙️</span>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Queue worker</h3>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="qwRunning ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200' : 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-200'"
                    >
                        <span
                            class="inline-block h-2 w-2 rounded-full"
                            :class="qwRunning ? 'bg-emerald-500 animate-pulse' : 'bg-rose-500'"
                        />
                        {{ qwRunning ? 'Corriendo' : 'Detenido' }}
                    </span>
                </div>

                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                    Procesa transcripciones y resúmenes en background. <strong>Sin esto los
                    jobs se quedan en cola para siempre</strong> (la pantalla muestra
                    "En cola" pero nada avanza).
                </p>

                <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-3">
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Jobs en cola</dt>
                        <dd class="font-mono font-semibold" :class="qw.pending_jobs > 0 ? 'text-amber-700 dark:text-amber-200' : 'text-gray-800 dark:text-gray-100'">
                            {{ qw.pending_jobs ?? 0 }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Jobs fallidos</dt>
                        <dd class="font-mono font-semibold" :class="qw.failed_jobs > 0 ? 'text-rose-700 dark:text-rose-200' : 'text-gray-800 dark:text-gray-100'">
                            {{ qw.failed_jobs ?? 0 }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Origen</dt>
                        <dd class="text-xs font-medium text-gray-700 dark:text-gray-200">
                            <template v-if="qw.managed">Iniciado desde el panel</template>
                            <template v-else-if="qw.external_pids?.length">Externo (PID {{ qw.external_pids.join(', ') }})</template>
                            <template v-else>—</template>
                        </dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="busy || qwRunning"
                        @click="startQueue"
                    >
                        ▶ Iniciar
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-rose-300 dark:border-rose-800 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-semibold text-rose-700 dark:text-rose-200 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-900/30 disabled:opacity-50"
                        :disabled="busy || ! qwRunning"
                        @click="stopQueue"
                    >
                        ■ Detener
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
                        :disabled="busy"
                        @click="restartQueue"
                    >
                        ⟳ Reiniciar
                    </button>
                </div>

                <details class="mt-5 rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                    <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-200">
                        Log reciente del queue worker
                    </summary>
                    <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-words bg-slate-900 px-3 py-3 text-[11px] leading-snug text-slate-100">{{ qw.log_tail || '— sin actividad (puede ser worker externo, no managed) —' }}</pre>
                </details>
            </section>

            <!-- GPU status -->
            <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Aceleración por GPU</h3>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="text-xs text-gray-500 dark:text-gray-400 underline hover:text-gray-700 dark:hover:text-gray-200"
                            title="Re-ejecutar la detección"
                            @click="refreshGpuDetection"
                        >
                            ⟳ refrescar
                        </button>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="gpu.available ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-200'"
                        >
                            <span
                                class="inline-block h-2 w-2 rounded-full"
                                :class="gpu.available ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500'"
                            />
                            {{ gpu.available ? 'GPU activa' : 'CPU' }}
                        </span>
                    </div>
                </div>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Detección sobre la PC donde corre el Whisper local. En modo
                    Host el dato relevante es el del worker remoto, mostrado más abajo.
                </p>

                <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">GPU</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-100">{{ gpu.name || (gpu.available ? 'detectada' : 'sin detectar') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Compute type</dt>
                        <dd class="font-mono font-medium text-gray-800 dark:text-gray-100">{{ gpu.compute_type || 'int8' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Devices CUDA</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-100">{{ gpu.device_count ?? 0 }}</dd>
                    </div>
                    <div class="flex justify-between" v-if="gpu.error">
                        <dt class="text-gray-500 dark:text-gray-400">Error</dt>
                        <dd class="font-mono text-rose-700 dark:text-rose-200">{{ gpu.error }}</dd>
                    </div>
                </dl>

                <p
                    v-if="gpu.available && gpu.cuda_runtime"
                    class="mt-4 rounded-md bg-emerald-50 dark:bg-emerald-900/30 p-3 text-xs leading-relaxed text-emerald-800 dark:text-emerald-200"
                >
                    ✓ faster-whisper va a correr en GPU con
                    <code class="rounded bg-white dark:bg-gray-800 px-1 py-0.5">{{ gpu.compute_type }}</code>.
                    Cada transcripción loguea "loading_model device:cuda" para confirmarlo.
                </p>
                <p
                    v-else-if="gpu.device_count > 0 && ! gpu.cuda_runtime"
                    class="mt-4 rounded-md bg-amber-50 dark:bg-amber-900/30 p-3 text-xs leading-relaxed text-amber-800 dark:text-amber-200"
                >
                    ⚠️ Tenés GPU NVIDIA con driver, pero <strong>falta CUDA Toolkit</strong>.
                    Las transcripciones van a correr en CPU (funcionan, pero más lento).
                    Para activar GPU: descargar
                    <a href="https://developer.nvidia.com/cuda-12-4-0-download-archive" target="_blank" rel="noopener" class="underline font-semibold">CUDA Toolkit 12.x</a>
                    (~3 GB) e instalar. Después un refrescar acá.
                </p>
                <p
                    v-else
                    class="mt-4 rounded-md bg-amber-50 dark:bg-amber-900/30 p-3 text-xs leading-relaxed text-amber-800 dark:text-amber-200"
                >
                    No se detectó GPU. Whisper corre en CPU (más lento).
                    Si tenés NVIDIA: verificá driver actualizado y reiniciá; si
                    no, esto es esperado.
                </p>
            </section>

            <!-- Whisper timeout -->
            <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Timeout de transcripción</h3>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ whisperTimeout.overridden ? 'Personalizado' : 'Por defecto (.env)' }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Cuánto tiempo máximo puede correr una transcripción antes de
                    que el sistema la cancele. Audios largos con noise reduction
                    o modelos grandes en CPU pueden necesitar varias horas.
                </p>

                <div class="mt-5 flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-200">Minutos</label>
                        <input
                            v-model="timeoutMinutes"
                            type="number"
                            min="1"
                            max="1440"
                            class="mt-1 w-32 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">≈ {{ timeoutHumanized }}</p>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="preset in presetTimeouts"
                            :key="preset.minutes"
                            type="button"
                            class="rounded-md border px-2.5 py-1 text-xs font-medium transition"
                            :class="Number(timeoutMinutes) === preset.minutes
                                ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-200'
                                : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            @click="timeoutMinutes = preset.minutes"
                        >
                            {{ preset.label }}
                        </button>
                    </div>

                    <button
                        type="button"
                        class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
                        @click="submitTimeout"
                    >
                        Guardar
                    </button>
                </div>

                <p class="mt-4 text-[11px] text-gray-500 dark:text-gray-400">
                    Aplica tanto en modo Local (proceso Python) como Host
                    (HTTP request al worker remoto). El cambio toma efecto en la
                    próxima transcripción que arranque — los jobs en curso
                    siguen con el timeout que tenían al iniciar.
                </p>
            </section>

            <!-- Worker control panel -->
            <section v-if="proc.manageable" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Worker FastAPI</h3>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="procRunning ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                    >
                        <span
                            class="inline-block h-2 w-2 rounded-full"
                            :class="procRunning ? 'bg-emerald-500 animate-pulse' : 'bg-gray-400'"
                        />
                        {{ procRunning ? 'Corriendo' : 'Detenido' }}
                    </span>
                </div>

                <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">PID</dt>
                        <dd class="font-mono font-medium text-gray-800 dark:text-gray-100">{{ proc.pid || '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Bind</dt>
                        <dd class="font-mono font-medium text-gray-800 dark:text-gray-100">{{ proc.host }}:{{ proc.port }}</dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="busy || procRunning"
                        @click="startWorker"
                    >
                        ▶ Iniciar
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-rose-300 dark:border-rose-800 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-semibold text-rose-700 dark:text-rose-200 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-900/30 disabled:opacity-50"
                        :disabled="busy || ! procRunning"
                        @click="stopWorker"
                    >
                        ■ Detener
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
                        :disabled="busy"
                        @click="restartWorker"
                    >
                        ⟳ Reiniciar
                    </button>
                </div>

                <details class="mt-5 rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                    <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-200">
                        Log reciente del worker
                    </summary>
                    <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-words bg-slate-900 px-3 py-3 text-[11px] leading-snug text-slate-100">{{ proc.log_tail || '— sin actividad —' }}</pre>
                </details>
            </section>

            <!-- Cloudflare Tunnel control -->
            <section v-if="cf.manageable" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-2xl">☁️</span>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Cloudflare Tunnel</h3>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="cfRunning ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                    >
                        <span
                            class="inline-block h-2 w-2 rounded-full"
                            :class="cfRunning ? 'bg-emerald-500 animate-pulse' : 'bg-gray-400'"
                        />
                        {{ cfRunning ? 'Conectado' : 'Desconectado' }}
                    </span>
                </div>

                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                    Puente entre Cloudflare e Internet → tu PC. Mientras esté
                    detenido, el hosting <strong>no podrá alcanzar</strong> tus
                    modelos aunque el FastAPI esté corriendo.
                </p>

                <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">PID</dt>
                        <dd class="font-mono font-medium text-gray-800 dark:text-gray-100">{{ cf.pid || '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Túnel</dt>
                        <dd class="font-mono font-medium text-gray-800 dark:text-gray-100">{{ cf.tunnel || 'config por defecto' }}</dd>
                    </div>
                    <div class="flex justify-between sm:col-span-2">
                        <dt class="text-gray-500 dark:text-gray-400">Binario disponible</dt>
                        <dd class="font-medium" :class="cf.binary_available ? 'text-emerald-700 dark:text-emerald-200' : 'text-rose-700 dark:text-rose-200'">
                            {{ cf.binary_available ? 'Sí' : 'No (instalá cloudflared)' }}
                        </dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="busy || cfRunning || ! cf.binary_available"
                        @click="startTunnel"
                    >
                        ▶ Conectar
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-rose-300 dark:border-rose-800 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-semibold text-rose-700 dark:text-rose-200 shadow-sm hover:bg-rose-50 dark:hover:bg-rose-900/30 disabled:opacity-50"
                        :disabled="busy || ! cfRunning"
                        @click="stopTunnel"
                    >
                        ■ Desconectar
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
                        :disabled="busy || ! cf.binary_available"
                        @click="restartTunnel"
                    >
                        ⟳ Reconectar
                    </button>
                </div>

                <p v-if="! cf.binary_available" class="mt-4 rounded-md bg-amber-50 dark:bg-amber-900/30 p-3 text-xs text-amber-700 dark:text-amber-200">
                    Instalá cloudflared con <code>winget install Cloudflare.cloudflared</code>
                    o configurá la variable <code>CLOUDFLARED_BIN</code> en el <code>.env</code>
                    apuntando a la ruta absoluta del ejecutable.
                </p>

                <p v-else-if="! cf.tunnel" class="mt-4 rounded-md bg-blue-50 dark:bg-blue-900/30 p-3 text-xs text-blue-700 dark:text-blue-200">
                    Sin <code>CLOUDFLARED_TUNNEL</code> en el <code>.env</code>, se va a
                    usar la config por defecto en <code>~/.cloudflared/config.yml</code>.
                </p>

                <details class="mt-5 rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                    <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-200">
                        Log reciente de cloudflared
                    </summary>
                    <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-words bg-slate-900 px-3 py-3 text-[11px] leading-snug text-slate-100">{{ cf.log_tail || '— sin actividad —' }}</pre>
                </details>
            </section>

            <!-- Health probe (URL configurada) -->
            <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Salud del worker remoto</h3>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="healthBadge.color">
                        {{ healthBadge.label }}
                    </span>
                </div>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">URL base</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-100">
                            {{ remoteWorker.base_url || '— no configurado —' }}
                        </dd>
                    </div>
                    <div class="flex justify-between" v-if="remoteWorker.health?.payload">
                        <dt class="text-gray-500 dark:text-gray-400">Whisper</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-100">{{ remoteWorker.health.payload.whisper || '-' }}</dd>
                    </div>
                    <div class="flex justify-between" v-if="remoteWorker.health?.payload">
                        <dt class="text-gray-500 dark:text-gray-400">Ollama</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-100">{{ remoteWorker.health.payload.ollama || '-' }}</dd>
                    </div>
                </dl>

                <p v-if="! remoteWorker.configured" class="mt-4 rounded-md bg-amber-50 dark:bg-amber-900/30 p-3 text-xs text-amber-700 dark:text-amber-200">
                    Configurá <code>REMOTE_WORKER_URL</code> y
                    <code>REMOTE_WORKER_TOKEN</code> en el <code>.env</code> del
                    hosting para activar el modo Host.
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
