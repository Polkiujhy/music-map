@extends('layouts.app')

@section('title', 'Integracje')

@section('content')
    <section aria-labelledby="integrations-heading" class="mx-auto max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Ustawienia</p>
        <h1 id="integrations-heading" class="mt-3 text-4xl font-semibold tracking-tight sm:text-5xl">Integracje streamingowe</h1>
        <p class="mt-3 text-lg leading-8 text-ash-grey-200/70">Połącz konto Spotify lub wybrany kanał YouTube.</p>

        @if (session('status'))
            <div role="status" class="mt-8 rounded-xl border border-ash-grey-600 bg-ash-grey-900/70 px-4 py-3 text-ash-grey-100">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div role="alert" class="mt-8 rounded-xl border border-red-800 bg-red-950/30 px-4 py-3 text-red-200">{{ session('error') }}</div>
        @endif

        <div class="mt-10 flex flex-wrap gap-4">
            @foreach ([\App\Enums\StreamingProvider::Spotify, \App\Enums\StreamingProvider::YouTube] as $provider)
                @php($account = $accounts->get($provider->value))
                <form method="POST" action="{{ route('integrations.connect', $provider->value) }}">
                    @csrf
                    <button type="submit" class="primary-button">
                        {{ $account ? 'Połącz ponownie' : 'Połącz' }} {{ $provider === \App\Enums\StreamingProvider::Spotify ? 'Spotify' : 'YouTube' }}
                    </button>
                </form>

                @if ($account)
                    <form method="POST" action="{{ route('integrations.accounts.verify', $account) }}">
                        @csrf
                        <button type="submit" class="secondary-button">Sprawdź połączenie</button>
                    </form>
                @endif
            @endforeach
        </div>
    </section>
@endsection
