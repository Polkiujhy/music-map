<x-layouts.auth>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Odzyskaj dostęp</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Wyślemy instrukcję zmiany hasła, jeśli konto używa tego adresu.</p>
        </div>

        @if (session('status'))
            <p class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200" role="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
            @csrf
            <div>
                <label for="email" class="field-label">Adres e-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" aria-describedby="email-error" class="field-input" />
                <x-input-error id="email-error" :messages="$errors->get('email')" />
            </div>
            <button type="submit" class="primary-button w-full">Wyślij link do resetu</button>
        </form>

        <p class="text-center text-sm"><a href="{{ route('login') }}" class="font-medium text-indigo-700 underline underline-offset-4 outline-offset-4 focus-visible:outline-2 focus-visible:outline-indigo-600 dark:text-indigo-300">Wróć do logowania</a></p>
    </div>
</x-layouts.auth>
