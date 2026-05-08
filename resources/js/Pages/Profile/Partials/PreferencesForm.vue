<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
    providerInfo: {
        type: Object,
        default: () => ({
            groq_configured: false,
            ollama_model: 'gemma3:12b',
            ollama_base_url: 'http://localhost:11434',
        }),
    },
});

const form = useForm({
    backup_on_replace: !! props.settings.backup_on_replace,
    transcription_provider: props.settings.transcription_provider || 'local',
    summary_provider: props.settings.summary_provider || 'groq',
});

const submit = () => {
    form.patch(route('profile.settings'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Configuración</h2>
            <p class="mt-1 text-sm text-gray-600">
                Ajustes generales del comportamiento de Escríbelo.
            </p>
        </header>

        <form
            class="mt-6 space-y-8"
            @submit.prevent="submit"
        >
            <!-- Backup -->
            <div>
                <label class="flex cursor-pointer items-start gap-3">
                    <input
                        v-model="form.backup_on_replace"
                        type="checkbox"
                        class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                    >
                    <span class="text-sm">
                        <span class="font-medium text-gray-900">
                            Hacer backup al reemplazar el audio original
                        </span>
                        <span class="mt-1 block text-gray-600">
                            Cuando uses "Reemplazar original" con un audio
                            limpio, antes de sobreescribir se guardará una
                            copia del audio original como
                            <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs">{nombre}_original.{ext}</code>
                            en la misma carpeta.
                        </span>
                    </span>
                </label>
            </div>

            <!-- Motor de transcripción -->
            <fieldset>
                <legend class="text-sm font-medium text-gray-900">
                    Motor de transcripción
                </legend>
                <p class="mt-1 text-sm text-gray-600">
                    Decide quién procesa los audios para convertirlos en texto.
                </p>
                <div class="mt-3 space-y-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 p-3 hover:bg-gray-50">
                        <input
                            v-model="form.transcription_provider"
                            type="radio"
                            value="local"
                            class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500"
                        >
                        <span class="text-sm">
                            <span class="font-semibold text-gray-900">🏠 Local (Whisper en tu PC)</span>
                            <span class="mt-1 block text-gray-600">
                                Privado, sin costo, sin límites. Usa tu CPU/GPU local.
                            </span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 p-3 hover:bg-gray-50">
                        <input
                            v-model="form.transcription_provider"
                            type="radio"
                            value="groq"
                            class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500"
                        >
                        <span class="text-sm">
                            <span class="font-semibold text-gray-900">☁️ Groq (online · whisper-large-v3)</span>
                            <span class="mt-1 block text-gray-600">
                                Velocidad alta, free tier disponible. El audio sale del equipo.
                                <em>(Próximamente — por ahora la transcripción se hace local.)</em>
                            </span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <!-- Motor de resumen -->
            <fieldset>
                <legend class="text-sm font-medium text-gray-900">
                    Motor de resumen
                </legend>
                <p class="mt-1 text-sm text-gray-600">
                    Decide qué LLM usar para generar resúmenes y puntos clave.
                </p>
                <div class="mt-3 space-y-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 p-3 hover:bg-gray-50">
                        <input
                            v-model="form.summary_provider"
                            type="radio"
                            value="groq"
                            class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500"
                        >
                        <span class="text-sm">
                            <span class="font-semibold text-gray-900">☁️ Groq (online)</span>
                            <span class="mt-1 block text-gray-600">
                                Llama 3.1 8B vía Groq · respuestas en segundos · free tier.
                                <span
                                    v-if="!providerInfo.groq_configured"
                                    class="font-semibold text-rose-700"
                                >
                                    Falta configurar <code>GROQ_APIKEY</code>.
                                </span>
                            </span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 p-3 hover:bg-gray-50">
                        <input
                            v-model="form.summary_provider"
                            type="radio"
                            value="ollama"
                            class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500"
                        >
                        <span class="text-sm">
                            <span class="font-semibold text-gray-900">🏠 Ollama local</span>
                            <span class="mt-1 block text-gray-600">
                                Modelo <code class="rounded bg-gray-100 px-1 py-0.5">{{ providerInfo.ollama_model }}</code> corriendo en
                                <code class="rounded bg-gray-100 px-1 py-0.5">{{ providerInfo.ollama_base_url }}</code>.
                                Privado, sin límites, más lento.
                            </span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition hover:bg-gray-700 disabled:opacity-50"
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
                        class="text-sm text-gray-600"
                    >
                        Guardado.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
