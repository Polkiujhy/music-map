---
project: music-map
researched_at: 2026-09-06
recommended_platform: current self-hosted server
runner_up: Railway
context_type: mvp
tech_stack:
  language: PHP 8.4
  framework: Laravel 13
  runtime: Docker containers on Linux
---

## Recommendation

**Deploy on the current self-hosted server.**

For this MVP, the existing host wins after context weighting: it supports the required long-running Laravel queue worker and scheduler, provides co-located PostgreSQL, and already has a project-specific immutable-image deployment and rollback path through `s-manager`. The first production release is the working Laravel scaffold rather than the completed MVP. After that baseline and a safe canary, a dedicated reconciler automatically deploys each eligible schema-neutral merge to `main`; schema-changing releases use a separate supervised s-manager operation before deployment. This keeps intermediate work live without granting the reconciler authority to mutate PostgreSQL. Its raw platform score is lower than managed PaaS options because the operator still owns the OS, network, and hardware, but its low marginal cost and the implementation already completed for this exact four-container topology make it the fastest route within the three-week MVP window. Railway is the preferred escape hatch if host reliability or operator effort becomes unacceptable.

The decision reflects these interview constraints: persistent processes are required; cost and developer experience have equal weight; there is no existing platform familiarity to break ties; one European region is sufficient; and co-located services are preferred.

## Platform Comparison

Scoring uses `Pass = 2`, `Partial = 1`, and `Fail = 0`. The score measures agent-friendly operations; runtime incompatibility remains a hard filter regardless of the score.

| Platform | CLI-first | Managed / serverless | Agent-readable docs | Stable deployment API | MCP / integration | Total | Eligibility |
|---|---:|---:|---:|---:|---:|---:|---|
| Cloudflare Workers + Pages | Pass | Pass | Pass | Pass | Pass | 10/10 | Filtered: no native PHP 8.4/Laravel runtime or persistent PHP worker |
| Vercel | Pass | Pass | Pass | Pass | Partial | 9/10 | Filtered: Docker/Services are Public Beta and do not keep Laravel workers alive |
| Netlify | Partial | Pass | Pass | Partial | Pass | 8/10 | Filtered: no PHP application runtime or persistent PHP worker |
| Fly.io | Pass | Pass | Pass | Pass | Partial | 9/10 | Eligible; managed PostgreSQL makes the complete stack expensive |
| Railway | Pass | Pass | Pass | Pass | Partial | 9/10 | Eligible; shortlisted |
| Render | Pass | Pass | Pass | Pass | Pass | 10/10 | Eligible; shortlisted |
| Current self-hosted server | Pass | Partial | Pass | Pass | Partial | 8/10 | Eligible; recommended after context weighting |

