<x-layouts.auth>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Potwierdź adres e-mail</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Wysłaliśmy link weryfikacyjny na adres przypisany do konta.</p>
        </div>

        @if (session('status') === 'verification-link-sent')
            <p class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200" role="status">Wysłaliśmy nowy link weryfikacyjny.</p>
        @endif

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="primary-button w-full">Wyślij link ponownie</button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <button type="submit" class="text-sm font-medium text-indigo-700 underline underline-offset-4 outline-offset-4 focus-visible:outline-2 focus-visible:outline-indigo-600 dark:text-indigo-300">Wyloguj się</button>
        </form>
    </div>
</x-layouts.auth>
