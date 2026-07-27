## [0.7.1](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.7.0...v0.7.1) (2026-07-27)


### Bug Fixes

* **frontend:** swap blockified pagination on AJAX page change ([#15](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/15)) ([#16](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/16)) ([fd4c013](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/fd4c0137b0ba50ac3b69f278df015360d0db3cae))

# [0.7.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.6.0...v0.7.0) (2026-07-21)


### Bug Fixes

* **admin:** send shift64_woo_search_filter_brands_enabled in save_filters AJAX payload ([99e5059](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/99e50590edfcd40342c2c79420456f15678e6359))
* **archive:** bypass custom AJAX partial fragment on non-Kadence block themes ([aa4b195](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/aa4b1951b1a0a9e818bf8d55a391b47f4d5fc168))
* **brands:** reindex products on brand deletion and cover the upgrade map ([c7dfc64](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/c7dfc64c49053d876fa55f5c9cd28501e9bd75be))
* **frontend:** expand productWrap selectors to support non-Kadence themes ([9616119](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/961611938c9ea607f0ffbc2f5c23a0f0b9f4f52f))
* **frontend:** guard single filter render and support modular AJAX DOM swap on block themes ([3e935ed](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/3e935edb24b26f341de369c07a4abcff2ad4ef5d))
* **tests:** drop ReflectionMethod::setAccessible, deprecated on PHP 8.5 ([304311c](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/304311c13b5fd2421fdd51fe53ec2e5ddfc80236))


### Features

* **brands:** add native WooCommerce product_brand support ([#10](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/10)) ([409c90f](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/409c90f6ab79f91ebfc2c38be771a35b821cc9ff))

# [0.6.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.5.0...v0.6.0) (2026-07-21)


### Features

* Spec: Native WooCommerce Brands Support (product_brand) ([#10](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/10)) ([#11](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/11)) ([2ecfa2b](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/2ecfa2b714c873f3b6ce6c8ea813523e4e8811a0))

# [0.5.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.4.0...v0.5.0) (2026-07-19)


### Features

* add PHP-only product search blocks ([#9](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/9)) ([349f616](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/349f6164d465d351fb498e150b3e27b6606f82b1))

# [0.4.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.3.0...v0.4.0) (2026-07-17)


### Features

* **docs:** add initial Starlight site ([#8](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/8)) ([7231934](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/723193497595352032146977611706192921f048))

# [0.3.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.2.0...v0.3.0) (2026-07-17)


### Features

* **core:** initial product search foundation ([#1](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/1)) ([7bf9fc5](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/7bf9fc5b61541a9d0e9c2776466dc0159d1fe9d5))

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
