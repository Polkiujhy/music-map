# Import playlisty z linku do prywatnego banku — plan implementacji

## Przegląd

Zmiana dostarcza wycinek S-02: zalogowany i zweryfikowany użytkownik podaje
kanoniczny link Spotify albo YouTube i otrzymuje prywatną kartę playlisty w
banku lub bezpieczną, możliwą do naprawienia odmowę. Import jest synchroniczny,
ograniczony do 20 pozycji i atomowy; ponowienie dla tej samej playlisty
odświeża istniejący rekord bez pozostawienia częściowego snapshotu.

Publiczny YouTube korzysta z ograniczonego API key. Spotify korzysta wyłącznie
z efemerycznego dostępu udostępnionego przez aplikacyjny kontrakt S-04 oraz
powiązane konto streamingowe. S-04 przechowuje refresh token zaszyfrowany w
bazie aplikacji; S-02 nigdy go nie odczytuje ani nie zapisuje, a access token
istnieje wyłącznie w pamięci wywołania. Manager przechowuje i dostarcza
aplikacyjne client credentials/runtime config, bez logiki OAuth użytkownika i
playlist.

## Analiza stanu obecnego

F-01 i S-01 są ukończone. Aplikacja ma sesyjny auth, chroniony `/bank`, modele
`User` i `AuthIdentity`, kolejki bazodanowe oraz probe'y akceptacyjne
Spotify/YouTube. Bank jest obecnie statycznym pustym stanem: trasa jest closure,
a test celowo potwierdza brak kontrolki importu. Nie ma modeli playlist i pozycji,
klientów importu runtime, polityk ani jobów aplikacyjnych.

Probe `music-map.platform-access.v1` jest kontraktem akceptacyjnym inicjowanym
przez Managera, a nie klientem runtime. Jego DTO wymagają principal typu
technical/tester, wyniki służą CLI, a ścieżka testera mutuje i sprząta
zarezerwowaną fixture. S-02 może wykorzystać sprawdzony wzorzec Laravel HTTP
Client, krótkich timeoutów i `Http::fake`, ale nie może wywoływać
`PlatformProbe`, `RefreshTokenRotationSink` ani zwracać `ProbeFailure` z web flow.

Równoległy plan S-04 w `context/changes/streaming-account-linking/` jest
właścicielem modelu połączenia, zaszyfrowanego refresh tokenu, rotacji oraz
aplikacyjnego kontraktu `WithStreamingAccess`. S-02 może rozpocząć integrację
Spotify po wylądowaniu tego kontraktu; nie tworzy drugiej ścieżki OAuth i nie
wywołuje Managera.

### Kluczowe odkrycia

- `GET /bank` is protected by `auth` and `verified`; the current view does not
  query data and does not contain a form (`routes/web.php:19`,
  `resources/views/bank/index.blade.php:22`).
- `User` currently only has the `authIdentities()` relationship, and the previous
  plan explicitly reserved ownership and isolation of playlist records for
  S-02 (`app/Models/User.php:21`,
  `context/archive/2026-09-12-private-account-and-bank/plan.md:41`).
- `AuthIdentity` is a login identity without tokens and cannot be reused as
  a streaming account (`app/Models/AuthIdentity.php:11`).
- Spotify's current endpoint is `/v1/playlists/{id}/items`; for applications in
  Development Mode, the content is available only to the owner or
  playlist collaborator. The response uses `items[].item`, and the endpoint
  accepts a limit of up to 50.
- `playlists.list` and `playlistItems.list` YouTube allow reading public data
  with an API key; `playlistItems.list` accepts `maxResults` up to 50 and costs
  one quota unit per call.
- Under YouTube policies, non-authorized API data may not be retained without
  refresh for longer than 30 days. S-02 therefore needs provenance, a timestamp
  freshness, refresh before the deadline and deletion/hiding of outdated data
  when refresh fails. The owner must accept the interpretation of the policy;
  the plan is not legal advice.
- Each new PHP file in `app/` and `tests/` must be added to the manual manifest
  `scripts/verify-source-contract` (`scripts/verify-source-contract:143`).
- The same files `User.php`, `routes/web.php`, `config/services.php`,
  `.env.example`, `bootstrap/providers.php`, `scripts/verify-source-contract`,
  CI and roadmap can be changed by S-04. Their integration must be serialized,
  and implementations should work in separate worktrees after a common contract
  commit.

## Pożądany stan końcowy

- Verified user sees an HTTPS link form and exclusively their own playlist cards
  in `/bank`.
- The parser accepts a narrow list of canonical Spotify and YouTube URLs and
  never dereferences the provided host, follows a redirect, or builds a request
  from an untrusted URL.
- Public YouTube imports without linking an account; Spotify imports only through
  the proper linked owner/collaborator account provided by the S-04 application
  contract.
- Zero to 20 positions are saved in original order, including duplicates and
  explicit unavailable placeholders. A detected 21st position refuses the whole
  import without modifying the previous snapshot.
- Reimport of the same `(user, provider, provider playlist ID)` atomically
  replaces metadata and items in the same bank record.
- The bank card displays name, source, item count, unavailable item count and
  freshness/import status, but does not expose the item list or editing from S-03.
- Public Terms and Privacy pages are always available. Before each import, the
  user explicitly accepts the linked policies; YouTube attribution is visible
  before the first live API-backed flow is enabled.
- YouTube API metadata is refreshed before 30 days; stale data is not displayed
  as current and is removed or hidden when timely refresh cannot be confirmed.
