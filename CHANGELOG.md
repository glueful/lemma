# Changelog

All notable changes to this Glueful API application will be documented in this file.

This project is generated from `glueful/api-skeleton`. Start recording application-specific changes here after scaffolding.

## [Unreleased]

### Added
- Initial Glueful API skeleton.
- Delivery API `FilterCompiler`: safe, typed, filterable-only JSONB filter predicates
  (`?filter[field][op]=value`) with always-bound values, mirroring the filterable-field
  expression indexes via a shared `FieldSqlExpression` helper.

### Changed
- `FieldValidator` normalizes `datetime` field values to canonical ISO-8601 UTC
  (`YYYY-MM-DDTHH:MM:SSZ`) on write, keeping stored values lexicographically comparable
  as text for the datetime expression index and filter range comparisons.
