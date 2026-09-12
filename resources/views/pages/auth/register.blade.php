<x-layouts.auth>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Utwórz konto</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Twoje playlisty będą widoczne tylko dla Ciebie.</p>
        </div>

        <a href="{{ route('auth.google.redirect') }}" class="flex w-full items-center justify-center rounded-lg border border-zinc-300 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-900 shadow-sm outline-offset-2 transition hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-indigo-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-800">Kontynuuj przez Google</a>
        <p class="text-center text-xs text-zinc-500 dark:text-zinc-400">Jeśli masz już konto z tym samym zweryfikowanym adresem e-mail, połączymy metody logowania.</p>

        <x-input-error id="google-error" :messages="$errors->get('google')" />

        <div class="flex items-center gap-3" aria-hidden="true">
            <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
            <span class="text-xs uppercase tracking-wider text-zinc-500">lub e-mail</span>
            <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
        </div>

        <form method="POST" action="{{ route('register.store') }}" class="space-y-5">
            @csrf
            <div>
                <label for="name" class="field-label">Nazwa</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name" aria-describedby="name-error" class="field-input" />
                <x-input-error id="name-error" :messages="$errors->get('name')" />
            </div>
            <div>
                <label for="email" class="field-label">Adres e-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" aria-describedby="email-error" class="field-input" />
                <x-input-error id="email-error" :messages="$errors->get('email')" />
            </div>
            <div>
                <label for="password" class="field-label">Hasło</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" aria-describedby="password-error" class="field-input" />
                <x-input-error id="password-error" :messages="$errors->get('password')" />
            </div>
            <div>
                <label for="password_confirmation" class="field-label">Powtórz hasło</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="field-input" />
            </div>
            <button type="submit" class="primary-button w-full">Załóż konto</button>
        </form>

        <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">Masz już konto? <a href="{{ route('login') }}" class="font-medium text-indigo-700 underline underline-offset-4 outline-offset-4 focus-visible:outline-2 focus-visible:outline-indigo-600 dark:text-indigo-300">Zaloguj się</a>.</p>
    </div>
</x-layouts.auth>
