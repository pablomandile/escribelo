<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DownloadFormatModal from '@/Components/DownloadFormatModal.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { marked } from 'marked';
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';

marked.setOptions({ gfm: true, breaks: false });

const { open: openConfirm } = useConfirm();
const toast = useToast();

const transcriptionOpen = ref(true);
const transcriptionDataOpen = ref(false);
const summaryOpen = ref(true);

// Scroll-to-top: aparece cuando el usuario se acerca al final de la página.
const showScrollTop = ref(false);
const onScroll = () => {
    const scrolled = window.scrollY + window.innerHeight;
    const threshold = document.documentElement.scrollHeight - 200;
    showScrollTop.value = scrolled >= threshold && window.scrollY > 200;
};
const scrollToTop = () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
});
onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll);
});
const showDownloadModal = ref(false);

const editingName = ref(false);
const nameDraft = ref('');
const savingName = ref(false);
const nameInputRef = ref(null);

const startEditingName = async () => {
    nameDraft.value = props.file.original_name || '';
    editingName.value = true;
    await nextTick();
    nameInputRef.value?.focus();
    nameInputRef.value?.select();
};

const cancelEditingName = () => {
    editingName.value = false;
    nameDraft.value = '';
};

const saveName = () => {
    const trimmed = (nameDraft.value || '').trim();
    if (trimmed === '' || trimmed === props.file.original_name) {
        cancelEditingName();
        return;
    }
    savingName.value = true;
    router.patch(route('transcriptions.rename', props.file.id), {
        original_name: trimmed,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            editingName.value = false;
            toast.success('Nombre actualizado.');
        },
        onError: (errors) => {
            toast.error(errors.original_name || 'No se pudo renombrar.');
        },
        onFinish: () => { savingName.value = false; },
    });
};

const editingText = ref(false);
const editedDraft = ref('');
const savingText = ref(false);

const startEditing = () => {
    editedDraft.value = props.file.transcription?.text || '';
    editingText.value = true;
    transcriptionOpen.value = true;
};

const cancelEditing = () => {
    editingText.value = false;
    editedDraft.value = '';
};

const saveText = () => {
    savingText.value = true;
    router.patch(route('transcriptions.text.update', props.file.id), {
        text: editedDraft.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            editingText.value = false;
            toast.success('Transcripción guardada.');
        },
        onError: () => toast.error('No se pudo guardar.'),
        onFinish: () => { savingText.value = false; },
    });
};

const restoreOriginal = async () => {
    const ok = await openConfirm({
        title: 'Restaurar al original',
        message: 'Se descarta tu versión editada y vuelve al texto original que devolvió Whisper. ¿Continuar?',
        confirmText: 'Restaurar',
        cancelText: 'Cancelar',
        danger: true,
    });
    if (! ok) return;
    router.delete(route('transcriptions.text.restore', props.file.id), {
        preserveScroll: true,
        onSuccess: () => toast.success('Restaurado al original.'),
        onError: () => toast.error('No se pudo restaurar.'),
    });
};

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
    ollamaAvailable: {
        type: Boolean,
        default: false,
    },
    ollamaConfig: {
        type: Object,
        default: () => ({ model: 'gemma3:12b', base_url: 'http://localhost:11434' }),
    },
});

