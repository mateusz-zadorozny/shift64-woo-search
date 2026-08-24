import { execFileSync } from 'node:child_process';

/**
 * BASE_URL selects the target site: the CI `wp server` (default) or a LocalWP
 * dev site (e.g. BASE_URL=http://my-site.local).
 */
export const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8889';

const WP_CLI_BIN = process.env.WP_CLI_BIN || 'wp';
const WP_ROOT = process.env.WP_ROOT;

/**
 * Run a wp-cli command against the target install and return stdout.
 * Honors the same WP_CLI_BIN / WP_ROOT contract as bin/e2e-provision.sh.
 */
export function wpCli(args: string[]): string {
	const finalArgs = WP_ROOT ? [...args, `--path=${WP_ROOT}`] : args;
	return execFileSync(WP_CLI_BIN, finalArgs, {
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
	});
}

/**
 * The block theme the environment runs by default (activated by
 * bin/e2e-install-wp.sh); override to match a differently-provisioned site.
 */
export const BLOCK_THEME = process.env.E2E_BLOCK_THEME || 'twentytwentyfive';

/**
 * The classic Woo theme the classic-theme project switches to. Installed
 * (inactive) by bin/e2e-install-wp.sh; override to match a
 * differently-provisioned site.
 */
export const CLASSIC_THEME = process.env.E2E_CLASSIC_THEME || 'storefront';

/**
 * Where the degrade setup records the site's original Redis connection so the
 * restore teardown puts back what was actually there — not env defaults.
 * Lives under test-results/ (gitignored, wiped by Playwright between runs).
 */
export const REDIS_STATE_PATH = 'test-results/e2e-redis-state.json';
