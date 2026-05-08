import { ref } from 'vue';

const isOpen = ref(false);
const options = ref({
    title: 'Confirmar',
    message: '¿Estás seguro?',
    confirmText: 'Confirmar',
    cancelText: 'Cancelar',
    danger: false,
});
let resolver = null;

const open = (opts = {}) => {
    options.value = {
        title: opts.title || 'Confirmar',
        message: opts.message || '¿Estás seguro?',
        confirmText: opts.confirmText || 'Confirmar',
        cancelText: opts.cancelText || 'Cancelar',
        danger: opts.danger || false,
    };
    isOpen.value = true;
    return new Promise((resolve) => {
        resolver = resolve;
    });
};

const confirm = () => {
    isOpen.value = false;
    resolver?.(true);
    resolver = null;
};

const cancel = () => {
    isOpen.value = false;
    resolver?.(false);
    resolver = null;
};

export function useConfirm() {
    return { isOpen, options, open, confirm, cancel };
}
