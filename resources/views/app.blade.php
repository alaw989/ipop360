<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="no-js">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <!-- Progressive enhancement: swap no-js → js as early as possible so
             JS-gated styles (e.g. the homepage .scroll-reveal hidden state) only
             apply when JavaScript is actually running. Without JS the sections
             render fully visible instead of being stuck at opacity:0. -->
        <script>document.documentElement.classList.replace('no-js', 'js')</script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicon -->
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
        <link rel="apple-touch-icon" href="/img/apple-touch-icon.png" />

        <!-- Added to a phone's home screen, the site opens full screen with
             its own icon, like an app. -->
        <link rel="manifest" href="/manifest.json" />
        <meta name="apple-mobile-web-app-title" content="iPop360" />

        <!-- Theme color for mobile browser chrome. The site always renders its
             light theme (nothing sets .dark), so the chrome matches it on a
             dark-mode phone too. -->
        <meta name="theme-color" content="#ffffff" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
