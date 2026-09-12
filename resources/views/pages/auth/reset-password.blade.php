<x-layouts.auth>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Ustaw nowe hasło</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Wybierz nowe, bezpieczne hasło do konta.</p>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf
            <input name="token" type="hidden" value="{{ $request->route('token') }}" />
            <div>
                <label for="email" class="field-label">Adres e-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="email" aria-describedby="email-error" class="field-input" />
                <x-input-error id="email-error" :messages="$errors->get('email')" />
            </div>
            <div>
                <label for="password" class="field-label">Nowe hasło</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" aria-describedby="password-error" class="field-input" />
                <x-input-error id="password-error" :messages="$errors->get('password')" />
            </div>
            <div>
                <label for="password_confirmation" class="field-label">Powtórz nowe hasło</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="field-input" />
            </div>
            <button type="submit" class="primary-button w-full">Zapisz nowe hasło</button>
        </form>
    </div>
</x-layouts.auth>
