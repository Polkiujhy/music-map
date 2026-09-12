# Spotify and YouTube platform-access readiness

This runbook verifies the external capabilities required by Music Map before
playlist import, account linking, export or synchronization is implemented. It
proves development readiness only: Spotify Development Mode and Google
External/Testing are not production approval.

Run the matrix separately for the dedicated technical account and a fresh test
account on each provider. Nie wolno użyć konta technicznego jako fallbacku.
A failed test-account check remains failed. YouTube explicitly reuses the
configured Google OAuth client under `music-map.platform-access.v1`, but its
callback, grant, scopes and refresh token remain separate from Google login.

## Evidence safety

Record results only in
`context/changes/platform-access-readiness/reviews/manual-verification.md`.
Nie zapisuj sekretów ani PII. In particular, never record access or refresh
tokens, API keys, client secrets, authorization codes, complete provider
payloads, email addresses, provider account IDs, playlist IDs or private URLs.
Use local aliases such as
`spotify-technical` and record only the response category and a short sanitized
observation.

Do not put credentials in command-line arguments, shell history, URLs,
screenshots or repository files. Use the provider's official console/API
Explorer or a reviewed local OAuth tool that accepts secrets through a hidden
prompt or process environment. Clear its temporary state after each identity.
Application logs must retain at most an error class/category and correlation ID.

## Preconditions

- Spotify app is in Development Mode, its owner has active Premium, both test
  identities are explicitly authorized, and every redirect URI is an exact
  match. New Development Mode apps are limited to five authorized users.
- The Google Cloud project has YouTube Data API v3 enabled and an OAuth consent
  screen configured as External/Testing. Both Google identities are test users
  and each has a YouTube channel.
- Runtime configuration supplies all `SPOTIFY_*` and `YOUTUBE_*` names listed in
  `.env.example`; values are inspected only for presence, never copied into
  evidence.
- Each identity owns an isolated two-item source playlist. A separate Spotify
  playlist has made that identity a genuine collaborator. A separate YouTube
  playlist is available as public or `unlisted`; another is private.
- The operator has selected two ordinary catalog items per platform and has a
  private cleanup ledger outside the repository containing every created
  resource ID and URL.
- The official provider references and quota pages are re-read on the UTC date
  of the check. A contract change produces `BLOCKED`, not an improvised bypass.

## Result rules

Each row starts as `PENDING` and finishes as exactly one of:

- `PASS`: the expected success or expected denial was observed for the stated
  identity with the documented minimal authorization.
- `BLOCKED`: configuration is absent, provider behavior differs, quota prevents
  the check, or the result cannot be established safely.

Classify failures as `configuration`, `401`, `403`, `404/unavailable`,
`429/quota`, `5xx/provider`, or `unexpected`. Do not copy response bodies.
Never retry `429` or `5xx` automatically during this readiness check.

## Spotify matrix

Use Authorization Code flow independently for each identity. Confirm that the
grant yields renewable access without recording the token. Request only the
scopes needed by the exercised path:

- stable current-user subject: `user-read-private`;
- owned or collaborator item read: `playlist-read-private`;
- create/update with `public=false`: `playlist-modify-private`.

Do not add `playlist-read-collaborative` merely to call the current playlist
items endpoint. Re-evaluate it only if a later feature discovers collaborative
playlists through the current-user playlist collection.

Do not request email scope or use email as account identity. Establish the
stable account subject with the current-user profile response and record only
that a non-empty stable subject was observed.

For `spotify-technical`, then independently for `spotify-test`:

1. Complete Authorization Code consent with the minimal union of scopes above;
   confirm renewable access and a stable subject (`PASS`), or classify the
   failure (`BLOCKED`).
2. Read the identity's own two-item playlist with
   `GET /playlists/{playlist_id}/items`; verify two ordered items.
3. Read the prepared collaborative playlist and verify its two ordered items.
   Following or possessing a share link alone does not satisfy this check.
4. Request items from a playlist for which the identity is neither owner nor
   collaborator. The expected result is `403`; metadata-only access is not a
   successful item read.
5. Create a playlist with `POST /me/playlists`, explicitly setting
   `public=false`, and add its ID to the private cleanup ledger.
