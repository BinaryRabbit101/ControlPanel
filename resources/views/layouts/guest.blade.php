<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#0f172a">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="Control Panel">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased" style="background-color: var(--ink); color: var(--mist)">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-10 sm:pt-0 px-6">
            {{-- The shared estate sign-in header: the app's own mark at 64px, its
                 name, then one line of purpose. This was the stock Laravel logo in
                 grey, which told you nothing about which app was asking. --}}
            <a href="/" class="flex flex-col items-center text-center">
                <img src="/apple-touch-icon.png" alt="" width="64" height="64" class="w-16 h-16 rounded-2xl" />
                <span class="mt-4 text-2xl font-semibold" style="color: var(--mist)">Control Panel</span>
            </a>
            <p class="mt-1 text-sm" style="color: var(--muted)">Sign in to run the estate.</p>

            <div class="w-full sm:max-w-md mt-8 px-6 py-6 overflow-hidden rounded-xl"
                 style="background-color: var(--slate); border: 1px solid var(--line)">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
