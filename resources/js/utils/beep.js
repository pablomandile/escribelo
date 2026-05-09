// Beep corto usando WebAudio. Sin assets de audio.
// Se reusa el mismo AudioContext entre llamadas para no acumular instancias.

let ctx = null;
let primedListenerAttached = false;

function getCtx() {
    if (typeof window === 'undefined') {
        return null;
    }
    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (! Ctor) {
        return null;
    }
    if (! ctx) {
        ctx = new Ctor();
    }
    return ctx;
}

// Los navegadores requieren un gesto de usuario para activar el AudioContext.
// Si el usuario llega al dashboard, sube un archivo (gesto), y se va a otra
// pestaña a esperar, el contexto se queda en "suspended" y el beep posterior
// no suena. Esto engancha un listener global one-shot que crea y resume el
// contexto al primer click/keydown/touch para que esté listo cuando llegue
// el momento del beep.
export function primeBeepOnFirstGesture() {
    if (primedListenerAttached || typeof document === 'undefined') {
        return;
    }
    primedListenerAttached = true;

    const onGesture = () => {
        const c = getCtx();
        if (c && c.state === 'suspended') {
            c.resume().catch(() => {});
        }
        document.removeEventListener('pointerdown', onGesture);
        document.removeEventListener('keydown', onGesture);
        document.removeEventListener('touchstart', onGesture);
    };

    document.addEventListener('pointerdown', onGesture, { once: false, passive: true });
    document.addEventListener('keydown', onGesture, { once: false, passive: true });
    document.addEventListener('touchstart', onGesture, { once: false, passive: true });
}

export function playBeep({ frequency = 880, duration = 160, volume = 0.18 } = {}) {
    try {
        const c = getCtx();
        if (! c) {
            return;
        }
        if (c.state === 'suspended') {
            // Intento best-effort. Si el navegador no nos da permiso, salimos en silencio.
            c.resume().catch(() => {});
        }

        const now = c.currentTime;
        const osc = c.createOscillator();
        const gain = c.createGain();

        osc.type = 'sine';
        osc.frequency.value = frequency;

        // Envolvente con fade-in y fade-out cortos para que suene "limpio" y no clack.
        gain.gain.setValueAtTime(0, now);
        gain.gain.linearRampToValueAtTime(volume, now + 0.01);
        gain.gain.linearRampToValueAtTime(0, now + duration / 1000);

        osc.connect(gain).connect(c.destination);
        osc.start(now);
        osc.stop(now + duration / 1000 + 0.02);
    } catch (e) {
        // Silenciar — el beep es nice-to-have, no debe romper la app.
    }
}
