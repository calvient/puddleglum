# Agent Notes

- This is a Laravel package that generates TypeScript files from a Laravel backend: Eloquent models, FormRequests, API routes, and `GlumRequest` / `GlumResponse` attributes.
- Treat generated TypeScript files as the primary product. Prefer tests that run the real `puddleglum:generate` command against Laravel-like fixtures and compare emitted files.
- The golden generated-output contract lives in `tests/Fixtures/Expected/puddleglum`.
- The realistic end-to-end tests live in `tests/Feature/GeneratePuddleglumTypesTest.php`.
- Run package tests with `./vendor/bin/testbench package:test --no-coverage`. Raw `vendor/bin/phpunit` can fail on this machine because the XML requests coverage and no coverage driver is installed.
- `composer test` runs the package test suite in parallel with `--no-coverage`.
- Run static analysis with `composer stan`.

## Generation Flow

- `src/Commands/PuddleglumGenerateCommand.php` reads `config('puddleglum.output')` and calls `PuddleglumGenerator`.
- `src/PuddleglumGenerator.php` reads the current working directory's `composer.json`, discovers PSR-4 PHP classes, dispatches class reflections to generators, builds a file map, and writes changed files only.
- `src/Generators/ModelGenerator.php` maps database columns, relations, relation counts, and accessors into model interfaces.
- `src/Generators/RequestGenerator.php` maps validation rules into request interfaces, including nested object and array rules.
- `src/Generators/ApiRouteGenerator.php` maps `api` middleware routes into Axios client classes under `api/`.
- `src/Support/TypeScriptFormatter.php` owns TypeScript indentation and write-if-changed behavior. Keep formatting decisions there when practical.

## Invariants

- Preserve the recent empty-body behavior: routes without a request body still pass `{}` for POST/PUT/PATCH calls.
- Preserve `GlumRequest([])` support. An empty attribute array is meaningful and must not be treated as absent.
- Do not rewrite unchanged generated files. The generator should leave mtimes intact when content is identical.
- Avoid private-helper unit tests as the main safety net. Add or update fixture/golden tests when generator behavior changes.
- Keep generated TS Prettier-shaped at source so users do not pay a large reformatting cost after generation.
