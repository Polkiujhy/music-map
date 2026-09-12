<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <a href="#main-content" class="skip-link">Przejdź do treści</a>

        <main id="main-content" class="mx-auto flex min-h-screen w-full max-w-md items-center px-5 py-10">
            <section class="w-full rounded-2xl bg-white p-7 shadow-sm ring-1 ring-zinc-950/5 dark:bg-zinc-900 dark:ring-white/10 sm:p-9">
                <x-auth-header />

                {{ $slot }}
            </section>
        </main>

        @fluxScripts
    </body>
</html>
