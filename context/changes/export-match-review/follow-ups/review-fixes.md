# Implementation Review Follow-ups

## Integrate the canonical S-03 playlist fingerprint

- **Source contract**: `/srv/music-map/app/Actions/Playlists/FingerprintPlaylistContent.php` (`playlist-content:v1`).
- **When**: While integrating or rebasing S-05 with `bank-playlist-editing`.
- **Action**: Replace `app/Actions/ExportReviews/FingerprintPlaylist.php` with the shared `App\Actions\Playlists\FingerprintPlaylistContent` dependency in start, job, confirmation, and tests.
- **Boundary**: Keep `SourceTrack::fingerprint()` separate as the normalized, versioned `catalog-match:v1` cache identity; it does not replace the exact playlist-content fingerprint.
