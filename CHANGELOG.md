# Changelog

## v1.16 - 2026-06-08

- Adds a GitHub-hosted fallback for PHP 8.4 CI and release jobs when `BEPLY_GHA_RUNNER` is not available for the repository.
- Supersedes the cancelled `v1.15` validation run without moving the existing immutable tag.

## v1.15 - 2026-06-08

- Enables tag-based GitHub releases for the PHP 8.4 validation workflow.

## v1.14 - 2026-06-08

- Adds a Tests workflow for the PHP 8.4 scanner.

- Declares PHP 8.4 as the minimum supported runtime.
- Adds a PHP 8.4 compatibility scan across plugin source files in CI.
- Aligns release metadata for the PHP 8.4 validation pass.
