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

## Managed-export credential boundary

Managed account exports depend on the separate, versioned
`music-map.managed-export.v1` PaaS capability. Only the persistent queue worker
receives its Unix-socket locator. Manager owns the technical OAuth grant,
refresh exchange, replacement-token adoption and operator recovery; Music Map
owns export jobs, provider mutations, retry policy and product idempotency. The
application receives only a short-lived access token and keeps it in memory for
the current provider operation, with no fallback to probe credentials or
technical tokens in its environment.

The consumer accepts only the exact versioned response schema and performs no
provider mutation after a malformed or failure response. Transport failures are
not retry authorization. A retry is permitted only when a validated broker
error document explicitly contains `retryable: true`; replacement refresh
tokens never cross the PaaS boundary into Music Map. The v1 transport bounds a
newline-terminated request at 4,096 bytes and a newline-terminated response at
16,384 bytes; exceeding either published limit fails closed.
