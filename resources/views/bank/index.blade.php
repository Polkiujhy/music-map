@extends('layouts.app')

@section('title', 'Twój bank')

@section('content')
    <section aria-labelledby="bank-heading">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Prywatna przestrzeń</p>
        <div class="mt-3 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <h1 id="bank-heading" class="text-4xl font-semibold tracking-tight sm:text-5xl">Twój bank playlist</h1>
                <p class="mt-3 max-w-2xl text-lg leading-8 text-ash-grey-200/70">Witaj, {{ auth()->user()->name }}. Zapisane playlisty są dostępne wyłącznie z Twojego konta.</p>
            </div>
            <span class="inline-flex w-fit items-center gap-2 rounded-full bg-ash-grey-900 px-3 py-1.5 text-sm font-semibold text-ash-grey-200">Widoczne tylko dla Ciebie</span>
        </div>

        @if (session('status'))
            <div role="status" class="mt-8 rounded-xl border border-ash-grey-600 bg-ash-grey-900/70 px-4 py-3 text-ash-grey-100">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div role="alert" class="mt-8 rounded-xl border border-red-800 bg-red-950/30 px-4 py-3 text-red-200">{{ session('error') }}</div>
        @endif

        <section aria-labelledby="import-heading" class="mt-10 rounded-3xl border border-ash-grey-900 bg-[#1d211e] p-6 shadow-2xl shadow-black/20 sm:p-8">
            <h2 id="import-heading" class="text-2xl font-semibold">Importuj playlistę</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-ash-grey-200/70">Wklej publiczny link do playlisty YouTube. Spotify będzie wymagać połączenia konta.</p>

            <form method="POST" action="{{ route('playlists.import') }}" class="mt-6 max-w-3xl space-y-5">
                @csrf
                <div>
                    <label for="playlist_url" class="field-label">Link do playlisty</label>
                    <input id="playlist_url" name="playlist_url" type="url" maxlength="512" required autocomplete="off" class="field-input" placeholder="https://www.youtube.com/playlist?list=…" value="{{ old('playlist_url') }}" aria-describedby="playlist-url-error">
                    <x-input-error :messages="$errors->get('playlist_url')" id="playlist-url-error" />
                </div>

                <div>
                    <label class="flex items-start gap-3 text-sm leading-6 text-ash-grey-200">
                        <input name="policy_consent" type="checkbox" value="1" class="mt-1 size-4 rounded border-ash-grey-700 bg-[#171717] text-ash-grey-400 focus:ring-ash-grey-400" @checked(old('policy_consent'))>
                        <span>Akceptuję <a href="{{ route('legal.terms') }}" class="auth-link">Warunki korzystania</a> i <a href="{{ route('legal.privacy') }}" class="auth-link">Politykę prywatności</a> dla tego importu.</span>
                    </label>
                    <x-input-error :messages="$errors->get('policy_consent')" />
                </div>

                <button type="submit" class="primary-button">Importuj playlistę</button>
            </form>
        </section>

        @if ($playlists->isEmpty())
            <div class="mt-10 overflow-hidden rounded-3xl border border-ash-grey-900 bg-[#1d211e] shadow-2xl shadow-black/20">
                <div class="grid min-h-72 place-items-center px-6 py-12 text-center sm:px-12">
                    <div class="max-w-lg">
                        <div aria-hidden="true" class="mx-auto flex size-20 items-center justify-center rounded-3xl bg-ash-grey-900 text-4xl text-ash-grey-300">♫</div>
                        <h2 class="mt-7 text-2xl font-semibold tracking-tight">Bank czeka na pierwszą playlistę</h2>
                        <p class="mt-3 leading-7 text-ash-grey-200/70">Każda zapisana tu playlista będzie prywatna i dostępna wyłącznie z Twojego konta.</p>
                    </div>
                </div>
            </div>
        @else
            <section aria-labelledby="saved-heading" class="mt-10">
                <h2 id="saved-heading" class="text-2xl font-semibold">Zapisane playlisty</h2>
                <div class="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($playlists as $playlist)
                        @php
                            $youtubeExpired = $playlist->source_provider === \App\Enums\StreamingProvider::YouTube
                                && $playlist->provider_metadata_refreshed_at->lte(now()->subDays(30));
                        @endphp
                        <article class="rounded-2xl border border-ash-grey-900 bg-[#1d211e] p-6">
                            @if ($playlist->source_provider === \App\Enums\StreamingProvider::YouTube)
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Źródło: YouTube</p>
                            @else
                                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-ash-grey-400">
                                    <span>Źródło:</span>
                                    <a href="{{ $playlist->canonical_source_url }}" rel="noreferrer noopener" aria-label="Otwórz playlistę w Spotify" class="-m-3 mt-1 inline-flex p-3">
                                        <img src="{{ asset('images/spotify-full-logo-white.svg') }}" alt="Spotify" width="88" height="24" class="h-auto w-[88px]">
                                    </a>
                                </div>
                            @endif
                            @if ($youtubeExpired)
                                <h3 class="mt-3 text-xl font-semibold">Playlista YouTube — dane wymagają odświeżenia</h3>
                                <p class="mt-3 text-sm leading-6 text-ash-grey-200/70">Dane z YouTube wygasły i nie są teraz wyświetlane. Wklej link ponownie w formularzu powyżej, aby odzyskać aktualny snapshot.</p>
                            @else
                                <h3 class="mt-3 text-xl font-semibold">{{ $playlist->name ?? ($playlist->source_provider === \App\Enums\StreamingProvider::YouTube ? 'Playlista YouTube — dane wymagają odświeżenia' : 'Playlista Spotify — dane wymagają odświeżenia') }}</h3>
                                @if ($playlist->description)
                                    <p class="mt-2 line-clamp-3 text-sm leading-6 text-ash-grey-200/70">{{ $playlist->description }}</p>
                                @endif
                                <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
                                    <div><dt class="text-ash-grey-400">Pozycje</dt><dd class="mt-1 font-semibold">{{ $playlist->items_count }}</dd></div>
                                    <div><dt class="text-ash-grey-400">Niedostępne</dt><dd class="mt-1 font-semibold">{{ $playlist->unavailable_items_count }}</dd></div>
                                    <div class="col-span-2"><dt class="text-ash-grey-400">Dane odświeżone</dt><dd class="mt-1 font-semibold"><time datetime="{{ $playlist->provider_metadata_refreshed_at->toIso8601String() }}">{{ $playlist->provider_metadata_refreshed_at->format('Y-m-d H:i') }}</time></dd></div>
                                </dl>
                            @endif
                            <div class="mt-5 flex flex-wrap gap-4">
                                <a href="{{ $playlist->canonical_source_url }}" rel="noreferrer noopener" class="auth-link">Otwórz źródło</a>
                                <a href="{{ route('bank.playlists.edit', $playlist) }}" class="auth-link">Przeglądaj i edytuj</a>
                            </div>

                            @php
                                $unlinkedActiveOperations = $playlist->activeExportOperations->filter(fn ($operation) =>
                                    $operation->playlist_export_link_id === null
                                );
                            @endphp
                            @if ($unlinkedActiveOperations->isNotEmpty())
                                <section aria-labelledby="active-exports-heading-{{ $playlist->id }}" class="mt-6 border-t border-ash-grey-900 pt-5">
                                    <h4 id="active-exports-heading-{{ $playlist->id }}" class="font-semibold">Aktywne eksporty</h4>
                                    <div class="mt-3 space-y-3">
                                        @foreach ($unlinkedActiveOperations as $operation)
                                            <article class="rounded-xl border border-ash-grey-800 p-4">
                                                <p class="font-semibold">{{ $operation->target_provider === \App\Enums\StreamingProvider::Spotify ? 'Spotify' : 'YouTube' }}</p>
                                                <p class="mt-1 text-xs leading-5 text-ash-grey-200/70">{{ $operation->destination_type === \App\Enums\ExportDestinationType::Linked ? 'Twoje połączone konto' : 'Konto zarządzane przez music-map' }}</p>
                                                <a href="{{ route('export-operations.show', [$playlist, $operation->operation_id]) }}" class="auth-link mt-3 inline-block">Otwórz aktywny eksport</a>
                                            </article>
                                        @endforeach
                                    </div>
                                </section>
                            @endif

                            @if ($playlist->exportLinks->isNotEmpty())
                                <section aria-labelledby="copies-heading-{{ $playlist->id }}" class="mt-6 border-t border-ash-grey-900 pt-5">
                                    <h4 id="copies-heading-{{ $playlist->id }}" class="font-semibold">Powiązane kopie</h4>
                                    <div class="mt-3 space-y-3">
                                        @foreach ($playlist->exportLinks as $link)
                                            @php
                                                $target = $link->targetPlaylist;
                                                $latestOperation = $link->latestOperation;
                                                $targetUrl = $target === null
                                                    ? null
                                                    : \App\Integrations\PlaylistExport\ProviderPlaylistUrl::fromId($link->provider, $target->source_playlist_id);
                                                $copyStatus = match (true) {
                                                    $latestOperation === null => 'Brak zakończonej operacji',
                                                    in_array($latestOperation->status, [\App\Enums\ExportOperationStatus::Queued, \App\Enums\ExportOperationStatus::Processing], true) => 'W trakcie przenoszenia',
                                                    $latestOperation->status === \App\Enums\ExportOperationStatus::Transferred && $link->destination_type === \App\Enums\ExportDestinationType::Linked => 'Przeniesiona — na Twoim koncie',
                                                    $latestOperation->status === \App\Enums\ExportOperationStatus::Transferred => 'Przeniesiona — zarządzana przez music-map',
                                                    $latestOperation->status === \App\Enums\ExportOperationStatus::Incomplete => 'Nie udało się dokończyć przenoszenia',
                                                    default => 'Nie przeniesiono',
                                                };
                                            @endphp
                                            <article class="rounded-xl border border-ash-grey-800 p-4">
                                                <div class="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <p class="font-semibold">{{ $link->provider === \App\Enums\StreamingProvider::Spotify ? 'Spotify' : 'YouTube' }}</p>
                                                        <p class="mt-1 text-xs leading-5 text-ash-grey-200/70">
                                                            {{ $link->destination_type === \App\Enums\ExportDestinationType::Linked ? 'Właściciel: Twoje połączone konto' : 'Właściciel: music-map' }}
                                                        </p>
                                                    </div>
                                                    @if ($link->retired_at !== null)
                                                        <span class="rounded-full bg-ash-grey-900 px-2.5 py-1 text-xs">Historia — cel usunięty</span>
                                                    @endif
                                                </div>
                                                <p class="mt-3 text-sm">{{ $copyStatus }}</p>
                                                <div class="mt-3 flex flex-wrap gap-4">
                                                    @if ($targetUrl !== null)
                                                        <a href="{{ $targetUrl }}" rel="noreferrer noopener" class="auth-link">Otwórz kopię</a>
                                                    @endif
                                                    @if ($latestOperation !== null)
                                                        <a href="{{ route('export-operations.show', [$playlist, $latestOperation->operation_id]) }}" class="auth-link">Otwórz wynik eksportu</a>
                                                    @endif
                                                </div>
                                            </article>
                                        @endforeach
                                    </div>
                                </section>
                            @endif

                            @if (! $youtubeExpired && $playlist->items_count > 0)
                                <section aria-labelledby="export-heading-{{ $playlist->id }}" class="mt-6 border-t border-ash-grey-900 pt-5">
                                    <h4 id="export-heading-{{ $playlist->id }}" class="font-semibold">Przygotuj eksport</h4>
                                    <p class="mt-1 text-sm leading-6 text-ash-grey-200/70">Najpierw sprawdzisz każde dopasowanie. Na tym etapie nic nie zostanie zapisane u providera.</p>

                                    <div class="mt-4 space-y-4">
                                        @foreach ([\App\Enums\StreamingProvider::Spotify, \App\Enums\StreamingProvider::YouTube] as $provider)
                                            @php
                                                $account = $accounts->get($provider->value);
                                                $linked = $account !== null && $account->connectionState() === \App\Models\StreamingAccount::STATE_CONNECTED;
                                                $destinationType = $linked ? \App\Enums\ExportDestinationType::Linked : \App\Enums\ExportDestinationType::Managed;
                                                $targetAccountId = $linked
                                                    ? $account->provider_account_id
                                                    : (string) config("services.platform_access.{$provider->value}.technical.account_id");
                                                $sameSource = $playlist->source_provider === $provider
                                                    && $playlist->source_account_id !== null
                                                    && $targetAccountId !== ''
                                                    && hash_equals($playlist->source_account_id, $targetAccountId);
                                                $activeReview = $playlist->exportReviews->first(fn ($review) =>
                                                    $review->target_provider === $provider
                                                    && $review->destination_type === $destinationType
                                                    && $review->target_account_id === $targetAccountId
                                                    && in_array($review->status, [\App\Enums\ExportReviewStatus::Queued, \App\Enums\ExportReviewStatus::Processing, \App\Enums\ExportReviewStatus::Ready], true)
                                                    && $review->expires_at->isFuture()
                                                );
                                                $retryableReview = $playlist->exportReviews->first(fn ($review) =>
                                                    $review->target_provider === $provider
                                                    && $review->destination_type === $destinationType
                                                    && $review->target_account_id === $targetAccountId
                                                    && ($review->status === \App\Enums\ExportReviewStatus::Failed
                                                        || $review->status === \App\Enums\ExportReviewStatus::Expired
                                                        || $review->expires_at->isPast())
                                                );
                                                $activeOperation = $playlist->activeExportOperations->first(fn ($operation) =>
                                                    $operation->target_provider === $provider
                                                    && $operation->destination_type === $destinationType
                                                    && $operation->target_account_id === $targetAccountId
                                                    && $operation->active_key !== null
                                                );
                                                $providerName = $provider === \App\Enums\StreamingProvider::Spotify ? 'Spotify' : 'YouTube';
                                            @endphp

                                            <div class="rounded-xl border border-ash-grey-800 p-4">
                                                <div class="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p class="font-semibold">{{ $providerName }}</p>
                                                        <p class="mt-1 text-xs leading-5 text-ash-grey-200/70">
                                                            Cel: {{ $linked ? 'Twoje połączone konto — '.($account->label ?: $account->provider_account_id) : 'konto zarządzane przez Music Map' }}
                                                        </p>
                                                    </div>
                                                    <span class="rounded-full bg-ash-grey-900 px-2.5 py-1 text-xs">{{ $linked ? 'linked' : 'managed' }}</span>
                                                </div>

                                                @if ($sameSource)
                                                    <p class="mt-3 text-sm text-ash-grey-400">Niedostępne: źródło i cel to to samo konto {{ $providerName }}.</p>
                                                @elseif ($targetAccountId === '')
                                                    <p class="mt-3 text-sm text-ash-grey-400">Ten cel nie jest obecnie skonfigurowany.</p>
                                                @elseif ($activeOperation)
                                                    @if ($activeOperation->playlist_export_link_id === null)
                                                        <p class="mt-3 text-sm text-ash-grey-400">Aktywny eksport jest pokazany powyżej.</p>
                                                    @else
                                                        <a href="{{ route('export-operations.show', [$playlist, $activeOperation->operation_id]) }}" class="auth-link mt-3 inline-block">Otwórz aktywny eksport</a>
                                                    @endif
                                                @elseif ($activeReview)
                                                    <a href="{{ route('export-reviews.show', [$playlist, $activeReview]) }}" class="auth-link mt-3 inline-block">Wznów przegląd</a>
                                                @else
                                                    <form method="POST" action="{{ route('export-reviews.store', $playlist) }}" class="mt-3">
                                                        @csrf
                                                        <input type="hidden" name="target_provider" value="{{ $provider->value }}">
                                                        <input type="hidden" name="destination_type" value="{{ $destinationType->value }}">
                                                        @if ($linked)
                                                            <input type="hidden" name="streaming_account_id" value="{{ $account->id }}">
                                                        @endif
                                                        <button type="submit" class="auth-link">{{ $retryableReview ? 'Przygotuj nowy wynik' : 'Rozpocznij przegląd' }}</button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </section>
                            @endif
                        </article>
                    @endforeach
                </div>
                <div class="mt-8">
                    {{ $playlists->links() }}
                </div>
            </section>
        @endif
    </section>
@endsection
