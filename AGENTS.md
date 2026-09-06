# Repository Guidelines

music-map is a Laravel 13/PHP 8.4 web application with a Vite 8 and Tailwind CSS 4 frontend. Product behavior and scope are defined in @context/foundation/prd.md.

## Working Rules

- Keep durable product and stack decisions in `context/foundation/`; edit those documents in place. Put change-specific research and plans in `context/changes/<change-id>/`, and treat `context/archive/` as read-only.
- Never commit `.env`, credentials, OAuth tokens, or generated runtime data. Add safe placeholders to `.env.example`, and keep integration secrets out of logs.

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
