# Phase 4 manual verification

This file is the release evidence template for the exact candidate that will be
verified. It intentionally contains no credential values, tokens, account or
channel identifiers, authorization codes, or provider payloads.

## Candidate

- Prepared on: 2026-09-13
- Verification date: 2026-09-14
- Environment: live (pre-launch production environment approved for this smoke)
- Commit: `12b9837633bc1bf455af6e063ab3d11a9b7aabc7`
- Release: `mm-12b9837633bc-34790134642-1`
- Overall status: PASS

The observations below apply only to the exact commit and release recorded
above. No credential values, tokens, account or channel identifiers,
authorization codes, provider payloads, or personal data were retained.

## Required runtime settings

Record presence only; never copy values into this file.

| Setting | Present | Notes |
| --- | --- | --- |
| `APP_KEY` | PASS | Presence only; Laravel encryption key |
| `APP_PREVIOUS_KEYS` | N/A | No key rotation required for this release |
| `SPOTIFY_CLIENT_ID` | PASS | Presence only; shared application client identifier |
| `SPOTIFY_CLIENT_SECRET` | PASS | Presence only; application client credential |
| `SPOTIFY_REDIRECT_URI` | PASS | Exact canonical HTTPS application callback |
| `GOOGLE_CLIENT_ID` | PASS | Presence only; shared application client identifier |
| `GOOGLE_CLIENT_SECRET` | PASS | Presence only; application client credential |
| `YOUTUBE_REDIRECT_URI` | PASS | Exact canonical HTTPS application callback |

## Supervised schema release

- Public PaaS operation: `schema-release`
- Exact candidate matched the commit above: PASS
- Additive migration completed before application smoke: PASS
- Result and non-sensitive operator observation: PASS — the supervised
  operation completed successfully for the exact candidate, with the additive
  migration applied before the HTTPS and provider smoke checks.

The Manager implementation, secret transport, host paths, and lifecycle
mechanics are outside this application's evidence and must not be recorded here.

## Spotify live smoke

- Dedicated product smoke account used (no identifier recorded): PASS — the
  existing tester account was reused for the product smoke; the technical
  account and credential remained separate.
- Consent showed the four expected application scopes: PASS
- Link succeeded and displayed only the minimal label: PASS
- Relink of the same account succeeded: PASS
- Refresh/verify succeeded: PASS
- Invalid grant produced `reconnect-required`: PASS
- Unlink succeeded locally and displayed the `Remove Access` instruction: PASS
- Final provider-side `Remove Access` completed: PASS
- Client owner has active Premium (no identifier recorded): PASS — Premium Individual
- Occupied Development Mode allowlist seats: 2
- Technical and shared tester/product roles fit within the five-user limit: PASS — 2/5 unique seats

## YouTube live smoke

- Dedicated Google/Brand Account used (no identifier recorded): PASS
- Provider channel selection completed during consent/login: PASS
- Link succeeded and displayed only the minimal channel label: PASS
- Relink of the same channel succeeded: PASS
- Refresh/verify succeeded: PASS
- Invalid grant produced `reconnect-required`: PASS
- Reconnect after invalid grant succeeded: PASS
- Unlink succeeded locally and provider revoke was confirmed: PASS
- Final provider dashboard check confirmed no active grant: PASS
- Google login identity and the active `music-map` session remained intact: PASS

## Secret-absence observations

Use only narrowly scoped inspection for the exact candidate and environment.
Record a pass/fail observation, never the inspected values.

| Surface | Access token absent | Authorization code absent | Plaintext refresh token absent | Provider payload absent |
| --- | --- | --- | --- | --- |
| Database | PASS | PASS | PASS | PASS |
| Rendered HTML | PASS | PASS | PASS | PASS |
| Limited application logs | PASS | PASS | PASS | PASS |

## Sign-off

- Operator/reviewer: owner/operator; two independent manual reviewers
- Observations: Exact HTTPS root returned success and both unauthenticated
  callback paths returned the expected same-origin login redirect. Both
  provider lifecycles completed, the secret-absence matrix passed 12/12, final
  local streaming-account count was zero, provider grants were removed, and
  the Google login identities remained present. Both independent reviewers
  assessed 4.7, 4.8, and 4.9 as PASS and confirmed the exact healthy release;
  their only blocking finding before this update was the previously pending
  evidence artifact.
- Final result: PASS
