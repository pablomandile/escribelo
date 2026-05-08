<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';

const { open: openConfirm } = useConfirm();
const toast = useToast();

const segmentsOpen = ref(false);
const transcriptionOpen = ref(true);

const props = defineProps({
    file: {
        type: Object,
        required: true,
    },
    groqUsage: {
        type: Object,
        default: () => ({
            requests_count: 0,
            tokens_used: 0,
            limits: { requests_per_day: 14400, tokens_per_day: 500000 },
            configured: false,
        }),
    },
    summaryProvider: {
        type: String,
        default: 'groq',
    },
    ollamaConfig: {
        type: Object,
        default: () => ({ model: 'gemma3:12b', base_url: 'http://localhost:11434' }),
    },
});

const summaryStatus = computed(() => props.file.transcription?.summary_status ?? 'idle');
const hasSummary = computed(() => !! props.file.transcription?.summary);

const requestsPct = computed(() => {
    const limit = props.groqUsage?.limits?.requests_per_day ?? 1;
    return Math.min(100, Math.round((props.groqUsage.requests_count / limit) * 100));
});
const tokensPct = computed(() => {
    const limit = props.groqUsage?.limits?.tokens_per_day ?? 1;
    return Math.min(100, Math.round((props.groqUsage.tokens_used / limit) * 100));
});
const usagePct = computed(() => Math.max(requestsPct.value, tokensPct.value));

const generateSummary = () => {
    router.post(route('transcriptions.summary', props.file.id), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo iniciar la generación del resumen.'),
    });
};

let summaryPollTimer = null;
const startSummaryPoll = () => {
    if (summaryPollTimer) return;
    summaryPollTimer = setInterval(() => {
        router.reload({
            only: ['file', 'groqUsage'],
            preserveUrl: true,
            preserveScroll: true,
            preserveState: true,
        });
    }, 2500);
};
const stopSummaryPoll = () => {
    if (summaryPollTimer) {
        clearInterval(summaryPollTimer);
        summaryPollTimer = null;
    }
};

watch(summaryStatus, (status) => {
    if (status === 'queued' || status === 'processing') {
        startSummaryPoll();
    } else {
        stopSummaryPoll();
    }
}, { immediate: true });

onBeforeUnmount(stopSummaryPoll);

const audioRef = ref(null);
const currentTime = ref(0);
const audioSource = ref(props.file.has_cleaned_audio ? 'cleaned' : 'original');

const audioSrc = computed(() =>
    audioSource.value === 'cleaned' && props.file.has_cleaned_audio
        ? route('transcriptions.audio.cleaned', props.file.id)
        : route('transcriptions.audio', props.file.id),
);

watch(audioSrc, () => {
    currentTime.value = 0;
});

const replaceOriginal = async () => {
    const ok = await openConfirm({
        title: 'Reemplazar audio original',
        message: 'Esto sobreescribe el archivo original en tu biblioteca con la versión limpia. Si tu configuración tiene activado "backup", se guardará una copia con sufijo "_original".',
        confirmText: 'Reemplazar',
        cancelText: 'Cancelar',
        danger: true,
    });
    if (! ok) return;
    router.post(route('transcriptions.cleaned.replace', props.file.id), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo reemplazar el original.'),
    });
};

const saveAsNew = () => {
    router.post(route('transcriptions.cleaned.saveAsNew', props.file.id), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo guardar la copia.'),
    });
};

const discardCleaned = async () => {
    const ok = await openConfirm({
        title: 'Descartar audio limpio',
        message: 'El archivo de audio limpio se eliminará. Vas a poder volver a generarlo si querés re-transcribiendo el original.',
        confirmText: 'Descartar',
        cancelText: 'Cancelar',
        danger: true,
    });
    if (! ok) return;
    router.delete(route('transcriptions.cleaned.discard', props.file.id), {
        preserveScroll: true,
        onError: () => toast.error('No se pudo descartar.'),
    });
};

const segments = computed(() => props.file.transcription?.segments ?? []);

const activeSegmentIndex = computed(() => {
    const t = currentTime.value;
    if (t <= 0 || segments.value.length === 0) {
        return -1;
    }
    return segments.value.findIndex(
        (seg) => t >= seg.start_seconds && t < seg.end_seconds,
    );
});