- S-02 tables, sessions, jobs, HTML and logs contain neither provider access nor
  refresh tokens, API key, raw provider payload or the raw submitted URL. The S-04
  table contains only the encrypted refresh token; HTML may contain only the
  normalized canonical source URL needed for the source link.

## Czego NIE robimy

- We do not build playlist item browsing/editing; this is S-03.
- We do not add source–export relationships, matching states, export, two-way
  synchronization or drift recovery; these are S-05–S-09.
- We do not create playlists manually from scratch or support playlists larger
  than 20 positions.
- We do not create a global `Track` table or deduplicate occurrences of the same
  song; an item is an ordered occurrence within one snapshot.
- We do not accept raw IDs, `http`, shortened links, `/watch`, `/shorts`,
  arbitrary subdomains, redirects, userinfo, non-default ports or unknown query
  parameters.
- We do not use `AuthIdentity`, acceptance probes or technical/tester sessions as
  runtime user integration.
- We do not store OAuth tokens in S-02, bypass the S-04 access action or
  reconstruct the implementation of Manager.
- S-02 application phases do not implement PaaS behavior. A separately owned PaaS
  hand-off may publish required symbolic runtime configuration and operational
  readiness, but contains no playlist business logic or secret values.
- We do not promise indefinite retention of provider metadata contrary to
  Spotify/YouTube policies.

## Podejście do implementacji

The import subsystem will live separately from `PlatformAccess` and consist of a
pure link parser, immutable references/snapshots, a closed failure taxonomy,
provider readers and the `ImportPlaylist` use case. The controller only validates
the request, invokes the use case, and maps typed results to fixed Polish messages.
Provider payloads do not cross the adapter boundary.

First parse and retrieve the entire bounded snapshot outside the transaction.
Only a complete and validated snapshot is saved in a short transaction. The
existing playlist row is acquired by the user's relation and locked; metadata is
updated and child items are replaced. A unique database constraint closes the
race between simultaneous first imports. Any provider failure or database error
leaves the previous snapshot unchanged.

YouTube reader uses `YOUTUBE_API_KEY` and two fixed-base requests: metadata and
the first 21 positions. Spotify import runs inside S-04 `WithStreamingAccess`,
which refreshes and persists rotation before invoking the reader callback. The
reader receives only an in-memory access token and non-secret stable account ID;
it never sees the refresh token or calls Manager. There is no fallback between
user, technical and tester identities.

### Krytyczne szczegóły implementacji

#### Sekwencjonowanie stanu

Network I/O must complete before the database transaction. During reimport, the
old snapshot remains visible until the new one has been fully validated and the
transaction committed. The application must not first delete items and then
download their replacements.

#### Współpraca z S-04

Before Phase 4, S-04 must publish the application-internal `WithStreamingAccess`
interface and its failure taxonomy. The action accepts an owner-scoped connection,
required scopes and a non-queued callback; it refreshes outside a transaction,
persists any rotated refresh token through `credential_version` CAS before the
callback, and exposes the access token only inside that callback. A stale CAS,
deleted connection or `invalid_grant` prevents the playlist request. The callback
must not return, serialize, persist or log the token.

The S-04 owner owns the canonical provider enum, connection metadata and
ephemeral-access action. S-02 owns playlist schema, URL parser, readers,
import use case and bank UI. Shared hot files (`User.php`, `routes/web.php`,
`config/services.php`, `.env.example`, `bootstrap/providers.php`,
`scripts/verify-source-contract`, CI and roadmap) are merged by a single
integration owner after rereading their current contents; neither branch
overwrites the other's additions.

#### Zgodność danych YouTube

Attribution and freshness are part of correctness. A scheduled refresh runs with
margin before 30 days, records success only after a complete refresh and never
pretends a failed attempt made the data fresh. Once the policy deadline is
reached without confirmed refresh, the UI cannot present provider metadata as
current; the cleanup path removes API-derived item rows and fields while keeping
only the user-owned bank shell and the canonical source reference submitted by
the user, subject to owner policy review.

## Phase 1: Kontrakty importu i prywatny model danych

### Przegląd

The phase builds provider-independent input, result and persistence contracts.
It can be implemented independently of S-04 and ends with a tested, atomically
replaceable private snapshot without external HTTP.

### Wymagane zmiany

**Phase source-manifest rule**: Every stable PHP file added in this phase is
added to `scripts/verify-source-contract` in the same commit. The integration
owner preserves current S-04 entries when touching the shared manifest.

#### 1. Parser kanonicznych linków

**Files**: `app/Integrations/PlaylistImport/PlaylistShareUrlParser.php`,
`app/Integrations/PlaylistImport/Data/PlaylistReference.php`; the S-04-owned
`app/Enums/StreamingProvider.php` is a read-only prerequisite at integration

**Purpose**: Convert a narrow HTTPS sharing URL to a trusted provider and ID without
making a network request.

**Contract**: Spotify accepts exact host `open.spotify.com`, exact path
`/playlist/{22 base62}`, and optionally one `si` parameter, which is ignored.
YouTube accepts exact hosts `www.youtube.com` and `music.youtube.com`, exact path
`/playlist`, exactly one `list` of 1–128 ASCII URL-safe characters
(`[A-Za-z0-9_-]`) and optionally one ignored `si`. The complete submitted URL is
limited to 512 bytes. Parser
rejects scheme other than HTTPS, userinfo, explicit port, fragment, duplicate or
unknown query, lookalike/arbitrary subdomain, encoded separator/control character,
invalid ID and overlong URL. The canonical URL is built from parsed values and
never preserves tracking parameters.

