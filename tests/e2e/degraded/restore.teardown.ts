import { existsSync, readFileSync, rmSync } from 'node:fs';
import { test as teardown } from '@playwright/test';
import { REDIS_STATE_PATH, wpCli } from '../helpers/env';

/**
 * Restore the healthy environment with the real `setup` command: it PINGs
 * Redis before saving anything, so a passing teardown doubles as a post-suite
 * health assertion — a local run must never leave the dev site broken.
 *
 * Connection values come from, in order: explicit env, the state captured by
 * degrade.setup.ts, then the stock defaults. This project also runs when the
 * degrade setup was skipped (e.g. a red main) — that is safe and intentional:
 * `setup` is idempotent and merely re-asserts the healthy state.
 */
teardown('restore: re-run shift64-woo-search setup (PING doubles as health assert)', () => {
	let captured: { host?: string; port?: string } = {};
	if (existsSync(REDIS_STATE_PATH)) {
		captured = JSON.parse(readFileSync(REDIS_STATE_PATH, 'utf8'));
	}
	const host = process.env.REDIS_HOST || captured.host || '127.0.0.1';
	const port = process.env.REDIS_PORT || captured.port || '6379';

	wpCli(['shift64-woo-search', 'setup', `--host=${host}`, `--port=${port}`]);
	rmSync(REDIS_STATE_PATH, { force: true });
});
