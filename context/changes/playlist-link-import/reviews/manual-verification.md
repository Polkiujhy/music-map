# Manual verification — playlist link import

This is a release evidence template. Every result remains pending until a human
operator performs the check against the exact candidate release. Do not paste
playlist URLs or identifiers, provider account IDs, API keys, OAuth data,
credentials, provider payloads, or restricted logs into this document.

## Evidence identity

| Field | Value |
| --- | --- |
| Verification date (UTC) | Pending |
| Environment (non-secret label only) | Pending |
| Candidate commit (full SHA) | Pending |
| Verifier | Pending |
| Overall result | Pending |

Use fixture aliases only, for example `YT-PUBLIC-SMALL`, `SP-OWNER`, and
`SP-COLLABORATOR`. Store the alias-to-provider-resource mapping outside this
repository in the approved operator system.

## Release readiness

| Check | Expected evidence | Result |
| --- | --- | --- |
| Runtime configuration | Operator confirms that a restricted, non-placeholder `YOUTUBE_API_KEY` is available to the application; record only ready/not ready | Pending |
| Additive schema | Before releasing any image that reads the playlist tables, operator uses the published supervised schema-release capability for the candidate commit | Pending |
| HTTPS smoke | `/bank`, Terms, and Privacy load over HTTPS without exposing submitted URLs or credentials | Pending |

No deployment or production migration is performed by this repository's
automated phase. Those actions remain human-owned.

## YouTube verification

| Scenario | Fixture alias | Expected category | Observed category | Result |
| --- | --- | --- | --- | --- |
| First public import | `YT-PUBLIC-SMALL` | Imported once with attribution, canonical source link, counts, and freshness | Pending | Pending |
| Reimport | `YT-PUBLIC-SMALL` | Existing private card refreshed; no duplicate playlist | Pending | Pending |
| More than 20 positions | `YT-PUBLIC-OVER-LIMIT` | Refused without partial write | Pending | Pending |
| Unavailable/private source | `YT-UNAVAILABLE` | Safe actionable refusal with correlation UUID | Pending | Pending |
| Refresh near 28 days | `YT-PUBLIC-SMALL` | Bounded refresh is dispatched and successful freshness advances atomically | Pending | Pending |
| Failed refresh | `YT-PUBLIC-SMALL` | Existing snapshot and freshness remain unchanged | Pending | Pending |
| 30-day retention boundary | `YT-PUBLIC-SMALL` | API-derived metadata/items hidden and purged; shell and canonical link remain | Pending | Pending |
| Recovery after purge | `YT-PUBLIC-SMALL` | Reimport restores current metadata/items on the same private shell | Pending | Pending |

## Spotify verification

| Scenario | Fixture alias | Expected category | Observed category | Result |
| --- | --- | --- | --- | --- |
| Owner import | `SP-OWNER` | Imported through the linked user's `WithStreamingAccess` boundary | Pending | Pending |
| Collaborator import | `SP-COLLABORATOR` | Accessible collaborative playlist imported | Pending | Pending |
| Wrong account/access | `SP-WRONG-ACCOUNT` | Safe actionable refusal; previous snapshot unchanged | Pending | Pending |
| Missing connection | `SP-NO-CONNECTION` | Linked-account-required refusal and no provider playlist request | Pending | Pending |
| Reconnect required | `SP-RECONNECT` | Reauthorization refusal and no fallback to probe credentials | Pending | Pending |

## Privacy, safety, and presentation

| Check | Expected evidence | Result |
| --- | --- | --- |
| Database | No API key, OAuth credential, raw submitted URL, or provider payload | Pending |
| Queue payloads | Playlist refresh job contains only the playlist ID; no secret or source URL | Pending |
| HTML and validation | No secret or raw rejected URL; failures show only fixed copy and correlation UUID | Pending |
| Application logs | One sanitized failure event contains only correlation UUID, failure code, and provider | Pending |
| Keyboard and zoom | Import form, notices, errors, and cards usable by keyboard at 200% zoom | Pending |
| Responsive layout | Bank remains usable on representative narrow and wide viewports | Pending |
| Policy copy | Owner approves Terms, Privacy, consent, attribution, and stale-state copy | Pending |

## Safe notes

Record only result categories, non-secret timings, and corrective actions. Leave
this section `Pending` until verification occurs.

Pending.