`StreamingProvider` is a shared contract owned by S-04. If it has not yet landed,
the S-02 branch uses a private import-local value object in the files it owns and
must not modify or merge a second public enum; integration requires the canonical
S-04 enum.

#### 2. Typowane dane i wyniki importu

**Files**: `app/Integrations/PlaylistImport/Data/PlaylistSnapshot.php`,
`app/Integrations/PlaylistImport/Data/PlaylistItemSnapshot.php`,
`app/Integrations/PlaylistImport/ImportFailureCode.php`,
`app/Integrations/PlaylistImport/ImportResult.php`

**Purpose**: Keep provider data and failure text out of controller, persistence and
logs.

**Contract**: Snapshot contains normalized playlist metadata, stable provider ID,
non-secret owner/account ID, canonical URL, optional provider revision and zero
to 20 ordered items. Item includes occurrence ID, catalog ID/URI, nullable display
fields, ordered creators, album, duration, ISRC, original position and availability.
Closed failures cover invalid URL, unsupported provider/item, linked account
required, reauthorization required, insufficient scope, unavailable/not found
playlist, too many items, rate/quota limiting, provider unavailable and invalid
response. No result contains raw body, provider exception, secret or the raw
submitted URL.
Each web import attempt also receives an application-generated correlation UUID.
The fixed user-facing failure message shows that UUID, while one structured safe
log event records the same UUID, failure code and provider only—never the input,
canonical URL, provider payload, account ID or credentials.

#### 3. Addytywny schemat playlist

**Files**:
`database/migrations/2026_09_13_010000_create_playlists_table.php`,
`database/migrations/2026_09_13_010100_create_playlist_items_table.php`,
`tests/Feature/Playlists/PlaylistMigrationTest.php`

**Purpose**: Persist a user-owned bank snapshot independently of platform tokens and
future exports.

**Contract**: `playlists` includes `user_id`, provider, provider playlist ID,
nullable stable source account ID, canonical source URL, nullable provider revision,
nullable `name`/`description`, `provider_metadata_refreshed_at`, `imported_at` and
timestamps. Nullable display metadata allows the YouTube cleanup phase to preserve
a bank shell; its UI fallback is a fixed provider label such as
`Playlista YouTube — dane wymagają odświeżenia`, not a provider-derived value.
Unique key is `(user_id, source_provider, source_playlist_id)`; the list path has
an index beginning with `user_id`. No OAuth credential exists in the table.

`playlist_items` includes `playlist_id`, zero-based `position`, nullable occurrence
and catalog IDs/URI, nullable title/album/ISRC/duration, ordered creators JSON and
`is_available`. It does not need timestamps. Unique key `(playlist_id, position)`
preserves exact order while allowing repeated catalog IDs. Both foreign keys
cascade only inside the user's bank. Application validation, not unsigned behavior,
keeps the schema portable between SQLite and PostgreSQL.

#### 4. Modele, fabryki i własność

**Files**: `app/Models/Playlist.php`, `app/Models/PlaylistItem.php`,
`app/Models/User.php`, `database/factories/PlaylistFactory.php`,
`database/factories/PlaylistItemFactory.php`

**Purpose**: Expose relationships and casts without opening cross-user lookups.

**Contract**: `User::playlists()` is `HasMany`; `Playlist::items()` is always ordered
by `position`. Models cast provider, creators, booleans and immutable timestamps.
Factories use canary data only. Resource reads and updates start from
`$user->playlists()`; S-02 adds no unscoped endpoint by numeric playlist ID.

#### 5. Atomowy zapis i ponowny import

**Files**: `app/Actions/Playlists/ReplaceImportedPlaylist.php`,
`tests/Feature/Playlists/PlaylistPersistenceTest.php`

**Purpose**: Store a complete snapshot or change nothing.

**Contract**: The action performs no HTTP. In one transaction it creates or acquires
the unique user/provider/source record, locks it, updates metadata, deletes old
items and inserts exact replacement items. A simultaneous first-import uniqueness
race uses a database-portable upsert or retries the complete transaction after the
losing transaction has rolled back; it never rereads inside a PostgreSQL transaction
left aborted by a unique violation. A failure while replacing children rolls back metadata
and items. The same source for another user creates another private record.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Parser and pure DTO/failure tests pass for accepted and hostile URL matrices:
  `php artisan test tests/Unit/Integrations/PlaylistImport`.
- A dedicated migration test applies and rolls back both migrations on an
  explicitly configured in-memory SQLite connection without reading the current
  `.env` database connection:
  `php artisan test tests/Feature/Playlists/PlaylistMigrationTest.php`.
- Persistence tests prove ownership, per-user uniqueness, duplicates, placeholders,
  exact order, atomic replacement and rollback:
  `php artisan test tests/Feature/Playlists/PlaylistPersistenceTest.php`.
- Existing auth/model tests remain green:
  `php artisan test tests/Feature/Auth/AuthIdentityModelTest.php tests/Feature/BankAccessTest.php`.
- PHP formatting and the phase source manifest pass:
  `vendor/bin/pint --test && sh scripts/verify-source-contract --worktree`.

#### Weryfikacja ręczna

- Schema review confirms an additive migration, no credential fields and no global
  uniqueness that would prevent two users from importing the same public source.
- Shared-contract review confirms one provider enum and no overlap with the S-04
  connection model.

**Implementation note**: After the automatic checks are green, stop for schema and
shared-contract review before adding network adapters.

---

## Phase 2: Publiczny import YouTube od końca do końca

### Przegląd

The phase connects an API-key reader, the atomic use case and the existing bank
to deliver the first user-visible import without waiting for S-04.

