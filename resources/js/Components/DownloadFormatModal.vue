<script setup>
import { computed } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    file: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const close = () => emit('close');

// Defer modal close so the browser can fire the navigation/download first.
const onPick = () => {
    setTimeout(close, 50);
};

const baseName = computed(() => {
    const name = props.file?.original_name || 'transcripcion';
    return name.replace(/\.[^/.]+$/, '');
});

const downloadUrl = (format) => {
    if (! props.file) return '#';
    return `/transcriptions/${props.file.id}/download/${format}`;
};

const formats = [
    {
        key: 'pdf',
        label: 'PDF',
        description: 'Documento listo para imprimir o compartir.',
        icon: '📄',
        color: 'border-rose-200 hover:border-rose-400 hover:bg-rose-50 dark:border-rose-800 dark:hover:border-rose-500 dark:hover:bg-rose-900/30',
        badge: 'bg-rose-100 text-rose-700 dark:bg-rose-900/50 dark:text-rose-200',
    },
    {
        key: 'srt',
        label: 'SRT',
        description: 'Subtítulos sincronizados con el audio.',
        icon: '🎬',
        color: 'border-violet-200 hover:border-violet-400 hover:bg-violet-50 dark:border-violet-800 dark:hover:border-violet-500 dark:hover:bg-violet-900/30',
        badge: 'bg-violet-100 text-violet-700 dark:bg-violet-900/50 dark:text-violet-200',
    },
    {
        key: 'txt',
        label: 'TXT',
        description: 'Texto plano editable.',
        icon: '📝',
        color: 'border-emerald-200 hover:border-emerald-400 hover:bg-emerald-50 dark:border-emerald-800 dark:hover:border-emerald-500 dark:hover:bg-emerald-900/30',
        badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-200',
    },
];
</script>

<template>
    <Modal :show="show" max-width="md" @close="close">
        <div class="p-6">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Descargar transcripción</h2>
            <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">
                {{ baseName }}
            </p>

            <div class="mt-5 space-y-3">
                <a
                    v-for="format in formats"
                    :key="format.key"
                    :href="downloadUrl(format.key)"
                    :download="`${baseName}.${format.key}`"
                    class="flex items-center gap-4 rounded-xl border bg-white p-4 transition dark:bg-gray-800"
                    :class="format.color"
                    @click="onPick"
                >
                    <span class="text-3xl" aria-hidden="true">{{ format.icon }}</span>
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold text-gray-900 dark:text-gray-100">{{ format.label }}</span>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="format.badge">
                                .{{ format.key }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ format.description }}</p>
                    </div>
                    <svg class="h-5 w-5 text-gray-400 dark:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v9.586l3.293-3.293a1 1 0 111.414 1.414l-5 5a1 1 0 01-1.414 0l-5-5a1 1 0 111.414-1.414L9 13.586V4a1 1 0 011-1z" clip-rule="evenodd" />
                    </svg>
                </a>
            </div>

            <div class="mt-6 flex justify-end">
                <button
                    type="button"
                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    @click="close"
                >
                    Cancelar
                </button>
            </div>
        </div>
    </Modal>
</template>
