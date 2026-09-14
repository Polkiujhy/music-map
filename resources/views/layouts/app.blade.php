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
        @auth
            <x-app-navigation />
        @else
            <header class="border-b border-ash-grey-900 bg-[#171717]/95">
                <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-5 py-4 sm:px-8 lg:px-12">
                    <x-app-logo />
                    <a href="{{ route('login') }}" class="auth-link">Zaloguj się</a>
                </div>
            </header>
        @endauth

        <main id="main-content" class="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8 sm:py-14 lg:px-12">
            @yield('content')
        </main>

        <footer class="border-t border-ash-grey-900">
            <nav aria-label="Dokumenty prawne" class="mx-auto flex w-full max-w-7xl flex-wrap gap-5 px-5 py-6 text-sm sm:px-8 lg:px-12">
                <a href="{{ route('legal.terms') }}" class="auth-link">Warunki korzystania</a>
                <a href="{{ route('legal.privacy') }}" class="auth-link">Polityka prywatności</a>
            </nav>
        </footer>

        @fluxScripts
    </body>
</html>