### Wymagane zmiany

**Phase source-manifest rule**: Every stable PHP file added in this phase is
added to `scripts/verify-source-contract` in the same commit. The integration
owner preserves current S-04 entries when touching the shared manifest.

#### 1. Konfiguracja publicznego odczytu

**Files**: `.env.example`, `config/services.php`

**Purpose**: Publish one symbolic configuration name without exposing its value.

**Contract**: `YOUTUBE_API_KEY` is required at runtime and read from a dedicated
playlist-import configuration subtree. It is never rendered, serialized or logged.
Production key is restricted at the provider. The integration owner serializes
this edit with S-04's changes to the same files. A separate Manager-owned hand-off
publishes this symbolic key as application runtime configuration; it does not add
the key to `music-map.platform-access.v1`. Live import remains blocked until the
operator has supplied a non-placeholder value, applied it through the public
runtime-refresh capability and confirmed the intended Google API restrictions.

#### 2. Port odczytu i reader YouTube

**Files**:
`app/Integrations/PlaylistImport/Contracts/PlaylistSourceReader.php`,
`app/Integrations/PlaylistImport/PlaylistSourceReaderRegistry.php`,
`app/Integrations/PlaylistImport/Providers/YouTubePlaylistReader.php`,
`app/Integrations/PlaylistImport/ProviderImportFailureMapper.php`

**Purpose**: Read one public playlist into a normalized bounded snapshot.

**Contract**: Reader builds fixed Google API URLs from validated ID and sends the
API key as a parameter. It requests playlist metadata and exactly one page with
`maxResults=21`; `count/totalResults > 20` or `nextPageToken` refuses import without
another page. Empty through 20 positions succeed. Order and duplicates are kept;
deleted/private items become explicit unavailable placeholders when the response
still provides an occurrence/position. Structurally unusable items or unsupported
resource kinds refuse the whole snapshot rather than silently changing it.

Mapper recognizes only bounded structural status/reason values and produces the
closed import taxonomy. `403/404`, quota, `429`, `5xx`, transport errors and malformed
success payloads have separate safe outcomes. There is no OAuth or technical/tester
fallback and no retry in the user's request.

#### 3. Przypadek użycia importu

**Files**: `app/Actions/Playlists/ImportPlaylist.php`,
`app/Providers/PlaylistImportServiceProvider.php`, `bootstrap/providers.php`

**Purpose**: Orchestrate parse, access selection, complete fetch and atomic persist.

**Contract**: All validation and HTTP finish before `ReplaceImportedPlaylist` opens
a transaction. The use case returns imported vs refreshed success with playlist ID
or typed failure. It creates the correlation UUID at the application boundary and
emits exactly one sanitized diagnostic event for a failed attempt. Failed reimport
leaves the prior snapshot and freshness untouched.
`PlaylistImportServiceProvider` registers the named `playlist-import` limiter as
five attempts per minute, keyed only by the authenticated user's stable application
ID. The limiter does not use e-mail, provider account identity or submitted URL.

#### 4. Warunki, prywatność, zgoda i atrybucja YouTube

**Files**: `routes/web.php`, `resources/views/legal/terms.blade.php`,
`resources/views/legal/privacy.blade.php`, `resources/views/layouts/app.blade.php`,
`resources/views/bank/index.blade.php`,
`app/Http/Requests/ImportPlaylistRequest.php`

**Purpose**: Satisfy the user-facing YouTube policy prerequisites before the
first live API-backed import.

**Contract**: Named public Terms and Privacy routes remain prominently and
continuously accessible. The application's terms state that use of YouTube-backed
features is also subject to the YouTube Terms of Service. The privacy policy states
that the application uses YouTube API Services, describes the API data it accesses,
stores, refreshes and deletes, and links to the Google Privacy Policy and Google
security settings. Before each import, the form explicitly links both application
policies and requires an unchecked-by-default acceptance checkbox; validation rejects
the request before provider work when consent is absent. YouTube cards identify
YouTube as their source and retain the canonical source link. Exact policy copy
requires owner approval before live smoke.

#### 5. Formularz, kontroler i karty banku

**Files**: `app/Http/Controllers/BankController.php`,
`app/Http/Controllers/PlaylistImportController.php`,
`app/Http/Requests/ImportPlaylistRequest.php`, `routes/web.php`,
`resources/views/bank/index.blade.php`

**Purpose**: Turn the static bank into the vertical S-02 YouTube flow.

**Contract**: `GET /bank` uses a controller and queries only the authenticated user's
playlists with item/unavailable counts. A named POST import route uses `auth`,
`verified`, CSRF, `throttle:playlist-import` and Post/Redirect/Get. Fixed Polish messages
provide a cause, next action and correlation UUID without reflecting input or
provider text. Success
returns to the bank and shows a card with name, provider, counts and freshness.
There is no item detail, edit, delete, sync or export control.

#### 6. Testy adaptera i przepływu webowego

**Files**: `tests/Unit/Integrations/PlaylistImport/YouTubePlaylistReaderTest.php`,
`tests/Feature/Playlists/PlaylistImportTest.php`,
`tests/Feature/BankAccessTest.php`

**Purpose**: Prove the complete flow without real network or shared secrets.

