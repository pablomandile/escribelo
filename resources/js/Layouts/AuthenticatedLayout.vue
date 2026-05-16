<script setup>
import { computed, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import ToastContainer from '@/Components/ToastContainer.vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);

const page = usePage();
const isDark = computed(() => page.props.auth.user?.theme === 'dark');

const toggleTheme = () => {
    const next = isDark.value ? 'light' : 'dark';
    // Aplicación optimista: cambiamos la clase ya para feedback inmediato.
    // app.js re-sincroniza desde props.auth.user.theme tras la respuesta del PATCH.
    if (next === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
    try { localStorage.setItem('theme', next); } catch (e) {}
    router.patch(route('profile.theme'), { theme: next }, {
        preserveScroll: true,
        preserveState: true,
    });
};
</script>

<template>
    <div>
        <ConfirmModal />
        <ToastContainer />
        <div class="flex min-h-screen flex-col bg-gray-100 dark:bg-gray-900">
            <nav
                class="border-b border-gray-100 bg-white dark:border-gray-800 dark:bg-gray-800"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-100"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Últimas transcripciones
                                </NavLink>
                                <NavLink
                                    :href="route('folders.index')"
                                    :active="route().current('folders.*')"
                                >
                                    Biblioteca
                                </NavLink>
                                <NavLink
                                    :href="route('about.model')"
                                    :active="route().current('about.model')"
                                >
                                    Modelo
                                </NavLink>
                                <NavLink
                                    :href="route('about.faq')"
                                    :active="route().current('about.faq')"
                                >
                                    FAQ
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <!-- Theme toggle -->
                            <button
                                type="button"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-md text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100"
                                :title="isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
                                :aria-label="isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
                                @click="toggleTheme"
                            >
                                <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="4" />
                                    <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                                </svg>
                                <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                                </svg>
                            </button>

                            <!-- Settings Dropdown -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none dark:bg-gray-800 dark:text-gray-300 dark:hover:text-gray-100"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
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
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            Perfil
                                        </DropdownLink>
                                        <DropdownLink
                                            v-if="$page.props.auth.user?.is_admin"
                                            :href="route('admin.settings.edit')"
                                        >
                                            Configuración de Escríbelo
                                        </DropdownLink>
                                        <DropdownLink
                                            v-if="$page.props.auth.user?.is_admin"
                                            :href="route('admin.users.index')"
                                        >
                                            Usuarios
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Cerrar Sesión
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200 dark:focus:bg-gray-700"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            Últimas transcripciones
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('folders.index')"
                            :active="route().current('folders.*')"
                        >
                            Biblioteca
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('about.model')"
                            :active="route().current('about.model')"
                        >
                            Modelo
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('about.faq')"
                            :active="route().current('about.faq')"
                        >
                            FAQ
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-gray-200 pb-1 pt-4 dark:border-gray-700"
                    >
                        <div class="flex items-center justify-between px-4">
                            <div>
                                <div
                                    class="text-base font-medium text-gray-800 dark:text-gray-200"
                                >
                                    {{ $page.props.auth.user.name }}
                                </div>
                                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ $page.props.auth.user.email }}
                                </div>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-md text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100"
                                :title="isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
                                :aria-label="isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
                                @click="toggleTheme"
                            >
                                <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="4" />
                                    <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                                </svg>
                                <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                                </svg>
                            </button>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Perfil
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                v-if="$page.props.auth.user?.is_admin"
                                :href="route('admin.settings.edit')"
                            >
                                Configuración de Escríbelo
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                v-if="$page.props.auth.user?.is_admin"
                                :href="route('admin.users.index')"
                            >
                                Usuarios
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Cerrar Sesión
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header
                class="bg-white shadow dark:bg-gray-800 dark:shadow-gray-950/50"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1">
                <slot />
            </main>

            <!-- Footer -->
            <footer
                class="border-t border-gray-200 bg-gradient-to-r from-gray-200 to-gray-50 dark:border-gray-800 dark:from-gray-800 dark:to-gray-900"
            >
                <div class="mx-auto flex max-w-7xl flex-col items-center gap-2 px-4 py-4 text-center text-sm text-gray-700 sm:px-6 lg:px-8 dark:text-gray-300">
                    <p class="font-medium">© 2026 Escríbelo</p>
                    <nav class="flex flex-wrap justify-center gap-x-4 gap-y-1">
                        <Link :href="route('dashboard')" class="hover:text-gray-900 dark:hover:text-white">Inicio</Link>
                        <Link :href="route('folders.index')" class="hover:text-gray-900 dark:hover:text-white">Biblioteca</Link>
                        <Link :href="route('about.model')" class="hover:text-gray-900 dark:hover:text-white">Modelo</Link>
                        <Link :href="route('about.faq')" class="hover:text-gray-900 dark:hover:text-white">FAQ</Link>
                        <Link :href="route('profile.edit')" class="hover:text-gray-900 dark:hover:text-white">Configuración de la cuenta</Link>
                    </nav>
                </div>
            </footer>
        </div>
    </div>
</template>
