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
	],
});