**Contract**: `Http::fake` asserts fixed hosts, exact parameters, two bounded calls,
zero calls for invalid URLs, and absence of secret/raw payloads from outputs and
logs. The matrix covers 0/1/20/21 positions, duplicates, placeholders, malformed
payload, 403/404/429/quota/5xx/timeout and failed reimport. Feature tests cover
guest, unverified, cross-user isolation, validation, throttling, success/refreshed
copy and every actionable failure message. They match the displayed correlation UUID
to the sanitized log event and prove that neither contains sensitive context. The
old assertion that no import control
exists is replaced deliberately.
Tests also prove that the Terms and Privacy routes are public and linked, required
notices are present, and a missing consent checkbox causes zero provider requests
and zero database writes. Throttling tests prove that the sixth attempt within one
minute is rejected before provider work and that two users have independent limits.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- YouTube reader matrix passes without network:
  `php artisan test tests/Unit/Integrations/PlaylistImport/YouTubePlaylistReaderTest.php`.
- Complete import, bank isolation and the named per-user limiter pass:
  `php artisan test tests/Feature/Playlists/PlaylistImportTest.php tests/Feature/BankAccessTest.php`.
- Invalid links make zero HTTP calls and every failed import/reimport leaves the
  database unchanged.
- Public policy pages, required links and explicit import consent pass feature tests;
  missing consent makes zero provider requests and zero writes.
- Views build: `npm run build`.
- PHP formatting and the phase source manifest pass:
  `vendor/bin/pint --test && sh scripts/verify-source-contract --worktree`.

#### Weryfikacja ręczna

- Bank form, safe errors and cards work with keyboard, at 200% zoom, and on narrow
  and wide screens.
- Owner approves the exact Terms, Privacy, consent and YouTube attribution copy
  before any live API-backed import.
- The PaaS owner confirms that a restricted, non-placeholder `YOUTUBE_API_KEY` is
  delivered to the accepted runtime without recording its value in evidence.
- A real public YouTube playlist with no linked account imports and reimports into
  the same card; a private/missing and a 21-item playlist show the expected action.

**Implementation note**: Stop for owner policy-copy acceptance before the first
live public-YouTube smoke, then stop again for smoke and UX acceptance before
scheduling retention work.

---

## Phase 3: Świeżość i zgodność danych YouTube

### Przegląd

The phase makes the API-key import sustainable under YouTube data policies without
implementing the user-controlled two-way synchronization from S-08.

### Wymagane zmiany

**Phase source-manifest rule**: Every stable PHP file added in this phase is
added to `scripts/verify-source-contract` in the same commit. The integration
owner preserves current S-04 entries when touching the shared manifest.

#### 1. Odświeżenie jednego snapshotu

**Files**: `app/Actions/Playlists/RefreshYouTubePlaylistMetadata.php`,
`app/Jobs/RefreshYouTubePlaylistMetadata.php`

**Purpose**: Reuse the reader and atomic replacement for compliance refresh.

**Contract**: The job receives only playlist ID, reloads an eligible YouTube record,
fetches outside a transaction and atomically replaces API-derived metadata/items on
success. It updates `provider_metadata_refreshed_at` inside the same transaction as
the replacement so the snapshot and freshness become visible atomically. Retries are
bounded and honor provider rate/quota outcomes; duplicate jobs are unique per
playlist. It does not turn into generic source synchronization or write to YouTube.

#### 2. Harmonogram i usuwanie przeterminowanych danych

**Files**: `app/Console/Commands/RefreshStaleYouTubePlaylistMetadata.php`,
`routes/console.php`

**Purpose**: Refresh with margin and fail closed at the retention boundary.

**Contract**: A daily command dispatches bounded batches approaching 28 days since
the last confirmed refresh. At 30 days without success, API-derived name,
description, stable owner and item rows are no longer displayed as current and are
removed by an idempotent cleanup path; the user-owned shell and canonical link they
submitted remain for reimport. A failed attempt does not advance freshness. Queue
jobs contain no API key, canonical source URL or raw submitted URL.

#### 3. Informacje o świeżości dla użytkownika

**Files**: `resources/views/bank/index.blade.php`

**Purpose**: Explain stale state without overstating permanence while preserving
the policy links and attribution established before live import in Phase 2.

**Contract**: A stale or purged shell is visibly unavailable and offers reimport;
it never shows expired API-derived fields as current. Existing YouTube attribution
and policy links remain visible. Spotify attribution is added with its adapter in
Phase 4. Exact stale-state copy receives owner review.

#### 4. Testy cyklu 28/30 dni

**Files**: `tests/Feature/Playlists/YouTubeMetadataLifecycleTest.php`

**Purpose**: Make the retention rule executable and resistant to clock/race errors.

**Contract**: Frozen-time tests cover records below 28 days, dispatch at the refresh
threshold, successful refresh, rate/quota failure, duplicate dispatch, 29-day
visibility, 30-day purge/hide and later recovery. They prove failure does not update
freshness and logs/jobs do not carry secrets or source URLs.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Freshness, job uniqueness and 28/30-day lifecycle tests pass:
  `php artisan test tests/Feature/Playlists/YouTubeMetadataLifecycleTest.php`.
- Queue behavior passes with `sync` in tests and does not serialize secrets.
- The scheduled command appears in `php artisan schedule:list` with one daily entry.
- Playlist import and bank tests remain green.
- Views, PHP formatting and the phase source manifest pass:
  `npm run build && vendor/bin/pint --test && sh scripts/verify-source-contract --worktree`.

#### Weryfikacja ręczna

- Owner reviews the stale-state copy and the distinction between a durable user-owned
  bank shell and refreshed provider metadata; Phase 2 policy acceptance remains
  unchanged.
- With frozen/staged dates, the bank never presents 30-day-old API data as current
  and offers a clear reimport path after cleanup.

