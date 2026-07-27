import { test as teardown } from '@playwright/test';
import { wpCli } from '../helpers/env';

const REDIS_HOST = process.env.REDIS_HOST || '127.0.0.1';
const REDIS_PORT = process.env.REDIS_PORT || '6379';

/**
 * Restore the healthy environment with the real `setup` command: it PINGs
 * Redis before saving anything, so a passing teardown doubles as a post-suite
 * health assertion — a local run must never leave the dev site broken.
 */
teardown('restore: re-run shift64-woo-search setup (PING doubles as health assert)', () => {
	wpCli(['shift64-woo-search', 'setup', `--host=${REDIS_HOST}`, `--port=${REDIS_PORT}`]);
});
