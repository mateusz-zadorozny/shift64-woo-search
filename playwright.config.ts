import { defineConfig } from '@playwright/test';

// The server lifecycle deliberately lives outside Playwright (a CI workflow
// step, or the already-running LocalWP site) — BASE_URL selects the target.
const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8889';
const IS_CI = !!process.env.CI;

export default defineConfig({
	testDir: 'tests/e2e',
	// The degraded project mutates global server state (Redis config), and the
	// whole suite shares one PHP built-in server and one rate-limit bucket —
	// ordering must be a guarantee, not a convention.
	workers: 1,
	fullyParallel: false,
	forbidOnly: IS_CI,
	retries: IS_CI ? 1 : 0,
	reporter: [['list'], ['html', { open: 'never' }]],
	use: {
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
		video: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'main',
			testDir: 'tests/e2e/specs',
		},
		// Second, blockified projection of the AJAX-swap journeys (#17). It
		// REALLY switches the target site's theme, but the switch is bounded by
		// the spec file's beforeAll/afterAll rather than by a setup/teardown
		// project pair: a spec file is the unit a worker runs to completion, so
		// no other project can observe the switched theme, and no dependency
		// edge has to be threaded through the degraded chain's teardown.
		{
			name: 'block-theme',
			testDir: 'tests/e2e/block-theme',
			dependencies: ['main'],
		},
		// The degraded chain REALLY mutates the target site's Redis config.
		// Ordering is enforced by dependencies. restore-env may also run when
		// the degrade setup was skipped (e.g. a red `main`) — that is safe by
		// design: it re-runs the idempotent real `setup`, which only re-asserts
		// the healthy state it PINGs first.
		{
			name: 'degrade-env',
			testDir: 'tests/e2e/degraded',
			testMatch: /degrade\.setup\.ts/,
			dependencies: ['main'],
			teardown: 'restore-env',
		},
		{
			name: 'degraded',
			testDir: 'tests/e2e/degraded',
			testMatch: /degraded\.spec\.ts/,
			dependencies: ['degrade-env'],
		},
		{
			name: 'restore-env',
			testDir: 'tests/e2e/degraded',
			testMatch: /restore\.teardown\.ts/,
		},
	],
});
