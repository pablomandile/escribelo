<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Head title="Escríbelo" />
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-black via-zinc-900 to-yellow-500 px-6"
    >
        <img
            src="/images/logo.png"
            alt="Escríbelo"
            class="w-full max-w-md sm:max-w-lg lg:max-w-2xl [filter:drop-shadow(0_0_25px_rgba(255,255,255,0.8))_drop-shadow(0_0_60px_rgba(255,255,255,0.5))]"
        />

        <h1
            class="mt-8 text-center text-3xl font-bold text-white sm:text-4xl lg:text-5xl"
        >
            Transcribe tus audios con IA
        </h1>
        <p
            class="mt-4 max-w-2xl text-center text-base text-white/80 sm:text-lg"
        >
            Subí tus grabaciones y obtené el texto en minutos. Elegí el modelo
            según la velocidad y precisión que necesités.
        </p>

        <div
            class="mt-8 grid w-full max-w-3xl grid-cols-1 gap-4 sm:grid-cols-3"
        >
            <div
                class="flex flex-col items-center rounded-xl border border-white/15 bg-white/5 p-5 text-center backdrop-blur-sm"
            >
                <span class="text-4xl" aria-hidden="true">🐇</span>
                <p class="mt-2 text-sm font-semibold text-white">small</p>
                <p class="mt-1 text-xs text-white/70">Rápido</p>
            </div>
            <div
                class="flex flex-col items-center rounded-xl border border-white/15 bg-white/5 p-5 text-center backdrop-blur-sm"
            >
                <span class="text-4xl" aria-hidden="true">🦊</span>
                <p class="mt-2 text-sm font-semibold text-white">medium</p>
                <p class="mt-1 text-xs text-white/70">Equilibrado</p>
            </div>
            <div
                class="flex flex-col items-center rounded-xl border border-white/15 bg-white/5 p-5 text-center backdrop-blur-sm"
            >
                <span class="text-4xl" aria-hidden="true">🐋</span>
                <p class="mt-2 text-sm font-semibold text-white">large-v3</p>
                <p class="mt-1 text-xs text-white/70">Máxima precisión</p>
            </div>
        </div>

        <nav
            v-if="canLogin"
            class="mt-10 flex flex-col items-center gap-4 sm:flex-row sm:gap-6"
        >
            <Link
                v-if="$page.props.auth.user"
                :href="route('dashboard')"
                class="rounded-lg bg-white px-8 py-3 text-base font-semibold text-black shadow-lg transition hover:bg-yellow-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-yellow-400"
            >
                Últimas transcripciones
            </Link>

            <template v-else>
                <Link
                    :href="route('login')"
                    class="rounded-lg bg-white px-8 py-3 text-base font-semibold text-black shadow-lg transition hover:bg-yellow-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-yellow-400"
                >
                    Iniciar Sesión
                </Link>

                <Link
                    v-if="canRegister"
                    :href="route('register')"
                    class="rounded-lg border border-white/40 px-8 py-3 text-base font-semibold text-white shadow-lg transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-yellow-400"
                >
                    Registrarse
                </Link>
            </template>
        </nav>
    </div>
</template>
