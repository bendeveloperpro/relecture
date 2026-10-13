# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] — 2026-10-13

### Added

- Review screen: title and text blocks of a content in a three-column table (original
  text, correction, note), autosaved through the REST API with an optimistic lock.
- Correction statuses (to validate, accepted, rejected, applied) and review statuses
  (in progress, review complete, corrections applied), review rounds.
- Snapshot of the text, change detection and resync that keeps corrections and notes.
- Guidelines: notes flagged “for future content”, gathered on their own page, exportable.
- Exports: Excel (.xlsx), CSV, Word (.docx), Markdown and JSON, without external library.
- REST API `relecture/v1` and WP-CLI commands `wp relecture …`.
- French translation (fr_FR).
