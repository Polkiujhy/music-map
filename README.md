# music-map

`music-map` is a Laravel application for keeping a private, platform-independent
playlist bank. Authenticated, verified users can import bounded public YouTube
playlists and Spotify playlists available through their linked account into the
private `/bank` area.

## Local setup

Create a local environment from the safe template, then override its production
defaults for local SQLite before running the setup command:

```bash
cp .env.example .env
touch database/database.sqlite
composer setup
composer dev
```

At minimum, set `APP_ENV=local`, `APP_DEBUG=true`, an appropriate local
`APP_URL`, `DB_CONNECTION=sqlite`, and
`DB_DATABASE=/absolute/path/to/music-map/database/database.sqlite` in `.env`.
`composer setup` installs locked PHP and Node dependencies, generates the
application key, runs Laravel migrations, and builds the frontend. Never commit
`.env`, the SQLite database, or credentials.

SQLite is a local-development and fast-test convenience only. Production uses
PostgreSQL for all persistent application data, including users, authentication
identities, sessions, cache records, and queued jobs, as defined by the
production-safe defaults in `.env.example`.

## Authentication configuration

Copy safe settings from `.env.example` and replace placeholders only in your
local `.env` or the deployment secret store. Google OAuth requires a client whose
authorized callback exactly matches the configured URL:

```dotenv
GOOGLE_CLIENT_ID=local-google-client-id
GOOGLE_CLIENT_SECRET=local-google-client-secret
GOOGLE_REDIRECT_URI=https://music-map.example.invalid/auth/google/callback
```

For email verification and password resets, configure a test SMTP inbox rather
than a personal or production mailbox:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.example.invalid
MAIL_PORT=465
MAIL_USERNAME=local-smtp-username
MAIL_PASSWORD=local-smtp-password
MAIL_FROM_ADDRESS=no-reply@example.invalid
```

Provider client secrets and user access tokens must not be written to source
files, database tables, command output, HTML, sessions, or application logs.
The only user OAuth credential persisted by the streaming-account subsystem is
the refresh token, encrypted at rest by Laravel with `APP_KEY`.

## Streaming-account linking

Authenticated, verified users manage Spotify and YouTube connections at
`/integrations`. The application owns the complete user OAuth flow: link,
relink, refresh/verify, reconnect state, unlink, and provider operations. It
persists the stable provider account identifier, minimal display label, granted
scopes, and an encrypted refresh token. Access tokens remain request-local and
must never be persisted or rendered.

`WithStreamingAccess` is the application port for operations that need a
short-lived access token. It refreshes the user grant, protects refresh-token
rotation with `credential_version`, and invokes a synchronous callback only
while the credential still belongs to the same connected account. A provider's
definitive rejection clears the local refresh token and changes the connection
to `reconnect-required`; transient failures leave the credential unchanged.

Manager is an external PaaS for this repository. It stores and supplies the
application-level client credentials and runtime configuration under these
symbolic settings:

```dotenv
SPOTIFY_CLIENT_ID=__REQUIRED_RUNTIME_SECRET__
SPOTIFY_CLIENT_SECRET=__REQUIRED_RUNTIME_SECRET__
SPOTIFY_REDIRECT_URI=https://music-map.example.invalid/integrations/spotify/callback
GOOGLE_CLIENT_ID=__REQUIRED_RUNTIME_SECRET__
GOOGLE_CLIENT_SECRET=__REQUIRED_RUNTIME_SECRET__
YOUTUBE_REDIRECT_URI=https://music-map.example.invalid/integrations/youtube/callback
YOUTUBE_API_KEY=__REQUIRED_RUNTIME_VALUE__
```

Manager does not handle application-user OAuth callbacks, grants, linked
accounts, refresh/reconnect, unlink/revoke, or playlist operations. Keep all
credential values out of source, output, and logs. `APP_PREVIOUS_KEYS` must
retain old Laravel encryption keys during a controlled `APP_KEY` rotation until
existing refresh tokens have been re-encrypted or users have reauthorized.

## Playlist imports

The import form accepts only narrow HTTPS share links. Spotify links use the
exact shape `https://open.spotify.com/playlist/{22-character-base62-id}`.
YouTube links use `https://www.youtube.com/playlist?list={playlist-id}` or the
same path on `music.youtube.com`; the playlist ID is 1–128 ASCII letters,
digits, underscores, or hyphens. Either provider may include one optional `si`
query parameter, which is discarded when the canonical source link is built.
Submitted URLs are limited to 512 bytes, and no other hosts, ports, fragments,
paths, or query parameters are accepted.

An import preserves source order, duplicate tracks, and representable
unavailable positions, but is limited to at most 20 positions. A source that
reports more positions is refused without partial persistence. Public YouTube
reads require the symbolic runtime setting `YOUTUBE_API_KEY`; its value must be
provided outside the repository and must never be rendered or logged. YouTube
metadata is refreshed as it approaches 28 days old. At 30 days without a
successful refresh, API-derived display metadata and items are removed while
the user-owned bank shell and canonical source link remain available for
reimport. The bank retains YouTube attribution and links to the applicable
application, YouTube, and Google policies.

Spotify imports require the user's matching linked streaming account. Provider
access crosses only the S-04 `WithStreamingAccess` application boundary, which
supplies a short-lived token solely inside a synchronous callback. Playlist
imports never fall back to technical or tester probe credentials. Manager's
only role here is supplying the application's symbolic runtime configuration;
playlist parsing, account selection, provider reads, persistence, refresh, and
retention remain application responsibilities.

