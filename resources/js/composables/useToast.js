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
        // `duration` va antes del spread para que el caller pueda pisarla.
        error: (msg, opts) => show(msg, { duration: 6000, ...opts, type: 'error' }),
        info: (msg, opts) => show(msg, { ...opts, type: 'info' }),
    };
}
