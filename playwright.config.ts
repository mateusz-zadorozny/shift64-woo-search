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
		// Blockified projection of the pagination journeys (#17). The block
		// theme is the environment default (#63), so the guard in the spec's
		// beforeAll is normally a no-op; against a differently-themed target
		// it still switches and restores, bounded by beforeAll/afterAll.
		{
			name: 'block-theme',
			testDir: 'tests/e2e/block-theme',
			dependencies: ['main'],
		},
		// The classic (Storefront) projection lived here. It covered the
		// plugin-owned AJAX archive swap, which the block theme-only release
		// removed: with no swap there is nothing theme-specific left to project,
		// and switching a real site to a classic theme to prove the plugin does
		// nothing would only cost run time. Classic-theme graceful degradation —
		// search and CLI keep working, no controls are injected — is asserted in
		// PHPUnit, which needs no theme switch.
		// The degraded chain REALLY mutates the target site's Redis config.
		// Ordering is enforced by dependencies. restore-env may also run when
		// the degrade setup was skipped (e.g. a red `main`) — that is safe by
		// design: it re-runs the idempotent real `setup`, which only re-asserts
		// the healthy state it PINGs first.
		{
			name: 'degrade-env',
			testDir: 'tests/e2e/degraded',
			testMatch: /degrade\.setup\.ts/,
			// Depends on block-theme, not main. Both are state-mutating chains;
			// if they merely shared `main` as a dependency, their relative order
			// would rest on undocumented intra-stage scheduling, and a
			// degrade-env that ran first would point Redis at a dead port and
			// red the block-theme journeys (which need a live Redis). Making the
			// edge explicit forces main -> block-theme -> degrade-env ->
			// degraded. block-theme is a DEPENDENCY here, not a dependent, so
			// restore-env still fires as soon as `degraded` is done.
			dependencies: ['block-theme'],
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
