---
bootstrapped_at: 2026-08-28T23:15:54Z
starter_id: laravel
starter_name: Laravel
project_name: music-map
language_family: php
package_manager: composer
cwd_strategy: subdir-then-move
bootstrapper_confidence: verified
phase_3_status: ok
audit_command: "null"
---

## Hand-off

```text
---
starter_id: laravel
package_manager: composer
project_name: music-map
hints:
  language_family: php
  team_size: solo
  deployment_target: self-host
  ci_provider: github-actions
  ci_default_flow: auto-deploy-on-merge
  bootstrapper_confidence: verified
  path_taken: standard
  quality_override: false
  self_check_answers: null
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: false
  has_background_jobs: true
---

## Why this stack

Laravel jest rekomendowanym starterem PHP dla jednej osoby tworzącej po godzinach małe webowe MVP w ciągu trzech tygodni. Oficjalny Livewire Starter Kit z Flux UI pozwala wykonać większość aplikacji w znanym PHP, zapewniając uwierzytelnianie, konwencjonalną strukturę i produktywną warstwę komponentów. Kolejki Laravela obsłużą import, eksport oraz cykliczną synchronizację playlist. Zweryfikowana ścieżka bootstrappera używa Composera, zakłada wdrożenie na własnym serwerze pod adresem `music.adamis.me` i konfiguruje GitHub Actions do automatycznego wdrażania po pomyślnych testach zmian scalonych do `main`.
```

## Pre-scaffold verification

| Signal | Value | Severity | Notes |
| --- | --- | --- | --- |
| npm package | not run | n/a | non-JS starter |
| GitHub repo | not run | n/a | `https://laravel.com/docs` is not a GitHub repository URL; no recency signal available |

## Scaffold log

**Resolved invocation**: `composer create-project laravel/laravel .bootstrap-scaffold --no-interaction --prefer-dist`
**Strategy**: subdir-then-move
**Exit code**: 0
**Files moved**: 8900
**Conflicts (.scaffold siblings)**: `README.md` → `README.md.scaffold`
**.gitignore handling**: moved silently
**.bootstrap-scaffold cleanup**: deleted

The non-interactive shell did not include Composer in `PATH`, so the resolved invocation was executed through `/home/adam/.config/herd-lite/bin/composer`. Composer installed `laravel/laravel` v13.10.1 and 109 dependency packages. Its install-time check reported no security vulnerability advisories.

### Move log

| Scaffold entry | Resolution |
| --- | --- |
| `.editorconfig` | moved |
| `.env` | moved |
| `.env.example` | moved |
| `.gitattributes` | moved |
| `.gitignore` | moved silently |
| `.npmrc` | moved |
| `AGENTS.md` | moved |
| `CLAUDE.md` | moved |
| `README.md` | preserved as `README.md.scaffold` because the cwd copy won |
| `app/` | moved recursively |
| `artisan` | moved |
| `bootstrap/` | moved recursively |
| `composer.json` | moved |
| `composer.lock` | moved |
| `config/` | moved recursively |
| `database/` | moved recursively |
| `package.json` | moved |
| `phpunit.xml` | moved |
| `public/` | moved recursively |
| `resources/` | moved recursively |
| `routes/` | moved recursively |
| `storage/` | moved recursively |
| `tests/` | moved recursively |
| `vendor/` | moved recursively |
| `vite.config.js` | moved |

The starter did not contain a `context/` directory. The cwd `context/` tree remained canonical and untouched by the merge.

## Post-scaffold audit

**Tool**: skipped — no built-in audit tool configured for php
**Recommended external tool**: Roave's `security-advisories` Composer package or `local-php-security-checker`

The scaffold command's Composer run independently reported no security vulnerability advisories. This observation is not recorded as a substitute for the skipped post-scaffold audit.

## Hints recorded but not acted on

| Hint | Value |
| --- | --- |
| bootstrapper_confidence | verified |
| quality_override | false |
| path_taken | standard |
| self_check_answers | null |
| team_size | solo |
| deployment_target | self-host |
| ci_provider | github-actions |
| ci_default_flow | auto-deploy-on-merge |
| has_auth | true |
| has_payments | false |
| has_realtime | false |
| has_ai | false |
| has_background_jobs | true |

## Next steps

Next: a future skill will set up agent context (CLAUDE.md, AGENTS.md). For now, your project is scaffolded and verified — happy hacking.

Useful manual steps in the meantime:

- `git init` (if you have not already) to start your own repo history.
- Review any `.scaffold` siblings the conflict policy created and decide which version of each file to keep.
- Address audit findings per your project's risk tolerance — the full breakdown is in this log.
