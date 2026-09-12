<x-layouts.auth>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Zaloguj się</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Wejdź do swojego prywatnego banku playlist.</p>
        </div>

        @if (session('status'))
            <p class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200" role="status">{{ session('status') }}</p>
        @endif

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
                    <a href="{{ route('password.request') }}" class="text-sm font-medium text-indigo-700 underline underline-offset-4 outline-offset-4 focus-visible:outline-2 focus-visible:outline-indigo-600 dark:text-indigo-300">Nie pamiętasz hasła?</a>
                </div>
                <input id="password" name="password" type="password" required autocomplete="current-password" aria-describedby="password-error" class="field-input" />
                <x-input-error id="password-error" :messages="$errors->get('password')" />
            </div>
            <label class="flex items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                <input name="remember" type="checkbox" value="1" class="size-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600" />
                Zapamiętaj mnie na tym urządzeniu
            </label>
            <button type="submit" class="primary-button w-full">Zaloguj się</button>
        </form>

        <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">Nie masz konta? <a href="{{ route('register') }}" class="font-medium text-indigo-700 underline underline-offset-4 outline-offset-4 focus-visible:outline-2 focus-visible:outline-indigo-600 dark:text-indigo-300">Zarejestruj się</a>.</p>
    </div>
</x-layouts.auth>
