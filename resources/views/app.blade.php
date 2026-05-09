<!DOCTYPE html>
@php
    $userTheme = auth()->check() ? (auth()->user()->getSetting('theme') ?? 'light') : null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $userTheme === 'dark' ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/x-icon" href="/images/logoicono.ico">
        <link rel="icon" type="image/png" href="/images/logoicono.png">

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
