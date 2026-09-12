# Frame Brief: Trusted client IP for Google OAuth throttling

> Framing step before /10x-plan. This document captures what is *actually*
> at issue, separated from what was initially assumed.

## Reported Observation

In the deployed public-edge → application-nginx → FPM topology,
`bootstrap/app.php` trusts only `HEADER_X_FORWARDED_PROTO`, so `Request::ip()`
ignores the forwarded client address and resolves to the public edge proxy seen
by the application nginx. Consequently, every user shares this 10-request
bucket; any unauthenticated caller can exhaust it and prevent all Google OAuth
redirects and callbacks for the decay period.

## Initial Framing (preserved)

- **User's stated cause or approach**: Laravel does not trust the forwarded
  client address, so the limiter resolves every external request to the
  public-edge peer.
- **User's proposed direction**: Configure a validated client-IP trust chain and
  key the limiter from that address rather than the proxy peer.
- **Pre-dispatch narrowing**: This is a conclusion from configuration and
  topology inspection, not a reproduced production lockout incident.

## Dimension Map

The observation could originate at any of these dimensions:

1. **Public ingress contract** — the application must correctly consume the
   ordered XFF chain supplied by its controlled ingress.
2. **NGINX-to-FPM hand-off** — application nginx may discard the forwarded
   address or expose the wrong peer as `REMOTE_ADDR`.
3. **Laravel proxy trust** — the trusted header mask excludes XFF while the
   trusted-proxy scope is wildcarded. ← initial framing
4. **Limiter identity** — redirect and callback may use a key that does not
   represent the external client.

## Hypothesis Investigation

| Hypothesis | Evidence | Verdict |
| --- | --- | --- |
| The ingress contract contains an ordered client chain | The deployed route appends the edge-observed peer to XFF. Strict right-to-left resolution can therefore distinguish it from a caller-supplied leftmost prefix. This is an environmental input, not work owned by this change. | STRONG |
| Application nginx loses the client identity | `docker/nginx/default.conf:27-33` passes request headers to FastCGI and stock `fastcgi_params` sets `REMOTE_ADDR $remote_addr`; FPM therefore sees public-edge as `REMOTE_ADDR` while XFF remains available. | PARTIAL — transport is intact, but the peer fallback explains the symptom |
| Laravel ignores XFF | `bootstrap/app.php:15-18` trusts only `HEADER_X_FORWARDED_PROTO`. Symfony returns `REMOTE_ADDR` when XFF is not in the trusted mask; an exact-version probe reproduced that result. | STRONG |
| The limiter creates one global bucket | `app/Providers/FortifyServiceProvider.php:49-50` keys the shared redirect/callback limiter only by `Request::ip()`. Both routes attach the same limiter in `routes/web.php:10-16`. | STRONG |

## Narrowing Signals

- Live containers place public-edge, application nginx, and FPM on the shared
  `srv-internal` network; current addresses are dynamic container addresses.
- Existing coverage proves one ten-request bucket is exhausted, but does not
  model two forwarded clients or a hostile incoming XFF prefix.
- Enabling XFF while retaining `at: '*'` makes every XFF address trusted and can
  select attacker-controlled input; it is not a safe isolated fix.
- A strict right-to-left chain that trusts only the immediate validated hop
  selects the edge-observed client rather than a caller-supplied leftmost value.

## Cross-System Convention

Applications behind a controlled ingress should trust only the validated direct
hop, enable only the forwarded headers they consume, resolve the chain from the
trusted side, and test both client separation and spoof resistance. This change
owns that application-side consumption; ingress configuration remains outside
the repository's responsibility.

## Reframed (or Confirmed) Problem Statement

> **The actual problem to plan around is**: Music Map does not safely consume
> the client-IP chain supplied by its deployed ingress, causing a global OAuth
> throttle key today and a spoofing risk if XFF is enabled naively.

The initial diagnosis of the global bucket is correct. The application must
narrow proxy trust to its direct peer, accept XFF and forwarded proto, and prove
that separate clients get separate buckets while a hostile XFF prefix cannot
choose the limiter key.

## Confidence

- **HIGH** — application code, framework behavior, generated proxy configuration,
  and the live container topology all support the same causal chain.

## What Changes for /10x-plan

Keep the correction inside `music-map`: update Laravel's trusted-proxy contract
and add behavioral limiter tests. Do not add manager configuration work or
manager operational responsibilities to this change or its PR description.

## References

- Source files: `bootstrap/app.php:15`,
  `app/Providers/FortifyServiceProvider.php:49`, `routes/web.php:10`,
  `docker/nginx/default.conf:27`,
  `/srv/manager/manager/music_map_route.py:46`,
  `/srv/manager/services/public-edge/compose.yml:24`
- Related review: `context/changes/private-account-and-bank/reviews/impl-review.md`
- Investigation tasks: `edge_chain`, `fpm_chain`, `laravel_ip`
