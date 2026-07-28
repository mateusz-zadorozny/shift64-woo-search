# Installing phpredis in Local by Flywheel

## TL;DR

```bash
# Site must be running in Local first.
bin/install-phpredis-local.sh
```

Then in WP Admin: **WooCommerce → Shift64 Woo Search → System → Connection → Test Connection**
should turn green. Then click **Regenerate SHORTINIT Config** under **System → Diagnostics** for
good measure.

When to re-run:
- Local upgrades the bundled PHP (e.g. `php-8.4.18+1` → `php-8.4.19+0`).
- You switch the site's PHP version in Local UI.
- Plugin shows: *"Connection failed: phpredis extension is not installed."*

The script auto-detects which Local site to target (first running one).
Pass an explicit Local site id as the first argument to override:
`bin/install-phpredis-local.sh <SITE_ID>`.

## Why this is needed

The plugin talks to Redis through the native **phpredis** extension (not the
pure-PHP `predis` library). Local's PHP distribution does not ship phpredis,
so we have to compile it ourselves against Local's PHP headers and drop the
resulting `redis.so` into Local's extension directory.

Quick intuition for the two layers involved:

| Layer | What it is | Where it runs |
|---|---|---|
| Redis | The search engine itself (RediSearch module). | The `redis-stack-server` Docker container on `127.0.0.1:6379`. |
| phpredis | The PHP "driver" that knows how to speak Redis's protocol. | Inside PHP — a `.so` file loaded by `extension=` in `php.ini`. |

Having Redis running is necessary but not sufficient. Without phpredis, PHP
has no way to talk to it, so the plugin's "Test Connection" fails immediately,
no autocomplete works, archive page filtering falls back to nothing useful,
and `wp shift64-woo-search` commands error out.

## Why we don't just `pecl install redis`

Local's PHP is a self-contained, pre-built distribution shipped from a CI
builder. Two consequences:

1. **`phpize` and `php-config` have a hardcoded prefix** pointing at the
   builder's filesystem: `/Users/distiller/project/php/<ver>/bin/darwin-arm64/`.
   That path doesn't exist on your Mac, so an out-of-the-box `phpize` errors
   with `Cannot find build files at '/Users/distiller/...'`.
2. **The PHP binary's compiled-in `extension_dir`** also points at the
   distiller path, so a bare `extension=redis.so` in `php.ini` fails with
   `Unable to load dynamic library 'redis.so'` even when the file is sitting
   in the *real* extension directory of Local's bundle.

Additionally, `phpize` refuses paths containing whitespace, and the real
bundle path is under `~/Library/Application Support/Local/...` (note the
space in `Application Support`).

The install script handles all three:

- Detects the distiller prefix in `phpize`/`php-config` and rewrites
  patched copies that point at the real bundle.
- Creates a `/tmp` symlink without spaces to bypass the whitespace check.
- Writes the `php.ini` entry as an **absolute path** (matching the convention
  Local already uses for `imagick.so` and `xdebug.so`).

A pure `pecl install redis` from Homebrew's PHP would also work in principle,
but Homebrew's PHP 8.4 and Local's PHP 8.4 ship slightly different bundled
dylibs, so the resulting `.so` may load but crash on certain calls. Building
against Local's own headers is the safe path.

## What the script does, step by step

1. Picks the Local site (first running, or `$1` if given) by scanning
   `~/Library/Application Support/Local/run/`.
2. Finds the live `php-fpm` master process for that site and uses `lsof`
   on its PID to determine which `lightning-services/php-X.Y.Z+N` bundle
   it loaded from. This is more reliable than parsing config files.
3. Reads `PHP API` from `php -i` to compute the correct extension
   subdirectory (`no-debug-non-zts-<PHP_API>`).
4. Creates a temp working dir, symlinks the bundle as `/tmp/lp-phpredis-shim.<pid>`
   (no spaces), and writes patched `phpize`/`php-config` that point at the
   symlink instead of the distiller path.
5. Clones [phpredis/phpredis](https://github.com/phpredis/phpredis) (master),
   runs `phpize` → `./configure` → `make`. All output goes to
   `$WORK/build.log` and is kept on failure so you can inspect it.
6. Sanity-loads the freshly built `redis.so` via Local's CLI PHP to confirm
   the class `Redis` registers.
7. Copies the `.so` into Local's extension dir.
8. Backs up the site's `php.ini` (timestamped `.bak.<epoch>`), removes any
   stale `extension=*redis.so` line, and appends one with the canonical
   absolute path.
9. Sends `SIGUSR2` to the php-fpm master — a graceful reload that re-reads
   `php.ini` without dropping in-flight requests.
10. Final verify: opens a Redis connection from CLI using the site's `php.ini`
    and PINGs the server.

## Troubleshooting

**"no running Local site detected"** — Start the site in Local first.
Then re-run the script.

**Build fails** — Check the log path printed in the error message (something
like `/var/folders/.../phpredis-build.XXXX/build.log`). The most likely
cause is missing Xcode Command Line Tools: `xcode-select --install`.

**Final verify says "Redis connect failed"** — phpredis is installed
correctly, but the Redis container isn't reachable on `127.0.0.1:6379`.
Check that `redis-stack-server` is running in Docker. From the host:

```bash
docker ps | grep redis-stack
nc -zv 127.0.0.1 6379
```

**"Test Connection" in WP Admin still says phpredis missing** — Your php-fpm
master PID changed between when this script started and finished (rare, but
possible if Local restarted services). Re-run the script, or restart the
site in Local UI.

**You switched the site to PHP 8.5/8.3 and now it's broken again** — Expected.
phpredis is compiled per-PHP-API. Re-run `bin/install-phpredis-local.sh`.

**Where things live on disk**

| Thing | Path |
|---|---|
| Site `php.ini` | `~/Library/Application Support/Local/run/<SITE_ID>/conf/php/php.ini` |
| Extension dir (per PHP version) | `~/Library/Application Support/Local/lightning-services/php-<ver>/bin/darwin-arm64/lib/php/extensions/no-debug-non-zts-<API>/` |
| Backup ini files | next to the live `php.ini`, named `php.ini.bak.<epoch>` |

## Caveats for production

This setup is **local-dev only**. On staging/production servers we don't use
Local — phpredis is installed system-wide via the OS package manager
(`apt install php8.4-redis` on Debian/Ubuntu, `dnf install php-redis` on RHEL)
and `php.ini` lives in `/etc/php/<version>/fpm/conf.d/`. See
`bin/setup-redis-instance.sh` for the production Redis provisioning side.
