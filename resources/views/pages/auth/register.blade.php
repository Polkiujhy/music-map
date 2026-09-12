<x-layouts.auth>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Utwórz konto</h1>
            <p class="mt-2 text-sm text-ash-grey-300">Twoje playlisty będą widoczne tylko dla Ciebie.</p>
        </div>

        <a href="{{ route('auth.google.redirect') }}" class="oauth-button">Kontynuuj przez Google</a>
        <p class="text-center text-xs text-ash-grey-400">Jeśli masz już konto z tym samym zweryfikowanym adresem e-mail, połączymy metody logowania.</p>

        <x-input-error id="google-error" :messages="$errors->get('google')" />

        <div class="flex items-center gap-3" aria-hidden="true">
            <span class="h-px flex-1 bg-ash-grey-800"></span>
            <span class="text-xs uppercase tracking-wider text-ash-grey-500">lub e-mail</span>
            <span class="h-px flex-1 bg-ash-grey-800"></span>
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

        <p class="text-center text-sm text-ash-grey-300">Masz już konto? <a href="{{ route('login') }}" class="auth-link">Zaloguj się</a>.</p>
    </div>
</x-layouts.auth>
