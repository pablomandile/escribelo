<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Modelo" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-900 sm:text-2xl">
                    Whisper · el motor de transcripción
                </h2>
                <p class="mt-1 text-sm text-gray-600">
                    Información sobre el modelo de IA que convierte tus audios
                    en texto.
                </p>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-10 px-4 py-8 sm:px-6 lg:px-8">
            <section
                class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-teal-700 via-cyan-500 to-sky-300 text-white shadow-lg"
            >
                <div class="absolute -right-20 -top-24 h-96 w-96 rounded-full bg-cyan-200/30 blur-3xl" />
                <div class="absolute -bottom-32 -left-16 h-96 w-96 rounded-full bg-teal-900/30 blur-3xl" />

                <div class="relative px-6 py-12 sm:px-10 sm:py-16">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-white ring-1 ring-white/30 backdrop-blur">
                    OpenAI · MIT · Local
                </span>
                <h1 class="mt-5 text-5xl font-extrabold leading-[1.05] tracking-tight sm:text-7xl">
                    Whisper
                </h1>
                <p class="mt-4 max-w-2xl text-lg font-medium leading-relaxed text-white/90 sm:text-xl">
                    El modelo de reconocimiento de voz que potencia Escríbelo.
                    Multilingüe, robusto, y corriendo enteramente en tu equipo.
                </p>

                <dl class="mt-10 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="rounded-xl bg-white/15 p-4 ring-1 ring-white/25 backdrop-blur">
                        <dt class="text-xs uppercase tracking-wider text-white/70">Entrenamiento</dt>
                        <dd class="mt-1 text-3xl font-extrabold tracking-tight">680 k</dd>
                        <dd class="text-xs text-white/70">horas de audio</dd>
                    </div>
                    <div class="rounded-xl bg-white/15 p-4 ring-1 ring-white/25 backdrop-blur">
                        <dt class="text-xs uppercase tracking-wider text-white/70">Idiomas</dt>
                        <dd class="mt-1 text-3xl font-extrabold tracking-tight">~99</dd>
                        <dd class="text-xs text-white/70">soportados</dd>
                    </div>
                    <div class="rounded-xl bg-white/15 p-4 ring-1 ring-white/25 backdrop-blur">
                        <dt class="text-xs uppercase tracking-wider text-white/70">Lanzamiento</dt>
                        <dd class="mt-1 text-3xl font-extrabold tracking-tight">2022</dd>
                        <dd class="text-xs text-white/70">por OpenAI</dd>
                    </div>
                    <div class="rounded-xl bg-white/15 p-4 ring-1 ring-white/25 backdrop-blur">
                        <dt class="text-xs uppercase tracking-wider text-white/70">Licencia</dt>
                        <dd class="mt-1 text-3xl font-extrabold tracking-tight">MIT</dd>
                        <dd class="text-xs text-white/70">open source</dd>
                    </div>
                </dl>
                </div>
            </section>

            <section class="grid gap-6 md:grid-cols-[auto_1fr] md:items-start">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-yellow-100 text-2xl">
                        💡
                    </div>
                    <div>
                        <h3 class="text-3xl font-extrabold tracking-tight text-gray-900">¿Qué es Whisper?</h3>
                        <p class="mt-3 text-base leading-7 text-gray-700">
                            <strong>Whisper</strong> es un modelo de
                            reconocimiento automático de voz (ASR) creado por
                            <strong>OpenAI</strong> y publicado como código
                            abierto bajo licencia MIT en septiembre de 2022.
                            Está entrenado sobre <strong>680.000 horas</strong>
                            de audio multilingüe etiquetado obtenido de la web,
                            lo que le da gran robustez frente a acentos, ruido
                            de fondo y vocabulario técnico.
                        </p>
                        <p class="mt-3 text-base leading-7 text-gray-700">
                            Resuelve varias tareas a la vez con un único
                            modelo: <strong>transcripción</strong> (audio →
                            texto en el mismo idioma),
                            <strong>traducción</strong> al inglés, e
                            <strong>identificación de idioma</strong>. Soporta
                            cerca de <strong>99 idiomas</strong>.
                        </p>
                    </div>
                </section>

                <section class="grid gap-6 md:grid-cols-[auto_1fr] md:items-start">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-2xl">
                        ⚙️
                    </div>
                    <div>
                        <h3 class="text-3xl font-extrabold tracking-tight text-gray-900">Cómo funciona</h3>
                        <p class="mt-3 text-base leading-7 text-gray-700">
                            Whisper es un <strong>Transformer</strong> de tipo
                            encoder-decoder. El audio se procesa en ventanas de
                            30 segundos, se convierte a un espectrograma
                            log-Mel y el encoder extrae representaciones
                            acústicas. El decoder genera el texto token por
                            token, condicionado por instrucciones especiales
                            que le indican qué tarea ejecutar (transcribir
                            vs. traducir) y en qué idioma.
                        </p>
                        <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                            <span class="rounded-md bg-slate-100 px-3 py-1.5 font-medium text-slate-700">🎙️ Audio</span>
                            <span class="text-slate-400">→</span>
                            <span class="rounded-md bg-slate-100 px-3 py-1.5 font-medium text-slate-700">📊 Espectrograma</span>
                            <span class="text-slate-400">→</span>
                            <span class="rounded-md bg-slate-100 px-3 py-1.5 font-medium text-slate-700">🧠 Encoder</span>
                            <span class="text-slate-400">→</span>
                            <span class="rounded-md bg-slate-100 px-3 py-1.5 font-medium text-slate-700">✍️ Decoder</span>
                            <span class="text-slate-400">→</span>
                            <span class="rounded-md bg-emerald-100 px-3 py-1.5 font-medium text-emerald-700">📝 Texto</span>
                        </div>
                    </div>
                </section>

                <section>
                    <div class="text-center">
                        <h3 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">Modelos disponibles</h3>
                        <p class="mt-3 text-base font-medium text-gray-600 sm:text-lg">
                            Elegí el balance entre velocidad y precisión que
                            necesités para cada audio.
                        </p>
                    </div>
                    <div class="mt-6 grid gap-4 md:grid-cols-3">
                        <article class="group relative overflow-hidden rounded-2xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                            <div class="absolute -right-4 -top-4 text-7xl opacity-20" aria-hidden="true">🐇</div>
                            <div class="relative">
                                <span class="text-5xl" aria-hidden="true">🐇</span>
                                <h4 class="mt-3 text-2xl font-extrabold tracking-tight text-gray-900">small</h4>
                                <p class="text-sm font-semibold text-emerald-700">Rápido</p>
                                <dl class="mt-4 space-y-1.5 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Parámetros</dt>
                                        <dd class="font-semibold text-gray-800">244 M</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">VRAM aprox.</dt>
                                        <dd class="font-semibold text-gray-800">~2 GB</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Velocidad</dt>
                                        <dd class="font-semibold text-gray-800">~6×</dd>
                                    </div>
                                </dl>
                                <p class="mt-4 border-t border-emerald-100 pt-3 text-xs leading-relaxed text-gray-600">
                                    Notas rápidas, audio limpio, dictados.
                                </p>
                            </div>
                        </article>

                        <article class="group relative overflow-hidden rounded-2xl border border-amber-200 bg-gradient-to-br from-amber-50 to-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                            <div class="absolute -right-4 -top-4 text-7xl opacity-20" aria-hidden="true">🦊</div>
                            <div class="relative">
                                <span class="text-5xl" aria-hidden="true">🦊</span>
                                <h4 class="mt-3 text-2xl font-extrabold tracking-tight text-gray-900">medium</h4>
                                <p class="text-sm font-semibold text-amber-700">Equilibrado</p>
                                <dl class="mt-4 space-y-1.5 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Parámetros</dt>
                                        <dd class="font-semibold text-gray-800">769 M</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">VRAM aprox.</dt>
                                        <dd class="font-semibold text-gray-800">~5 GB</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Velocidad</dt>
                                        <dd class="font-semibold text-gray-800">~2×</dd>
                                    </div>
                                </dl>
                                <p class="mt-4 border-t border-amber-100 pt-3 text-xs leading-relaxed text-gray-600">
                                    Reuniones, podcasts, audio con algo de
                                    ruido.
                                </p>
                            </div>
                        </article>

                        <article class="group relative overflow-hidden rounded-2xl border border-blue-200 bg-gradient-to-br from-blue-50 to-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                            <div class="absolute -right-4 -top-4 text-7xl opacity-20" aria-hidden="true">🐋</div>
                            <div class="relative">
                                <span class="text-5xl" aria-hidden="true">🐋</span>
                                <h4 class="mt-3 text-2xl font-extrabold tracking-tight text-gray-900">large-v3</h4>
                                <p class="text-sm font-semibold text-blue-700">Máxima precisión</p>
                                <dl class="mt-4 space-y-1.5 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Parámetros</dt>
                                        <dd class="font-semibold text-gray-800">1.550 M</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">VRAM aprox.</dt>
                                        <dd class="font-semibold text-gray-800">~10 GB</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Velocidad</dt>
                                        <dd class="font-semibold text-gray-800">~1×</dd>
                                    </div>
                                </dl>
                                <p class="mt-4 border-t border-blue-100 pt-3 text-xs leading-relaxed text-gray-600">
                                    Audio difícil, terminología especializada,
                                    máxima fidelidad.
                                </p>
                            </div>
                        </article>
                    </div>
                    <p class="mt-4 text-center text-xs text-gray-500">
                        Velocidad relativa medida frente a <code class="rounded bg-slate-100 px-1.5 py-0.5">large-v3</code>
                        en la misma GPU. En CPU el modelo igual funciona, pero
                        más lento.
                    </p>
                </section>

                <section class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-6 shadow-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl" aria-hidden="true">✨</span>
                            <h3 class="text-lg font-bold text-gray-900">Características clave</h3>
                        </div>
                        <ul class="mt-4 space-y-2.5 text-sm leading-6 text-gray-700">
                            <li class="flex gap-2">
                                <span class="text-emerald-600">✓</span>
                                <span>Multilingüe nativo, sin necesidad de pre-seleccionar idioma.</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-emerald-600">✓</span>
                                <span>Resistente a ruido de fondo, acentos y mala calidad de grabación.</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-emerald-600">✓</span>
                                <span>Devuelve <strong>segmentos con timestamps</strong>, ideales para sincronizar texto y audio.</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-emerald-600">✓</span>
                                <span>Funciona localmente: tu audio nunca sale del equipo.</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-emerald-600">✓</span>
                                <span>Open source, sin costo por minuto transcrito.</span>
                            </li>
                        </ul>
                    </div>

                    <div class="rounded-2xl border border-rose-200 bg-gradient-to-br from-rose-50 to-white p-6 shadow-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl" aria-hidden="true">⚠️</span>
                            <h3 class="text-lg font-bold text-gray-900">Limitaciones</h3>
                        </div>
                        <ul class="mt-4 space-y-2.5 text-sm leading-6 text-gray-700">
                            <li class="flex gap-2">
                                <span class="text-rose-600">!</span>
                                <span>Puede <strong>alucinar</strong> texto en silencios prolongados o audios muy cortos.</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-rose-600">!</span>
                                <span>La precisión cae con varios hablantes simultáneos (no hace diarización por sí mismo).</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-rose-600">!</span>
                                <span>Los nombres propios poco frecuentes pueden transcribirse mal.</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-rose-600">!</span>
                                <span>Sin GPU compatible, los modelos grandes pueden tardar varios minutos por minuto de audio.</span>
                            </li>
                        </ul>
                    </div>
                </section>

                <footer class="rounded-xl border border-gray-200 bg-white p-5 text-xs text-gray-500 shadow-sm">
                    <p class="font-semibold uppercase tracking-wider text-gray-700">Fuentes</p>
                    <p class="mt-2 leading-relaxed">
                        Documentación oficial de OpenAI Whisper —
                        <a
                            href="https://github.com/openai/whisper"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-medium text-blue-700 underline hover:text-blue-900"
                        >github.com/openai/whisper</a>
                        — y el paper <em>Robust Speech Recognition via
                        Large-Scale Weak Supervision</em> (Radford et al.,
                        2022).
                    </p>
                </footer>
        </div>
    </AuthenticatedLayout>
</template>
