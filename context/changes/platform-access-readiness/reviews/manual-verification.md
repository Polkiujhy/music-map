# Platform access manual verification

Status: `PENDING`

This is a sanitized evidence template, not proof that checks have run. Replace
`PENDING` only from a contemporary execution of
`docs/platform-access-readiness.md`.

Never record access or refresh tokens, API keys, client secrets, authorization
codes, complete provider payloads, email addresses, provider account IDs,
playlist IDs, private URLs, screenshots containing them, or other PII.

## Execution context

- UTC date: `PENDING`
- Environment: `development-readiness`
- Spotify application alias: `spotify-development`
- Google project alias: `youtube-testing`
- Official documentation rechecked: `PENDING`
- Operator: `PENDING` (non-PII role only)

## PaaS preflight (2026-09-12 UTC)

- Published protocol: `music-map.platform-access.v1`
- Sanitized status: both providers reported `configured=false`; no prior probe
  receipt and no recovery requirement were reported.
- Published runtime configuration does not currently name delivery of the
  application-side `YOUTUBE_API_KEY`; this remains a PaaS contract gap.
- Consumer entrypoint: the current checkout does not expose
  `php artisan platform-access:probe`; the published PaaS document names the
  entrypoint but does not publish its complete input/output JSON schema.
- Result: `BLOCKED` before OAuth or playlist mutation. No credential value was
  read and no provider operation was attempted.

## Capability matrix

| Provider | Identity alias | Scopes | Capability | Response category | Visibility / quota observation | Result |
| --- | --- | --- | --- | --- | --- | --- |
| Spotify | spotify-technical | user-read-private, read-private, modify-private | Authorization Code, renewable access, stable subject | PENDING | Development Mode | PENDING |
| Spotify | spotify-technical | read-private | own playlist items | PENDING | two ordered items | PENDING |
| Spotify | spotify-technical | read-private | collaborator playlist items | PENDING | two ordered items | PENDING |
| Spotify | spotify-technical | read-private | foreign non-collaborator denial | PENDING | expected denial | PENDING |
| Spotify | spotify-technical | modify-private | create, add two items, update/reorder | PENDING | `public=false` | PENDING |
| Spotify | spotify-technical | modify-private | profile/search visibility | PENDING | absent; link is not access control | PENDING |
| Spotify | spotify-test | user-read-private, read-private, modify-private | Authorization Code, renewable access, stable subject | PENDING | Development Mode | PENDING |
| Spotify | spotify-test | read-private | own playlist items | PENDING | two ordered items | PENDING |
| Spotify | spotify-test | read-private | collaborator playlist items | PENDING | two ordered items | PENDING |
| Spotify | spotify-test | read-private | foreign non-collaborator denial | PENDING | expected denial | PENDING |
| Spotify | spotify-test | modify-private | create, add two items, update/reorder | PENDING | `public=false` | PENDING |
| Spotify | spotify-test | modify-private | profile/search visibility | PENDING | absent; link is not access control | PENDING |
| YouTube | application | API key | public/`unlisted` playlist items | PENDING | two ordered items | PENDING |
| YouTube | application | API key | private playlist denial | PENDING | expected unavailable/denial | PENDING |
| YouTube | youtube-technical | youtube | Authorization Code offline and channel presence | PENDING | External/Testing | PENDING |
| YouTube | youtube-technical | youtube | create `unlisted`, add two items, update/reorder | PENDING | quota delta: PENDING | PENDING |
| YouTube | youtube-technical | youtube | link/channel visibility | PENDING | link works; not public | PENDING |
| YouTube | youtube-test | youtube | Authorization Code offline and channel presence | PENDING | seven-day Testing token limit accepted | PENDING |
| YouTube | youtube-test | youtube | create `unlisted`, add two items, update/reorder | PENDING | quota delta: PENDING | PENDING |
| YouTube | youtube-test | youtube | link/channel visibility | PENDING | link works; not public | PENDING |

## Failure classification

Record only sanitized observations. Allowed categories are `configuration`,
`401`, `403`, `404/unavailable`, `429/quota`, `5xx/provider`, and `unexpected`.

- Observation: `PENDING`
- Product/roadmap impact: `PENDING`

## Cleanup

| Identity alias | Provider | Created resources removed/unfollowed | Read-back confirmed | Result |
| --- | --- | --- | --- | --- |
| spotify-technical | Spotify | PENDING | PENDING | PENDING |
| spotify-test | Spotify | PENDING | PENDING | PENDING |
| youtube-technical | YouTube | PENDING | PENDING | PENDING |
| youtube-test | YouTube | PENDING | PENDING | PENDING |

- Private cleanup ledger removed: `PENDING`
- Local OAuth/API tool state cleared: `PENDING`
- Sanitized diff/log review: `PENDING`

## Gate decision

- Development readiness: `PENDING`
- Production readiness: not claimed
- Spotify Development Mode limits accepted: `PENDING`
- Google External/Testing limits accepted: `PENDING`
- Dependent slices remain blocked while any row is not `PASS`: yes
