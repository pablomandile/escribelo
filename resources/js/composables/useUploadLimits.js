import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Si el server no compartió los límites (página vieja en caché), asumimos lo
// más conservador que suele traer un hosting compartido, para no prometer de
// más y volver a comer un 413.
const FALLBACK = { per_file: 64 * 1024 * 1024, per_request: 64 * 1024 * 1024 };

export function formatBytes(bytes) {
    if (bytes >= 1073741824) return `${(bytes / 1073741824).toFixed(1).replace('.', ',')} GB`;
    if (bytes >= 1048576) return `${Math.round(bytes / 1048576)} MB`;
    return `${Math.round(bytes / 1024)} KB`;
}

/**
 * Los límites de subida reales del servidor (upload_max_filesize y
 * post_max_size), compartidos por HandleInertiaRequests.
 *
 * Sirven para frenar la subida ANTES de mandarla: si el POST se pasa de
 * post_max_size, PHP descarta el body y responde 413 sin que la validación de
 * Laravel llegue a correr, así que el usuario no ve un mensaje útil (y encima
 * ya esperó a que se subiera todo).
 */
export function useUploadLimits() {
    const page = usePage();
    const limits = computed(() => page.props.uploadLimits || FALLBACK);

    /**
     * @returns {string|null} el motivo del rechazo, o null si la selección entra.
     */
    const rejectionReason = (files) => {
        const { per_file: perFile, per_request: perRequest } = limits.value;

        const tooBig = files.find((f) => f.size > perFile);
        if (tooBig) {
            return `«${tooBig.name}» pesa ${formatBytes(tooBig.size)} y el máximo por archivo es ${formatBytes(perFile)}.`;
        }

        const total = files.reduce((sum, f) => sum + f.size, 0);
        if (files.length > 1 && total > perRequest) {
            return `Los ${files.length} archivos suman ${formatBytes(total)} y el servidor acepta hasta ${formatBytes(perRequest)} por subida. Mandalos en tandas más chicas.`;
        }

        return null;
    };

    return { limits, rejectionReason };
}
