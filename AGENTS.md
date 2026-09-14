# Repository Guidelines

music-map is a Laravel 13/PHP 8.4 web application with a Vite 8 and Tailwind CSS 4 frontend. Product behavior and scope are defined in @context/foundation/prd.md.

## Working Rules

- Keep durable product and stack decisions in `context/foundation/`; edit those documents in place. Put change-specific research and plans in `context/changes/<change-id>/`, and treat `context/archive/` as read-only.
- Never commit `.env`, credentials, OAuth tokens, or generated runtime data. Add safe placeholders to `.env.example`, and keep integration secrets out of logs.

## s-manager PaaS Boundary

- For work involving s-manager commands, runtime delivery, deployment, probes, or other Manager-provided capabilities, use the global `s-manager-use` skill and treat s-manager as an external PaaS.
- Keep only the public interoperability contract required by music-map in this repository: consumer-visible configuration names, required entrypoints or paths, data schemas, responses, exit codes, and semantic guarantees. Keep secret values confidential.
- Do not copy Manager implementation details into music-map plans, code, or tests. This includes host file validation, transport-size limits, mount flags, UID/GID choices, container arguments, locks, rollback, OAuth orchestration, and cleanup mechanics unless a detail is explicitly part of the public consumer contract.
- Do not route routine music-map integration work to `s-manager-ops`; that skill is reserved for implementing, operating, or investigating `/srv/manager` itself.

## Build, Test, and Development Commands

- `composer setup` installs PHP and Node dependencies, creates `.env` when absent, generates the app key, migrates SQLite, and builds assets.
- `composer dev` launches Laravel's coordinated local development processes.
- `composer test` clears cached configuration and runs the complete PHPUnit suite.
- `vendor/bin/pint --test` checks PHP formatting without changing files; use `vendor/bin/pint` to apply fixes.
- `npm run build` produces the Vite/Tailwind production bundle; `npm run dev` runs only the asset server.

## Project Structure & Module Organization

Application code lives in `app/`; HTTP entry points are in `routes/`, templates and frontend sources in `resources/`, and migrations, factories, and seeders in `database/`. PHPUnit suites are split between `tests/Unit/` and `tests/Feature/`. Treat `public/build/`, `vendor/`, `node_modules/`, and generated files under `storage/` as disposable artifacts.

## Coding Style & Naming Conventions

Follow @.editorconfig: LF endings, final newlines, four-space indentation, and two spaces for YAML. PHP follows Laravel Pint and PSR-4 namespaces from @composer.json. Use PascalCase class names, camelCase methods, timestamped snake_case migration filenames, and `*Test.php` test classes. Frontend files use ES modules; match the single-quoted, semicolon-terminated style in @vite.config.js.

## Testing Guidelines

PHPUnit 12 is configured by @phpunit.xml with in-memory SQLite. Put isolated logic in `tests/Unit/` and framework, HTTP, or database behavior in `tests/Feature/`; name methods `test_*`. Run one file with `php artisan test tests/Feature/ExampleTest.php`. No coverage threshold is currently configured.

## Commit & Pull Request Guidelines

Git history currently contains only `first commit`, so no prefix convention is established. Keep commits focused with concise imperative subjects. Pull requests should summarize behavior, link relevant issues, call out migrations or configuration changes, and include screenshots for Blade/UI changes. Report `composer test`, `vendor/bin/pint --test`, and `npm run build` results; no GitHub Actions workflow currently enforces them.
<!-- BEGIN @przeprogramowani/10x-cli -->

---
name: 10xDevs AI Toolkit - Module 3, Lesson 4 (E2E Tests)
description: End-to-end testing with AI tools
license: CC BY-NC-ND 4.0
metadata:
  tags: AI, E2E, testing, Playwright
  version: 1.0.0
  module: 3
  lesson: 4
---

## 10xDevs AI Toolkit - Moduł 3, Lekcja 4 (Testy E2E)

**Do testów E2E użyj umiejętności `/10x-e2e`.** Jest to jedyne źródło prawdy
dla przepływu pracy — ryzyko → test początkowy + reguły → generowanie → przegląd pod kątem pięciu
antywzorców → ponowne zapytanie → weryfikacja. `references/` tej umiejętności
zawierają pełne reguły, antywzorce, wzorzec początkowy i szablon promptu.

Kilka twardych reguł, które obowiązują jeszcze przed wywołaniem umiejętności:

- **Lokatory:** Najpierw `getByRole` / `getByLabel` / `getByText`; `getByTestId`
  tylko wtedy, gdy atrybuty dostępności są niejednoznaczne. Nigdy selektory CSS, XPath
  ani struktura DOM.
- **Nigdy `page.waitForTimeout()`.** Czekaj na stan: `toBeVisible()`,
  `waitForURL()`, `waitForResponse()`.
- **Niezależność testów + czyszczenie.** Każdy test działa samodzielnie — własna konfiguracja,
  akcja, asercja i czyszczenie; unikalne identyfikatory (sufiks znacznika czasu), aby równoległe uruchomienia
  i ponowne uruchomienia nie kolidowały.

Dwie granice, które należy rozróżnić:

- **DOM (migawka) jest domyślny.** Wizja (`--caps=vision`) jest uzupełnieniem dla
  ryzyk wizualnych (układ, z-index, animacja); dla regresji pikseli preferuj
  narzędzia deterministyczne (`toMatchSnapshot`, Argos, Lost Pixel). Wybór/koszt modelu VLM
  to temat debugowania (Lekcja 5), a nie testowania.
- **Healer pomaga w selektorach, szkodzi w logice.** Zmieniony selektor → healer
  odnajduje go ponownie (trasa przez przegląd PR). Zmienione zachowanie biznesowe → healer
  maskuje błąd; ten przypadek nieudanego testu do naprawy to Lekcja 5.

<!-- END @przeprogramowani/10x-cli -->
