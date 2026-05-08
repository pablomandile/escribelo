<script setup>
import { useToast } from '@/composables/useToast';
import { usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const { toasts, dismiss, success, error } = useToast();
const page = usePage();

const flash = computed(() => page.props.flash || {});
const errorBag = computed(() => page.props.errors || {});

watch(
    () => flash.value.success,
    (value) => {
        if (value) {
            success(value);
        }
    },
    { immediate: true },
);

watch(
    () => flash.value.error,
    (value) => {
        if (value) {
            error(value);
        }
    },
    { immediate: true },
);

watch(errorBag, (bag) => {
    const messages = Object.values(bag || {}).filter(Boolean);
    if (messages.length === 0) {
        return;
    }
    const seen = new Set();
    for (const msg of messages) {
        if (! seen.has(msg)) {
            seen.add(msg);
            error(msg);
        }
    }
});

const typeClasses = (type) => ({
    success: 'bg-emerald-600 text-white',
    error: 'bg-rose-600 text-white',
    info: 'bg-slate-800 text-white',
}[type] || 'bg-slate-800 text-white');

const typeIcon = (type) => ({
    success: '✓',
    error: '✕',
    info: 'ℹ',
}[type] || '');
</script>

<template>
    <div class="pointer-events-none fixed right-4 top-4 z-[70] flex w-full max-w-sm flex-col gap-2">
        <TransitionGroup
            enter-active-class="transition duration-200"
            leave-active-class="transition duration-150"
            enter-from-class="translate-x-full opacity-0"
            leave-to-class="translate-x-full opacity-0"
            move-class="transition duration-200"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-start gap-3 rounded-md px-4 py-3 shadow-lg"
                :class="typeClasses(toast.type)"
                role="status"
            >
                <span class="text-lg leading-none" aria-hidden="true">
                    {{ typeIcon(toast.type) }}
                </span>
                <p class="flex-1 text-sm">{{ toast.message }}</p>
                <button
                    type="button"
                    class="ml-2 text-white/70 hover:text-white"
                    aria-label="Cerrar"
                    @click="dismiss(toast.id)"
                >
                    ✕
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
