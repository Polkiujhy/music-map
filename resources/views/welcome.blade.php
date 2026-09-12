<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Music Map to niezależny, prywatny bank Twoich playlist.">
        <title>Music Map — prywatny bank playlist</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[#171717] text-ash-grey-50 antialiased selection:bg-ash-grey-300 selection:text-ash-grey-950">
        <a href="#main-content" class="skip-link">Przejdź do treści</a>

        <div class="relative isolate min-h-screen overflow-hidden">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 opacity-20 [background-image:linear-gradient(rgba(159,198,170,0.09)_1px,transparent_1px),linear-gradient(90deg,rgba(159,198,170,0.09)_1px,transparent_1px)] [background-size:48px_48px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"></div>

            <header class="mx-auto flex w-full max-w-7xl items-center justify-between px-5 py-6 sm:px-8 lg:px-12">
                <x-app-logo />

                <nav aria-label="Nawigacja konta" class="flex items-center gap-3 sm:gap-5">
                    @auth
                        <a href="{{ route('bank.index') }}" class="rounded-full border border-ash-grey-600 bg-ash-grey-900/60 px-4 py-2.5 text-sm font-semibold text-ash-grey-100 outline-offset-4 transition hover:border-ash-grey-400 hover:bg-ash-grey-800 focus-visible:outline-2 focus-visible:outline-ash-grey-300">Przejdź do banku</a>
                    @else
                        <a href="{{ route('login') }}" class="text-sm font-semibold text-ash-grey-200 underline-offset-4 outline-offset-4 transition hover:text-white hover:underline focus-visible:outline-2 focus-visible:outline-ash-grey-300">Zaloguj się</a>
                        <a href="{{ route('register') }}" class="rounded-full bg-ash-grey-300 px-4 py-2.5 text-sm font-semibold text-ash-grey-950 outline-offset-4 transition hover:bg-ash-grey-200 focus-visible:outline-2 focus-visible:outline-ash-grey-300">Załóż konto</a>
                    @endauth
                </nav>
            </header>

            <main id="main-content">
                <section class="mx-auto grid w-full max-w-7xl items-center gap-14 px-5 pb-20 pt-14 sm:px-8 sm:pt-24 lg:min-h-[calc(100vh-6rem)] lg:grid-cols-[1.08fr_0.92fr] lg:px-12 lg:pb-28 lg:pt-20">
                    <div class="max-w-3xl">
                        <p class="mb-7 flex items-center gap-3 text-sm font-medium tracking-wide text-ash-grey-300">
                            <span aria-hidden="true" class="h-px w-9 bg-ash-grey-500"></span>
                            NIEZALEŻNY BANK PLAYLIST
                        </p>
                        <h1 class="text-balance text-5xl font-semibold leading-[0.96] tracking-[-0.055em] text-white sm:text-7xl lg:text-[5.5rem]">
                            Twoja muzyka.<br>
                            <span class="text-ash-grey-300">Poza platformami.</span>
                        </h1>
                        <p class="mt-8 max-w-2xl text-pretty text-lg leading-8 text-ash-grey-100/75 sm:text-xl">Zachowaj playlisty w jednym prywatnym miejscu. Zmieniaj serwis muzyczny bez układania swojej kolekcji od początku.</p>

                        <div class="mt-10 flex flex-col gap-3 sm:flex-row">
                            @auth
                                <a href="{{ route('bank.index') }}" class="inline-flex items-center justify-center rounded-full bg-ash-grey-300 px-7 py-3.5 text-sm font-semibold text-ash-grey-950 outline-offset-4 transition hover:bg-ash-grey-200 focus-visible:outline-2 focus-visible:outline-ash-grey-300">Otwórz mój bank <span aria-hidden="true" class="ml-2">→</span></a>
                            @else
                                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-full bg-ash-grey-300 px-7 py-3.5 text-sm font-semibold text-ash-grey-950 outline-offset-4 transition hover:bg-ash-grey-200 focus-visible:outline-2 focus-visible:outline-ash-grey-300">Utwórz prywatny bank <span aria-hidden="true" class="ml-2">→</span></a>
                                <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-full border border-ash-grey-700 px-7 py-3.5 text-sm font-semibold text-ash-grey-100 outline-offset-4 transition hover:border-ash-grey-400 hover:bg-ash-grey-900/60 focus-visible:outline-2 focus-visible:outline-ash-grey-300">Mam już konto</a>
                            @endauth
                        </div>

                        <p class="mt-6 flex items-center gap-2 text-sm text-ash-grey-400">
                            <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" class="size-4" stroke="currentColor" stroke-width="1.8">
                                <rect x="4.5" y="8.5" width="11" height="8" rx="2" />
                                <path d="M7 8.5V6a3 3 0 0 1 6 0v2.5" />
                            </svg>
                            Prywatna przestrzeń widoczna tylko dla Ciebie
                        </p>
                    </div>

                    <div class="relative mx-auto aspect-square w-full max-w-lg" aria-hidden="true">
                        <div class="absolute inset-[12%] rounded-full border border-ash-grey-700/60"></div>
                        <div class="absolute inset-[27%] rounded-full border border-ash-grey-600/70"></div>
                        <div class="absolute left-[8%] top-[31%] flex size-16 items-center justify-center rounded-2xl border border-ash-grey-700 bg-[#171717] text-xl text-ash-grey-300 shadow-xl">♫</div>
                        <div class="absolute right-[5%] top-[18%] flex size-12 items-center justify-center rounded-full border border-ash-grey-600 bg-ash-grey-900 text-sm font-semibold text-ash-grey-200">01</div>
                        <div class="absolute bottom-[12%] right-[17%] flex size-20 rotate-6 items-center justify-center rounded-3xl border border-ash-grey-700 bg-[#1d211e] text-2xl text-ash-grey-300 shadow-xl">♪</div>
                        <div class="absolute bottom-[11%] left-[17%] size-3 rounded-full bg-ash-grey-400 shadow-[0_0_24px_rgba(128,179,142,0.8)]"></div>
                        <div class="absolute right-[13%] top-[42%] size-2 rounded-full bg-ash-grey-200"></div>

                        <svg viewBox="0 0 500 500" class="absolute inset-0 size-full text-ash-grey-500/60" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M104 180C159 142 177 236 222 230" />
                            <path d="M286 221C342 197 353 137 405 119" />
                            <path d="M282 281C330 303 339 355 385 374" />
                            <path d="M217 291C180 325 158 368 103 390" />
                        </svg>

                        <div class="absolute left-1/2 top-1/2 flex size-32 -translate-x-1/2 -translate-y-1/2 flex-col items-center justify-center rounded-full bg-ash-grey-300 px-4 text-center text-ash-grey-950 shadow-[0_0_80px_rgba(159,198,170,0.24)] sm:size-36">
                            <span class="block text-xs font-semibold uppercase tracking-[0.18em]">Music Map</span>
                            <span class="mt-1 block text-sm font-medium">Twój bank</span>
                        </div>
                    </div>
                </section>

                <section aria-labelledby="benefits-heading" class="border-t border-ash-grey-900 bg-ash-grey-950/30">
                    <div class="mx-auto w-full max-w-7xl px-5 py-16 sm:px-8 lg:px-12">
                        <h2 id="benefits-heading" class="sr-only">Co daje Music Map</h2>
                        <div class="grid gap-px overflow-hidden rounded-3xl border border-ash-grey-900 bg-ash-grey-900 md:grid-cols-3">
                            <article class="bg-[#171717] p-7 sm:p-9">
                                <p class="text-xs font-semibold tracking-[0.2em] text-ash-grey-500">ZACHOWAJ</p>
                                <h3 class="mt-4 text-xl font-semibold text-ash-grey-50">Jedna kolekcja</h3>
                                <p class="mt-3 leading-7 text-ash-grey-200/65">Niezależna od tego, za którą platformę płacisz w tym miesiącu.</p>
                            </article>
                            <article class="bg-[#171717] p-7 sm:p-9">
                                <p class="text-xs font-semibold tracking-[0.2em] text-ash-grey-500">CHROŃ</p>
                                <h3 class="mt-4 text-xl font-semibold text-ash-grey-50">Prywatny bank</h3>
                                <p class="mt-3 leading-7 text-ash-grey-200/65">Twoje playlisty pozostają dostępne wyłącznie z Twojego konta.</p>
                            </article>
                            <article class="bg-[#171717] p-7 sm:p-9">
                                <p class="text-xs font-semibold tracking-[0.2em] text-ash-grey-500">PRZENOŚ</p>
                                <h3 class="mt-4 text-xl font-semibold text-ash-grey-50">Import już wkrótce</h3>
                                <p class="mt-3 leading-7 text-ash-grey-200/65">W kolejnym etapie dodasz playlistę i przygotujesz ją do przeniesienia.</p>
                            </article>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </body>
</html>