const summaryStatus = computed(() => props.file.transcription?.summary_status ?? 'idle');
const hasSummary = computed(() => !! props.file.transcription?.summary);
const summaryHtml = computed(() => {
    const raw = props.file.transcription?.summary ?? '';
    if (! raw) return '';
    return marked.parse(raw);
});
const summaryModel = computed(() => props.file.transcription?.summary_model);
const backHref = computed(() => {
    const folderId = props.file.folder?.id;
    return folderId
        ? route('dashboard', { folder: folderId })
        : route('dashboard');
});
const summaryElapsedLabel = computed(() => {
    const s = props.file.transcription?.summary_elapsed_seconds;
    if (! s || s < 0) return null;
    const minutes = Math.floor(s / 60);
    const seconds = s % 60;
    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

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

const cancelSummary = async () => {
    const ok = await openConfirm({
        title: 'Cancelar resumen',
        message: 'Se va a abortar la generación. El modelo va a soltar el trabajo en curso. ¿Continuar?',
        confirmText: 'Cancelar resumen',
        cancelText: 'Volver',
        danger: true,
    });
    if (! ok) return;
    router.delete(route('transcriptions.summary.cancel', props.file.id), {
        preserveScroll: true,
        onSuccess: () => toast.success('Resumen cancelado.'),
        onError: () => toast.error('No se pudo cancelar.'),
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

const meta = computed(() => props.file.audio_metadata || {});
const hasAudioMetadata = computed(() => {
    const m = meta.value;
    if (! m) return false;
    return !! (m.album || m.artist || m.composer || m.genre || m.year);
});
const artworkFailed = ref(false);
const hasArtwork = computed(() => meta.value.has_picture && ! artworkFailed.value);
const artworkUrl = computed(() => route('transcriptions.artwork', props.file.id));
const showArtworkModal = ref(false);

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

// Reconectar audio: sube el archivo por HTTP cuando no está disponible en el server
// (transcripciones cuyo stored_path apunta a una ruta local inexistente acá).
const reuploadInput = ref(null);
const reuploading = ref(false);
const triggerReupload = () => reuploadInput.value?.click();
const onReuploadSelected = (e) => {
    const f = e.target.files?.[0];
    if (! f) return;
    reuploading.value = true;
    router.post(route('transcriptions.reconnect', props.file.id), { audio: f }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => toast.success('Audio reconectado.'),
        onError: (errors) => toast.error(errors.audio || 'No se pudo reconectar el audio.'),
        onFinish: () => {
            reuploading.value = false;
            if (reuploadInput.value) reuploadInput.value.value = '';
        },
    });
};

const segments = computed(() => props.file.transcription?.segments ?? []);
const segmentsCount = computed(() => props.file.transcription?.segments_count ?? segments.value.length);

// Agrupa los segmentos en párrafos para mejorar la legibilidad sin perder
// la granularidad de timing (cada segmento sigue siendo un <span> clickeable).
// Reglas:
//  - Pausa larga (>= 1.6s) entre segmentos → corte de párrafo siempre.
//  - Pausa media (>= 0.6s) tras un segmento que termina en .!?… → corte.
//  - Si el párrafo ya supera ~600 caracteres y el último segmento cierra
//    una oración, también cortamos para evitar bloques enormes.
const paragraphs = computed(() => {
    const result = [];
    let current = [];
    let charsAccum = 0;

    segments.value.forEach((seg, idx) => {
        const prev = idx > 0 ? segments.value[idx - 1] : null;
        const gap = prev ? Math.max(0, seg.start_seconds - prev.end_seconds) : 0;
        const prevText = prev ? (prev.text || '').trimEnd() : '';
        const endsSentence = /[.!?…][")\]]?$/.test(prevText);

        let shouldBreak = false;
        if (current.length > 0) {
            if (gap >= 1.6) shouldBreak = true;
            else if (endsSentence && gap >= 0.6) shouldBreak = true;
            else if (charsAccum >= 600 && endsSentence) shouldBreak = true;
        }

        if (shouldBreak) {
            result.push(current);
            current = [];
            charsAccum = 0;
        }
        current.push({ idx, segment: seg });
        charsAccum += (seg.text || '').length;
    });
    if (current.length) result.push(current);
    return result;
});

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

const formatTime = (seconds) => {
    const total = Math.max(Math.round(seconds || 0), 0);
    const minutes = Math.floor(total / 60);
    const rest = total % 60;

    return `${minutes}:${String(rest).padStart(2, '0')}`;
};

const statusLabel = (status) => ({
    queued: 'En cola',
    waiting_for_worker: 'Esperando worker',
    enhancing: 'Mejorando',
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
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:border-gray-400 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:border-gray-500 dark:hover:bg-gray-700 dark:hover:text-gray-100"
                        :href="backHref"
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
                    <div class="mt-2 max-w-3xl">
                        <div
                            v-if="!editingName"
                            class="group flex items-center gap-2"
                        >
                            <h2 class="truncate text-xl font-semibold leading-tight text-gray-900 dark:text-gray-100">
                                {{ file.original_name }}
                            </h2>
                            <button
                                type="button"
                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-gray-400 opacity-0 transition hover:bg-gray-100 hover:text-gray-700 group-hover:opacity-100 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                title="Renombrar"
                                @click="startEditingName"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897L16.862 4.487zm0 0L19.5 7.125" />
                                </svg>
                            </button>
                        </div>
                        <div v-else class="flex items-center gap-2">
                            <input
                                ref="nameInputRef"
                                v-model="nameDraft"
                                type="text"
                                maxlength="255"
                                :disabled="savingName"
                                class="block w-full rounded-md border-gray-300 text-xl font-semibold leading-tight text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:opacity-60 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                @keydown.enter.prevent="saveName"
                                @keydown.esc.prevent="cancelEditingName"
                            />
                            <button
                                type="button"
                                class="inline-flex h-9 items-center rounded-md bg-blue-600 px-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50"
                                :disabled="savingName"
                                title="Guardar (Enter)"
                                @click="saveName"
                            >
                                {{ savingName ? '...' : 'Guardar' }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                :disabled="savingName"
                                title="Cancelar (Esc)"
                                @click="cancelEditingName"
                            >
                                Cancelar
                            </button>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm font-semibold text-gray-600 dark:text-gray-400">
                        {{ statusLabel(file.status) }}
                    </span>
                    <button
                        v-if="file.status === 'completed'"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700 shadow-sm transition hover:border-blue-300 hover:bg-blue-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-200 dark:hover:border-blue-700 dark:hover:bg-blue-900/50"
                        @click="showDownloadModal = true"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        Descargar
                    </button>
                </div>
            </div>
        </template>

        <div class="bg-slate-50 py-8 dark:bg-gray-900">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <section
                    v-if="file.status === 'failed'"
                    class="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 dark:border-rose-800 dark:bg-rose-900/30 dark:text-rose-200"
                >
                    {{ file.error_message }}
                </section>

                <!-- Datos del Audio (ID3 metadata) + Datos de la transcripción (collapsible) -->
                <section class="relative overflow-hidden rounded-md bg-gradient-to-br from-teal-700 via-cyan-500 to-sky-300 p-5 text-white shadow-sm">
                    <template v-if="hasAudioMetadata">
                        <h3 class="text-base font-semibold text-white">Datos del Audio</h3>
                        <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            <div v-if="meta.album" class="flex flex-col">
                                <dt class="text-xs uppercase tracking-wide text-white/80">Álbum</dt>
                                <dd class="font-medium text-white">{{ meta.album }}</dd>
                            </div>
                            <div v-if="meta.artist" class="flex flex-col">
                                <dt class="text-xs uppercase tracking-wide text-white/80">Artista</dt>
                                <dd class="font-medium text-white">{{ meta.artist }}</dd>
                            </div>
                            <div v-if="meta.composer" class="flex flex-col">
                                <dt class="text-xs uppercase tracking-wide text-white/80">Compositor</dt>
                                <dd class="font-medium text-white">{{ meta.composer }}</dd>
                            </div>
                            <div v-if="meta.genre" class="flex flex-col">
                                <dt class="text-xs uppercase tracking-wide text-white/80">Género</dt>
                                <dd class="font-medium text-white">{{ meta.genre }}</dd>
                            </div>
                            <div v-if="meta.year" class="flex flex-col">
                                <dt class="text-xs uppercase tracking-wide text-white/80">Año</dt>
                                <dd class="font-medium text-white">{{ meta.year }}</dd>
                            </div>
                        </dl>
                    </template>

                    <!-- Datos de la transcripción (collapsible) -->
                    <div :class="hasAudioMetadata ? 'mt-5 border-t border-white/20 pt-4' : ''">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 text-left"
                            :aria-expanded="transcriptionDataOpen"
                            aria-controls="transcription-data"
                            @click="transcriptionDataOpen = !transcriptionDataOpen"
                        >
                            <h3 class="text-base font-semibold text-white">
                                Datos de la transcripción
                            </h3>
                            <svg
                                class="h-5 w-5 shrink-0 text-white/80 transition-transform duration-200"
                                :class="{ 'rotate-180': transcriptionDataOpen }"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>
                        <div
                            v-show="transcriptionDataOpen"
                            id="transcription-data"
                            class="mt-3 grid gap-4 text-sm text-white/90 sm:grid-cols-4"
                        >
                            <div>
                                <p class="font-semibold text-white">Modelo</p>
                                <p>{{ file.model }}</p>
                            </div>
                            <div>
                                <p class="font-semibold text-white">Idioma</p>
                                <p>{{ file.language || '-' }}</p>
                            </div>
                            <div>
                                <p class="font-semibold text-white">Duración</p>
                                <p>{{ file.duration_seconds ? formatTime(file.duration_seconds) : '-' }}</p>
                            </div>
                            <div>
                                <p class="font-semibold text-white">Segmentos</p>
                                <p>{{ segmentsCount }}</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-md border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            Reproducir audio
                        </h3>
                        <div
                            v-if="file.has_cleaned_audio && file.audio_available"
                            class="inline-flex rounded-md border border-gray-200 bg-gray-50 p-0.5 text-xs dark:border-gray-700 dark:bg-gray-900/50"
                            role="tablist"
                            aria-label="Fuente de audio"
                        >
                            <button
                                type="button"
                                class="rounded px-3 py-1 transition"
                                :class="audioSource === 'original' ? 'bg-white font-semibold text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100'"
                                role="tab"
                                :aria-selected="audioSource === 'original'"
                                @click="audioSource = 'original'"
                            >
                                Original
                            </button>
                            <button
                                type="button"
                                class="rounded px-3 py-1 transition"
                                :class="audioSource === 'cleaned' ? 'bg-yellow-100 font-semibold text-amber-900 shadow-sm dark:bg-yellow-900/40 dark:text-yellow-100' : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100'"
                                role="tab"
                                :aria-selected="audioSource === 'cleaned'"
                                @click="audioSource = 'cleaned'"
                            >
                                ✨ Con reducción de ruido
                            </button>
                        </div>
                    </div>
                    <div v-if="file.audio_available" class="flex items-stretch gap-3">
                        <button
                            v-if="hasArtwork"
                            type="button"
                            class="group relative h-16 w-16 shrink-0 overflow-hidden rounded-md ring-1 ring-gray-200 transition hover:ring-2 hover:ring-blue-400 sm:h-[3.25rem] sm:w-[3.25rem] dark:ring-gray-700"
                            title="Click para agrandar"
                            @click="showArtworkModal = true"
                        >
                            <img
                                :src="artworkUrl"
                                alt="Carátula"
                                class="h-full w-full object-cover"
                                @error="artworkFailed = true"
                            />
                            <span class="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition group-hover:bg-black/30 group-hover:opacity-100">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m-3-3h6" />
                                </svg>
                            </span>
                        </button>
                        <div
                            v-else-if="meta.has_picture && ! artworkFailed"
                            class="h-16 w-16 shrink-0 animate-pulse rounded-md bg-gray-100 sm:h-[3.25rem] sm:w-[3.25rem] dark:bg-gray-700"
                        />
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
                    </div>
                    <div
                        v-else
                        class="flex flex-col items-start gap-3 rounded-md border border-dashed border-gray-300 bg-gray-50 p-4 dark:border-gray-600 dark:bg-gray-900/40"
                    >
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                El audio no está disponible en el servidor
                            </p>
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                La transcripción está guardada, pero el archivo de audio no se
                                encuentra en el servidor. Resubí el mismo archivo para volver a
                                escucharlo (no se vuelve a transcribir).
                            </p>
                        </div>
                        <input
                            ref="reuploadInput"
                            type="file"
                            accept=".mp3,.wav,.m4a,.mp4,.webm,.ogg,.oga,.flac,.aac,audio/*"
                            class="hidden"
                            @change="onReuploadSelected"
                        >
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="reuploading"
                            @click="triggerReupload"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2" />
                            </svg>
                            {{ reuploading ? 'Subiendo…' : 'Resubir archivo' }}
                        </button>
                    </div>

                    <div
                        v-if="file.has_cleaned_audio && file.audio_available"
                        class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/30"
                    >
                        <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                            Tenés un audio limpio listo
                        </p>
                        <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">
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
                                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                @click="discardCleaned"
                            >
                                Descartar
                            </button>
                        </div>
                    </div>
                </section>

                <section class="rounded-md border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-700"
                        :class="{ '!border-transparent': !summaryOpen }"
                    >
                        <button
                            type="button"
                            class="flex flex-1 items-center gap-3 text-left"
                            :aria-expanded="summaryOpen"
                            aria-controls="summary-body"
                            @click="summaryOpen = !summaryOpen"
                        >
                            <div class="flex-1">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                    Resumen
                                </h3>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <template v-if="summaryProvider === 'ollama'">
                                        🏠 Ollama local · <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-700">{{ summaryModel || ollamaConfig.model }}</code>
                                    </template>
                                    <template v-else>
                                        ☁️ Groq · <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-700">{{ summaryModel || 'llama-3.1-8b-instant' }}</code>
                                    </template>
                                    <span
                                        v-if="summaryElapsedLabel"
                                        class="ml-1 text-[10px] text-gray-400 dark:text-gray-500"
                                    >
                                        · Generado en {{ summaryElapsedLabel }}
                                    </span>
                                </p>
                            </div>
                            <svg
                                class="h-5 w-5 shrink-0 text-gray-500 transition-transform duration-200 dark:text-gray-400"
                                :class="{ 'rotate-180': summaryOpen }"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>
                        <div class="flex items-center gap-3">
                            <div
                                v-if="summaryProvider === 'groq' && groqUsage.configured"
                                class="flex flex-col text-right"
                                :title="`Tokens hoy: ${groqUsage.tokens_used.toLocaleString()} / ${groqUsage.limits.tokens_per_day.toLocaleString()} · Requests hoy: ${groqUsage.requests_count} / ${groqUsage.limits.requests_per_day}`"
                            >
                                <span class="text-[10px] uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    Free tier hoy
                                </span>
                                <div class="mt-0.5 flex items-center gap-2">
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div
                                            class="h-full rounded-full transition-all"
                                            :class="usagePct < 80 ? 'bg-emerald-500' : usagePct < 95 ? 'bg-amber-500' : 'bg-rose-500'"
                                            :style="{ width: `${usagePct}%` }"
                                        />
                                    </div>
                                    <span class="text-xs font-medium tabular-nums text-gray-600 dark:text-gray-400">
                                        {{ usagePct }}%
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
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
                                <button
                                    v-if="summaryStatus === 'queued' || summaryStatus === 'processing'"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-rose-300 bg-white px-3 py-1.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50 dark:border-rose-700 dark:bg-gray-800 dark:text-rose-300 dark:hover:bg-rose-900/30"
                                    title="Cancelar el resumen en curso"
                                    @click="cancelSummary"
                                >
                                    ✕ Cancelar
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-show="summaryOpen" id="summary-body" class="p-5">
                        <p
                            v-if="summaryProvider === 'groq' && ! groqUsage.configured"
                            class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200"
                        >
                            Falta configurar <code>GROQ_APIKEY</code> en el servidor para poder generar resúmenes con Groq.
                            Cambialo a Ollama desde <a :href="route('profile.edit')" class="font-semibold underline">Configuración</a>.
                        </p>
                        <p
                            v-else-if="summaryStatus === 'failed'"
                            class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-800 dark:bg-rose-900/30 dark:text-rose-200"
                        >
                            {{ props.file.transcription?.summary_error || 'No se pudo generar el resumen.' }}
                        </p>
                        <p
                            v-else-if="! hasSummary && summaryStatus === 'idle'"
                            class="text-sm text-gray-500 dark:text-gray-400"
                        >
                            Aún no hay resumen. Hacé clic en "Resumir" para generar uno con {{ ollamaAvailable ? 'Ollama' : 'Groq' }}.
                        </p>
                        <div
                            v-else-if="hasSummary"
                            class="space-y-6"
                        >
                            <div
                                class="summary-markdown text-[16px] leading-7 text-gray-800 dark:text-gray-100"
                                v-html="summaryHtml"
                            />

                            <div v-if="props.file.transcription.key_points?.length">
                                <h4 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-gray-700 dark:text-gray-200">
                                    <span aria-hidden="true">🎯</span>
                                    Puntos principales
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        {{ props.file.transcription.key_points.length }}
                                    </span>
                                </h4>
                                <ul class="mt-3 space-y-2">
                                    <li
                                        v-for="(point, i) in props.file.transcription.key_points"
                                        :key="i"
                                        class="flex items-start gap-3 rounded-lg border border-gray-100 bg-gradient-to-r from-blue-50/40 to-transparent p-3 transition hover:border-blue-200 hover:from-blue-50 dark:border-gray-800 dark:from-blue-900/20 dark:hover:border-blue-800 dark:hover:from-blue-900/30"
                                    >
                                        <span
                                            class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white"
                                            aria-hidden="true"
                                        >
                                            {{ i + 1 }}
                                        </span>
                                        <span class="flex-1 text-base leading-7 text-gray-800 dark:text-gray-100">
                                            {{ point }}
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div
                        class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-700"
                        :class="{ '!border-transparent': !transcriptionOpen }"
                    >
                        <button
                            type="button"
                            class="flex flex-1 items-center gap-3 text-left"
                            :aria-expanded="transcriptionOpen"
                            aria-controls="transcription-body"
                            @click="transcriptionOpen = !transcriptionOpen"
                        >
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                Transcripción
                            </h3>
                            <span
                                v-if="file.transcription?.edited"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-200"
                                title="Texto editado por el usuario"
                            >
                                ✎ Editada
                            </span>
                            <span
                                v-if="segments.length && transcriptionOpen && ! editingText && ! file.transcription?.edited"
                                class="text-xs font-normal text-gray-500 dark:text-gray-400"
                            >
                                Hacé clic en cualquier parte para reproducir desde ahí
                            </span>
                            <svg
                                class="ml-auto h-5 w-5 shrink-0 text-gray-500 transition-transform duration-200 dark:text-gray-400"
                                :class="{ 'rotate-180': transcriptionOpen }"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>
                        <div v-if="file.transcription?.text && ! editingText" class="flex shrink-0 gap-1.5">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                title="Editar texto de la transcripción"
                                @click="startEditing"
                            >
                                ✎ Editar
                            </button>
                            <button
                                v-if="file.transcription?.edited"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 shadow-sm hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200 dark:hover:bg-amber-900/50"
                                title="Volver al texto original de Whisper"
                                @click="restoreOriginal"
                            >
                                ↺ Original
                            </button>
                        </div>
                    </div>
                    <div
                        v-show="transcriptionOpen"
                        id="transcription-body"
                        class="p-5"
                    >
                        <!-- Modo edición -->
                        <template v-if="editingText">
                            <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                                Editá libremente — borrá oraciones iniciales, partes irrelevantes, lo que quieras.
                                Al guardar, los segmentos se vuelven a alinear: <strong>karaoke y SRT siguen sincronizados</strong>
                                con tu versión editada. (Las palabras que agregues, sin equivalente en el audio, heredan
                                el timestamp del vecino más cercano.)
                            </p>
                            <textarea
                                v-model="editedDraft"
                                rows="20"
                                class="block w-full rounded-md border-gray-300 font-sans text-sm leading-7 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500"
                                spellcheck="true"
                            />
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ editedDraft.length.toLocaleString() }} caracteres
                                </p>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                        :disabled="savingText"
                                        @click="cancelEditing"
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                                        :disabled="savingText"
                                        @click="saveText"
                                    >
                                        {{ savingText ? 'Guardando...' : 'Guardar' }}
                                    </button>
                                </div>
                            </div>
                        </template>

                        <!-- Modo lectura -->
                        <template v-else>
                            <p
                                v-if="!file.transcription?.text"
                                class="text-sm text-gray-500 dark:text-gray-400"
                            >
                                Sin texto disponible
                            </p>
                            <div
                                v-if="segments.length"
                                class="space-y-4 text-sm leading-7 text-gray-800 dark:text-gray-100"
                            >
                                <p
                                    v-for="(para, pIdx) in paragraphs"
                                    :key="pIdx"
                                >
                                    <span
                                        v-for="entry in para"
                                        :key="entry.idx"
                                    >
                                        <span
                                            class="cursor-pointer rounded transition"
                                            :class="activeSegmentIndex === entry.idx ? 'bg-yellow-200 px-0.5 dark:bg-yellow-900/40 dark:text-yellow-100' : 'hover:bg-yellow-50 dark:hover:bg-yellow-900/20'"
                                            :title="formatTime(entry.segment.start_seconds)"
                                            @click="seekTo(entry.segment.start_seconds)"
                                        >{{ entry.segment.text }}</span>
                                        <span> </span>
                                    </span>
                                </p>
                            </div>
                            <div
                                v-else
                                class="whitespace-pre-wrap text-sm leading-7 text-gray-800 dark:text-gray-100"
                            >
                                {{ file.transcription.text }}
                            </div>
                        </template>
                    </div>
                </section>

            </div>
        </div>

        <DownloadFormatModal :show="showDownloadModal" :file="file" @close="showDownloadModal = false" />

        <Modal :show="showArtworkModal" max-width="2xl" @close="showArtworkModal = false">
            <div class="relative flex items-center justify-center bg-black p-2">
                <button
                    type="button"
                    class="absolute right-2 top-2 z-10 inline-flex h-8 w-8 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-black/80"
                    title="Cerrar"
                    @click="showArtworkModal = false"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <img
                    v-if="hasArtwork"
                    :src="artworkUrl"
                    alt="Carátula"
                    class="max-h-[80vh] max-w-full rounded object-contain"
                />
            </div>
            <div v-if="meta.album || meta.artist" class="bg-white px-6 py-4 text-sm dark:bg-gray-800">
                <p v-if="meta.album" class="font-semibold text-gray-900 dark:text-gray-100">{{ meta.album }}</p>
                <p v-if="meta.artist" class="text-gray-600 dark:text-gray-400">{{ meta.artist }}</p>
            </div>
        </Modal>

        <!-- Botón scroll-to-top: aparece cerca del fondo de la página -->
        <Transition
            enter-active-class="transition duration-200"
            leave-active-class="transition duration-150"
            enter-from-class="opacity-0 translate-y-2"
            leave-to-class="opacity-0 translate-y-2"
        >
            <button
                v-if="showScrollTop"
                type="button"
                class="fixed bottom-6 right-6 z-40 inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-900 text-white shadow-lg transition hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:bg-gray-200 dark:text-gray-900 dark:hover:bg-white"
                aria-label="Ir al inicio de la página"
                title="Ir al inicio"
                @click="scrollToTop"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                </svg>
            </button>
        </Transition>
    </AuthenticatedLayout>
</template>

<style scoped>
.summary-markdown :deep(h2) {
    @apply mt-6 mb-3 border-b border-gray-200 pb-1 text-lg font-bold text-gray-900 dark:border-gray-700 dark:text-gray-100;
}
.summary-markdown :deep(h2:first-child) {
    @apply mt-0;
}
.summary-markdown :deep(h3) {
    @apply mt-5 mb-2 text-base font-semibold text-gray-900 dark:text-gray-100;
}
.summary-markdown :deep(p) {
    @apply my-3 leading-7;
}
.summary-markdown :deep(ul) {
    @apply my-3 list-disc space-y-1 pl-6;
}
.summary-markdown :deep(ol) {
    @apply my-3 list-decimal space-y-1 pl-6;
}
.summary-markdown :deep(li) {
    @apply leading-7;
}
.summary-markdown :deep(strong) {
    @apply font-semibold text-gray-900 dark:text-gray-50;
}
.summary-markdown :deep(em) {
    @apply italic;
}
.summary-markdown :deep(hr) {
    @apply my-5 border-gray-200 dark:border-gray-700;
}
.summary-markdown :deep(code) {
    @apply rounded bg-gray-100 px-1 py-0.5 text-sm dark:bg-gray-700;
}
.summary-markdown :deep(blockquote) {
    @apply my-3 border-l-4 border-gray-300 pl-4 italic text-gray-700 dark:border-gray-600 dark:text-gray-300;
}
</style>
