# Changelog

## [0.1.0] — unreleased

- `scan`: deterministic inspection of working, staged, or branch changes for weakened tests
  (removed, skipped, emptied, tautological, bent expectations), swallowed errors, removed validation,
  fixed-input special cases, new suppressions, and relaxed PHPUnit / PHPStan / Psalm / Composer / Infection / CI settings.
- `lock` / `verify`: a contract of files and single tests an agent must not change; changing the lock itself is reported.
- `baseline` + `scan --mutation`: report when a file's MSI drops below its trusted baseline.
- `install-hook`: git pre-commit hook and a Claude Code Stop-hook setting.
