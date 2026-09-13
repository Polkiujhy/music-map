@extends('layouts.app')

@section('title', 'Integracje')

@section('content')
    <section aria-labelledby="integrations-heading" class="mx-auto max-w-5xl">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Ustawienia</p>
        <h1 id="integrations-heading" class="mt-3 text-4xl font-semibold tracking-tight sm:text-5xl">Integracje streamingowe</h1>
        <p class="mt-3 max-w-3xl text-lg leading-8 text-ash-grey-200/70">Zarządzaj kontem Spotify i kanałem YouTube używanymi przez Music Map.</p>

        @if (session('status'))
            <div role="status" class="mt-8 rounded-xl border border-ash-grey-600 bg-ash-grey-900/70 px-4 py-3 text-ash-grey-100">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div role="alert" class="mt-8 rounded-xl border border-red-800 bg-red-950/30 px-4 py-3 text-red-200">{{ session('error') }}</div>
        @endif

        @if ($errors->has('confirm_disconnect'))
            <div role="alert" class="mt-8 rounded-xl border border-red-800 bg-red-950/30 px-4 py-3 text-red-200">Potwierdź odłączenie konta i spróbuj ponownie.</div>
        @endif

        <div class="mt-10 grid gap-6 lg:grid-cols-2">
            @foreach ([\App\Enums\StreamingProvider::Spotify, \App\Enums\StreamingProvider::YouTube] as $provider)
                @php
                    $account = $accounts->get($provider->value);
                    $providerName = $provider === \App\Enums\StreamingProvider::Spotify ? 'Spotify' : 'YouTube';
                    $connected = $account?->connectionState() === \App\Models\StreamingAccount::STATE_CONNECTED;
                    $modalName = 'disconnect-'.$provider->value;
                @endphp

                <article class="flex min-h-80 flex-col rounded-3xl border border-ash-grey-900 bg-[#1d211e] p-6 shadow-xl shadow-black/10 sm:p-8" aria-labelledby="{{ $provider->value }}-heading">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Platforma</p>
                            <h2 id="{{ $provider->value }}-heading" class="mt-2 text-2xl font-semibold">{{ $providerName }}</h2>
                        </div>
                        <span class="rounded-full border border-ash-grey-700 px-3 py-1 text-xs font-semibold text-ash-grey-200">
                            @if (! $account)
                                Niepołączone
                            @elseif ($connected)
                                Połączone
                            @else
                                Wymaga ponownego połączenia
                            @endif
                        </span>
                    </div>

                    <div class="mt-7 flex-1">
                        @if (! $account)
                            <p class="leading-7 text-ash-grey-200/70">Połącz {{ $providerName }}, aby udostępnić Music Map funkcje wymagające Twojej zgody.</p>
                        @else
                            <p class="text-sm text-ash-grey-400">Połączone jako</p>
                            <p class="mt-1 text-lg font-semibold">{{ $account->label ?: 'Połączone konto' }}</p>
                            @if (! $connected)
                                <p class="mt-4 leading-7 text-amber-200/80">Dostęp wygasł lub został cofnięty. Połącz to samo konto ponownie, aby przywrócić funkcje integracji.</p>
                            @endif
                        @endif
                    </div>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('integrations.connect', $provider->value) }}">
                            @csrf
                            <button type="submit" class="primary-button">{{ $account ? 'Połącz ponownie' : 'Połącz' }} {{ $providerName }}</button>
                        </form>

                        @if ($connected)
                            <form method="POST" action="{{ route('integrations.accounts.verify', $account) }}">
                                @csrf
                                <button type="submit" class="secondary-button">Sprawdź połączenie</button>
                            </form>
                        @endif

                        @if ($account)
                            <flux:modal.trigger :name="$modalName">
                                <button type="button" class="danger-button">Odłącz</button>
                            </flux:modal.trigger>
                        @endif
                    </div>
                </article>

                @if ($account)
                    <flux:modal :name="$modalName" class="max-w-lg">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Odłącz {{ $providerName }}</p>
                            <h2 class="mt-3 text-2xl font-semibold">Czy na pewno odłączyć konto?</h2>
                            <p class="mt-4 leading-7 text-ash-grey-200/80">Importy i eksporty wymagające tego konta przestaną działać, a przyszłe synchronizacje zostaną wyłączone. Zapisane playlisty pozostaną w Twoim banku.</p>
                            @if ($provider === \App\Enums\StreamingProvider::Spotify)
                                <p class="mt-3 text-sm leading-6 text-ash-grey-300">Music Map usunie dostęp lokalnie. Dostęp po stronie Spotify trzeba potem cofnąć ręcznie przez <span lang="en">Remove Access</span>.</p>
                            @else
                                <p class="mt-3 text-sm leading-6 text-ash-grey-300">Music Map najpierw usunie dostęp lokalnie, a następnie spróbuje cofnąć grant u Google. Niepowodzenie tej próby nie przywróci połączenia.</p>
                            @endif

                            <div class="mt-7 flex flex-wrap justify-end gap-3">
                                <flux:modal.close>
                                    <button type="button" class="secondary-button">Anuluj</button>
                                </flux:modal.close>
                                <form method="POST" action="{{ route('integrations.destroy', $account) }}">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="confirm_disconnect" value="yes">
                                    <button type="submit" class="danger-button">Odłącz konto</button>
                                </form>
                            </div>
                        </div>
                    </flux:modal>
                @endif
            @endforeach
        </div>
    </section>
@endsection
