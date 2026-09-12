<header class="border-b border-ash-grey-900 bg-[#171717]/95">
    <div class="mx-auto flex w-full max-w-7xl items-center justify-between gap-5 px-5 py-4 sm:px-8 lg:px-12">
        <x-app-logo />

        <nav aria-label="Nawigacja użytkownika" class="flex items-center gap-3 sm:gap-5">
            <div class="hidden text-right sm:block">
                <p class="text-sm font-semibold text-ash-grey-50">{{ auth()->user()->name }}</p>
                <p class="text-xs text-ash-grey-400">{{ auth()->user()->email }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="secondary-button">Wyloguj się</button>
            </form>
        </nav>
    </div>
</header>
