import { ref } from 'vue';

const toasts = ref([]);
let nextId = 1;

const dismiss = (id) => {
    toasts.value = toasts.value.filter((t) => t.id !== id);
};

const show = (message, { type = 'info', duration = 4000 } = {}) => {
    const id = nextId++;
    toasts.value.push({ id, message, type });
    if (duration > 0) {
        setTimeout(() => dismiss(id), duration);
    }
    return id;
};

export function useToast() {
    return {
        toasts,
        show,
        dismiss,
        success: (msg, opts) => show(msg, { ...opts, type: 'success' }),
        error: (msg, opts) => show(msg, { ...opts, type: 'error', duration: 6000 }),
        info: (msg, opts) => show(msg, { ...opts, type: 'info' }),
    };
}
