<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    folder: {
        type: Object,
        required: true,
    },
});

const formatDuration = (seconds) => {
    if (! seconds) {
        return '-';
    }
    const total = Math.round(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = total % 60;
    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }
    return `${minutes}m ${rest}s`;
};

const formatDate = (value) => {
    if (! value) {
        return '-';
    }
    return new Intl.DateTimeFormat('es-AR', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

const statusLabel = (status) => ({
    queued: 'En cola',
    waiting_for_worker: 'Esperando worker',
    enhancing: 'Mejorando',
    processing: 'Procesando',
    completed: 'Completado',
    failed: 'Error',
    cancelled: 'Cancelado',
}[status] || status);

const statusClass = (status) => ({
    queued: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200',
    waiting_for_worker: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-200',
    enhancing: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200',
    processing: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200',
    completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200',
    failed: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200',
    cancelled: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
}[status] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300');
</script>

<template>
    <Head :title="folder.name" />

    <AuthenticatedLayout>
        <template #header>
            <nav class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <Link
                    :href="route('folders.index')"
                    class="hover:text-blue-700 dark:hover:text-blue-300"
                >
                    Biblioteca
                </Link>
                <span v-if="folder.parent">/</span>
                <Link
                    v-if="folder.parent"
                    :href="route('folders.show', folder.parent.id)"
                    class="hover:text-blue-700 dark:hover:text-blue-300"
                >
                    {{ folder.parent.name }}
                </Link>
                <span>/</span>
                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ folder.name }}</span>
            </nav>
            <h2 class="mt-2 text-xl font-semibold leading-tight text-gray-900 sm:text-2xl dark:text-gray-100">
                📁 {{ folder.name }}
            </h2>
        </template>

        <div class="bg-slate-50 py-8 dark:bg-gray-900">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <section v-if="folder.children.length">
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Subcarpetas
                    </h3>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="child in folder.children"
                            :key="child.id"
                            :href="route('folders.show', child.id)"
                            class="group flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition hover:border-blue-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-blue-500 dark:hover:shadow-black/30"
                        >
                            <span class="text-2xl" aria-hidden="true">📂</span>
                            <div class="min-w-0">
                                <p
                                    class="truncate font-semibold text-gray-900 group-hover:text-blue-700 dark:text-gray-100 dark:group-hover:text-blue-300"
                                    :title="child.name"
                                >
                                    {{ child.name }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ child.files_count }} archivo{{ child.files_count === 1 ? '' : 's' }}
                                </p>
                            </div>
                        </Link>
                    </div>
                </section>

                <section>
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Transcripciones
                    </h3>
                    <div class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <p
                            v-if="folder.files.length === 0"
                            class="p-6 text-sm text-gray-500 dark:text-gray-400"
                        >
                            Sin transcripciones en esta carpeta.
                        </p>
                        <table
                            v-else
                            class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                        >
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Nombre
                                    </th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Subido
                                    </th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Duración
                                    </th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Modelo
                                    </th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Estado
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                <tr
                                    v-for="file in folder.files"
                                    :key="file.id"
                                    class="hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                >
                                    <td class="max-w-md px-5 py-4">
                                        <Link
                                            v-if="file.status === 'completed'"
                                            class="block truncate text-sm font-medium text-gray-900 hover:text-blue-700 dark:text-gray-100 dark:hover:text-blue-300"
                                            :href="route('transcriptions.show', file.id)"
                                        >
                                            {{ file.original_name }}
                                        </Link>
                                        <span
                                            v-else
                                            class="block cursor-not-allowed truncate text-sm font-medium text-gray-500 dark:text-gray-400"
                                            :title="`Disponible cuando termine la transcripción (estado: ${file.status})`"
                                        >
                                            {{ file.original_name }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                        {{ formatDate(file.created_at) }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                        {{ formatDuration(file.duration_seconds) }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                        {{ file.model }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="statusClass(file.status)"
                                        >
                                            {{ statusLabel(file.status) }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
