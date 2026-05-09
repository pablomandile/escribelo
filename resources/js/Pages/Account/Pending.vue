<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    name: { type: String, default: '' },
    email: { type: String, default: '' },
});
</script>

<template>
    <Head title="Esperando aprobación" />

    <div class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-amber-900 flex items-center justify-center px-4">
        <div class="w-full max-w-lg rounded-2xl bg-white dark:bg-gray-800 p-8 shadow-2xl">
            <div class="flex flex-col items-center text-center">
                <ApplicationLogo class="h-16 w-auto" />

                <div class="mt-6 inline-flex items-center gap-2 rounded-full bg-amber-100 dark:bg-amber-900/30 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-200">
                    ⏳ Pendiente
                </div>

                <h1 class="mt-4 text-2xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">
                    Tu cuenta está pendiente de aprobación
                </h1>

                <p class="mt-3 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                    Hola <strong>{{ name }}</strong> 👋
                    Recibimos tu registro con el email <strong>{{ email }}</strong>.
                    Un administrador tiene que aprobar tu cuenta para que puedas
                    empezar a transcribir audios. Te avisaremos cuando esté lista.
                </p>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="mt-8 inline-flex items-center gap-1.5 rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 shadow-sm transition hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                    Cerrar sesión
                </Link>
            </div>
        </div>
    </div>
</template>
