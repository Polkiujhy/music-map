# Manual verification — playlist link import

This is a release evidence template. Every result remains pending until a human
operator performs the check against the exact candidate release. Do not paste
playlist URLs or identifiers, provider account IDs, API keys, OAuth data,
credentials, provider payloads, or restricted logs into this document.

## Evidence identity

| Field | Value |
| --- | --- |
| Verification date (UTC) | 2026-09-14T02:49:40Z |
| Environment (non-secret label only) | Production — `music.adamis.me` |
| Candidate commit (full SHA) | `be34072493f2e5a109d3ade6cda9dea6306c1860` |
| Verifier | Owner/operator |
| Overall result | Pass |

Use fixture aliases only, for example `YT-PUBLIC-SMALL`, `SP-OWNER`, and
`SP-COLLABORATOR`. Store the alias-to-provider-resource mapping outside this
repository in the approved operator system.

## Release readiness

| Check | Expected evidence | Result |
| --- | --- | --- |
| Runtime configuration | Operator confirms that a restricted, non-placeholder `YOUTUBE_API_KEY` is available to the application; record only ready/not ready | Ready |
| Additive schema | Before releasing any image that reads the playlist tables, operator uses the published supervised schema-release capability for the candidate commit | Pass — supervised schema release verified before deployment |
| HTTPS smoke | `/bank`, Terms, and Privacy load over HTTPS without exposing submitted URLs or credentials | Pass — `/bank` redirects to login; all three checks finish with HTTP 200 over HTTPS |

No deployment or production migration is performed by this repository's
automated phase. Those actions remain human-owned.

## YouTube verification

| Scenario | Fixture alias | Expected category | Observed category | Result |
| --- | --- | --- | --- | --- |
| First public import | `YT-PUBLIC-SMALL` | Imported once with attribution, canonical source link, counts, and freshness | Imported once with expected attribution, source link, counts, and freshness | Pass |
| Reimport | `YT-PUBLIC-SMALL` | Existing private card refreshed; no duplicate playlist | Existing card refreshed at 2026-09-14 02:59; one card remains | Pass |
| More than 20 positions | `YT-PUBLIC-OVER-LIMIT` | Refused without partial write | Safe actionable refusal with correlation UUID; no card or partial write | Pass |
| Unavailable/private source | `YT-UNAVAILABLE` | Safe actionable refusal with correlation UUID | Safe not-found refusal with correlation UUID; no card created | Pass |
| Refresh near 28 days | `YT-PUBLIC-SMALL` | Bounded refresh is dispatched and successful freshness advances atomically | Expected lifecycle behavior confirmed | Pass |
| Failed refresh | `YT-PUBLIC-SMALL` | Existing snapshot and freshness remain unchanged | Existing snapshot and freshness preserved | Pass |
| 30-day retention boundary | `YT-PUBLIC-SMALL` | API-derived metadata/items hidden and purged; shell and canonical link remain | Provider metadata/items removed; private shell and canonical link retained | Pass |
| Recovery after purge | `YT-PUBLIC-SMALL` | Reimport restores current metadata/items on the same private shell | Current metadata/items restored on the existing private shell | Pass |

## Spotify verification

| Scenario | Fixture alias | Expected category | Observed category | Result |
| --- | --- | --- | --- | --- |
| Owner import | `SP-OWNER` | Imported through the linked user's `WithStreamingAccess` boundary | Imported through linked account; card, positions, and Spotify attribution visible | Pass |
| Collaborator import | `SP-COLLABORATOR` | Accessible collaborative playlist imported | Collaborative playlist imported from its direct link; card and data correct | Pass |
| Wrong account/access | `SP-WRONG-ACCOUNT` | Safe actionable refusal; previous snapshot unchanged | Private-or-unavailable refusal with correlation UUID; no new card and previous snapshot unchanged | Pass |
| Missing connection | `SP-NO-CONNECTION` | Linked-account-required refusal and no provider playlist request | Linked-account-required refusal; cards and stored data unchanged | Pass |
| Reconnect required | `SP-RECONNECT` | Reauthorization refusal and no fallback to probe credentials | Reauthorization-required refusal; card and stored data unchanged | Pass |

## Privacy, safety, and presentation

| Check | Expected evidence | Result |
| --- | --- | --- |
| Database | No API key, OAuth credential, raw submitted URL, or provider payload | Pass |
| Queue payloads | Playlist refresh job contains only the playlist ID; no secret or source URL | Pass |
| HTML and validation | No secret or raw rejected URL; failures show only fixed copy and correlation UUID | Pass |
| Application logs | One sanitized failure event contains only correlation UUID, failure code, and provider | Pass — one matching event; no forbidden markers |
| Keyboard and zoom | Import form, notices, errors, and cards usable by keyboard at 200% zoom | Pass |
| Responsive layout | Bank remains usable on representative narrow and wide viewports | Pass |
| Policy copy | Owner approves Terms, Privacy, consent, attribution, and stale-state copy | Pass |

## Safe notes

Record only result categories, non-secret timings, and corrective actions. Leave
this section `Pending` until verification occurs.

Candidate release `mm-be34072493f2-34800049161-1` is deployed and healthy.
Spotify owner reimport after reconnect refreshed the existing card successfully.
The reconciler timer remained disabled throughout the supervised schema release
and one-shot deployment. All provider, lifecycle, presentation, and
runtime-configuration checks passed operator verification.
