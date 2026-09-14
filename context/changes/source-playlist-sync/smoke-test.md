# Source playlist synchronization smoke test

Run this checklist only against dedicated non-production provider accounts and
playlists. Use unique names such as `music-map-sync-smoke-YYYYMMDD-HHMMSS` and
keep every playlist at 20 positions or fewer. Never paste client secrets,
refresh tokens, access tokens, authorization codes, cookies, or raw provider
responses into this file, terminal transcripts, screenshots, or application
logs.

## Preparation

- [ ] Record the bank/source item IDs and order in a private scratch location
  that will be deleted after the run; do not record credentials.
- [ ] Create owned Spotify public and private playlists with distinct unique
  names, plus one owned private YouTube playlist.
- [ ] Put a short ordered list in each playlist, including one duplicate where
  the provider permits it, and keep a copy of the original state for cleanup.
- [ ] Connect the application account through the normal OAuth UI. For an older
  Spotify grant, reconnect and confirm consent includes
  `playlist-read-private`, `playlist-modify-private`,
  `playlist-modify-public`, `playlist-read-collaborative`, and
  `user-read-private`. Confirm YouTube consent includes
  `https://www.googleapis.com/auth/youtube`.

## Spotify public playlist

- [ ] Import the dedicated public playlist, activate synchronization, and
  confirm the initial preview direction and item counts.
- [ ] Change only the source order, run pull, and confirm the bank exactly
  matches the source, including duplicate positions.
- [ ] Change only the bank order, run push, and confirm Spotify exactly matches
  the bank.
- [ ] Change both sides differently before a run and confirm source-wins restores
  the bank without a write to Spotify.

## Spotify private playlist

- [ ] Repeat activation, pull, push, and source-wins checks for the dedicated
  private playlist.
- [ ] Reconnect once during the exercise and confirm synchronization resumes
  without replacing the bank or source playlist identity.

## YouTube private playlist

- [ ] Import the dedicated private playlist, activate synchronization, change
  only YouTube, and confirm pull preserves exact order.
- [ ] Change only the bank, run a real push, and confirm the YouTube playlist
  reaches the exact desired order.
- [ ] Record the run's non-secret operation ID. If a safe controlled failure can
  be introduced after one mutation, retry that same run/operation ID and confirm
  it reaches the desired state without a second admission. Otherwise leave this
  optional destructive-failure exercise unchecked and rely on the automated
  fault-injection matrix.
- [ ] Change YouTube after the run has read its starting state but before the
  next write can safely continue; confirm the result is source-wins pull and no
  duplicate admission is created.

## Cleanup

- [ ] Restore each provider playlist and bank playlist to its recorded original
  state, or delete every dedicated smoke-test playlist and imported bank copy.
- [ ] Delete the private scratch record of item IDs and operation IDs.
- [ ] Inspect captured output and artifacts for secret or token material; remove
  any affected artifact and rotate/revoke the credential if exposure occurred.
- [ ] Confirm no test playlist, queued retry, or automatic synchronization from
  this smoke test remains active.
