import { computed, onMounted, onUnmounted, ref } from 'vue';

// El evento beforeinstallprompt lo captura un script inline en app.blade.php
// (window.__pwaInstall), porque Chrome lo dispara antes de que monte Vue.

const STANDALONE = '(display-mode: standalone)';

// iOS/iPadOS Safari nunca dispara beforeinstallprompt: la instalación es manual.
function detectIosSafari() {
    const ua = navigator.userAgent;
    const isIosDevice =
        /iPad|iPhone|iPod/.test(ua) ||
        // iPadOS se reporta como Mac; se distingue por el touch.
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    // Chrome/Firefox/Edge en iOS usan WebKit pero no pueden instalar PWAs.
    return isIosDevice && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
}

function detectInstalled() {
    return (
        window.matchMedia?.(STANDALONE).matches === true ||
        window.navigator.standalone === true || // iOS
        window.__pwaInstall?.installed === true
    );
}

export function usePwaInstall() {
    const isInstalled = ref(detectInstalled());
    const isIos = detectIosSafari();

    // Queda en true aunque el prompt ya se haya consumido (sirve una sola vez),
    // para caer en las instrucciones manuales en vez de dejar el botón muerto.
    const offered = ref(!!window.__pwaInstall?.prompt);

    const canInstall = computed(() => !isInstalled.value && (offered.value || isIos));

    const onInstallable = () => (offered.value = true);
    const onInstalled = () => (isInstalled.value = true);
    const onDisplayModeChange = (e) => (isInstalled.value = e.matches);
    const mq = window.matchMedia?.(STANDALONE);

    onMounted(() => {
        window.addEventListener('pwa:installable', onInstallable);
        window.addEventListener('pwa:installed', onInstalled);
        mq?.addEventListener?.('change', onDisplayModeChange);
    });
    onUnmounted(() => {
        window.removeEventListener('pwa:installable', onInstallable);
        window.removeEventListener('pwa:installed', onInstalled);
        mq?.removeEventListener?.('change', onDisplayModeChange);
    });

    /** @returns {Promise<'accepted'|'dismissed'|'manual'>} */
    async function install() {
        const deferred = window.__pwaInstall?.prompt;
        if (!deferred) return 'manual';

        window.__pwaInstall.prompt = null;
        deferred.prompt();
        const { outcome } = await deferred.userChoice;
        return outcome;
    }

    return { canInstall, isInstalled, isIos, install };
}
