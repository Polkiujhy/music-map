<x-layouts.auth>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Zaloguj się</h1>
            <p class="mt-2 text-sm text-ash-grey-300">Wejdź do swojego prywatnego banku playlist.</p>
        </div>

        @if (session('status'))
            <p class="rounded-lg bg-ash-grey-900 p-3 text-sm text-ash-grey-200" role="status">{{ session('status') }}</p>
        @endif

        <a href="{{ route('auth.google.redirect') }}" class="oauth-button">Kontynuuj przez Google</a>
        <p class="text-center text-xs text-ash-grey-400">Jeśli masz już konto z tym samym zweryfikowanym adresem e-mail, połączymy metody logowania.</p>

        <x-input-error id="google-error" :messages="$errors->get('google')" />

        <div class="flex items-center gap-3" aria-hidden="true">
            <span class="h-px flex-1 bg-ash-grey-800"></span>
            <span class="text-xs uppercase tracking-wider text-ash-grey-500">lub e-mail</span>
            <span class="h-px flex-1 bg-ash-grey-800"></span>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
            @csrf
            <div>
                <label for="email" class="field-label">Adres e-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" aria-describedby="email-error" class="field-input" />
                <x-input-error id="email-error" :messages="$errors->get('email')" />
            </div>
            <div>
                <div class="flex items-center justify-between gap-4">
                    <label for="password" class="field-label">Hasło</label>
                    <a href="{{ route('password.request') }}" class="auth-link text-sm">Nie pamiętasz hasła?</a>
                </div>
                <input id="password" name="password" type="password" required autocomplete="current-password" aria-describedby="password-error" class="field-input" />
                <x-input-error id="password-error" :messages="$errors->get('password')" />
            </div>
            <label class="flex items-center gap-3 text-sm text-ash-grey-200">
                <input name="remember" type="checkbox" value="1" class="size-4 rounded border-ash-grey-700 bg-[#171717] text-ash-grey-400 focus:ring-ash-grey-400" />
                Zapamiętaj mnie na tym urządzeniu
            </label>
            <button type="submit" class="primary-button w-full">Zaloguj się</button>
        </form>

        <p class="text-center text-sm text-ash-grey-300">Nie masz konta? <a href="{{ route('register') }}" class="auth-link">Zarejestruj się</a>.</p>
    </div>
</x-layouts.auth>