## Platform-access probe

Manager, treated by this repository as an external PaaS, may verify the public
`music-map.platform-access.v1` contract through this application entrypoint:

```bash
php artisan platform-access:probe --provider=spotify --principal=technical --write --format=json --no-ansi --no-interaction
```

The supported providers are `spotify` and `youtube`; the supported principals
are `technical` and `tester`. The command emits one closed JSON document to
stdout on success or stderr on failure and exits with `0`, `1`, or `2` according
to the public contract. Technical credentials come only from the documented
runtime configuration. Tester sessions and optional replacement refresh tokens
use the fixed private locators defined by `music-map.platform-access.v1`.

For the technical/tester probe only, OAuth, credential lifecycle, probe
invocation, promotion, revoke, and recovery remain PaaS responsibilities. This
does not include application-user streaming OAuth described above. Never pass
probe credentials on the command line or write their values to source,
application storage, output, or logs.

## YouTube write admission

Every future export or synchronization that writes to YouTube must first persist
its own stable logical operation ID. It then calls the `AdmitYouTubeWrite` port,
outside any consumer-owned database transaction, with the same operation type
and ID on every retry. The call returns only after its reservation transaction
has committed.

Only `admitted-new` and `admitted-existing` permit the consumer to begin its
first YouTube mutation. `limit-reached` and
`YouTubeWriteAdmissionUnavailable` both mean that the consumer performs zero
YouTube mutations. A failure after an admitted result does not refund the
reservation; retrying the same type-and-ID pair recovers it without consuming a
second slot.

`YOUTUBE_WRITE_DAILY_LIMIT` is the symbolic runtime setting for the global
positive daily limit and defaults to `5`. The first new operation after local
midnight in `America/Los_Angeles` snapshots that day's value. The additive
`youtube_write_quota_states` and `youtube_write_admissions` schema must be
applied through the published supervised `schema-release` capability before
code that uses this port is released. Manager remains an external PaaS: it
supplies runtime configuration and the schema-release capability, while the
application owns admission behavior and all future consumer integration.

## Source-playlist synchronization

An activated synchronization compares the private bank playlist with its
Spotify or YouTube source. Source changes win conflicts; a bank-only change is
pushed back to the source. Synchronization is limited to 20 positions and
preserves order and duplicate occurrences. Provider failures do not advance the
confirmed baseline. Authentication failures require reconnecting the linked
account, while retryable rate-limit and provider failures remain available to
the queue retry policy.

The application uses Laravel's standard queue worker and scheduler. Run a
worker for the configured queue connection and invoke `php artisan
schedule:run` every minute (or keep `php artisan schedule:work` running in an
appropriate development environment). The scheduler dispatches due checks no
less frequently than the configured maximum interval; a successful login may
also dispatch a check once the login threshold has elapsed.

Only the following application-owned runtime settings control this feature:

```dotenv
PLAYLIST_SYNC_MAXIMUM_CHECK_INTERVAL_MINUTES=240
PLAYLIST_SYNC_LOGIN_CHECK_THRESHOLD_MINUTES=15
PLAYLIST_SYNC_DISPATCH_BATCH_SIZE=50
PLAYLIST_SYNC_RUN_RETENTION_DAYS=14
PLAYLIST_SYNC_PRUNE_BATCH_SIZE=500
PLAYLIST_SYNC_JOB_TRIES=3
PLAYLIST_SYNC_JOB_TIMEOUT_SECONDS=450
```

`PLAYLIST_SYNC_MAXIMUM_CHECK_INTERVAL_MINUTES` must not exceed `240` (four
hours). The login threshold defaults to `15` minutes. Batch and retention
settings bound scheduler/pruner work and retain terminal run diagnostics for 14
days by default. These values contain no credentials; deployment secrets remain
outside the repository.

Spotify synchronization requires `playlist-read-private`,
`playlist-read-collaborative`, `playlist-modify-private`,
`playlist-modify-public`, and `user-read-private`.
YouTube synchronization requires
`https://www.googleapis.com/auth/youtube`. Users whose historical grant lacks a
required scope must reconnect before synchronization resumes. Manager remains
an external PaaS and only supplies the documented symbolic runtime settings;
the application owns queue jobs, scheduling, OAuth grants, reads, writes,
retries, and retention.

## Disposable PostgreSQL smoke test

CI keeps the full PHPUnit suite on in-memory SQLite and separately rebuilds the
schema and runs the full PHPUnit suite against PostgreSQL. To repeat that smoke
test locally, point Laravel at a disposable, non-production database using
local-only credentials:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=music_map_test
DB_USERNAME=music_map_test
DB_PASSWORD=music_map_test
DB_SSLMODE=disable
SESSION_DRIVER=array
CACHE_STORE=array
QUEUE_CONNECTION=sync
```

With `pdo_pgsql` installed and that disposable database running, rebuild it and
run the same suite:

```bash
php artisan migrate:fresh --force --no-interaction
php artisan test
```

`migrate:fresh` destroys all tables in the selected database. Verify the target
is disposable before running it. Do not point these commands at `music_map` or
create application tables manually.

## Quality gates

```bash
composer test
vendor/bin/pint --test
npm run build
sh scripts/verify-source-contract --worktree
composer audit --locked --no-interaction
npm audit --audit-level=high
```

Production schema changes are not automated by CI or normal application release.
Before an image that reads the additive playlist tables is released, an operator
must apply the schema through the published supervised schema-release capability.
