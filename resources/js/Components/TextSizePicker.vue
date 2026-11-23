<script setup>
import { TEXT_SIZES } from '@/composables/useTextSize';

// Tres «T» de tamaño creciente; la elegida queda resaltada.
const size = defineModel({ type: String, default: 'md' });

const icons = {
    sm: 'text-[11px]',
    md: 'text-sm',
    lg: 'text-lg',
};
</script>

<template>
    <div
        class="inline-flex shrink-0 items-center rounded-md border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-700 dark:bg-gray-900/50"
        role="group"
        aria-label="Tamaño del texto"
    >
        <button
            v-for="(option, key) in TEXT_SIZES"
            :key="key"
            type="button"
            class="flex h-7 w-7 items-center justify-center rounded font-serif font-semibold leading-none transition"
            :class="[
                icons[key],
                size === key
                    ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100'
                    : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100',
            ]"
            :aria-pressed="size === key"
            :title="`Texto ${option.label}`"
            @click.stop="size = key"
        >
            <span aria-hidden="true">T</span>
            <span class="sr-only">Texto {{ option.label }}</span>
        </button>
    </div>
</template>
