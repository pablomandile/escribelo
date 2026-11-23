import { computed, ref, watch } from 'vue';

/**
 * Los tres tamaños de lectura de las fichas de texto (Transcripción,
 * Traducción). El interlineado acompaña al tamaño para que el texto grande no
 * quede apretado.
 */
export const TEXT_SIZES = {
    sm: { label: 'chico', classes: 'text-sm leading-7' },
    md: { label: 'mediano', classes: 'text-base leading-7' },
    lg: { label: 'grande', classes: 'text-xl leading-9' },
};

/**
 * Tamaño elegido para una ficha, recordado en este navegador. Si el
 * almacenamiento no está disponible (modo privado, bloqueado) funciona igual,
 * solo que no se recuerda.
 */
export function useTextSize(storageKey, fallback = 'md') {
    const key = `escribelo_text_size_${storageKey}`;

    let initial = fallback;
    try {
        const saved = localStorage.getItem(key);
        if (saved && TEXT_SIZES[saved]) initial = saved;
    } catch {
        // sin almacenamiento: queda el valor por defecto
    }

    const size = ref(initial);
    watch(size, (value) => {
        try {
            localStorage.setItem(key, value);
        } catch {
            // idem
        }
    });

    const classes = computed(() => (TEXT_SIZES[size.value] || TEXT_SIZES[fallback]).classes);

    return { size, classes };
}
