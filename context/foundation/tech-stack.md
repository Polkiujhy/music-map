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
