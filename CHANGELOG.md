# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.2] - 2026-02-05

### Changed

- Applied Laravel Best Practices to code structure and patterns
- Updated `pestphp/pest` from `^3.0` to `^4.0`
- Updated `pestphp/pest-plugin-laravel` from `^3.0` to `^4.0`
- Updated `larastan/larastan` from `^2.0` to `^3.9`
- Extended `orchestra/testbench` support to include `^10.0` for Laravel 12 compatibility
- Consolidated PHPStan configuration through Larastan integration

## [0.5.1] - 2026-01-30

### Fixed
- Issue with stub file in MakeImporterCommand

## [0.5.0] - 2026-01-30

### Added

- Comprehensive troubleshooting documentation with solutions for common issues
- Performance optimization tips and best practices guide
- Security best practices documentation with configuration examples
- Advanced error handling examples and patterns
- Enhanced documentation for `relateMany()` functionality
- Detailed `resolveModelUsing()` configuration examples
- Composite keys workaround documentation
- `ingest:prune-files` command documentation

### Documentation

- Created new `docs/advanced/troubleshooting.md` with 10 detailed troubleshooting sections
- Enhanced existing documentation with practical examples and solutions
- Added debugging techniques and performance optimization guides
- Improved error handling documentation with real-world scenarios

## [0.4.1] - 2026-01-25

### Changed

- See full changelog for details

## [0.4.0] - 2026-01-25

### Changed

- See full changelog for details

## [0.3.0] - 2026-01-23

### Changed

- See full changelog for details

## [0.2.0] - 2026-01-20

### Changed

- See full changelog for details

## [0.1.2] - 2025-12-05

### Fixed

- Fix tests

## [0.1.1] - 2025-12-03

### Fixed

- Add version to `composer.json`

## [0.1.0] - 2025-12-03

### Added

- Initial release of Laravel Ingest
- Configuration-driven ETL framework with `IngestConfig` fluent API
- Support for multiple source types: Upload, Filesystem, URL, FTP, SFTP
- CSV and Excel file parsing with streaming support via Generators
- Duplicate handling strategies: Skip, Update, Fail, UpdateIfNewer
- Automatic relationship resolution for BelongsTo and BelongsToMany
- Row-level validation with Laravel validation rules
- Dry run mode for testing imports without database changes
- Queue-based chunk processing for large files
- Transaction support with atomic and chunk-level modes
- Comprehensive error tracking with failed row export
- REST API endpoints for import management and monitoring
- Artisan commands: `ingest:run`, `ingest:list`, `ingest:status`, `ingest:cancel`, `ingest:retry`
- Event system: IngestRunStarted, ChunkProcessed, RowProcessed, IngestRunCompleted, IngestRunFailed
- Prunable models for automatic log cleanup
- Full documentation site
- GitHub workflow integration

[Unreleased]: https://github.com/zappzerapp/laravel-ingest/compare/v0.5.2...HEAD

[0.5.2]: https://github.com/zappzerapp/laravel-ingest/compare/v0.5.1...v0.5.2

[0.5.0]: https://github.com/zappzerapp/laravel-ingest/compare/v0.4.1...v0.5.0

[0.4.1]: https://github.com/zappzerapp/laravel-ingest/compare/v0.4.0...v0.4.1

[0.4.0]: https://github.com/zappzerapp/laravel-ingest/compare/v0.3.0...v0.4.0

[0.3.0]: https://github.com/zappzerapp/laravel-ingest/compare/v0.2.0...v0.3.0

[0.2.0]: https://github.com/zappzerapp/laravel-ingest/compare/v0.1.2...v0.2.0

[0.1.2]: https://github.com/zappzerapp/laravel-ingest/compare/v0.1.1...v0.1.2

[0.1.1]: https://github.com/zappzerapp/laravel-ingest/compare/v0.1.0...v0.1.1

[0.1.0]: https://github.com/zappzerapp/laravel-ingest/releases/tag/v0.1.0