<script setup>
import { useConfirm } from '@/composables/useConfirm';

const { isOpen, options, confirm, cancel } = useConfirm();
</script>

<template>
    <Transition
        enter-active-class="transition duration-150"
        leave-active-class="transition duration-100"
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
    >
        <div
            v-if="isOpen"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"
            @click.self="cancel"
            @keydown.esc="cancel"
        >
            <div
                class="w-full max-w-md overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-800"
                role="dialog"
                aria-modal="true"
            >
                <div class="px-5 pt-5">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        {{ options.title }}
                    </h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ options.message }}
                    </p>
                </div>
                <div class="mt-5 flex justify-end gap-2 bg-gray-50 px-5 py-3 dark:bg-gray-900/40">
                    <button
                        type="button"
                        class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        @click="cancel"
                    >
                        {{ options.cancelText }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-semibold text-white"
                        :class="
                            options.danger
                                ? 'bg-rose-600 hover:bg-rose-700'
                                : 'bg-blue-600 hover:bg-blue-700'
                        "
                        @click="confirm"
                    >
                        {{ options.confirmText }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>
