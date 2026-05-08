<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    file: {
        type: Object,
        required: true,
    },
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
                        class="text-sm font-medium text-blue-700 hover:text-blue-900"
                        :href="route('dashboard')"
                    >
                        Volver
                    </Link>
                    <h2 class="mt-1 max-w-3xl truncate text-xl font-semibold leading-tight text-gray-900">
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
                        controls
                        preload="metadata"
                        class="w-full"
                        :src="route('transcriptions.audio', file.id)"
                    >
                        Tu navegador no soporta la reproducción de audio.
                    </audio>
                </section>

                <section class="rounded-md border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h3 class="text-base font-semibold text-gray-900">
                            Transcripcion
                        </h3>
                    </div>
                    <div class="p-5">
                        <p
                            v-if="!file.transcription?.text"
                            class="text-sm text-gray-500"
                        >
                            Sin texto disponible
                        </p>
                        <div
                            v-else
                            class="whitespace-pre-wrap text-sm leading-7 text-gray-800"
                        >
                            {{ file.transcription.text }}
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h3 class="text-base font-semibold text-gray-900">
                            Segmentos
                        </h3>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <p
                            v-if="!file.transcription?.segments?.length"
                            class="px-5 py-6 text-sm text-gray-500"
                        >
                            Sin segmentos
                        </p>
                        <div
                            v-for="segment in file.transcription.segments"
                            :key="segment.id"
                            class="grid gap-3 px-5 py-4 text-sm sm:grid-cols-[90px_1fr]"
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