**Implementation note**: Stop for owner policy/UX acceptance; this plan records an
engineering interpretation of provider policy, not legal advice.

---

## Phase 4: Import Spotify przez aplikacyjny kontrakt S-04

### Przegląd

The phase completes S-02 by joining the already working playlist domain to the
S-04 connection and `WithStreamingAccess` action. It is blocked only until that
application-internal contract and encrypted credential lifecycle exist.

### Wymagane zmiany

**Phase source-manifest rule**: Every stable PHP file added in this phase is
added to `scripts/verify-source-contract` in the same commit. The integration
owner preserves current S-04 entries when touching the shared manifest.

#### 1. Bramka wspólnego kontraktu

**Files**: application interfaces and types published by S-04; no Manager
implementation files

**Purpose**: Verify the exact seam before writing the Spotify reader.

**Contract**: One canonical provider enum and connection model are available. The
connection stores non-secret identity/status/scopes and an encrypted refresh token.
`WithStreamingAccess` accepts the owner-scoped connection, required scope set and
a synchronous callback. It guarantees successful refresh-token rotation and CAS
before invoking the callback, provides the access token only inside that callback,
and returns a closed failure otherwise. Missing semantics keep this phase blocked;
S-02 does not infer or duplicate them.

#### 2. Reader Spotify

**Files**: `app/Integrations/PlaylistImport/Providers/SpotifyPlaylistReader.php`,
`app/Integrations/PlaylistImport/PlaylistSourceReaderRegistry.php`

**Purpose**: Import only an accessible owned/collaborative playlist using the linked
user account.

**Contract**: Without a matching connection, return `linked_account_required` and
make zero provider requests. Invoke the reader inside `WithStreamingAccess`,
require stable account identity and verify that granted scopes are a superset of
the documented S-02 read subset;
additional canonical S-04 read/write scopes are allowed. Then call fixed
`/v1/playlists/{id}` and
`/v1/playlists/{id}/items?limit=21&offset=0`. Use current `items[].item` shape.
`total > 20`, a next link or 21st item refuses import. Tracks preserve order and
duplicates; unavailable track occurrences become placeholders when safely
representable. Episodes, local files and structurally unusable resources refuse
the snapshot rather than silently changing it. `403` maps to actionable wrong
account/access; `invalid_grant` from S-04 maps to reconnect.

#### 3. Powiązanie źródła bez utraty banku

**Files**:
`database/migrations/2026_09_13_010200_link_playlists_to_streaming_accounts.php`,
`app/Models/Playlist.php`

**Purpose**: Relate an imported source to the account used without coupling bank
retention to credential lifetime.

**Contract**: Add a nullable foreign key to the final S-04 connection table with
`nullOnDelete`. Preserve non-secret `source_account_id` on the playlist, so unlink
does not delete the bank or erase provenance. Public YouTube keeps this FK null.
Migration name/timestamp is rebased after the final S-04 migration and never assumes
an uncommitted table name.

#### 4. Testy połączenia i odmów Spotify

**Files**: `tests/Unit/Integrations/PlaylistImport/SpotifyPlaylistReaderTest.php`,
`tests/Feature/Playlists/SpotifyPlaylistImportTest.php`

**Purpose**: Prove the cross-plan boundary without exposing credentials or calling
Manager in automated tests.

**Contract**: Fake `WithStreamingAccess` covers missing connection,
reauthorization, stale CAS, scope, account mismatch, rate/quota/unavailable and
success. HTTP fakes cover
owner/collaborator response, 0/1/20/21 positions, duplicates, placeholders,
episode/local/malformed item, 403/404/429/5xx and failed reimport. Tests assert no
fallback to technical/tester credentials and no token, raw payload or full URL in
database, logs, jobs, HTML or exceptions.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Spotify reader and S-04 access seam matrix pass without network:
  `php artisan test tests/Unit/Integrations/PlaylistImport/SpotifyPlaylistReaderTest.php`.
- Linked-account import and all refusal paths pass:
  `php artisan test tests/Feature/Playlists/SpotifyPlaylistImportTest.php`.
- Unlink nulls the relation but preserves the bank snapshot and source account ID.
- Regression for corrected S-04 and F-01 passes; the probe protocol remains byte-for-
  byte compatible in behavior.
- PHP formatting and the phase source manifest pass:
  `vendor/bin/pint --test && sh scripts/verify-source-contract --worktree`.

#### Weryfikacja ręczna

- Dedicated Spotify owner and collaborator accounts each import an accessible
  playlist; another account receives the expected corrective message.
- Reconnect-required state blocks import safely, and review confirms no secret or
  provider payload in database, HTML or restricted logs.

**Implementation note**: This phase may start only after the S-04 application
contract gate is accepted. Stop after cross-plan automated integration and live
account smoke.

---

## Phase 5: Utwardzenie, zgodność repozytorium i wydanie

### Przegląd

The phase verifies both database engines, serializes shared-file integration,
updates repository contracts and prepares a controlled schema release.

### Wymagane zmiany

#### 1. Manifest źródła i dokumentacja

**Files**: `scripts/verify-source-contract`, `README.md`

**Purpose**: Include every stable PHP path and explain only public runtime contracts.

**Contract**: Manifest includes all S-02 paths exactly once and no lifecycle-managed
change artifacts. README documents accepted link shapes, 20-item limit, YouTube
freshness/attribution and symbolic `YOUTUBE_API_KEY`. It names the S-04
`WithStreamingAccess` boundary and states that Manager supplies only application
runtime configuration, without describing Manager host, transport or secret
lifecycle internals. Integration owner merges current S-04 entries instead of
replacing them.