**Cloudflare Workers + Pages.** The operational surface is excellent: Wrangler supports deploy, rollback, and logs; documentation is available in agent-readable formats; and Cloudflare publishes official MCP servers. The candidate fails the hard runtime filter because Workers and Pages Functions do not natively run PHP 8.4 or Laravel 13, and cannot host a persistent `queue:work` process. Cloudflare Containers became **GA on 2026-04-13**, but that is a different architecture: instances use ephemeral disks, default to sleeping after inactivity, and currently require more explicit lifecycle and scaling control. It also lacks co-located managed PostgreSQL. Sources: [supported languages](https://developers.cloudflare.com/workers/languages/), [Containers architecture](https://developers.cloudflare.com/containers/concepts/architecture/), [pricing](https://developers.cloudflare.com/containers/platform/pricing/), and [Cloudflare MCP](https://developers.cloudflare.com/agents/model-context-protocol/cloudflare/servers-for-cloudflare/).

**Vercel.** Vercel documents Laravel 13/PHP 8.4 through `Dockerfile.vercel` and FrankenPHP, offers excellent previews, CLI operations, Markdown documentation, and an official MCP integration. It still fails the persistent-process requirement: containers execute as stateless functions, scale to zero after inactivity, and do not provide the always-on Laravel worker/scheduler model. Docker/Services and Vercel MCP were **Beta on 2026-09-06**. Hobby is free for personal non-commercial use; Pro starts at $20/month. Sources: [Laravel with Docker](https://vercel.com/kb/guide/laravel-php-with-docker), [rollback CLI](https://vercel.com/docs/cli/rollback), [agent-readable docs](https://vercel.com/docs/agent-resources/markdown-access), and [pricing](https://vercel.com/pricing).

**Netlify.** Netlify has strong deployment tooling, agent-readable documentation, and an official MCP server, but no PHP application runtime or container service. PHP is only a build-time tool and was documented only through PHP 8.3; Functions use JavaScript/TypeScript or Go compatibility. Background and scheduled functions have bounded execution times and cannot run Laravel's persistent worker. Netlify Database/Postgres was **GA on 2026-04-28**, while Blobs was still labelled **Beta**, but those services do not overcome the runtime mismatch. Sources: [build software](https://docs.netlify.com/build/configure-builds/available-software-at-build-time/), [Functions overview](https://docs.netlify.com/build/functions/overview/), [function limits](https://docs.netlify.com/build/functions/configuration/), and [agent setup](https://docs.netlify.com/build/build-with-ai/agent-setup-guides/agent-setup-overview/).

**Fly.io.** Fly.io passes the runtime filter through OCI images and supports separately scaled web, queue, and cron process groups. Its CLI and documentation are strong, but rollback means redeploying a retained previous image rather than invoking a dedicated rollback command. Two small always-on Machines are roughly $12–14/month before the database; co-located Managed Postgres starts around $38/month plus storage, taking the preferred complete stack above $50/month. `fly mcp` was **experimental on 2026-09-06**, and Managed Postgres is limited to selected regions. Sources: [Laravel deployment](https://fly.io/docs/laravel/), [process groups](https://fly.io/docs/launch/processes/), [rollback guide](https://fly.io/docs/blueprints/rollback-guide/), [pricing](https://fly.io/docs/about/pricing/), and [Managed Postgres](https://fly.io/docs/mpg/).

**Railway.** Railway is the runner-up because it supports Docker, permanent services, Laravel app/worker/cron layouts, PostgreSQL, private networking, PR environments, and GitHub autodeploy with low initial cost. The estimated MVP cost is $5–15/month, driven by actual RAM and CPU rather than 10k–100k light requests. Its PostgreSQL template is co-located but formally unmanaged, so backups and database maintenance remain the user's responsibility. Rollback is available through the dashboard and GraphQL API, with image retention of 72 hours on Hobby and 120 hours on Pro; there is no dedicated rollback CLI command. The remote Railway MCP was in **public testing** and Cloud Agents were **Beta on 2026-09-06**. Sources: [Laravel guide](https://docs.railway.com/guides/laravel), [Dockerfiles](https://docs.railway.com/builds/dockerfiles), [rollback](https://docs.railway.com/guides/roll-back-bad-deploy), [databases](https://docs.railway.com/databases), [pricing](https://docs.railway.com/pricing), and [Railway MCP](https://docs.railway.com/ai/mcp-server).

**Render.** Render has the highest unweighted score. It supports Docker, persistent background workers, cron jobs, WebSockets, managed PostgreSQL, private networking, PR previews, API-controlled rollback, agent-readable docs, and an official MCP server. A minimal paid web + worker + PostgreSQL + cron setup is about $21/month; the current separate nginx and FPM topology may require another service or a combined web image, increasing adaptation effort or cost. The Frankfurt region is available, but the service region cannot later be changed. The documentation-search MCP was **experimental on 2026-09-06**; the main Render MCP was not labelled experimental. Sources: [Laravel with Docker](https://render.com/docs/deploy-php-laravel-docker), [background workers](https://render.com/docs/background-workers), [rollbacks](https://render.com/docs/rollbacks), [MCP](https://render.com/docs/mcp-server), and [regions](https://render.com/docs/regions/).

**Current self-hosted server.** The host is a Debian system with four CPU cores, 16 GiB RAM, a large `/srv` volume, shared PostgreSQL, public nginx/TLS infrastructure, and a prepared `music.adamis.me` route. `s-manager` provides deterministic commands for exact four-image deployment, readiness verification, bounded logs, status, and rollback. PostgreSQL and Music Map host state have active off-host schedules; their current backups, isolated restores, freshness checks and full Restic read-data verification passed before the first deployment. The platform loses points because OS patching, power, residential networking, capacity, and hardware recovery remain local responsibilities; there is no automatic preview environment; and MCP access is read-only. Evidence: [`s-manager` deployment contract](/srv/manager/docs/music-map-image-deploy.md), [provisioning contract](/srv/manager/docs/music-map-provision.md), and [deployment drill](/srv/manager/docs/music-map-image-deploy-verification-20260906.md).

### Shortlisted Platforms

#### 1. Current self-hosted server (Recommended)

It directly matches the existing PHP 8.4 images and four roles, already has the dedicated database and runtime directories provisioned, and adds almost no new monthly platform cost. The project-specific deploy engine verifies immutable digests, migration fingerprints, container health, and HTTP readiness, then automatically restores the accepted release after a failed update.

#### 2. Railway

Railway is the lowest-friction managed alternative with persistent services and a plausible $5–15/month MVP bill. It trails the current host because the existing release topology must be adapted and its PostgreSQL template still leaves backup and maintenance work with the operator.

#### 3. Render

Render supplies the most complete managed operational story, including managed PostgreSQL, previews, and mature agent integrations. It ranks third for this MVP because the minimum paid topology costs more and the current split nginx/FPM images require platform-specific adaptation.

## Anti-Bias Cross-Check: Current Self-Hosted Server

### Devil's Advocate — Weaknesses

1. The machine, power supply, router, ISP connection, and public IP path form a single failure domain. A hardware or access failure can violate the 24-hour recovery target even when the database backup is healthy.
2. Music Map shares four CPU cores, memory, disk I/O, PostgreSQL, and the public edge with several unrelated services. Resource limits contain individual containers but cannot create missing physical capacity during a host-wide spike.
3. Backups and restore tests are active, but they still depend on this host's timers, credentials and network path. Alert freshness and periodic restore evidence must remain monitored so an enabled timer is not mistaken for recoverability.
4. The exact-image deployer deliberately refuses unapproved migration changes. The scaffold establishes the initial baseline early; every later schema-changing feature still needs a fresh verified backup, explicit expand/contract compatibility approval and a supervised schema-release operation before deployment.
5. Production has no automatic branch preview. OS security updates, certificate renewal, DDNS/routing, monitoring, and incident response remain operator work and compete with the three-week feature schedule.

### Pre-Mortem — How This Could Fail

The team chose the existing host because its marginal cost was small and the container layout was already prepared. The scaffold deployed successfully, but a later feature added migrations and was merged as though it were schema-neutral. The reconciler correctly refused it; under schedule pressure an operator bypassed the supervised schema-release workflow and applied the migration without a fresh restore-tested backup or backward-compatibility review. The new application then failed, while the previous image could not safely use the changed database shape. At the same time, unrelated services saturated shared PostgreSQL and disk I/O, causing playlist workers to exceed the one-minute interaction target. A power, disk, or router failure then took the entire host offline. Although off-host backups existed, rebuilding Debian, Docker, routing, secrets, images, and the public edge took longer than the required 24 hours. The MVP returned late and with an ambiguous rollback path, making the apparent cost saving more expensive than a managed PaaS subscription.

### Unknown Unknowns

- The trusted workflow publishes images and a release manifest, but publication alone is not deployment evidence; the host independently verifies the merge, workflow, artifact and current branch head.
- The early scaffold baseline makes later migration drift normal during development. Without the planned supervised schema-release operation, the first schema-changing feature will remain fail-closed and cannot be deployed by the reconciler.
- Rollback depends on the retained local images and manager release journal. Image cleanup or loss of `/var/lib/s-manager/music-map-deploy` can remove the authoritative previous-release path.
- `music.adamis.me` currently terminates TLS but intentionally returns HTTP 503 until the application is deployed and the public-edge upstream is enabled.
- Actual availability depends on ISP behavior, address changes, NAT/port forwarding, router state, power recovery, and unattended host boot behavior, none of which is proven by an application health check.

## Operational Story

- **Preview deploys**: Pull requests run application tests, formatting, audits, frontend builds, and four Docker target builds in GitHub Actions, but they do not currently publish a preview URL. A preview would need a separately named isolated Compose project, database, secrets, and route; this is not part of the current host contract.
- **Deployment trigger**: After activation, every new `main` head must come from a merged pull request and pass the trusted CI workflow for that exact commit. CI publishes four private GHCR images by immutable digest and a strict release manifest; an independent host reconciler rechecks the branch head, associated merged PR, workflow result, artifact and migration fingerprint before invoking `s-manager`. A green workflow for a direct push is not deployable. Migration drift remains blocked until the candidate completes the supervised schema-release path.
- **Review governance**: GitHub protects `main` from direct pushes and requires the blocking CI checks. The maintainer's decision to merge is the durable evidence that review is complete; the host does not duplicate GitHub's approval-count policy. Ordinary bypass is disabled.
- **Isolation from StorageApp**: Music Map uses its own GitHub App, read-only GHCR credential, reconciler state, receipts, and deployment configuration. It does not consume StorageApp approval tokens, reports, deployer state, credentials, or internal promotion paths. The only shared components are explicitly platform-level facilities such as `s-manager`, PostgreSQL, alerts, backups, and the public edge.
- **Secrets**: Production values live in `/srv/manager/secrets/music-map.env`, outside the repository. Provisioning creates the file once, preserves values on retry, and restricts it to mode `0600`; the application containers receive it through Compose `env_file`. GitHub App and GHCR credentials are separate manager-owned systemd credentials. A human operator rotates values with secret-safe tooling; secrets must not be printed in logs, receipts, or command arguments.
- **Rollback**: Run `s-manager rollback music-map --expected-current <current-release-id>`. The manager restores the retained previous four-image release and verifies container plus HTTP readiness. It does not reverse migrations, restore secrets, or restore application data, and zero downtime is not promised.
- **Approval**: Routine schema-neutral releases need no separate manual promotion after a reviewed PR is merged. A human still approves initial schema creation, every schema-changing release, secret rotation, public routing changes, database deletion, and rollback when data compatibility is uncertain. The reconciler remains disabled until the scaffold is manually deployed and accepted, so automatic deployment always has a healthy rollback target. It never runs migrations.
- **Logs**: Use `s-manager status music-map --json` for structured state and `s-manager logs music-map --no-follow --tail 100 --timestamps` for a read-only bounded snapshot. The host's read-only MCP broker also exposes fixed list, status, and bounded log operations, but no deployment mutation.

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---:|---:|---|
| Host, power, router, and ISP are one failure domain | Devil's advocate | M | H | Document and rehearse a bare-host restore within 24 hours; keep encrypted off-host backups and replacement-host instructions. |
| Shared CPU, RAM, disk, and PostgreSQL contention delays workers | Devil's advocate | M | M | Add Music Map to the central resource policy, monitor queue duration and host pressure, and stop nonessential services before exceeding the 60-second product target. |
| Runtime storage or release journals silently stop reaching off-host backup | Devil's advocate | L | H | Keep freshness and heartbeat alerts active, retain the reviewed Music Map allowlist, and require recurring isolated restore evidence rather than relying on timer state alone. |
| Initial deployment blocked by migration fingerprint | Research finding | H | M | Apply the exact reviewed migration set to the dedicated empty database, verify it independently, then record only the matching baseline fingerprint before deployment. |
| Application rollback cannot undo database migrations | Devil's advocate | M | H | Keep automatic deploy schema-neutral. For every changed migration fingerprint, require a supervised s-manager schema-release, backward-compatible expand/contract review and a fresh backup plus isolated restore before mutation. |
| CI publishes only part of a release or emits an inconsistent manifest | Unknown unknowns | L | M | Keep manifest emission after all four pushes, verify each digest and revision label, and let the host reject artifacts that do not match the exact workflow SHA. |
| No branch preview causes defects to be discovered on production | Devil's advocate | M | M | Keep migrations backward-compatible and add an isolated preview Compose project only when a feature's risk justifies its setup cost. |
| Local image cleanup removes rollback material | Unknown unknowns | M | H | Exclude current and previous Music Map digests from cleanup until a newer release has passed its retention window; monitor free disk space. |
| Dynamic DNS, NAT, TLS, or reboot behavior breaks the public route | Unknown unknowns | M | H | Monitor `https://music.adamis.me/up` externally and rehearse DDNS, certificate renewal, router recovery, and unattended service startup. |
| Secrets appear in application or operator logs | Research finding | L | H | Keep secrets only in the manager-owned env file, retain redaction checks, and inspect bounded logs after integration failures before broader log shipping. |
| PHP/Laravel image or deployment contract drifts | Research finding | M | M | Keep PHP 8.4, Laravel 13, four image roles, health checks, numeric users, and migration trees under CI contract checks before publication. |

## Getting Started

1. Protect `main` so changes arrive only through pull requests and the blocking `application`, `container-images`, and `source-security` checks must pass. Do not grant an ordinary direct-push bypass; a maintainer merge is the review decision consumed by the deployment contract.
2. Land the application workflow and Manager reconciler as separate reviewed pull requests. Keep Music Map's GitHub App, GHCR read credential, release state, and systemd units independent from every StorageApp deployment path.
3. Verify the already provisioned production inputs without changing them: `s-manager provision music-map --status`, then `s-manager status music-map --json`. The expected starting state is a dedicated `music_map` database with `schema: not_initialized` and application state `not_deployed`.
4. Merge the scaffold release PR. The trusted workflow must run the version-pinned checks, publish the `fpm`, `nginx`, `queue`, and `scheduler` images for the exact 40-character source SHA, and emit a schema-version-1 release manifest containing only immutable GHCR digests.
5. Initialize the dedicated PostgreSQL database from that exact scaffold candidate through `s-manager initialize-schema`. Independently verify the migration ledger and database identity, then manually deploy and validate all four roles while the route remains in maintenance or CIDR-limited validation mode.
6. Switch the healthy scaffold to `live`, enable the reconciler and verify a harmless merged PR canary is automatically deployed while retaining the scaffold as `previous`. Schema-neutral intermediate PRs then deploy without manual promotion.
7. Before merging the first migration-changing feature, land and exercise the supervised `s-manager schema-release` workflow. It requires a fresh PostgreSQL backup and isolated restore, exact candidate images, independent ledger verification and explicit backward-compatibility approval; the reconciler itself remains unable to run migrations.

## Out of Scope

The following were not evaluated in this research:

- Docker image configuration
- CI/CD pipeline setup
- Production-scale architecture (multi-region, HA, DR)
