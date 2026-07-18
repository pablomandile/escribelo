<script setup>
// Dos versiones del logo: una para modo claro y otra para modo oscuro.
// Se muestran/ocultan por CSS según la clase `dark` en <html>.
// inheritAttrs:false + v-bind="$attrs" hace que las clases de tamaño que pasa
// el componente padre (ej. h-20 w-auto) se apliquen a AMBAS imágenes.
defineOptions({ inheritAttrs: false });
</script>

<template>
    <img
        v-bind="$attrs"
        src="/images/logo.png"
        alt="Escríbelo"
        class="app-logo app-logo--light"
    />
    <img
        v-bind="$attrs"
        src="/images/logo-oscuro.png"
        alt="Escríbelo"
        class="app-logo app-logo--dark"
    />
</template>

<style>
/* Modo claro (por defecto): se ve el logo claro, se oculta el oscuro. */
.app-logo--dark {
    display: none;
}

/* Modo oscuro: se invierten. */
html.dark .app-logo--light {
    display: none;
}

html.dark .app-logo--dark {
    display: initial;
}
</style>
