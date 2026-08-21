<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head } from '@inertiajs/vue3';

const EMAIL = 'pablo.mandile@gmail.com';
const WEBSITE = 'https://bioinfo.pablomandile.com.ar/pablo';
</script>

<template>
    <Head title="Acerca de" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-900 dark:text-gray-100 sm:text-2xl">
                    Acerca de Escríbelo
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Qué es, para qué sirve y quién está detrás.
                </p>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-10 px-4 py-8 sm:px-6 lg:px-8">
            <!-- Hero -->
            <section
                class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-700 via-violet-500 to-fuchsia-400 text-white shadow-lg"
            >
                <div class="absolute -right-20 -top-24 h-96 w-96 rounded-full bg-fuchsia-200/30 blur-3xl" />
                <div class="absolute -bottom-32 -left-16 h-96 w-96 rounded-full bg-indigo-900/30 blur-3xl" />

                <div class="relative px-6 py-12 sm:px-10 sm:py-16">
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-white ring-1 ring-white/30 backdrop-blur">
                        Transcripción · Resúmenes · Privado
                    </span>
                    <h1 class="mt-5 text-5xl font-extrabold leading-[1.05] tracking-tight sm:text-7xl">
                        Escríbelo
                    </h1>
                    <p class="mt-4 max-w-2xl text-lg font-medium leading-relaxed text-white/90 sm:text-xl">
                        Convertí tus audios en texto con timestamps y generá
                        resúmenes estructurados, todo corriendo en tu propio
                        equipo.
                    </p>
                </div>
            </section>

            <!-- ¿Qué es? -->
            <section class="grid gap-6 md:grid-cols-[auto_1fr] md:items-start">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-violet-100 dark:bg-violet-900/30 text-2xl">
                    ✍️
                </div>
                <div>
                    <h3 class="text-3xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">¿Qué es Escríbelo?</h3>
                    <p class="mt-3 text-base leading-7 text-gray-700 dark:text-gray-200">
                        <strong>Escríbelo</strong> es una plataforma
                        self-hosted de transcripción y resumen de audio. Subís
                        un archivo (o lo referenciás desde una carpeta local),
                        <strong>Whisper</strong> lo transcribe con marcas de
                        tiempo a nivel segmento, y opcionalmente un modelo de
                        lenguaje genera un <strong>resumen estructurado</strong>
                        en markdown.
                    </p>
                    <p class="mt-3 text-base leading-7 text-gray-700 dark:text-gray-200">
                        Cada usuario tiene su biblioteca con carpetas
                        anidables, edición de texto con realineado automático
                        de timestamps, y exportación a
                        <strong>TXT, SRT y PDF</strong>.
                    </p>
                </div>
            </section>

            <!-- Qué podés hacer -->
            <section>
                <div class="grid gap-4 md:grid-cols-3">
                    <article class="rounded-2xl border border-violet-200 dark:border-violet-800 bg-gradient-to-br from-violet-50 to-white dark:from-violet-900/20 dark:to-gray-800 p-6 shadow-sm">
                        <span class="text-4xl" aria-hidden="true">🎙️</span>
                        <h4 class="mt-3 text-xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">Transcribí</h4>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                            Audio a texto con timestamps, karaoke sincronizado
                            y reducción de ruido opcional. Elegí el modelo
                            según el balance velocidad / precisión.
                        </p>
                    </article>

                    <article class="rounded-2xl border border-indigo-200 dark:border-indigo-800 bg-gradient-to-br from-indigo-50 to-white dark:from-indigo-900/20 dark:to-gray-800 p-6 shadow-sm">
                        <span class="text-4xl" aria-hidden="true">🧠</span>
                        <h4 class="mt-3 text-xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">Resumí</h4>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                            Resúmenes estructurados con puntos clave, generados
                            por un modelo local (Ollama) o en la nube (Groq),
                            según preferencia.
                        </p>
                    </article>

                    <article class="rounded-2xl border border-emerald-200 dark:border-emerald-800 bg-gradient-to-br from-emerald-50 to-white dark:from-emerald-900/20 dark:to-gray-800 p-6 shadow-sm">
                        <span class="text-4xl" aria-hidden="true">🔒</span>
                        <h4 class="mt-3 text-xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">Sin depender de terceros</h4>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                            El procesamiento corre en tu propio equipo: tu
                            audio no sale de casa y no hay costo por minuto
                            transcrito.
                        </p>
                    </article>
                </div>
            </section>

            <!-- Creado por -->
            <section class="overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm">
                <div class="grid gap-6 p-6 sm:p-8 md:grid-cols-[auto_1fr] md:items-center">
                    <div class="flex items-center justify-center rounded-xl bg-gray-50 dark:bg-gray-900/40 p-6">
                        <ApplicationLogo class="h-16 w-auto" />
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.075em] text-gray-500 dark:text-gray-400">
                            Creado por
                        </p>
                        <p class="mt-1 text-2xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">Pablo Mandile</p>

                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:gap-6">
                            <a
                                :href="`mailto:${EMAIL}`"
                                class="inline-flex items-center gap-2 text-sm font-medium text-gray-700 transition-colors hover:text-indigo-600 dark:text-gray-200 dark:hover:text-indigo-400"
                            >
                                <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="2" y="4" width="20" height="16" rx="2" />
                                    <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                                </svg>
                                <span class="break-all">{{ EMAIL }}</span>
                            </a>

                            <a
                                :href="WEBSITE"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-2 text-sm font-medium text-gray-700 transition-colors hover:text-indigo-600 dark:text-gray-200 dark:hover:text-indigo-400"
                            >
                                <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
                                </svg>
                                <span>Bioinfo</span>
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Nota final -->
            <footer class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5 text-xs text-gray-500 dark:text-gray-400 shadow-sm">
                <p class="font-semibold uppercase tracking-wider text-gray-700 dark:text-gray-200">Bajo el capó</p>
                <p class="mt-2 leading-relaxed">
                    Construido con Laravel, Inertia y Vue. La transcripción usa
                    <a
                        href="https://github.com/openai/whisper"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-medium text-indigo-700 dark:text-indigo-300 underline hover:text-indigo-900 dark:hover:text-indigo-200"
                    >OpenAI Whisper</a>
                    (open source, licencia MIT). ¿Querés saber más sobre el
                    motor? Mirá la sección <em>Modelo</em>.
                </p>
            </footer>
        </div>
    </AuthenticatedLayout>
</template>
