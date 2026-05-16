<template>
    <img src="/images/logo.png" alt="Escríbelo" class="app-logo" />
</template>

<style>
/* En modo oscuro suavizamos los bordes del logo con una máscara radial que se
 * desvanece a transparente. El recorte abrupto entre el blanco del PNG y el
 * gris oscuro del navbar deja de notarse. En modo claro la máscara no se aplica. */
html.dark .app-logo {
    -webkit-mask-image:
        linear-gradient(to right, transparent 0%, black 7%, black 93%, transparent 100%),
        linear-gradient(to bottom, transparent 0%, black 10%, black 90%, transparent 100%);
    mask-image:
        linear-gradient(to right, transparent 0%, black 7%, black 93%, transparent 100%),
        linear-gradient(to bottom, transparent 0%, black 10%, black 90%, transparent 100%);
    -webkit-mask-composite: source-in;
    mask-composite: intersect;
    -webkit-mask-repeat: no-repeat;
    mask-repeat: no-repeat;
}
</style>
