# Development Utilities

These scripts support local development and continuous integration. They are excluded from distribution packages.

- `install-wp-tests.sh` installs the WordPress PHPUnit test suite.
- `install-phpredis-local.sh` installs the PHP Redis extension in a Local development site.
- `diagnose-category-facets.php` inspects category facet data in a WordPress environment.

Production Redis provisioning is intentionally outside the plugin repository. BYOR operators should use their own infrastructure tooling; the managed service will use a separate private control plane.