6. Add two items with `POST /playlists/{id}/items`, read them back, then reorder
   or replace them with `PUT /playlists/{id}/items`. Verify the final order and
   a changed snapshot without recording either identifier.
7. Confirm that the playlist is absent from the owner's public profile and
   search. Record the limitation: `public=false` is not access control and a
   person with the link may still obtain access.

Development Mode owner Premium, app-user limits and current endpoint names are
part of the observation. A missing capability is `BLOCKED`; scraping or an
application-credentials fallback is forbidden.

## YouTube matrix

Use an API key only for public-data reads. For each account-owned operation use
Authorization Code flow with `access_type=offline` and the exact scope published
by the current PaaS contract: `https://www.googleapis.com/auth/youtube`. Do not
request the partner scope. A service account does not replace a normal Google
account with a YouTube channel.

First verify the application-only boundary:

1. With the API key, call `playlists.list` and `playlistItems.list` for the
   prepared public/`unlisted` playlist and verify the two ordered items.
2. With the same API key and no OAuth grant, request the private playlist. The
   expected result is no accessible resource (`404/unavailable`) or the current
   documented denial category; any returned private items are `BLOCKED`.

For `youtube-technical`, then independently for `youtube-test`:

1. Complete Authorization Code consent with offline access. Confirm a refresh
   token is issued and that `channels.list(mine=true)` resolves a channel,
   without recording token, channel ID or account data.
2. Create a playlist with `playlists.insert`, explicitly setting
   `status.privacyStatus=unlisted`, and add its ID to the private cleanup ledger.
3. Add two videos with two `playlistItems.insert` calls, then read them back.
4. Set manual playlist ordering if required by the current UI/provider rules,
   update one item's `snippet.position` with `playlistItems.update`, and verify
   the changed order.
5. Confirm that the playlist is reachable by its private test link but does not
   appear as public channel content.

For Google External/Testing, record that a refresh token covering YouTube scopes
normally expires after seven days. That is an accepted development limitation,
not evidence of production readiness.

## Quota budget for 50 utworów

Recalculate this section against the official quota page during every run.
Under the 2026-09-04 YouTube schedule, a conservative one-account export costs:

| Operation | Calls | Units per call | Units |
| --- | ---: | ---: | ---: |
| `playlists.insert` | 1 | 50 | 50 |
| `playlistItems.insert` | 50 | 50 | 2,500 |
| `playlistItems.list` verification | 1 | 1 | 1 |
| `playlistItems.update` reorder check | 1 | 50 | 50 |
| Total excluding matching | | | 2,601 |

Catalog matching is a separate budget. Current `search.list` has a dedicated
default allowance of 100 calls/day and costs one unit per call; a worst-case
one-search-per-track pass consumes 50 of those calls. Record actual console
quota before and after the live test. The readiness test itself uses two items.
Spotify publishes rate limits rather than a stable per-method unit schedule;
record success or `429/quota`, do not invent a unit total.

## Cleanup and final gate

1. Manually remove/unfollow every test playlist created on Spotify, noting that
   Spotify Web API has no delete-playlist operation. Delete every YouTube test
   playlist with the owning identity.
2. Re-read/list using the same identity and record only whether cleanup was
   confirmed. Remove the private cleanup ledger and clear local tool state.
3. Search the new repository diff and bounded application logs for accidental
   credentials, private URLs, IDs or PII. If found, stop, revoke affected
   credentials and sanitize the artifact before continuing.
4. Mark the F-01 gate `PASS` only when every required matrix row and cleanup row
   is `PASS`. Any `PENDING` or `BLOCKED` keeps F-01 and all dependent slices
   blocked.

## Official references

- Spotify: [February 2026 migration guide](https://developer.spotify.com/documentation/web-api/tutorials/february-2026-migration-guide),
  [playlist items](https://developer.spotify.com/documentation/web-api/reference/get-playlists-items),
  [playlist visibility](https://developer.spotify.com/documentation/web-api/concepts/playlists),
  and [quota modes](https://developer.spotify.com/documentation/web-api/concepts/quota-modes).
- Google/YouTube: [OAuth authorization](https://developers.google.com/youtube/v3/guides/authentication),
  [web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server),
  [playlist API](https://developers.google.com/youtube/v3/docs/playlists), and
  [quota calculator](https://developers.google.com/youtube/v3/determine_quota_cost).
