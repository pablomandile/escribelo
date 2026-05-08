<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const segmentsOpen = ref(false);

const props = defineProps({
    file: {
        type: Object,
        required: true,
    },
});

const audioRef = ref(null);
const currentTime = ref(0);

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
                    <h3 class="mb-3 text-base font-semibold text-gray-900">
                        Reproducir audio
                    </h3>
                    <audio
                        ref="audioRef"
                        controls
                        preload="metadata"
                        class="w-full"
                        :src="route('transcriptions.audio', file.id)"
                        @timeupdate="onTimeUpdate"
                    >
                        Tu navegador no soporta la reproducción de audio.
                    </audio>
                </section>

                <section class="rounded-md border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <h3 class="text-base font-semibold text-gray-900">
                            Transcripción
                        </h3>
                        <p
                            v-if="segments.length"
                            class="text-xs text-gray-500"
                        >
                            Hacé clic en cualquier parte para reproducir desde ahí
                        </p>
                    </div>
                    <div class="p-5">
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
