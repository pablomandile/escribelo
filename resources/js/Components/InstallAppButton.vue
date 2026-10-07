<script setup>
import { ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { usePwaInstall } from '@/composables/usePwaInstall';

defineProps({
    // 'icon': botón cuadrado para la barra desktop. 'row': fila con texto para el menú mobile.
    variant: {
        type: String,
        default: 'icon',
    },
});

const emit = defineEmits(['done']);

const { canInstall, isIos, install } = usePwaInstall();
const showHelp = ref(false);
const busy = ref(false);

async function onClick() {
    if (busy.value) return;
    busy.value = true;
    try {
        // 'manual' = iOS, o el prompt ya se usó: se explican los pasos a mano.
        if ((await install()) === 'manual') {
            showHelp.value = true;
        } else {
            emit('done');
        }
    } finally {
        busy.value = false;
    }
}

function closeHelp() {
    showHelp.value = false;
    emit('done');
}
</script>

<template>
    <button
        v-if="canInstall && variant === 'icon'"
        type="button"
        class="inline-flex h-9 w-9 items-center justify-center rounded-md text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100"
        title="Instalar app"
        aria-label="Instalar app"
        data-pwa-install
        :disabled="busy"
        @click="onClick"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
            <path d="M7 10l5 5 5-5" />
            <path d="M12 15V3" />
        </svg>
    </button>

    <button
        v-else-if="canInstall"
        type="button"
        class="flex w-full items-center gap-2 border-l-4 border-transparent py-2 pe-4 ps-3 text-start text-base font-medium text-gray-600 transition duration-150 ease-in-out hover:border-gray-300 hover:bg-gray-50 hover:text-gray-800 focus:outline-none dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-100"
        data-pwa-install
        :disabled="busy"
        @click="onClick"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
            <path d="M7 10l5 5 5-5" />
            <path d="M12 15V3" />
        </svg>
        Instalar app
    </button>

    <Modal :show="showHelp" max-width="sm" @close="closeHelp">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Instalar Escríbelo
            </h2>

            <ol v-if="isIos" class="mt-4 list-decimal space-y-2 ps-5 text-sm text-gray-600 dark:text-gray-300">
                <li>Tocá <strong>Compartir</strong> en la barra de Safari.</li>
                <li>Elegí <strong>Agregar a inicio</strong>.</li>
                <li>Confirmá con <strong>Agregar</strong>.</li>
            </ol>
            <ol v-else class="mt-4 list-decimal space-y-2 ps-5 text-sm text-gray-600 dark:text-gray-300">
                <li>Abrí el menú del navegador (<strong>⋮</strong>).</li>
                <li>Elegí <strong>Instalar aplicación</strong> o <strong>Agregar a la pantalla principal</strong>.</li>
            </ol>

            <div class="mt-6 flex justify-end">
                <SecondaryButton @click="closeHelp">Entendido</SecondaryButton>
            </div>
        </div>
    </Modal>
</template>