const onTimeUpdate = (event) => {
    currentTime.value = event.target.currentTime;
};

const SEEK_LEAD_IN_SECONDS = 0.25;

const seekTo = (seconds) => {
    if (audioRef.value) {
        audioRef.value.currentTime = Math.max(0, seconds - SEEK_LEAD_IN_SECONDS);
        audioRef.value.play();
    }
};

watch(activeSegmentIndex, (idx) => {
    if (idx < 0 || ! segmentsOpen.value) {
        return;
    }
    const el = document.querySelector(`[data-segment-row="${idx}"]`);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
});

const formatTime = (seconds) => {
    const total = Math.max(Math.round(seconds || 0), 0);
    const minutes = Math.floor(total / 60);
    const rest = total % 60;

    return `${minutes}:${String(rest).padStart(2, '0')}`;
};

const statusLabel = (status) => ({
    queued: 'En cola',
    processing: 'Procesando',
    completed: 'Completado',
    failed: 'Error',
}[status] || status);
</script>

<template>
    <Head :title="file.original_name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <Link
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:border-gray-400 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                        :href="route('dashboard')"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"
                            />
                        </svg>
                        Volver
                    </Link>
                    <h2 class="mt-2 max-w-3xl truncate text-xl font-semibold leading-tight text-gray-900">
                        {{ file.original_name }}
                    </h2>
                </div>
                <span class="text-sm font-semibold text-gray-600">
                    {{ statusLabel(file.status) }}
                </span>
            </div>
        </template>

        <div class="bg-slate-50 py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <section
                    v-if="file.status === 'failed'"
                    class="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
                >
                    {{ file.error_message }}
                </section>

                <section class="rounded-md border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="grid gap-4 text-sm text-gray-600 sm:grid-cols-4">
                        <div>
                            <p class="font-semibold text-gray-900">Modelo</p>
                            <p>{{ file.model }}</p>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900">Idioma</p>
                            <p>{{ file.language || '-' }}</p>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900">Duracion</p>
                            <p>{{ file.duration_seconds ? formatTime(file.duration_seconds) : '-' }}</p>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900">Segmentos</p>
                            <p>{{ file.transcription?.segments?.length || 0 }}</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-md border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-900">
                            Reproducir audio
                        </h3>
                        <div
                            v-if="file.has_cleaned_audio"
                            class="inline-flex rounded-md border border-gray-200 bg-gray-50 p-0.5 text-xs"
                            role="tablist"
                            aria-label="Fuente de audio"
                        >
                            <button
                                type="button"
                                class="rounded px-3 py-1 transition"
                                :class="audioSource === 'original' ? 'bg-white font-semibold text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                role="tab"
                                :aria-selected="audioSource === 'original'"
                                @click="audioSource = 'original'"
                            >
                                Original
                            </button>
                            <button
                                type="button"
                                class="rounded px-3 py-1 transition"
                                :class="audioSource === 'cleaned' ? 'bg-yellow-100 font-semibold text-amber-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                role="tab"
                                :aria-selected="audioSource === 'cleaned'"
                                @click="audioSource = 'cleaned'"
                            >
                                ✨ Con reducción de ruido
                            </button>
                        </div>
                    </div>
                    <audio
                        ref="audioRef"
                        controls
                        preload="metadata"
                        class="w-full"
                        :src="audioSrc"
                        @timeupdate="onTimeUpdate"
                    >
                        Tu navegador no soporta la reproducción de audio.
                    </audio>

                    <div
                        v-if="file.has_cleaned_audio"
                        class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3"
                    >
                        <p class="text-sm font-semibold text-amber-900">
                            Tenés un audio limpio listo
                        </p>
                        <p class="mt-1 text-xs text-amber-800">
                            Compará usando el toggle de arriba y elegí qué hacer:
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center rounded-md bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-rose-700"
                                @click="replaceOriginal"
                            >
                                Reemplazar original
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700"
                                @click="saveAsNew"
                            >
                                Guardar como copia "_NR"
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50"
                                @click="discardCleaned"
                            >
                                Descartar
                            </button>
                        </div>
                    </div>
                </section>

                <section class="rounded-md border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">
                                Resumen
                            </h3>
                            <p class="mt-0.5 text-xs text-gray-500">
                                <template v-if="summaryProvider === 'ollama'">
                                    🏠 Ollama local · <code class="rounded bg-gray-100 px-1 py-0.5">{{ ollamaConfig.model }}</code>
                                </template>
                                <template v-else>
                                    ☁️ Groq · <code class="rounded bg-gray-100 px-1 py-0.5">llama-3.1-8b-instant</code>
                                </template>
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div
                                v-if="summaryProvider === 'groq' && groqUsage.configured"
                                class="flex flex-col text-right"
                                :title="`Tokens hoy: ${groqUsage.tokens_used.toLocaleString()} / ${groqUsage.limits.tokens_per_day.toLocaleString()} · Requests hoy: ${groqUsage.requests_count} / ${groqUsage.limits.requests_per_day}`"
                            >
                                <span class="text-[10px] uppercase tracking-wider text-gray-500">
                                    Free tier hoy
                                </span>
                                <div class="mt-0.5 flex items-center gap-2">
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-gray-200">
                                        <div
                                            class="h-full rounded-full transition-all"
                                            :class="usagePct < 80 ? 'bg-emerald-500' : usagePct < 95 ? 'bg-amber-500' : 'bg-rose-500'"
                                            :style="{ width: `${usagePct}%` }"
                                        />
                                    </div>
                                    <span class="text-xs font-medium tabular-nums text-gray-600">
                                        {{ usagePct }}%
                                    </span>
                                </div>
                            </div>
                            <button
                                v-if="props.file.transcription?.text"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="summaryStatus === 'queued' || summaryStatus === 'processing' || (summaryProvider === 'groq' && ! groqUsage.configured)"
                                @click="generateSummary"
                            >
                                <svg
                                    v-if="summaryStatus === 'queued' || summaryStatus === 'processing'"
                                    class="h-4 w-4 animate-spin"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                                </svg>
                                <span v-if="summaryStatus === 'queued'">En cola...</span>
                                <span v-else-if="summaryStatus === 'processing'">Resumiendo...</span>
                                <span v-else-if="hasSummary">Regenerar resumen</span>
                                <span v-else>Resumir</span>
                            </button>
                        </div>
                    </div>
                    <div class="p-5">
                        <p
                            v-if="summaryProvider === 'groq' && ! groqUsage.configured"
                            class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
                        >
                            Falta configurar <code>GROQ_APIKEY</code> en el servidor para poder generar resúmenes con Groq.
                            Cambialo a Ollama desde <a :href="route('profile.edit')" class="font-semibold underline">Configuración</a>.
                        </p>
                        <p
                            v-else-if="summaryStatus === 'failed'"
                            class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"
                        >
                            {{ props.file.transcription?.summary_error || 'No se pudo generar el resumen.' }}
                        </p>
                        <p
                            v-else-if="! hasSummary && summaryStatus === 'idle'"
                            class="text-sm text-gray-500"
                        >
                            Aún no hay resumen. Hacé clic en "Resumir" para generar uno con Groq.
                        </p>
                        <div
                            v-else-if="hasSummary"
                            class="space-y-6"
                        >
                            <div class="flex items-start gap-3 rounded-lg border border-yellow-200 bg-yellow-50/60 p-4">
                                <span class="text-2xl leading-none" aria-hidden="true">💡</span>
                                <p class="whitespace-pre-wrap text-[17px] leading-8 text-gray-800">
                                    {{ props.file.transcription.summary }}
                                </p>
                            </div>

                            <div v-if="props.file.transcription.key_points?.length">
                                <h4 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-gray-700">
                                    <span aria-hidden="true">🎯</span>
                                    Puntos principales
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">
                                        {{ props.file.transcription.key_points.length }}
                                    </span>
                                </h4>
                                <ul class="mt-3 space-y-2">
                                    <li
                                        v-for="(point, i) in props.file.transcription.key_points"
                                        :key="i"
                                        class="flex items-start gap-3 rounded-lg border border-gray-100 bg-gradient-to-r from-blue-50/40 to-transparent p-3 transition hover:border-blue-200 hover:from-blue-50"
                                    >
                                        <span
                                            class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white"
                                            aria-hidden="true"
                                        >
                                            {{ i + 1 }}
                                        </span>
                                        <span class="flex-1 text-base leading-7 text-gray-800">
                                            {{ point }}
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 text-left transition hover:bg-gray-50"
                        :class="{ '!border-transparent': !transcriptionOpen }"
                        :aria-expanded="transcriptionOpen"
                        aria-controls="transcription-body"
                        @click="transcriptionOpen = !transcriptionOpen"
                    >
                        <span class="flex items-center gap-3">
                            <h3 class="text-base font-semibold text-gray-900">
                                Transcripción
                            </h3>
                            <span
                                v-if="segments.length && transcriptionOpen"
                                class="text-xs font-normal text-gray-500"
                            >
                                Hacé clic en cualquier parte para reproducir desde ahí
                            </span>
                        </span>
                        <svg
                            class="h-5 w-5 shrink-0 text-gray-500 transition-transform duration-200"
                            :class="{ 'rotate-180': transcriptionOpen }"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M19.5 8.25l-7.5 7.5-7.5-7.5"
                            />
                        </svg>
                    </button>
                    <div
                        v-show="transcriptionOpen"
                        id="transcription-body"
                        class="p-5"
                    >
                        <p
                            v-if="!file.transcription?.text"
                            class="text-sm text-gray-500"
                        >
                            Sin texto disponible
                        </p>
                        <div
                            v-else-if="segments.length"
                            class="text-sm leading-7 text-gray-800"
                        >
                            <span
                                v-for="(segment, idx) in segments"
                                :key="segment.id"
                            >
                                <span
                                    class="cursor-pointer rounded transition"
                                    :class="activeSegmentIndex === idx ? 'bg-yellow-200 px-0.5' : 'hover:bg-yellow-50'"
                                    :title="formatTime(segment.start_seconds)"
                                    @click="seekTo(segment.start_seconds)"
                                >{{ segment.text }}</span>
                                <span> </span>
                            </span>
                        </div>
                        <div
                            v-else
                            class="whitespace-pre-wrap text-sm leading-7 text-gray-800"
                        >
                            {{ file.transcription.text }}
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 text-left transition hover:bg-gray-50"
                        :class="{ '!border-transparent': !segmentsOpen }"
                        :aria-expanded="segmentsOpen"
                        aria-controls="segments-list"
                        @click="segmentsOpen = !segmentsOpen"
                    >
                        <h3 class="text-base font-semibold text-gray-900">
                            Segmentos
                            <span
                                v-if="file.transcription?.segments?.length"
                                class="ml-2 text-xs font-normal text-gray-500"
                            >
                                ({{ file.transcription.segments.length }})
                            </span>
                        </h3>
                        <svg
                            class="h-5 w-5 shrink-0 text-gray-500 transition-transform duration-200"
                            :class="{ 'rotate-180': segmentsOpen }"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M19.5 8.25l-7.5 7.5-7.5-7.5"
                            />
                        </svg>
                    </button>
                    <div
                        v-show="segmentsOpen"
                        id="segments-list"
                        class="divide-y divide-gray-100"
                    >
                        <p
                            v-if="!file.transcription?.segments?.length"
                            class="px-5 py-6 text-sm text-gray-500"
                        >
                            Sin segmentos
                        </p>
                        <div
                            v-for="(segment, idx) in segments"
                            :key="segment.id"
                            :data-segment-row="idx"
                            class="grid cursor-pointer gap-3 px-5 py-4 text-sm transition sm:grid-cols-[90px_1fr]"
                            :class="activeSegmentIndex === idx ? 'bg-yellow-100' : 'hover:bg-gray-50'"
                            @click="seekTo(segment.start_seconds)"
                        >
                            <span class="font-mono text-xs font-semibold text-gray-500">
                                {{ formatTime(segment.start_seconds) }}
                            </span>
                            <p class="leading-6 text-gray-800">
                                {{ segment.text }}
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