#### 2. PostgreSQL i CI

**Files**: `.github/workflows/ci.yml`,
`tests/Unit/ProductionInfrastructureTest.php`

**Purpose**: Verify uniqueness and atomic snapshot replacement on the production
database engine.

**Contract**: Existing isolated PostgreSQL job adds a focused matrix for migrations,
ownership, simultaneous/repeated import and child replacement. It uses fakes and
non-secret canaries, does not contact provider/Manager and preserves the fast full
SQLite suite plus current image/security gates.

#### 3. Weryfikacja wydania i providerów

**File**: `context/changes/playlist-link-import/reviews/manual-verification.md`

**Purpose**: Record exact commit/environment outcomes without secrets.

**Contract**: Before the image that reads new tables is released, the additive schema
uses the published supervised schema-release capability. Manual evidence records
date, environment, commit, public playlist fixtures and result categories without
URLs containing identifiers, account IDs, API key, OAuth data or provider payload.
It covers nonsecret confirmation of PaaS configuration readiness, YouTube
import/reimport/retention and Spotify owner/collaborator/refusal.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Full PHPUnit passes: `composer test`.
- PHP formatting passes: `vendor/bin/pint --test`.
- Production frontend builds: `npm run build`.
- Source manifest passes: `sh scripts/verify-source-contract --worktree`.
- Locked dependency audits pass:
  `composer audit --locked --no-interaction && npm audit --audit-level=high`.
- PostgreSQL CI runs the focused S-02 persistence/import matrix after
  `migrate:fresh` without real network or secrets.

#### Weryfikacja ręczna

- Controlled schema release and HTTPS smoke are documented for the exact candidate.
- Public YouTube and linked Spotify fixtures pass import and atomic reimport; all
  refusal messages are actionable and no stale provider data is presented as fresh.
- Bank form/cards pass keyboard, 200% zoom, narrow/wide layout and source attribution.
- Database, jobs, HTML and restricted logs contain no OAuth token, API key, raw
  payload or raw submitted URL; the normalized canonical source link is the only
  permitted source URL in HTML.

**Implementation note**: Operational actions remain human-owned and use only the
public PaaS interface. No autonomous phase runs production migration or deployment.

---

## Strategia testowania

### Testy jednostkowe

- URL parser: every accepted canonical format plus scheme, credentials, port,
  fragment, encoded separator, duplicate parameters, host lookalike and length
  boundaries.
- Snapshot/value objects and failure taxonomy reject malformed or sensitive data.
- Provider readers: exact fixed URL/header/query, limits, response normalization,
  typed errors, zero fallback and zero network for invalid preconditions.
- Time-based YouTube lifecycle with frozen time, unique jobs and failure semantics.

### Testy integracyjne

- SQLite and PostgreSQL constraints, ownership, cascade, exact item order, duplicates,
  placeholders, atomic replacement and rollback.
- Auth/verified/CSRF/throttle boundaries and no access to another user's records.
- Public YouTube import/reimport with `Http::fake`; linked Spotify with fake S-04
  access plus `Http::fake`.
- 0, 1, 20 and 21 positions; unavailable item, unsupported item, malformed payload,
  denied/not-found, rate/quota, timeout and provider failure.
- 28/30-day refresh and purge/hide; failed refresh never updates freshness.
- Regression of authentication, PlatformAccess protocol, source contract and views.

### Kroki testowania ręcznego

1. Import a canonical public YouTube playlist without linking an account and verify
   the private bank card, attribution and counts.
2. Reimport the changed source and confirm the same card is atomically refreshed.
3. Verify private/missing, malformed and 21-position links show a fixed corrective
   message without partial data.
4. Exercise the 28/30-day lifecycle in a staged environment and confirm stale API
   data is not shown as current.
5. After the S-04 `WithStreamingAccess` seam lands, import Spotify as owner and collaborator,
   then confirm a wrong/reconnect account is safely rejected.
6. Check bank UI using keyboard, 200% zoom and narrow/wide screens.
7. Review database, serialized jobs, HTML and restricted logs for absence of secrets,
   raw payloads and raw submitted URLs; HTML may expose only canonical source links.

## Uwagi dotyczące wydajności

The user-requested import is synchronous and bounded to two provider reads plus one
short database transaction. Readers request no more than 21 positions and do not
follow pagination URLs or retry inside the web request. Indexes serve the per-user
bank list and unique reimport key. No cache is required for the expected small scale.

The compliance refresh uses bounded batches and unique jobs; it must stop or defer
on rate/quota responses rather than create a retry storm. Provider `Retry-After` may
inform scheduled retry outside the active request but is never an unbounded sleep.

## Uwagi dotyczące migracji

Playlist and item tables are additive and do not affect the old image. The optional
connection foreign key lands only after the corrected S-04 table name and migration
order are final; it is nullable and uses `nullOnDelete`, so unlink and rollback of
credentials do not delete the bank. Database rollback does not form part of an
application image rollback; removing tables/data is a separate destructive decision.

Production schema changes go through the published supervised PaaS capability before
the image requiring them. This plan specifies only the application schema and
observable release precondition, not Manager implementation.

## Referencje

- Product scope: `context/foundation/prd.md` — FR-002, FR-004, NFR-001 and NFR-005.
- Roadmap slice: `context/foundation/roadmap.md` — S-02.
- Completed private bank: `context/archive/2026-09-12-private-account-and-bank/plan.md`.
- Completed acceptance probe:
  `context/archive/2026-09-12-platform-access-readiness/plan.md`.
