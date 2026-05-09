<script setup>
import { useForm } from '@inertiajs/vue3';
import { playBeep } from '@/utils/beep';

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    backup_on_replace: !! props.settings.backup_on_replace,
    theme: props.settings.theme === 'dark' ? 'dark' : 'light',
    notify_on_complete: !! props.settings.notify_on_complete,
});

const submit = () => {
    form.patch(route('profile.settings'), {
        preserveScroll: true,
    });
};

const previewBeep = () => {
    playBeep();
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Preferencias</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Ajustes personales del comportamiento de Escríbelo.
            </p>
        </header>

        <form
            class="mt-6 space-y-8"
            @submit.prevent="submit"
        >
            <div>
                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                    Tema de la interfaz
                </span>
                <span class="mt-1 block text-sm text-gray-600 dark:text-gray-400">
                    Elegí cómo se ve Escríbelo. Se aplica al instante y se recuerda en este y otros dispositivos cuando inicies sesión.
                </span>
                <div class="mt-3 grid grid-cols-2 gap-3 max-w-md">
                    <label
                        :class="[
                            'flex cursor-pointer items-center gap-3 rounded-md border px-4 py-3 transition',
                            form.theme === 'light'
                                ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-900/30'
                                : 'border-gray-300 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:hover:bg-gray-700',
                        ]"
                    >
                        <input
                            v-model="form.theme"
                            type="radio"
                            value="light"
                            class="h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"
                        >
                        <span class="flex flex-col">
                            <span class="font-medium text-gray-900 dark:text-gray-100">Claro</span>
                            <span class="text-xs text-gray-600 dark:text-gray-400">Fondo claro, texto oscuro.</span>
                        </span>
                    </label>
                    <label
                        :class="[
                            'flex cursor-pointer items-center gap-3 rounded-md border px-4 py-3 transition',
                            form.theme === 'dark'
                                ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-900/30'
                                : 'border-gray-300 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:hover:bg-gray-700',
                        ]"
                    >
                        <input
                            v-model="form.theme"
                            type="radio"
                            value="dark"
                            class="h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"
                        >
                        <span class="flex flex-col">
                            <span class="font-medium text-gray-900 dark:text-gray-100">Oscuro</span>
                            <span class="text-xs text-gray-600 dark:text-gray-400">Fondo negro, mejor para baja luz.</span>
                        </span>
                    </label>
                </div>
            </div>

            <div>
                <label class="flex cursor-pointer items-start gap-3">
                    <input
                        v-model="form.backup_on_replace"
                        type="checkbox"
                        class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"
                    >
                    <span class="text-sm">
                        <span class="font-medium text-gray-900 dark:text-gray-100">
                            Hacer backup al reemplazar el audio original
                        </span>
                        <span class="mt-1 block text-gray-600 dark:text-gray-400">
                            Cuando uses "Reemplazar original" con un audio
                            limpio, antes de sobreescribir se guardará una
                            copia del audio original como
                            <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-700 dark:text-gray-200">{nombre}_original.{ext}</code>
                            en la misma carpeta.
                        </span>
                    </span>
                </label>
            </div>

            <div>
                <label class="flex cursor-pointer items-start gap-3">
                    <input
                        v-model="form.notify_on_complete"
                        type="checkbox"
                        class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"
                    >
                    <span class="text-sm">
                        <span class="font-medium text-gray-900 dark:text-gray-100">
                            Sonido al terminar una transcripción
                        </span>
                        <span class="mt-1 block text-gray-600 dark:text-gray-400">
                            Reproduce un beep corto cuando un archivo pasa a
                            <strong>Completado</strong>. Útil si dejás la pestaña
                            abierta de fondo mientras procesás varios audios.
                        </span>
                    </span>
                </label>
                <button
                    type="button"
                    @click="previewBeep"
                    class="ml-7 mt-2 inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                >
                    🔔 Probar sonido
                </button>
            </div>

            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-200 dark:text-gray-900 dark:hover:bg-white"
                >
                    Guardar
                </button>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-gray-600 dark:text-gray-400"
                    >
                        Guardado.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
