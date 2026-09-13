# Phase 4 manual verification

This file is the release evidence template for the exact candidate that will be
verified. It intentionally contains no credential values, tokens, account or
channel identifiers, authorization codes, or provider payloads.

## Candidate

- Prepared on: 2026-09-13
- Verification date: PENDING
- Environment: PENDING (dedicated non-production live-smoke environment)
- Commit: PENDING (record the exact committed candidate before schema release)
- Overall status: NOT PERFORMED

No supervised schema release or live provider smoke has been performed or
claimed by this artifact yet.

## Required runtime settings

Record presence only; never copy values into this file.

| Setting | Present | Notes |
| --- | --- | --- |
| `APP_KEY` | PENDING | Laravel encryption key |
| `APP_PREVIOUS_KEYS` | PENDING | Required when prior keys must decrypt existing records |
| `SPOTIFY_CLIENT_ID` | PENDING | Shared application client identifier |
| `SPOTIFY_CLIENT_SECRET` | PENDING | Application client credential |
| `SPOTIFY_REDIRECT_URI` | PENDING | Exact HTTPS application callback |
| `GOOGLE_CLIENT_ID` | PENDING | Shared application client identifier |
| `GOOGLE_CLIENT_SECRET` | PENDING | Application client credential |
| `YOUTUBE_REDIRECT_URI` | PENDING | Exact HTTPS application callback |

## Supervised schema release

- Public PaaS operation: `schema-release`
- Exact candidate matched the commit above: PENDING
- Additive migration completed before application smoke: PENDING
- Result and non-sensitive operator observation: NOT PERFORMED

The Manager implementation, secret transport, host paths, and lifecycle
mechanics are outside this application's evidence and must not be recorded here.

## Spotify live smoke

- Dedicated product account used (no identifier recorded): PENDING
- Consent showed the four expected application scopes: PENDING
- Link succeeded and displayed only the minimal label: PENDING
- Relink of the same account succeeded: PENDING
- Refresh/verify succeeded: PENDING
- Invalid grant produced `reconnect-required`: PENDING
- Unlink succeeded locally and displayed the `Remove Access` instruction: PENDING
- Client owner has active Premium (no identifier recorded): PENDING
- Occupied Development Mode allowlist seats: PENDING (record count only)
- Technical, tester, and product accounts fit within the five-user limit: PENDING

## YouTube live smoke

- Dedicated Google/Brand Account used (no identifier recorded): PENDING
- Provider channel selection completed during consent/login: PENDING
- Link succeeded and displayed only the minimal channel label: PENDING
- Relink of the same channel succeeded: PENDING
- Refresh/verify succeeded: PENDING
- Invalid grant produced `reconnect-required`: PENDING
- Unlink succeeded locally and attempted provider revoke: PENDING
- Google login identity and the active `music-map` session remained intact: PENDING

## Secret-absence observations

Use only narrowly scoped inspection for the exact candidate and environment.
Record a pass/fail observation, never the inspected values.

| Surface | Access token absent | Authorization code absent | Plaintext refresh token absent | Provider payload absent |
| --- | --- | --- | --- | --- |
| Database | PENDING | PENDING | PENDING | PENDING |
| Rendered HTML | PENDING | PENDING | PENDING | PENDING |
| Limited application logs | PENDING | PENDING | PENDING | PENDING |

## Sign-off

- Operator/reviewer: PENDING (role or initials only)
- Observations: NOT PERFORMED
- Final result: PENDING