- Parallel S-04 plan: `context/changes/streaming-account-linking/plan.md`.
- Spotify Get Playlist Items:
  `https://developer.spotify.com/documentation/web-api/reference/get-playlists-items`.
- Spotify February 2026 migration guide:
  `https://developer.spotify.com/documentation/web-api/tutorials/february-2026-migration-guide`.
- Spotify Get Playlist and current profile:
  `https://developer.spotify.com/documentation/web-api/reference/get-playlist`,
  `https://developer.spotify.com/documentation/web-api/reference/get-current-users-profile`.
- YouTube playlists and playlist items:
  `https://developers.google.com/youtube/v3/docs/playlists/list`,
  `https://developers.google.com/youtube/v3/docs/playlistItems/list`.
- YouTube API registration and quota:
  `https://developers.google.com/youtube/registering_an_application`,
  `https://developers.google.com/youtube/v3/determine_quota_cost`.
- YouTube developer policies:
  `https://developers.google.com/youtube/terms/developer-policies`.
- Public PaaS boundary: `AGENTS.md` and global `s-manager-use`.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a
> step lands. Do not rename step titles.

### Phase 1: Kontrakty importu i prywatny model danych

#### Automated

- [x] 1.1 Parser i czyste kontrakty przechodzą macierz poprawnych i wrogich wejść — 9798962
- [x] 1.2 Migracje stosują się i cofają na izolowanym SQLite — 9798962
- [x] 1.3 Własność, kolejność i atomowy zapis przechodzą testy persistence — 9798962
- [x] 1.4 Regresja modeli auth i dostępu do banku przechodzi — 9798962
- [x] 1.5 Formatowanie PHP i manifest źródła fazy przechodzą — 9798962

#### Manual

- [ ] 1.6 Schemat nie zawiera poświadczeń ani globalnej blokady publicznego źródła
- [ ] 1.7 S-02 i S-04 używają jednego kontraktu providera bez nakładających się modeli

### Phase 2: Publiczny import YouTube od końca do końca

#### Automated

- [x] 2.1 Reader YouTube przechodzi pełną macierz limitów, danych i odmów — 601e346
- [x] 2.2 Import, prywatny bank, limiter per user i correlation ID przechodzą testy feature — 601e346
- [x] 2.3 Niepoprawne wejścia wykonują zero requestów, a awarie wykonują zero zapisów — 601e346
- [x] 2.4 Produkcyjny frontend buduje się — 601e346
- [x] 2.5 Formatowanie PHP i manifest źródła fazy przechodzą — 601e346
- [x] 2.8 Publiczne polityki i jawna zgoda importu przechodzą testy bez sieci — 601e346

#### Manual

- [ ] 2.6 Formularz, błędy i karty przechodzą weryfikację dostępności i responsywności
- [ ] 2.7 Publiczna playlista YouTube przechodzi import i atomowy reimport live
- [ ] 2.9 Właściciel akceptuje Terms, Privacy, zgodę i atrybucję przed live smoke
- [ ] 2.10 PaaS dostarcza ograniczony YOUTUBE_API_KEY bez ujawnienia wartości

### Phase 3: Świeżość i zgodność danych YouTube

#### Automated

- [x] 3.1 Cykl świeżości, unique job i granice 28/30 dni przechodzą testy
- [x] 3.2 Joby nie serializują sekretów, a awaria nie pozoruje świeżości
- [x] 3.3 Harmonogram zawiera dokładnie jedno codzienne uruchomienie cyklu
- [x] 3.4 Regresja importu i banku przechodzi
- [x] 3.5 Widoki, formatowanie i manifest źródła fazy przechodzą

#### Manual

- [ ] 3.6 Właściciel akceptuje atrybucję, linki i interpretację polityki danych YouTube
- [ ] 3.7 Bank nie pokazuje przeterminowanych danych API jako aktualnych

### Phase 4: Import Spotify przez aplikacyjny kontrakt S-04

#### Automated

- [ ] 4.1 Reader Spotify i port efemerycznego dostępu przechodzą macierz bez sieci
- [ ] 4.2 Linked-account import i wszystkie odmowy Spotify przechodzą testy feature
- [ ] 4.3 Unlink zachowuje bank i niesekretne pochodzenie playlisty
- [ ] 4.4 Regresja poprawionego S-04 oraz F-01 przechodzi
- [ ] 4.5 Formatowanie PHP i manifest źródła fazy przechodzą

#### Manual

- [ ] 4.6 Konto właściciela i współpracownika przechodzą import Spotify live
- [ ] 4.7 Reconnect i niewłaściwe konto kończą się bezpieczną odmową bez wycieku

### Phase 5: Utwardzenie, zgodność repozytorium i wydanie

#### Automated

- [ ] 5.1 Pełny PHPUnit przechodzi
- [ ] 5.2 Formatowanie PHP przechodzi
- [ ] 5.3 Produkcyjny frontend buduje się
- [ ] 5.4 Manifest źródła przechodzi dla worktree
- [ ] 5.5 Audyty przypiętych zależności przechodzą
- [ ] 5.6 PostgreSQL CI wykonuje krytyczną macierz S-02 bez sieci i sekretów

#### Manual

- [ ] 5.7 Kontrolowane wydanie schematu i HTTPS smoke są udokumentowane
- [ ] 5.8 Oba źródła przechodzą import, reimport i czytelne odmowy
- [ ] 5.9 Bank przechodzi końcową weryfikację dostępności i atrybucji
- [ ] 5.10 Baza, joby, HTML i logi nie zawierają sekretów ani surowych danych providera
