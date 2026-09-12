<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Twój prywatny bank playlist w Music Map.">
        <title>@yield('title', 'Twój bank') — Music Map</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-[#171717] text-ash-grey-50 antialiased selection:bg-ash-grey-300 selection:text-ash-grey-950">
        <a href="#main-content" class="skip-link">Przejdź do treści</a>
        <x-app-navigation />

        <main id="main-content" class="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8 sm:py-14 lg:px-12">
            @yield('content')
        </main>

        @fluxScripts
    </body>
</html>
