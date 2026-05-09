<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const faqs = [
    {
        q: '¿Qué es Escríbelo?',
        a: 'Escríbelo es un servicio de transcripción de IA que proporciona transcripción ilimitada de audio y video. Escríbelo convierte archivos de audio y video a texto en más de 98 idiomas con una precisión extremadamente alta.',
    },
    {
        q: '¿Qué formatos de audio/video soporta?',
        a: 'Escríbelo admite formatos comunes de audio y video, incluyendo MP3, M4A, MP4.',
    },
    {
        q: '¿Puedo exportar mi transcripción?',
        a: '¡Sí! Las transcripciones se pueden descargar en los siguientes formatos: PDF, subtítulos (SRT) y TXT.',
    },
    {
        q: '¿Qué idiomas soportan?',
        a: 'Escríbelo convierte el habla en texto en más de 98 idiomas utilizando la tecnología de transcripción por IA con la mayor precisión.\n\nIdiomas como el inglés son los más precisos, típicamente con niveles de rendimiento humanos y un fuerte reconocimiento del vocabulario especializado y específico de dominios. La precisión de la conversión de voz a texto varía según el idioma. Obtendrás los mejores resultados en los siguientes idiomas: inglés, español, francés, alemán, italiano, portugués, neerlandés, chino, japonés, ruso, árabe, hindi, sueco, noruego, danés, polaco, turco, hebreo, griego, checo, vietnamita y coreano.',
    },
    {
        q: '¿Qué pasa con los acentos, el ruido de fondo y la mala calidad de audio?',
        a: 'Aunque el audio limpio y claro produce los mejores resultados, Escríbelo generalmente maneja bien los acentos, el ruido de fondo y la baja calidad de audio.\n\nSi estás transcribiendo archivos con muy mala calidad de audio, Escríbelo tiene una herramienta de reducción de ruido integrada. Se puede activar mediante la opción "Reducir ruido antes de transcribir" (Checkbox) al subir un archivo. Esto utiliza IA para eliminar el ruido de fondo y mejorar el habla humana. La reducción de ruido de audio toma aproximadamente 20% más tiempo.',
    },
    {
        q: '¿Puedo subir archivos grandes?',
        a: '¡Sí! Escríbelo está diseñado para manejar cargas masivas. Cada archivo subido puede tener hasta 4 horas de duración y 3GB de tamaño.',
    },
    {
        q: '¿Quién está detrás de Escríbelo?',
        a: 'Hola 👋 Soy Pablo. Desarrollo software y me divierto creando productos y servicios como Escríbelo.',
    },
    {
        q: 'Tengo más preguntas.',
        a: 'Puedes enviar un correo electrónico a pablo.mandile@gmail.com con cualquier pregunta adicional y te responderé lo antes posible.',
    },
];

const openIndex = ref(0);

const toggle = (index) => {
    openIndex.value = openIndex.value === index ? -1 : index;
};
</script>

<template>
    <Head title="Preguntas frecuentes" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-900 dark:text-gray-100 sm:text-2xl">
                    Preguntas frecuentes
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Todo lo que necesitás saber sobre Escríbelo.
                </p>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-10 px-4 py-8 sm:px-6 lg:px-8">
            <section
                class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-teal-700 via-cyan-500 to-sky-300 text-white shadow-lg"
            >
                <div class="absolute -right-20 -top-24 h-96 w-96 rounded-full bg-cyan-200/30 blur-3xl" />
                <div class="absolute -bottom-32 -left-16 h-96 w-96 rounded-full bg-teal-900/30 blur-3xl" />

                <div class="relative px-6 py-12 sm:px-10 sm:py-14">
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-white ring-1 ring-white/30 backdrop-blur">
                        FAQ
                    </span>
                    <h1 class="mt-5 text-4xl font-extrabold leading-[1.05] tracking-tight sm:text-6xl">
                        Preguntas frecuentes
                    </h1>
                    <p class="mt-4 max-w-2xl text-lg font-medium leading-relaxed text-white/90 sm:text-xl">
                        Resolvé tus dudas sobre formatos, idiomas, archivos
                        grandes, calidad de audio y todo lo que ofrece
                        Escríbelo.
                    </p>
                </div>
            </section>

            <section class="space-y-3">
                <div
                    v-for="(item, index) in faqs"
                    :key="index"
                    class="overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm transition hover:shadow-md"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-4 px-6 py-5 text-left"
                        @click="toggle(index)"
                    >
                        <h3 class="text-base font-bold text-gray-900 dark:text-gray-100 sm:text-lg">
                            {{ item.q }}
                        </h3>
                        <svg
                            class="h-5 w-5 shrink-0 text-gray-500 dark:text-gray-400 transition-transform duration-200"
                            :class="{ 'rotate-180': openIndex === index }"
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </button>
                    <div
                        v-show="openIndex === index"
                        class="border-t border-gray-100 dark:border-gray-800 px-6 py-5 text-sm leading-7 text-gray-700 dark:text-gray-200 sm:text-base sm:leading-8"
                    >
                        <p
                            v-for="(paragraph, p) in item.a.split('\n\n')"
                            :key="p"
                            :class="{ 'mt-4': p > 0 }"
                        >
                            {{ paragraph }}
                        </p>
                    </div>
                </div>
            </section>

            <footer class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5 text-center text-sm text-gray-600 dark:text-gray-400 shadow-sm">
                ¿No encontraste lo que buscabas? Escribime a
                <a
                    href="mailto:pablo.mandile@gmail.com"
                    class="font-semibold text-teal-700 dark:text-teal-300 underline hover:text-teal-900 dark:hover:text-teal-200"
                >pablo.mandile@gmail.com</a>.
            </footer>
        </div>
    </AuthenticatedLayout>
</template>
