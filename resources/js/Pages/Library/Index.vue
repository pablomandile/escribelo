<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    folders: {
        type: Array,
        default: () => [],
    },
    unfiledCount: {
        type: Number,
        default: 0,
    },
});
</script>

<template>
    <Head title="Biblioteca" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-900 sm:text-2xl dark:text-gray-100">
                    Biblioteca
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Explorá tus temas principales y entrá a sus subcarpetas para
                    ver las transcripciones organizadas.
                </p>
            </div>
        </template>

        <div class="bg-slate-50 py-8 dark:bg-gray-900">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <p
                    v-if="unfiledCount > 0"
                    class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200"
                >
                    Tenés <strong>{{ unfiledCount }}</strong> transcripción(es)
                    sin asignar a ninguna carpeta. Podés moverlas arrastrándolas
                    desde
                    <Link
                        :href="route('dashboard')"
                        class="font-semibold underline hover:text-amber-900 dark:hover:text-amber-100"
                    >Últimas transcripciones</Link>.
                </p>

                <div
                    v-if="folders.length === 0"
                    class="rounded-md border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-600 dark:bg-gray-800"
                >
                    <p class="text-base font-semibold text-gray-700 dark:text-gray-200">
                        Todavía no tenés carpetas
                    </p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Creá tu primer tema principal desde
                        <Link
                            :href="route('dashboard')"
                            class="font-semibold text-blue-700 underline hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-200"
                        >Últimas transcripciones</Link>.
                    </p>
                </div>

                <div
                    v-else
                    class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <Link
                        v-for="folder in folders"
                        :key="folder.id"
                        :href="route('folders.show', folder.id)"
                        class="group flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm transition hover:border-blue-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-blue-500 dark:hover:shadow-black/30"
                    >
                        <span class="text-3xl" aria-hidden="true">📁</span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate font-semibold text-gray-900 group-hover:text-blue-700 dark:text-gray-100 dark:group-hover:text-blue-300"
                                :title="folder.name"
                            >
                                {{ folder.name }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ folder.files_count }} archivo{{ folder.files_count === 1 ? '' : 's' }}
                                · {{ folder.children_count }} subcarpeta{{ folder.children_count === 1 ? '' : 's' }}
                            </p>
                        </div>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
