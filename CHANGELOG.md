## [0.16.2](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.16.1...v0.16.2) (2026-08-19)


### Bug Fixes

* **tooling:** resolve the worktree SHA-1 without GNU coreutils ([#69](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/69)) ([14644c8](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/14644c81943df57b24a5922f3628b9b4998ba7b7)), closes [#68](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/68)

## [0.16.1](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.16.0...v0.16.1) (2026-08-18)


### Bug Fixes

* **tooling:** anchor the test env outside $TMPDIR and find PHP by path ([#66](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/66)) ([66ef1f3](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/66ef1f3130571d7691934447fe3a352df6ba2a86))

# [0.16.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.15.1...v0.16.0) (2026-08-18)


### Features

* **blocks:** add composable search blocks ([#60](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/60)) ([ca61b18](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/ca61b1803f593610e62b84e467684e0e39663972))

## [0.15.1](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.15.0...v0.15.1) (2026-08-17)


### Bug Fixes

* **tooling:** make isolated test environment reliable ([#61](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/61)) ([#62](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/62)) ([719a946](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/719a946e26bc102ceac84f989fe93bdc9975a30f))

# [0.15.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.14.0...v0.15.0) (2026-07-31)


### Features

* **tooling:** auto-install phpredis and gate degraded mode behind --allow-degraded ([#53](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/53)) ([#57](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/57)) ([52d8fe2](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/52d8fe2e9e2df496b03f73a99f2845f42950395c))

# [0.14.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.13.0...v0.14.0) (2026-07-31)


### Features

* **tooling:** one-shot isolated worktree test environments ([#53](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/53)) ([#56](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/56)) ([c0f285e](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/c0f285ea98e6691f4616c1f49762b40ccf0bd643))


### Reverts

* restore "one-shot isolated worktree test environments" to PR review ([#53](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/53)) ([ba35a43](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/ba35a439259c70c7ae5e872bd4d6a334ce3f5485)), closes [#55](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/55)

# [0.13.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.12.3...v0.13.0) (2026-07-31)


### Features

* **blocks:** integrate inherited Product Collection queries ([#51](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/51)) ([697cdef](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/697cdefdf3bfb234834013d15e14ff2ab1349419))
* **tooling:** one-shot isolated worktree test environments ([#53](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/53)) ([#55](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/55)) ([4527807](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/4527807adb89f094e015f3567ba8c89489228cb9))

## [0.12.3](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.12.2...v0.12.3) (2026-07-30)


### Bug Fixes

* **license:** assert one GPLv2-or-later claim with Shift64 attribution ([#49](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/49)) ([ebf0d88](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/ebf0d884aad9c44630b86a152a57f37ceeb09866))

## [0.12.2](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.12.1...v0.12.2) (2026-07-30)


### Bug Fixes

* **search:** honor WooCommerce catalog visibility ([#46](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/46)) ([be1d8dc](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/be1d8dc6c0a7148bfb429e9a2d5b718aa3a100b1))

## [Unreleased]

### Features

* **blocks:** integrate inherited WooCommerce Product Collections with Redis membership, marker-scoped totals, canonical URL state, and public Interactivity Router navigation while retaining WooCommerce rendering ownership ([#51](https://github.com/mateusz-zadorozny/shift64-woo-search/pull/51))
* **blocks:** replace PHP-only search blocks with composable Control/Panel children, progressive forms, scoped Interactivity API autocomplete, and native dialog behavior while preserving legacy parent content ([#60](https://github.com/mateusz-zadorozny/shift64-woo-search/pull/60))

### Bug Fixes

* **search:** exclude catalog-only products from Redis-backed full search and the public endpoint's default autocomplete mode while preserving direct product access ([#46](https://github.com/mateusz-zadorozny/shift64-woo-search/pull/46))

## [0.12.1](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.12.0...v0.12.1) (2026-07-30)


### Bug Fixes

* **build:** restore the executable bit on the tracked shell scripts ([#44](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/44)) ([df7bb9b](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/df7bb9b191e667c2ff3d1cc1bdb2bc1967e392af))

# [0.12.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.11.2...v0.12.0) (2026-07-30)


### Features

* **autocomplete:** configurable quick-search density and tray width ([#42](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/42)) ([c61dae0](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/c61dae05ea6ff4bf96026a9a27ec28f2debc8949)), closes [#41](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/41) [#41](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/41)

## [0.11.2](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.11.1...v0.11.2) (2026-07-29)


### Bug Fixes

* **blocks:** send editor preview attributes in the request body so firewalls stop rejecting the URL ([#38](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/38)) ([#40](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/40)) ([8beec70](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/8beec70b9ecc825a2b6756cdd51478349d7232f3))

## [0.11.1](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.11.0...v0.11.1) (2026-07-29)


### Bug Fixes

* **archive:** restore the search term after the Redis query so breadcrumbs and headings show it ([#37](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/37)) ([#39](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/39)) ([a2d6256](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/a2d6256afa7e9fa6b6d33db6a5e94ec2a998e13b))

# [0.11.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.10.2...v0.11.0) (2026-07-29)


### Features

* **archive:** break the debug panel's timings into request phases and browser timings ([#35](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/35)) ([18ece58](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/18ece5881abdb998305b2261c695a68bbb945b79))

## [0.10.2](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.10.1...v0.10.2) (2026-07-29)


### Bug Fixes

* **archive:** hide the storefront debug panel by default and keep it in sync with active filters ([#34](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/34)) ([9805c0c](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/9805c0c70b68ebbb86c0ad19da9cca62972b6b03)), closes [#hist](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/hist)

## [0.10.1](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.10.0...v0.10.1) (2026-07-29)


### Bug Fixes

* **search:** align autocomplete and full mode on which products match ([#26](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/26)) ([#28](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/28)) ([1610df6](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/1610df64646950080c392d57435b611b3c87c8d2))
* **tests:** split the multi-key array literal flagged by phpcs ([#36](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/36)) ([646cd9e](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/646cd9e98e623d7c51bf763cffa1a518b7d5ee22)), closes [#28](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/28)

# [0.10.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.9.1...v0.10.0) (2026-07-29)


### Features

* **admin:** six-workspace settings information architecture with canonical routes ([#33](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/33)) ([b85b303](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/b85b3038e458e8e56adf7ea5386a9d3d86d17ca3))

## [0.9.1](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.9.0...v0.9.1) (2026-07-28)


### Bug Fixes

* **demo-data:** carry the whole seed into the demo SKU ([#23](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/23)) ([#32](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/32)) ([b0900a4](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/b0900a4bc4ba0804c114d15cd9f425da987dae6d))

# [0.9.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.8.0...v0.9.0) (2026-07-28)


### Features

* **demo-data:** add a reset-only flag to tear down without reseeding ([#24](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/24)) ([#29](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/29)) ([e70d9ed](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/e70d9ed4c723b22b51ba73772803ebf52fa58b32))

# [0.8.0](https://github.com/mateusz-zadorozny/shift64-woo-search/compare/v0.7.1...v0.8.0) (2026-07-28)


### Features

* Implement new seeding script ([#18](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/18)) ([#22](https://github.com/mateusz-zadorozny/shift64-woo-search/issues/22)) ([553f4a7](https://github.com/mateusz-zadorozny/shift64-woo-search/commit/553f4a7e3e6d99c00a9104f98f3850dcb312e684))

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
