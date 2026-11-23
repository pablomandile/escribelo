<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

// Modo demo: cuenta compartida, sin Google ni recuperar contraseña.
const demo = computed(() => usePage().props.demo);
const enterDemo = () => {
    form.email = demo.value.email;
    form.password = demo.value.password;
    submit();
};
</script>

<template>
    <GuestLayout>
        <Head title="Iniciar sesión" />

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
            {{ status }}
        </div>

        <div
            v-if="demo"
            class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/30"
        >
            <p class="text-sm font-semibold text-blue-900 dark:text-blue-100">Probá la demo</p>
            <p class="mt-1 text-sm text-blue-800 dark:text-blue-200">
                Una cuenta con audios ya transcriptos, resumidos y traducidos. Podés recorrer todo;
                subir y procesar audios está desactivado. Se reinicia todas las noches.
            </p>
            <p class="mt-2 break-words text-xs text-blue-800 dark:text-blue-200">
                Usuario <code class="rounded bg-white/70 px-1 dark:bg-gray-800">{{ demo.email }}</code>
                · contraseña <code class="rounded bg-white/70 px-1 dark:bg-gray-800">{{ demo.password }}</code>
            </p>
            <button
                type="button"
                class="mt-3 inline-flex w-full items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
                :disabled="form.processing"
                @click="enterDemo"
            >
                Entrar a la demo
            </button>
        </div>

        <a
            v-if="! demo"
            :href="route('auth.google.redirect')"
            class="mb-4 flex w-full items-center justify-center gap-3 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600"
        >
            <svg class="h-4 w-4" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3a12 12 0 1 1-3.4-13.1l5.7-5.7A20 20 0 1 0 44 24c0-1.2-.1-2.4-.4-3.5z"/>
                <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8A12 12 0 0 1 24 12c3 0 5.7 1.1 7.8 3l5.7-5.7A20 20 0 0 0 6.3 14.7z"/>
                <path fill="#4CAF50" d="M24 44c5.2 0 10-2 13.6-5.2l-6.3-5.2A12 12 0 0 1 12.7 28l-6.6 5A20 20 0 0 0 24 44z"/>
                <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3a12 12 0 0 1-4.1 5.6l6.3 5.2c-.4.4 6.5-4.8 6.5-14.8 0-1.2-.1-2.4-.4-3.5z"/>
            </svg>
            Continuar con Google
        </a>

        <div class="mb-4 flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
            <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
            <span>{{ demo ? 'O a mano' : 'O con email' }}</span>
            <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Contraseña" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400"
                        >Recordarme</span
                    >
                </label>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link
                    v-if="canResetPassword && ! demo"
                    :href="route('password.request')"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:text-gray-400 dark:hover:text-gray-100 dark:focus:ring-offset-gray-800"
                >
                    ¿Olvidaste tu contraseña?
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Iniciar sesión
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
