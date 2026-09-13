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

Provider secrets and OAuth tokens must not be written to source files, database
tables, command output, or application logs.

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

OAuth, credential lifecycle, probe invocation, promotion, revoke, and recovery
remain PaaS responsibilities. Never pass credentials on the command line or
write their values to source, application storage, output, or logs.

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
php artisan test tests/Feature/Auth tests/Feature/BankAccessTest.php tests/Unit/Services/Auth/ResolveGoogleIdentityTest.php
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
