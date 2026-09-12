<x-layouts.auth>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Potwierdź adres e-mail</h1>
            <p class="mt-2 text-sm text-ash-grey-300">Wysłaliśmy link weryfikacyjny na adres przypisany do konta.</p>
        </div>

        @if (session('status') === 'verification-link-sent')
            <p class="rounded-lg bg-ash-grey-900 p-3 text-sm text-ash-grey-200" role="status">Wysłaliśmy nowy link weryfikacyjny.</p>
        @endif

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="primary-button w-full">Wyślij link ponownie</button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <button type="submit" class="auth-link text-sm">Wyloguj się</button>
        </form>
    </div>
</x-layouts.auth>
