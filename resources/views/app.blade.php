<!DOCTYPE html>
@php
    $userTheme = auth()->check() ? (auth()->user()->getSetting('theme') ?? 'light') : null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $userTheme === 'dark' ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <script>
            // Chrome dispara beforeinstallprompt apenas carga la página, antes de que
            // monte Vue: si se escucha desde un componente el evento ya pasó. Se guarda
            // acá y usePwaInstall.js lo lee.
            (function () {
                window.__pwaInstall = { prompt: null, installed: false };

                window.addEventListener('beforeinstallprompt', function (e) {
                    e.preventDefault();
                    window.__pwaInstall.prompt = e;
                    window.dispatchEvent(new CustomEvent('pwa:installable'));
                });

                window.addEventListener('appinstalled', function () {
                    window.__pwaInstall.prompt = null;
                    window.__pwaInstall.installed = true;
                    window.dispatchEvent(new CustomEvent('pwa:installed'));
                });
            })();
        </script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- PWA -->
        <meta name="theme-color" content="#ffffff">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="Escríbelo">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png?v=1">

        <link rel="icon" type="image/x-icon" href="/images/icono-escribelo.ico?v=2">
        <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png?v=1">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600|inter:400,500,600,700,800&display=swap" rel="stylesheet" />

        <script>
            // Pre-paint theme apply: usuarios autenticados ya viene desde server,
            // pero para guests (login/register) leemos de localStorage para evitar flash.
            (function () {
                try {
                    var saved = localStorage.getItem('theme');
                    var serverHasClass = document.documentElement.classList.contains('dark');
                    if (saved === 'dark' && !serverHasClass) {
                        document.documentElement.classList.add('dark');
                    } else if (saved === 'light' && serverHasClass) {
                        document.documentElement.classList.remove('dark');
                    }
                } catch (e) {}
            })();
        </script>

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-gray-100 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
        @inertia
    </body>
</html>
