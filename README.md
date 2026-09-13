# music-map

`music-map` is a Laravel application for keeping a private, platform-independent
playlist bank. The current slice provides email and Google authentication plus a
private, initially empty `/bank` area.

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
```

Manager does not handle application-user OAuth callbacks, grants, linked
accounts, refresh/reconnect, unlink/revoke, or playlist operations. Keep all
credential values out of source, output, and logs. `APP_PREVIOUS_KEYS` must
retain old Laravel encryption keys during a controlled `APP_KEY` rotation until
existing refresh tokens have been re-encrypted or users have reauthorized.

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

## Disposable PostgreSQL smoke test

CI keeps the full PHPUnit suite on in-memory SQLite and separately rebuilds the
schema and runs the critical authentication matrix against PostgreSQL. To repeat
that smoke test locally, point Laravel at a disposable, non-production database
using local-only credentials:

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
run the same critical matrix:

```bash
php artisan migrate:fresh --force --no-interaction
php artisan test tests/Feature/Auth tests/Feature/BankAccessTest.php tests/Feature/StreamingAccounts/StreamingAccountModelTest.php tests/Feature/StreamingAccounts/StreamingAccountLinkingTest.php tests/Unit/Integrations/StreamingAccounts/WithStreamingAccessTest.php tests/Feature/StreamingAccounts/StreamingAccountManagementTest.php tests/Unit/Services/Auth/ResolveGoogleIdentityTest.php
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

Production schema changes are not automated by CI or normal deployment. They use
the supervised `s-manager schema-release music-map` workflow with an exact release
manifest, current release ID, and fresh backup/restore evidence.
