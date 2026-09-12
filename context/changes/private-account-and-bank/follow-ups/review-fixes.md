# Implementation Review Fixes

## F1 — Add throttling to Google OAuth endpoints

- **Status**: DONE
- **Source**: `context/changes/private-account-and-bank/reviews/impl-review.md`
- **Location**: `routes/web.php:10`
- **Work**: Define a dedicated Google OAuth limiter, attach it to the redirect and callback routes, and add a feature test proving requests are rejected before provider or resolver work after the limit.
- **Verification**: `php artisan test tests/Feature/Auth/GoogleAuthenticationTest.php` (13 tests, 119 assertions) and targeted `vendor/bin/pint --test` passed.

## F2 — Add sanitized telemetry for unexpected Google callback failures

- **Status**: DONE
- **Source**: `context/changes/private-account-and-bank/reviews/impl-review.md`
- **Location**: `app/Http/Controllers/Auth/GoogleAuthController.php:54`
- **Work**: Preserve the neutral user response while emitting a sanitized event containing only an error category or exception class and a correlation ID. Do not include exception messages, request URIs or query parameters, tokens, provider payloads, or email addresses.
- **Verification**: `php artisan test tests/Feature/Auth/GoogleAuthenticationTest.php` (13 tests, 120 assertions) and targeted `vendor/bin/pint --test` passed.

## F3 — Complete Polish account-form validation messages

- **Status**: DONE
- **Source**: `context/changes/private-account-and-bank/reviews/impl-review.md`
- **Location**: `lang/pl/validation.php:3`
- **Work**: Add every validation message reachable from the account forms, including `min.string`, and add a feature assertion for the rendered Polish short-password error.
- **Verification**: `php artisan test tests/Feature/Auth` passed (35 tests, 204 assertions), including the rendered Polish validation message; targeted `vendor/bin/pint --test` passed.

## F4 — Resolve Google OAuth throttle keys from the forwarded client

- **Status**: DONE
- **Source**: Pull request review follow-up.
- **Location**: `bootstrap/app.php:15`
- **Work**: Trust only the direct request peer, enable the forwarded-client and forwarded-proto headers, and retain the existing limiter key based on `Request::ip()`.
- **Verification**: The Google auth feature test exhausts client A's shared redirect/callback bucket, confirms client B remains allowed through the same proxy peer, and proves a hostile leftmost XFF prefix cannot evade client A's limit.
