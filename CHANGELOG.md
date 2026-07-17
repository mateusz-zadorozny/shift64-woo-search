# [0.2.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.1.0...v0.2.0) (2026-07-17)


### Bug Fixes

* **ci:** disable git hooks in the Release job so releases can ship ([#6](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/6)) ([45ed0aa](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/45ed0aaa6191da96ad4deb268f7cb3e9a1d766e2)), closes [#2](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/2) [#4](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/4) [#4](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/4)
* replace Polish source strings with English and add language files ([#2](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/2)) ([8d17a53](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/8d17a53c037b239c65c56cbd41f21c352b59f015))
* **tests:** drop redundant setAccessible() calls that break PHP 8.5 ([#4](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/4)) ([169d16e](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/169d16e87c3a226ac1a6373d00f4e4cfcdd71ecd))


### Features

* raise the minimum PHP requirement from 7.4 to 8.3 ([#5](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/5)) ([#7](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/7)) ([0591a6e](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/0591a6ea08fd680dda770491ca708848c3174e73))

# Changelog

All notable changes to Shift64 Woo Search are documented in this file. The project follows Semantic Versioning and uses Conventional Commits for automated releases.

## [0.1.0] - 2026-07-16

### Added

- Initial independent Shift64 Woo Search development release.
- RediSearch-backed WooCommerce product indexing, autocomplete, archive search, facets, synonyms, statistics, administration, and WP-CLI tools.
- SHORTINIT endpoint deployment and generated per-site configuration.
- Release, test, and coding-standard foundations for the path to `1.0.0`.
