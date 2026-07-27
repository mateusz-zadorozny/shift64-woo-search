import { test as setup } from '@playwright/test';
import { wpCli } from '../helpers/env';

/**
 * Flip the environment to a dead Redis: point the port option at nothing and
 * regenerate the SHORTINIT config so options and generated constants diverge
 * from a healthy install exactly as they would in the field.
 *
 * `wp shift64-woo-search setup` cannot be used here — it PINGs first and
 * refuses dead hosts. The restore teardown runs the real `setup`, whose PING
 * doubles as the post-suite health assertion.
 *
 * If a local run aborts between this setup and the restore teardown, re-run
 * `npm run e2e:provision` (or `wp shift64-woo-search setup`) to heal the site.
 */
setup('degrade: point Redis at a dead port and regenerate the config', () => {
	wpCli(['option', 'update', 'shift64_woo_search_redis_port', '6390']);
	wpCli(['eval', 'Shift64_Woo_Search_Plugin::get_instance()->generate_mu_plugin_config();']);
});
