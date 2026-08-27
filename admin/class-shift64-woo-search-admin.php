<?php
/**
 * Admin — Menu registration + tab router + AJAX handlers + metabox + dashboard widget.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller for the Shift64 Woo Search plugin.
 */
class Shift64_Woo_Search_Admin {

	/**
	 * Register hooks for admin menu, scripts, metabox, dashboard widget and AJAX.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Product edit metabox.
		add_action( 'add_meta_boxes', array( $this, 'add_product_metabox' ) );
		add_action( 'save_post_product', array( $this, 'save_product_metabox' ) );

		// Dashboard widget.
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );

		// AJAX handlers.
		$ajax_actions = array(
			'test_query',
			'save_setting',
			'rebuild_index',
			'test_connection',
			'regen_config',
			'tuning_query',
			'toggle_product_flag',
			'synonys64ws_add',
			'synonys64ws_update',
			'synonys64ws_remove',
			'synonys64ws_export',
			'synonys64ws_import',
			'suggestions_add',
			'suggestions_update',
			'suggestions_remove',
			'suggestions_export',
			'suggestions_import',
			'get_stats',
			'cleanup_stats',
			'save_weights',
			'save_filters',
			'save_category_boost',
			'remove_category_boost',
			'add_category_exclude',
			'remove_category_exclude',
		);
		foreach ( $ajax_actions as $action ) {
			add_action( 'wp_ajax_shift64_woo_search_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/**
	 * Register the admin submenu page under WooCommerce.
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Shift64 Woo Search', 'shift64-woo-search' ),
			__( 'Shift64 Woo Search', 'shift64-woo-search' ),
			'manage_woocommerce', // phpcs:ignore WordPress.WP.Capabilities.Unknown
			'shift64-woo-search',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS on the plugin settings page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_shift64-woo-search' !== $hook ) {
			return;
		}

		$css_path = SHIFT64_WOO_SEARCH_PATH . 'admin/css/shift64-woo-search-admin.css';
		$js_path  = SHIFT64_WOO_SEARCH_PATH . 'admin/js/shift64-woo-search-admin.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : SHIFT64_WOO_SEARCH_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : SHIFT64_WOO_SEARCH_VERSION;

		wp_enqueue_style(
			'shift64-woo-search-admin',
			SHIFT64_WOO_SEARCH_URL . 'admin/css/shift64-woo-search-admin.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'shift64-woo-search-admin',
			SHIFT64_WOO_SEARCH_URL . 'admin/js/shift64-woo-search-admin.js',
			array(),
			$js_ver,
			true
		);

		wp_localize_script(
			'shift64-woo-search-admin',
			'shift64_woo_search_admin',
			array(
				'ajax_url'        => wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ),
				'nonce'           => wp_create_nonce( 'shift64_woo_search_admin' ),
				'default_weights' => Shift64_Woo_Search_Schema::get_default_weights(),
				'current_weights' => Shift64_Woo_Search_Schema::get_weights(),
			)
		);
	}

	/**
	 * Build a canonical admin URL for a workspace, optionally scoped to a section.
	 *
	 * Workspace links may omit the section: the resolver applies the workspace default,
	 * so the shortest canonical URL stays stable even if a default section is renamed.
	 *
	 * @param string $workspace Workspace slug.
	 * @param string $section   Optional section slug.
	 * @return string Relative admin URL.
	 */
	private function get_route_url( $workspace, $section = '' ) {
		$url = '?page=shift64-woo-search&tab=' . rawurlencode( $workspace );

		if ( '' !== $section ) {
			$url .= '&section=' . rawurlencode( $section );
		}

		return $url;
	}

	/**
	 * Render the main admin page: navigation shell plus the resolved section.
	 *
	 * `tab` and `section` are plain navigation parameters on a read-only GET, so they
	 * are nonce-exempt exactly as the legacy tab parameter was. They are never used to
	 * build a method name — `Shift64_Woo_Search_Admin_Routes::resolve()` maps them onto
	 * the fixed registry and returns one of its own declared callbacks.
	 */
	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameters, no state change.
		$requested_tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		$requested_section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$route      = Shift64_Woo_Search_Admin_Routes::resolve( $requested_tab, $requested_section );
		$workspaces = Shift64_Woo_Search_Admin_Routes::get_workspaces();
		$sections   = $workspaces[ $route['workspace'] ]['sections'];
		?>
		<div class="wrap shift64-woo-search-admin">
			<h1><?php esc_html_e( 'Shift64 Woo Search', 'shift64-woo-search' ); ?></h1>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Primary', 'shift64-woo-search' ); ?>">
				<?php foreach ( $workspaces as $workspace_slug => $workspace ) : ?>
					<?php $is_current = ( $route['workspace'] === $workspace_slug ); ?>
					<a href="<?php echo esc_url( $this->get_route_url( $workspace_slug ) ); ?>" class="nav-tab<?php echo $is_current ? ' nav-tab-active' : ''; ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $workspace['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( count( $sections ) > 1 ) : ?>
				<nav class="shift64-woo-search-admin__sections" aria-label="<?php esc_attr_e( 'Sections', 'shift64-woo-search' ); ?>">
					<ul class="subsubsub">
						<?php
						$section_index = 0;
						foreach ( $sections as $section_slug => $section ) :
							++$section_index;
							$is_current = ( $route['section'] === $section_slug );
							?>
							<li>
								<a href="<?php echo esc_url( $this->get_route_url( $route['workspace'], $section_slug ) ); ?>" class="shift64-woo-search-admin__section-link<?php echo $is_current ? ' current' : ''; ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $section['label'] ); ?></a><?php echo $section_index < count( $sections ) ? ' |' : ''; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<div class="shift64-woo-search-admin__content">
				<h2 class="shift64-woo-search-admin__section-title"><?php echo esc_html( $sections[ $route['section'] ]['label'] ); ?></h2>
				<?php
				/*
				 * The relocation notice keys off the *raw* requested tab, not the resolved
				 * route: `tab=search` and `tab=relevance&section=basic` land on the same
				 * renderer, and only the first one is a returning bookmark that needs
				 * telling where the rest of the old page went.
				 */
				if ( 'search' === $requested_tab ) {
					$this->render_legacy_search_relocation_notice();
				}

				$this->render_deprecated_settings_notice();

				/*
				 * Safe variable method call: `$route['callback']` is always a string the
				 * route registry itself declared. Request input selects which registry
				 * entry is used, but never contributes to the method name.
				 */
				$callback = $route['callback'];
				if ( method_exists( $this, $callback ) ) {
					$this->$callback();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Warn when this store has a deprecated setting *value* stored.
	 *
	 * Renders on every workspace rather than only on the two sections that own
	 * the fields: a merchant who has `OR` stored should learn it from wherever
	 * they land, and each line links to the field so the notice is actionable
	 * from anywhere. It is deliberately scoped to the plugin's own pages and
	 * never registered on the global `admin_notices` hook — a settings-quality
	 * warning has no business following anyone around wp-admin.
	 *
	 * Stateless, for the same reason the relocation notice above is: rendering
	 * a route never writes. Remembering a dismissal would mean writing user
	 * meta or an option on a page view, so this is markup-dismissible through
	 * WordPress's own client-side handler and nothing more. It reappears on
	 * reload and disappears for good when the merchant changes the setting —
	 * which is the only event that should clear it.
	 */
	private function render_deprecated_settings_notice() {
		$stored = Shift64_Woo_Search_Deprecations::stored();

		if ( empty( $stored ) ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible shift64-woo-search-admin__deprecated-notice">
			<p>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: how many deprecated settings this store has stored. */
							_n(
								'%d search setting is deprecated.',
								'%d search settings are deprecated.',
								count( $stored ),
								'shift64-woo-search'
							),
							count( $stored )
						)
					);
					?>
				</strong>
				<?php esc_html_e( 'They still work, and will be removed in a future release.', 'shift64-woo-search' ); ?>
			</p>
			<ul>
				<?php
				$workspaces = Shift64_Woo_Search_Admin_Routes::get_workspaces();

				foreach ( $stored as $entry ) :
					// The owning section's own label doubles as the link text, so two
					// entries never produce two identically-named links — a links list
					// read out of context still distinguishes them.
					$section_label = isset( $workspaces[ $entry['workspace'] ]['sections'][ $entry['section'] ]['label'] )
						? $workspaces[ $entry['workspace'] ]['sections'][ $entry['section'] ]['label']
						: $entry['field'];
					?>
					<li>
						<?php
						printf(
							/* translators: 1: settings field label, already wrapped in <strong>. 2: the deprecated value's label. */
							esc_html__( '%1$s is set to “%2$s”.', 'shift64-woo-search' ),
							wp_kses_post( '<strong>' . esc_html( $entry['field'] ) . '</strong>' ),
							esc_html( $entry['value_label'] )
						);
						?>
						<?php echo esc_html( $entry['reason'] ); ?>
						<a href="<?php echo esc_url( $this->get_route_url( $entry['workspace'], $entry['section'] ) ); ?>">
							<?php echo esc_html( $section_label ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render the relocation notice for bookmarks that still point at `tab=search`.
	 *
	 * The old Search tab was the mixed page: ranking, fuzzy matching, autocomplete
	 * sizing, and archive coverage all shared one form. The alias lands returning
	 * bookmarks on Relevance › Basic Ranking, which holds the ranking half — so the
	 * only thing left to explain is where the other halves went.
	 *
	 * Deliberately stateless: the notice is markup-dismissible through WordPress's own
	 * client-side handler and nothing more. Remembering a dismissal would mean writing
	 * user meta or an option on a page view, and "rendering a route never writes" is a
	 * contract this notice is not allowed to be the exception to. It disappears for
	 * good the moment the merchant updates the bookmark, which is the point.
	 */
	private function render_legacy_search_relocation_notice() {
		$matching_link   = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $this->get_route_url( 'relevance', 'matching' ) ),
			esc_html__( 'Matching & Fallback', 'shift64-woo-search' )
		);
		$experience_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $this->get_route_url( 'experience', 'autocomplete' ) ),
			esc_html__( 'Search Experience', 'shift64-woo-search' )
		);
		$results_link    = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $this->get_route_url( 'results', 'coverage' ) ),
			esc_html__( 'Results & Filters', 'shift64-woo-search' )
		);
		?>
		<div class="notice notice-info is-dismissible shift64-woo-search-admin__relocation-notice">
			<p>
				<strong><?php esc_html_e( 'The old Search tab has been split up.', 'shift64-woo-search' ); ?></strong>
				<?php
				printf(
					/* translators: %s: link to the Relevance › Matching & Fallback section. */
					esc_html__( 'Its ranking controls are on this page. Typo tolerance, fallback passes, and the full-search limit are one section over, under %s.', 'shift64-woo-search' ),
					wp_kses_post( $matching_link )
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: link to the Search Experience workspace. 2: link to the Results & Filters workspace. */
					esc_html__( 'The rest of the old page changed workspace: autocomplete and suggestion settings now live in %1$s, and archive coverage and price sorting in %2$s.', 'shift64-woo-search' ),
					wp_kses_post( $experience_link ),
					wp_kses_post( $results_link )
				);
				?>
			</p>
		</div>
		<?php
	}

	// ── Overview Tab ───────────────────────────────────────────

	/**
	 * Render the Overview landing page.
	 *
	 * Read-only by contract: this is a signpost to the five task workspaces. It must
	 * never write an option, query Redis, regenerate config, or expose credentials —
	 * merely visiting the plugin's default page has to be free of side effects.
	 */
	private function render_overview_tab() {
		$descriptions = array(
			'experience' => __( 'Search field behaviour, autocomplete, and the query and category suggestions shoppers see.', 'shift64-woo-search' ),
			'results'    => __( 'Which listings the search backend serves, and the facets shoppers filter results with.', 'shift64-woo-search' ),
			'relevance'  => __( 'Matching, ranking, synonyms, merchandising, and the tools for evaluating them.', 'shift64-woo-search' ),
			'insights'   => __( 'Search usage over time and the queries that come back empty.', 'shift64-woo-search' ),
			'system'     => __( 'Backend connection, index operations, traffic protection, and generated configuration.', 'shift64-woo-search' ),
		);
		?>
		<div class="shift64-woo-search-overview">
			<p class="description">
				<?php esc_html_e( 'Every setting, content list, tool, and report lives in one of the workspaces below. This page only points at them — nothing here changes your store.', 'shift64-woo-search' ); ?>
			</p>

			<ul class="shift64-woo-search-overview__cards">
				<?php foreach ( Shift64_Woo_Search_Admin_Routes::get_workspaces() as $workspace_slug => $workspace ) : ?>
					<?php if ( isset( $descriptions[ $workspace_slug ] ) ) : ?>
						<li class="shift64-woo-search-overview__card">
							<h3 class="shift64-woo-search-overview__card-title">
								<a href="<?php echo esc_url( $this->get_route_url( $workspace_slug ) ); ?>"><?php echo esc_html( $workspace['label'] ); ?></a>
							</h3>
							<p class="shift64-woo-search-overview__card-text"><?php echo esc_html( $descriptions[ $workspace_slug ] ); ?></p>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	// ── Test Search Tab ────────────────────────────────────────

	/**
	 * Render the Test Search tab.
	 */
	private function render_test_tab() {
		?>
		<div class="shift64-woo-search-test">
			<div class="shift64-woo-search-test__input-row">
				<input type="text" id="s64ws-test-query" class="regular-text"
						placeholder="<?php esc_attr_e( 'Type a search query...', 'shift64-woo-search' ); ?>" />
				<select id="s64ws-test-mode">
					<option value="autocomplete">Autocomplete</option>
					<option value="full">Full</option>
				</select>
				<input type="number" id="s64ws-test-limit" value="10" min="1" max="200" style="width:60px" />
				<button type="button" id="s64ws-test-run" class="button button-primary">
					<?php esc_html_e( 'Search', 'shift64-woo-search' ); ?>
				</button>
			</div>

			<div id="s64ws-test-debug" class="shift64-woo-search-test__debug" style="display:none">
				<div class="shift64-woo-search-test__debug-row">
					<span class="label"><?php esc_html_e( 'Search Pass:', 'shift64-woo-search' ); ?></span>
					<span id="s64ws-test-pass" class="shift64-woo-search-badge"></span>
				</div>
				<div class="shift64-woo-search-test__debug-row">
					<span class="label"><?php esc_html_e( 'FT.SEARCH query:', 'shift64-woo-search' ); ?></span>
					<code id="s64ws-test-ft-query"></code>
				</div>
				<div id="s64ws-test-debug-queries-container"></div>
				<div class="shift64-woo-search-test__debug-row">
					<span class="label"><?php esc_html_e( 'Time:', 'shift64-woo-search' ); ?></span>
					<span id="s64ws-test-time"></span>
				</div>
				<div class="shift64-woo-search-test__debug-row">
					<span class="label"><?php esc_html_e( 'Results:', 'shift64-woo-search' ); ?></span>
					<span id="s64ws-test-count"></span>
				</div>
			</div>

			<table id="s64ws-test-results" class="wp-list-table widefat fixed striped" style="display:none">
				<thead>
					<tr>
						<th style="width:40px">#</th>
						<th style="width:60px"><?php esc_html_e( 'ID', 'shift64-woo-search' ); ?></th>
						<th><?php esc_html_e( 'Title', 'shift64-woo-search' ); ?></th>
						<th style="width:100px"><?php esc_html_e( 'SKU', 'shift64-woo-search' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Category', 'shift64-woo-search' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'Stock', 'shift64-woo-search' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'Raw', 'shift64-woo-search' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'Final', 'shift64-woo-search' ); ?></th>
						<th style="width:90px"><?php esc_html_e( 'Actions', 'shift64-woo-search' ); ?></th>
					</tr>
				</thead>
				<tbody id="s64ws-test-results-body"></tbody>
			</table>

			<div id="s64ws-test-empty" style="display:none" class="shift64-woo-search-test__empty">
				<?php esc_html_e( 'No results found.', 'shift64-woo-search' ); ?>
			</div>
		</div>
		<?php
	}

	// ── Tuning Tab ─────────────────────────────────────────────

	/**
	 * Render the Tuning tab for comparing search passes.
	 */
	private function render_tuning_tab() {
		?>
		<div class="shift64-woo-search-tuning">
			<p class="description">
				<?php esc_html_e( 'Compare search passes side by side. All passes run regardless of results, so you can evaluate the impact of each strategy.', 'shift64-woo-search' ); ?>
			</p>

			<div class="shift64-woo-search-tuning__input-row">
				<input type="text" id="s64ws-tuning-query" class="regular-text"
						placeholder="<?php esc_attr_e( 'Enter a test query...', 'shift64-woo-search' ); ?>" />
				<input type="number" id="s64ws-tuning-limit" value="5" min="1" max="20" style="width:60px" />
				<button type="button" id="s64ws-tuning-run" class="button button-primary">
					<?php esc_html_e( 'Compare', 'shift64-woo-search' ); ?>
				</button>
			</div>

			<div id="s64ws-tuning-results" style="display:none">
				<div class="shift64-woo-search-tuning__grid" id="s64ws-tuning-grid"></div>
			</div>

			<div id="s64ws-tuning-empty" style="display:none" class="shift64-woo-search-test__empty">
				<?php esc_html_e( 'Enter a query and click Compare.', 'shift64-woo-search' ); ?>
			</div>
		</div>
		<?php
	}

	// ── Synonyms Tab ───────────────────────────────────────────

	/**
	 * Render the Synonyms management tab.
	 */
	private function render_synonys64ws_tab() {
		$synonyms = Shift64_Woo_Search_Synonyms::get_all();
		?>
		<div class="shift64-woo-search-synonyms">
			<p class="description">
				<?php esc_html_e( 'Synonym groups let RediSearch treat related terms as equivalent. Use comma for two-way, => for one-way (e.g. jumper => sweater).', 'shift64-woo-search' ); ?>
			</p>

			<div class="shift64-woo-search-synonys64ws__add">
				<input type="text" id="s64ws-syn-input" class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g. hoodie, sweatshirt — or one-way: jumper => sweater', 'shift64-woo-search' ); ?>" />
				<button type="button" id="s64ws-syn-add" class="button button-primary">
					<?php esc_html_e( 'Add Group', 'shift64-woo-search' ); ?>
				</button>
				<span id="s64ws-syn-status" class="shift64-woo-search-status"></span>
			</div>

			<table class="wp-list-table widefat fixed striped" id="s64ws-syn-table">
				<thead>
					<tr>
						<th style="width:40px">#</th>
						<th><?php esc_html_e( 'Terms', 'shift64-woo-search' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Actions', 'shift64-woo-search' ); ?></th>
					</tr>
				</thead>
				<tbody id="s64ws-syn-body">
					<?php foreach ( $synonyms as $i => $group ) : ?>
						<tr data-index="<?php echo esc_attr( $i ); ?>">
							<td><?php echo esc_html( $i + 1 ); ?></td>
							<td>
								<input type="text" class="regular-text s64ws-syn-terms" value="<?php echo esc_attr( Shift64_Woo_Search_Synonyms::format_group( $group ) ); ?>" />
							</td>
							<td>
								<button type="button" class="button s64ws-syn-save"><?php esc_html_e( 'Save', 'shift64-woo-search' ); ?></button>
								<button type="button" class="button s64ws-syn-delete"><?php esc_html_e( 'Delete', 'shift64-woo-search' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $synonyms ) ) : ?>
						<tr class="s64ws-syn-empty"><td colspan="3"><?php esc_html_e( 'No synonym groups defined.', 'shift64-woo-search' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="shift64-woo-search-synonys64ws__io" style="margin-top:16px;">
				<button type="button" id="s64ws-syn-export" class="button">
					<?php esc_html_e( 'Export', 'shift64-woo-search' ); ?>
				</button>
				<button type="button" id="s64ws-syn-import-toggle" class="button">
					<?php esc_html_e( 'Import', 'shift64-woo-search' ); ?>
				</button>
				<div id="s64ws-syn-import-area" style="display:none; margin-top:10px;">
					<p>
						<label class="button" style="cursor:pointer;">
							<?php esc_html_e( 'Load .txt file', 'shift64-woo-search' ); ?>
							<input type="file" id="s64ws-syn-import-file" accept=".txt" style="display:none;" />
						</label>
						<span id="s64ws-syn-import-filename" style="margin-left:8px; color:#666;"></span>
					</p>
					<textarea id="s64ws-syn-import-text" rows="8" class="large-text code"
							placeholder="<?php esc_attr_e( "One group per line:\nhoodie, sweatshirt\njumper => sweater", 'shift64-woo-search' ); ?>"></textarea>
					<p>
						<label>
							<input type="checkbox" id="s64ws-syn-import-replace" checked />
							<?php esc_html_e( 'Replace existing synonyms (uncheck to append)', 'shift64-woo-search' ); ?>
						</label>
					</p>
					<button type="button" id="s64ws-syn-import-run" class="button button-primary">
						<?php esc_html_e( 'Import', 'shift64-woo-search' ); ?>
					</button>
					<span id="s64ws-syn-import-status" class="shift64-woo-search-status"></span>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Search Experience › Query Suggestions ──────────────────

	/**
	 * Render the Query Suggestions section for managing autocomplete suggestions.
	 *
	 * Relocated verbatim from the legacy Suggestions tab: same markup, element ids,
	 * and AJAX actions, so the admin script binds to it exactly as before.
	 */
	private function render_experience_query_suggestions_section() {
		$suggestions = Shift64_Woo_Search_Suggestions::get_all();
		?>
		<div class="shift64-woo-search-suggestions">
			<p class="description">
				<?php esc_html_e( 'Search suggestions displayed in the autocomplete dropdown. They are sampled when the search field receives focus and filtered as the shopper types.', 'shift64-woo-search' ); ?>
			</p>

			<div class="shift64-woo-search-suggestions__add">
				<input type="text" id="s64ws-sug-input" class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g. wall-mounted soap dispenser', 'shift64-woo-search' ); ?>" />
				<button type="button" id="s64ws-sug-add" class="button button-primary">
					<?php esc_html_e( 'Add', 'shift64-woo-search' ); ?>
				</button>
				<span id="s64ws-sug-status" class="shift64-woo-search-status"></span>
			</div>

			<table class="wp-list-table widefat fixed striped" id="s64ws-sug-table">
				<thead>
					<tr>
						<th style="width:40px">#</th>
						<th><?php esc_html_e( 'Suggestion', 'shift64-woo-search' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Actions', 'shift64-woo-search' ); ?></th>
					</tr>
				</thead>
				<tbody id="s64ws-sug-body">
					<?php foreach ( $suggestions as $i => $text ) : ?>
						<tr data-index="<?php echo esc_attr( $i ); ?>">
							<td><?php echo esc_html( $i + 1 ); ?></td>
							<td>
								<input type="text" class="regular-text s64ws-sug-text" value="<?php echo esc_attr( $text ); ?>" />
							</td>
							<td>
								<button type="button" class="button s64ws-sug-save"><?php esc_html_e( 'Save', 'shift64-woo-search' ); ?></button>
								<button type="button" class="button s64ws-sug-delete"><?php esc_html_e( 'Delete', 'shift64-woo-search' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $suggestions ) ) : ?>
						<tr class="s64ws-sug-empty"><td colspan="3"><?php esc_html_e( 'No suggestions configured.', 'shift64-woo-search' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="shift64-woo-search-suggestions__io" style="margin-top:16px;">
				<button type="button" id="s64ws-sug-export" class="button">
					<?php esc_html_e( 'Export', 'shift64-woo-search' ); ?>
				</button>
				<button type="button" id="s64ws-sug-import-toggle" class="button">
					<?php esc_html_e( 'Import', 'shift64-woo-search' ); ?>
				</button>
				<div id="s64ws-sug-import-area" style="display:none; margin-top:10px;">
					<p>
						<label class="button" style="cursor:pointer;">
							<?php esc_html_e( 'Load a .txt file', 'shift64-woo-search' ); ?>
							<input type="file" id="s64ws-sug-import-file" accept=".txt" style="display:none;" />
						</label>
						<span id="s64ws-sug-import-filename" style="margin-left:8px; color:#666;"></span>
					</p>
					<textarea id="s64ws-sug-import-text" rows="8" class="large-text code"
							placeholder="<?php esc_attr_e( "One suggestion per line:\nwall-mounted soap dispenser\nautomatic dispenser", 'shift64-woo-search' ); ?>"></textarea>
					<p>
						<label>
							<input type="checkbox" id="s64ws-sug-import-replace" checked />
							<?php esc_html_e( 'Replace existing suggestions (uncheck to append)', 'shift64-woo-search' ); ?>
						</label>
					</p>
					<button type="button" id="s64ws-sug-import-run" class="button button-primary">
						<?php esc_html_e( 'Import', 'shift64-woo-search' ); ?>
					</button>
					<span id="s64ws-sug-import-status" class="shift64-woo-search-status"></span>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Search Experience › Category Suggestions ───────────────

	/**
	 * Resolve the configured per-category boosts into display rows.
	 *
	 * Drops entries whose term no longer exists so a deleted category can't
	 * leave a ghost row in the table.
	 *
	 * @return array<int,array{term_id:int,name:string,factor:float}>
	 */
	private function get_category_boost_rows() {
		$boosts = get_option( 'shift64_woo_search_category_boosts', array() );
		if ( ! is_array( $boosts ) ) {
			$boosts = array();
		}
		$rows = array();
		foreach ( $boosts as $term_id => $factor ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$rows[] = array(
				'term_id' => (int) $term_id,
				'name'    => $term->name,
				'factor'  => (float) $factor,
			);
		}
		return $rows;
	}

	/**
	 * Resolve the hidden-from-suggestions category IDs into display rows.
	 *
	 * Each row carries the term's name, slug and full ancestor path so an admin
	 * can tell apart two categories that share a display name (e.g. "Krzesła"
	 * under both "Meble" and "Biuro › Meble"). Drops entries whose term no
	 * longer exists so a deleted category can't leave a ghost row.
	 *
	 * @return array<int,array{term_id:int,name:string,slug:string,path:string}>
	 */
	private function get_category_exclude_rows() {
		$rows = array();
		foreach ( self::get_exclusion_ids() as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			// Build "Grandparent › Parent › Term" from the ancestor chain.
			$names     = array( $term->name );
			$ancestors = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_term( (int) $ancestor_id, 'product_cat' );
				if ( $ancestor && ! is_wp_error( $ancestor ) ) {
					array_unshift( $names, $ancestor->name );
				}
			}

			$rows[] = array(
				'term_id' => $term_id,
				'name'    => $term->name,
				'slug'    => $term->slug,
				'path'    => implode( ' › ', $names ),
			);
		}
		return $rows;
	}

	/**
	 * Render the Category Suggestions section.
	 *
	 * Everything that shapes the autocomplete category list lives here, in the order
	 * the resolver applies it: pins win over boosts, boosts order what remains, and
	 * exclusions remove categories from the list entirely.
	 *
	 * The pins editor is a generic settings form and therefore submits only its own
	 * key; the boost and exclusion editors keep their dedicated AJAX actions and the
	 * category-blob refresh that goes with them.
	 */
	private function render_experience_category_suggestions_section() {
		$rows = $this->get_category_boost_rows();
		?>
		<form id="s64ws-settings-form" class="shift64-woo-search-settings">
			<table class="form-table">
				<?php
				$this->render_textarea_field(
					'shift64_woo_search_category_pin_rules',
					__( 'Category Suggestion Pins', 'shift64-woo-search' ),
					'',
					__( 'Pins a category to the top of the autocomplete category list for a query. One rule per line. Format: query|category or query|category|priority. Category is a name or slug; priority defaults to 1 (higher wins). A pin fires while the shopper types (prefix match either way) and can surface a category even when its name does not contain the query. Lines starting with # are comments. No index rebuild required.', 'shift64-woo-search' )
				);
				?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-settings-status" class="shift64-woo-search-status"></span>
			</p>
		</form>

		<div class="shift64-woo-search-catboost">
			<h3 style="margin-top:2em"><?php esc_html_e( 'Category boosts', 'shift64-woo-search' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Relevance multiplier for category suggestions. Values above 1 promote a category and values below 1 demote it. Boosts apply within the same name-match level (exact, prefix, or substring) and take precedence over product count. Category pins have a higher priority than boosts.', 'shift64-woo-search' ); ?>
			</p>

			<div class="shift64-woo-search-catboost__add">
				<input type="text" id="s64ws-cb-input" class="regular-text"
						placeholder="<?php esc_attr_e( 'Category name, slug, or ID', 'shift64-woo-search' ); ?>" />
				<input type="number" id="s64ws-cb-factor" step="0.1" min="0.1" value="2.0" style="width:90px" />
				<button type="button" id="s64ws-cb-add" class="button button-primary">
					<?php esc_html_e( 'Add / Save', 'shift64-woo-search' ); ?>
				</button>
				<span id="s64ws-cb-status" class="shift64-woo-search-status"></span>
			</div>

			<table class="wp-list-table widefat fixed striped" id="s64ws-cb-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Category', 'shift64-woo-search' ); ?></th>
						<th style="width:120px"><?php esc_html_e( 'Boost', 'shift64-woo-search' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Actions', 'shift64-woo-search' ); ?></th>
					</tr>
				</thead>
				<tbody id="s64ws-cb-body">
					<?php foreach ( $rows as $row ) : ?>
						<tr data-term-id="<?php echo esc_attr( $row['term_id'] ); ?>">
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td>
								<input type="number" class="s64ws-cb-value" step="0.1" min="0.1" value="<?php echo esc_attr( $row['factor'] ); ?>" style="width:90px" />
							</td>
							<td>
								<button type="button" class="button s64ws-cb-save"><?php esc_html_e( 'Save', 'shift64-woo-search' ); ?></button>
								<button type="button" class="button s64ws-cb-delete"><?php esc_html_e( 'Delete', 'shift64-woo-search' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $rows ) ) : ?>
						<tr class="s64ws-cb-empty"><td colspan="3"><?php esc_html_e( 'No category boosts configured.', 'shift64-woo-search' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php $exclude_rows = $this->get_category_exclude_rows(); ?>
			<h3 style="margin-top:2em"><?php esc_html_e( 'Hide from suggestions', 'shift64-woo-search' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Categories hidden from the autocomplete category section. Product results are unaffected. Use a slug or ID to distinguish categories with the same display name; the full hierarchy is shown below.', 'shift64-woo-search' ); ?>
			</p>

			<div class="shift64-woo-search-catexclude__add">
				<input type="text" id="s64ws-cx-input" class="regular-text"
						placeholder="<?php esc_attr_e( 'Category name, slug, or ID', 'shift64-woo-search' ); ?>" />
				<button type="button" id="s64ws-cx-add" class="button button-primary">
					<?php esc_html_e( 'Hide category', 'shift64-woo-search' ); ?>
				</button>
				<span id="s64ws-cx-status" class="shift64-woo-search-status"></span>
			</div>

			<table class="wp-list-table widefat fixed striped" id="s64ws-cx-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Path', 'shift64-woo-search' ); ?></th>
						<th style="width:180px"><?php esc_html_e( 'Slug', 'shift64-woo-search' ); ?></th>
						<th style="width:70px"><?php esc_html_e( 'ID', 'shift64-woo-search' ); ?></th>
						<th style="width:120px"><?php esc_html_e( 'Actions', 'shift64-woo-search' ); ?></th>
					</tr>
				</thead>
				<tbody id="s64ws-cx-body">
					<?php foreach ( $exclude_rows as $row ) : ?>
						<tr data-term-id="<?php echo esc_attr( $row['term_id'] ); ?>">
							<td><?php echo esc_html( $row['path'] ); ?></td>
							<td><code><?php echo esc_html( $row['slug'] ); ?></code></td>
							<td><?php echo esc_html( $row['term_id'] ); ?></td>
							<td>
								<button type="button" class="button s64ws-cx-delete"><?php esc_html_e( 'Restore', 'shift64-woo-search' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $exclude_rows ) ) : ?>
						<tr class="s64ws-cx-empty"><td colspan="4"><?php esc_html_e( 'No hidden categories.', 'shift64-woo-search' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ── Statistics Tab ─────────────────────────────────────────

	/**
	 * Render the Statistics tab with search analytics.
	 */
	private function render_stats_tab() {
		$days       = 30;
		$total      = Shift64_Woo_Search_Stats::get_search_count( $days );
		$total_7d   = Shift64_Woo_Search_Stats::get_search_count( 7 );
		$avg_time   = Shift64_Woo_Search_Stats::get_avg_response_time( $days );
		$daily      = Shift64_Woo_Search_Stats::get_daily_counts( $days );
		$top        = Shift64_Woo_Search_Stats::get_top_searches( $days, 20 );
		$zero       = Shift64_Woo_Search_Stats::get_zero_result_searches( $days, 20 );
		$zero_count = 0;
		foreach ( $zero as $z ) {
			$zero_count += (int) $z->search_count;
		}
		$zero_rate = $total > 0 ? round( $zero_count / $total * 100, 1 ) : 0;
		?>
		<div class="shift64-woo-search-stats">
			<div class="shift64-woo-search-stats__controls">
				<select id="s64ws-stats-period">
					<option value="7">7 days</option>
					<option value="14">14 days</option>
					<option value="30" selected>30 days</option>
					<option value="90">90 days</option>
				</select>
				<button type="button" id="s64ws-stats-refresh" class="button"><?php esc_html_e( 'Refresh', 'shift64-woo-search' ); ?></button>
				<button type="button" id="s64ws-stats-cleanup" class="button"><?php esc_html_e( 'Delete older than 90 days', 'shift64-woo-search' ); ?></button>
				<button type="button" id="s64ws-stats-cleanup-all" class="button"><?php esc_html_e( 'Delete all stats', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-stats-status" class="shift64-woo-search-status"></span>
			</div>

			<div class="shift64-woo-search-stats__summary" id="s64ws-stats-summary">
				<div class="shift64-woo-search-stats__card">
					<span class="shift64-woo-search-stats__card-value" id="s64ws-stats-total-7d"><?php echo esc_html( $total_7d ); ?></span>
					<span class="shift64-woo-search-stats__card-label"><?php esc_html_e( 'Searches (7d)', 'shift64-woo-search' ); ?></span>
				</div>
				<div class="shift64-woo-search-stats__card">
					<span class="shift64-woo-search-stats__card-value" id="s64ws-stats-total"><?php echo esc_html( $total ); ?></span>
					<span class="shift64-woo-search-stats__card-label"><?php esc_html_e( 'Searches (period)', 'shift64-woo-search' ); ?></span>
				</div>
				<div class="shift64-woo-search-stats__card">
					<span class="shift64-woo-search-stats__card-value" id="s64ws-stats-avg-time"><?php echo esc_html( round( $avg_time, 1 ) ); ?>ms</span>
					<span class="shift64-woo-search-stats__card-label"><?php esc_html_e( 'Avg Response', 'shift64-woo-search' ); ?></span>
				</div>
				<div class="shift64-woo-search-stats__card">
					<span class="shift64-woo-search-stats__card-value" id="s64ws-stats-zero-rate"><?php echo esc_html( $zero_rate ); ?>%</span>
					<span class="shift64-woo-search-stats__card-label"><?php esc_html_e( 'Zero Results', 'shift64-woo-search' ); ?></span>
				</div>
			</div>

			<h3><?php esc_html_e( 'Search Volume', 'shift64-woo-search' ); ?></h3>
			<div class="shift64-woo-search-stats__chart" id="s64ws-stats-chart">
				<?php $this->render_volume_chart( $daily ); ?>
			</div>

			<div class="shift64-woo-search-stats__tables">
				<div>
					<h3><?php esc_html_e( 'Top Searches', 'shift64-woo-search' ); ?></h3>
					<table class="wp-list-table widefat fixed striped" id="s64ws-stats-top-table">
						<thead><tr><th><?php esc_html_e( 'Query', 'shift64-woo-search' ); ?></th><th style="width:60px"><?php esc_html_e( 'Count', 'shift64-woo-search' ); ?></th><th style="width:80px"><?php esc_html_e( 'Avg Results', 'shift64-woo-search' ); ?></th></tr></thead>
						<tbody id="s64ws-stats-top-body">
							<?php foreach ( $top as $row ) : ?>
								<tr><td><?php echo esc_html( $row->query_normalized ); ?></td><td><?php echo esc_html( $row->search_count ); ?></td><td><?php echo esc_html( round( $row->avg_results ) ); ?></td></tr>
							<?php endforeach; ?>
							<?php if ( empty( $top ) ) : ?>
								<tr><td colspan="3"><?php esc_html_e( 'No data.', 'shift64-woo-search' ); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<div>
					<h3><?php esc_html_e( 'Zero-Result Searches', 'shift64-woo-search' ); ?></h3>
					<table class="wp-list-table widefat fixed striped" id="s64ws-stats-zero-table">
						<thead><tr><th><?php esc_html_e( 'Query', 'shift64-woo-search' ); ?></th><th style="width:60px"><?php esc_html_e( 'Count', 'shift64-woo-search' ); ?></th></tr></thead>
						<tbody id="s64ws-stats-zero-body">
							<?php foreach ( $zero as $row ) : ?>
								<tr><td><?php echo esc_html( $row->query_normalized ); ?></td><td><?php echo esc_html( $row->search_count ); ?></td></tr>
							<?php endforeach; ?>
							<?php if ( empty( $zero ) ) : ?>
								<tr><td colspan="2"><?php esc_html_e( 'No data.', 'shift64-woo-search' ); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the daily search volume bar chart.
	 *
	 * @param array $daily Daily search count rows.
	 */
	private function render_volume_chart( $daily ) {
		if ( empty( $daily ) ) {
			echo '<p class="description">' . esc_html__( 'No data for this period.', 'shift64-woo-search' ) . '</p>';
			return;
		}
		$max = 1;
		foreach ( $daily as $d ) {
			if ( (int) $d->search_count > $max ) {
				$max = (int) $d->search_count;
			}
		}
		echo '<div class="shift64-woo-search-stats__bars">';
		foreach ( $daily as $d ) {
			$pct   = round( (int) $d->search_count / $max * 100 );
			$label = gmdate( 'j M', strtotime( $d->search_date ) );
			echo '<div class="shift64-woo-search-stats__bar-wrap" title="' . esc_attr( $label . ': ' . $d->search_count ) . '">';
			echo '<div class="shift64-woo-search-stats__bar" style="height:' . esc_attr( $pct ) . '%"></div>';
			echo '</div>';
		}
		echo '</div>';
	}

	// ── Weights Tab ────────────────────────────────────────────

	/**
	 * Render the Weights tab for adjusting field scoring.
	 */
	private function render_weights_tab() {
		$weights  = Shift64_Woo_Search_Schema::get_weights();
		$defaults = Shift64_Woo_Search_Schema::get_default_weights();
		$labels   = array(
			'title'           => __( 'Title', 'shift64-woo-search' ),
			'sku_text'        => __( 'SKU', 'shift64-woo-search' ),
			'brands_text'     => __( 'Brands', 'shift64-woo-search' ),
			'categories_text' => __( 'Categories', 'shift64-woo-search' ),
			'short_desc'      => __( 'Short Description', 'shift64-woo-search' ),
			'attributes'      => __( 'Attributes', 'shift64-woo-search' ),
			'description'     => __( 'Description', 'shift64-woo-search' ),
		);
		?>
		<div class="shift64-woo-search-weights">
			<p class="description">
				<?php esc_html_e( 'Adjust field weights for search scoring. Changing weights requires a full index rebuild.', 'shift64-woo-search' ); ?>
			</p>

			<div class="shift64-woo-search-weights__fields" id="s64ws-weights-fields">
				<?php foreach ( $defaults as $field => $default ) : ?>
					<div class="shift64-woo-search-weights__field">
						<label><?php echo esc_html( $labels[ $field ] ?? $field ); ?></label>
						<input type="range" min="0" max="20" step="1"
								class="s64ws-weight-slider" data-field="<?php echo esc_attr( $field ); ?>"
								value="<?php echo esc_attr( $weights[ $field ] ?? $default ); ?>" />
						<span class="shift64-woo-search-weights__value"><?php echo esc_html( $weights[ $field ] ?? $default ); ?></span>
						<span class="shift64-woo-search-weights__default">(default: <?php echo esc_html( $default ); ?>)</span>
					</div>
				<?php endforeach; ?>
			</div>

			<p class="submit">
				<button type="button" id="s64ws-weights-apply" class="button button-primary">
					<?php esc_html_e( 'Apply & Rebuild', 'shift64-woo-search' ); ?>
				</button>
				<button type="button" id="s64ws-weights-reset" class="button">
					<?php esc_html_e( 'Reset to Defaults', 'shift64-woo-search' ); ?>
				</button>
				<span id="s64ws-weights-status" class="shift64-woo-search-status"></span>
			</p>

			<div id="s64ws-weights-progress" style="display:none">
				<div class="shift64-woo-search-progress">
					<div class="shift64-woo-search-progress__bar" id="s64ws-weights-bar" style="width:0"></div>
				</div>
				<span id="s64ws-weights-progress-text"></span>
			</div>
		</div>
		<?php
	}

	// ── Results & Filters › Result Coverage ────────────────────

	/**
	 * Render the Result Coverage section — where Redis serves the listing.
	 *
	 * Relocated from the legacy Search tab. These three settings decide which
	 * storefront listings Redis answers at all; how those results are then ranked
	 * belongs to Relevance, and which facets shoppers see belongs to Facets.
	 *
	 * The taxonomy scopes need an index rebuild to take effect, while the other two
	 * are runtime switches — hence the separate headings. They deliberately share
	 * one Save action: splitting them would mean two forms writing one section.
	 */
	private function render_results_coverage_section() {
		?>
		<form id="s64ws-settings-form" class="shift64-woo-search-settings">

			<h3><?php esc_html_e( 'Archive / Search Results', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_checkbox_field( 'shift64_woo_search_archive_enabled', __( 'Redis Search Results', 'shift64-woo-search' ), 'no', __( 'Replace WooCommerce MySQL search on product search results pages with Redis.', 'shift64-woo-search' ) );
				$this->render_select_field(
					'shift64_woo_search_price_sort_mode',
					__( 'Price Sorting', 'shift64-woo-search' ),
					array(
						'redis' => __( 'Redis index prices (fast, base prices)', 'shift64-woo-search' ),
						'db'    => __( 'DB prices for logged-in users (accurate B2B tiers)', 'shift64-woo-search' ),
					),
					__( 'DB mode: logged-in users get prices from database (slower but accurate per-customer). Guests always use Redis.', 'shift64-woo-search' )
				);
				?>
			</table>

			<h3><?php esc_html_e( 'Taxonomy Archives', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$enabled_scopes = (array) get_option( 'shift64_woo_search_taxonomy_archive_scopes', array() );
				$scope_map      = Shift64_Woo_Search_Taxonomy_Archive::get_scope_map();
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Taxonomy Archive Filters', 'shift64-woo-search' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $scope_map as $taxonomy => $config ) : ?>
								<label style="display:block; margin-bottom:6px;">
									<input type="checkbox"
										name="shift64_woo_search_taxonomy_archive_scopes[]"
										value="<?php echo esc_attr( $taxonomy ); ?>"
										<?php checked( in_array( $taxonomy, $enabled_scopes, true ), true ); ?>
									/>
									<code><?php echo esc_html( $taxonomy ); ?></code>
									<span style="color:#666;">
										→ <?php echo esc_html( $config['redis_field'] ); ?>
									</span>
								</label>
							<?php endforeach; ?>
							<p class="description">
								<?php esc_html_e( 'Enable Redis-backed filtering (faceted pills) on these taxonomy archives. Does not affect sorting — Redis acts as a filter, theme/WC handle order.', 'shift64-woo-search' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-settings-status" class="shift64-woo-search-status"></span>
			</p>
		</form>
		<?php
	}

	// ── Results & Filters › Facets ─────────────────────────────

	/**
	 * Render the Facets section — the filters shoppers see beside their results.
	 *
	 * Relocated wholesale from the legacy Filters tab. All four filter groups stay
	 * in this one specialized form on purpose: its `shift64_woo_search_save_filters`
	 * handler writes every field it owns on each save, so splitting the form would
	 * make one half clear the other half's options.
	 */
	private function render_results_facets_section() {
		$attribute_taxonomies = wc_get_attribute_taxonomies();
		$selected             = get_option( 'shift64_woo_search_filter_attributes', array() );
		if ( ! is_array( $selected ) ) {
			$selected = array();
		}
		?>
		<div class="shift64-woo-search-filters-config">
			<h3><?php esc_html_e( 'Filter Attributes', 'shift64-woo-search' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Select which product attributes appear as search result filters. Changing attribute selection requires an index rebuild.', 'shift64-woo-search' ); ?></p>

			<form id="s64ws-filters-form">
				<table class="form-table">
					<?php if ( empty( $attribute_taxonomies ) ) : ?>
						<tr><td colspan="2"><?php esc_html_e( 'No WooCommerce product attributes found.', 'shift64-woo-search' ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $attribute_taxonomies as $attr ) :
							$tax_name = wc_attribute_taxonomy_name( $attr->attribute_name );
							?>
						<tr>
							<th><label><?php echo esc_html( $attr->attribute_label ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" name="filter_attributes[]"
										value="<?php echo esc_attr( $tax_name ); ?>"
										<?php checked( in_array( $tax_name, $selected, true ) ); ?> />
									<?php esc_html_e( 'Show as filter', 'shift64-woo-search' ); ?>
								</label>
								<span class="description" style="margin-left:8px"><?php echo esc_html( $tax_name ); ?></span>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</table>

				<h3><?php esc_html_e( 'Category Filter', 'shift64-woo-search' ); ?></h3>
				<table class="form-table">
					<?php $this->render_checkbox_field( 'shift64_woo_search_filter_categories_enabled', __( 'Show Category Filter', 'shift64-woo-search' ), 'yes', __( 'Display product category filter on search results pages.', 'shift64-woo-search' ) ); ?>
					<tr>
						<th scope="row">
							<label for="shift64_woo_search_filter_categories_excluded_input"><?php esc_html_e( 'Excluded Categories', 'shift64-woo-search' ); ?></label>
						</th>
						<td>
							<?php
							$excluded_ids    = get_option( 'shift64_woo_search_filter_categories_excluded', array() );
							$excluded_ids    = is_array( $excluded_ids ) ? array_filter( array_map( 'intval', $excluded_ids ) ) : array();
							$excluded_values = array();
							foreach ( $excluded_ids as $term_id ) {
								$term = get_term( $term_id, 'product_cat' );
								if ( $term && ! is_wp_error( $term ) ) {
									$excluded_values[] = $term->slug;
								}
							}
							?>
							<input
								type="text"
								id="shift64_woo_search_filter_categories_excluded_input"
								name="shift64_woo_search_filter_categories_excluded"
								class="regular-text"
								value="<?php echo esc_attr( implode( ', ', $excluded_values ) ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. kolekcje-v2, shift64-woo-search-unique-1', 'shift64-woo-search' ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'Comma-separated product category slugs or IDs to hide from the Category facet. No index rebuild required.', 'shift64-woo-search' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Brand Filter', 'shift64-woo-search' ); ?></h3>
				<table class="form-table">
					<?php $brands_available = taxonomy_exists( 'product_brand' ); ?>
					<tr>
						<th>
							<label for="shift64_woo_search_filter_brands_enabled"><?php esc_html_e( 'Show Brand Filter', 'shift64-woo-search' ); ?></label>
						</th>
						<td>
							<label>
								<?php if ( $brands_available ) : ?>
									<input type="hidden" name="shift64_woo_search_filter_brands_enabled" value="no" />
								<?php endif; ?>
								<input
									type="checkbox"
									id="shift64_woo_search_filter_brands_enabled"
									name="shift64_woo_search_filter_brands_enabled"
									value="yes"
									<?php checked( get_option( 'shift64_woo_search_filter_brands_enabled', 'no' ), 'yes' ); ?>
									<?php disabled( ! $brands_available ); ?> />
								<?php esc_html_e( 'Enabled', 'shift64-woo-search' ); ?>
							</label>
							<p class="description">
								<?php if ( $brands_available ) : ?>
									<?php esc_html_e( 'Display the product brand filter on search results pages.', 'shift64-woo-search' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Unavailable: this store has no product_brand taxonomy. WooCommerce provides it from version 9.4 onwards.', 'shift64-woo-search' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Filter Settings', 'shift64-woo-search' ); ?></button>
					<span style="color:#d63638;margin-left:12px;font-size:13px">
						<?php
						$index_link = sprintf(
							'<a href="%1$s">%2$s</a>',
							esc_url( $this->get_route_url( 'system', 'index' ) ),
							esc_html__( 'System › Index & Health', 'shift64-woo-search' )
						);

						printf(
							/* translators: %s: link to the System › Index & Health section. */
							esc_html__( 'After changing attributes, rebuild the index on %s.', 'shift64-woo-search' ),
							wp_kses_post( $index_link )
						);
						?>
					</span>
					<span id="s64ws-filters-status" class="shift64-woo-search-status"></span>
				</p>
			</form>
		</div>
		<?php
	}

	// ── Index Tab ─────────────────────────────────────────────

	/**
	 * Render the Index management tab.
	 */
	private function render_index_tab() {
		$redis        = Shift64_Woo_Search_Redis::get_instance();
		$available    = $redis->is_available();
		$index_exists = $available ? Shift64_Woo_Search_Schema::index_exists( $redis ) : false;
		$wc_count     = wp_count_posts( 'product' );
		$published    = isset( $wc_count->publish ) ? (int) $wc_count->publish : 0;
		?>
		<div class="shift64-woo-search-index">
			<h3><?php esc_html_e( 'Connection', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Redis', 'shift64-woo-search' ); ?></th>
					<td>
						<?php if ( $available ) : ?>
							<span class="shift64-woo-search-badge shift64-woo-search-badge--ok"><?php esc_html_e( 'Connected', 'shift64-woo-search' ); ?></span>
						<?php else : ?>
							<span class="shift64-woo-search-badge shift64-woo-search-badge--error"><?php esc_html_e( 'Unavailable', 'shift64-woo-search' ); ?></span>
							<p class="description"><?php echo esc_html( $redis->get_last_error() ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Index', 'shift64-woo-search' ); ?></th>
					<td>
						<?php if ( $index_exists ) : ?>
							<span class="shift64-woo-search-badge shift64-woo-search-badge--ok"><?php esc_html_e( 'Exists', 'shift64-woo-search' ); ?></span>
						<?php else : ?>
							<span class="shift64-woo-search-badge shift64-woo-search-badge--warning"><?php esc_html_e( 'Not created', 'shift64-woo-search' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'WC Products', 'shift64-woo-search' ); ?></th>
					<td><?php echo esc_html( $published ); ?> <?php esc_html_e( 'published', 'shift64-woo-search' ); ?></td>
				</tr>
				<?php if ( $available ) : ?>
				<tr>
					<th><?php esc_html_e( 'Redis Memory', 'shift64-woo-search' ); ?></th>
					<td>
						<?php
						$client = $redis->get_client();
						if ( $client ) {
							try {
								$mem = $client->info( 'memory' );
								echo esc_html( $mem['used_memory_human'] ?? '?' );
								echo ' (peak: ' . esc_html( $mem['used_memory_peak_human'] ?? '?' ) . ')';
							} catch ( Exception $e ) {
								echo '?';
							}
						}
						?>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<h3><?php esc_html_e( 'Actions', 'shift64-woo-search' ); ?></h3>
			<p>
				<button type="button" id="s64ws-rebuild-index" class="button button-primary" <?php disabled( ! $available ); ?>>
					<?php esc_html_e( 'Rebuild Index', 'shift64-woo-search' ); ?>
				</button>
				<span id="s64ws-rebuild-status" class="shift64-woo-search-status"></span>
			</p>
			<div id="s64ws-rebuild-progress" style="display:none">
				<div class="shift64-woo-search-progress">
					<div class="shift64-woo-search-progress__bar" id="s64ws-rebuild-bar" style="width:0"></div>
				</div>
				<span id="s64ws-rebuild-progress-text"></span>
			</div>
		</div>
		<?php
	}

	// ── System › Connection ────────────────────────────────────

	/**
	 * Render the Connection section — how this site reaches Redis.
	 *
	 * Relocated from the legacy Redis tab. Everything needed to open a connection
	 * lives here and nowhere else: host, port, optional ACL credentials, database,
	 * and the key prefix that isolates one site's keys from another's on a shared
	 * server. Rate limiting and the generated SHORTINIT config used to share this
	 * page; they are traffic policy and diagnostics, not connectivity, so they now
	 * have sections of their own.
	 *
	 * Test Connection keeps its current behavior and ordering: the button posts the
	 * connection fields as they stand in this form, so a merchant can prove a change
	 * works before saving it. This migration does not touch that flow.
	 *
	 * The credential rows are hidden *and* disabled when authentication is off, so
	 * they never reach the payload; the auth checkbox itself always serializes
	 * (the admin JS emits `yes`/`no` for checkboxes and skips the hidden fallback
	 * when a same-named checkbox exists), and that explicit `no` in the payload is
	 * what tells the persistence seam that clearing stored credentials was
	 * deliberate.
	 */
	private function render_system_connection_section() {
		?>
		<form id="s64ws-settings-form" class="shift64-woo-search-settings">

			<h3><?php esc_html_e( 'Redis Connection', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_text_field( 'shift64_woo_search_redis_host', __( 'Host', 'shift64-woo-search' ), '', 'e.g. 127.0.0.1' );
				$this->render_text_field( 'shift64_woo_search_redis_port', __( 'Port', 'shift64-woo-search' ), '', 'e.g. 6379', 'number' );

				// Migration: if the auth toggle was never explicitly saved, infer from existing credentials.
				$auth_enabled = get_option( 'shift64_woo_search_redis_auth_enabled', '' );
				if ( '' === $auth_enabled ) {
					$has_creds    = '' !== get_option( 'shift64_woo_search_redis_username', '' ) || '' !== get_option( 'shift64_woo_search_redis_password', '' );
					$auth_enabled = $has_creds ? 'yes' : 'no';
				}
				$auth_on        = ( 'yes' === $auth_enabled );
				$auth_row_attr  = $auth_on ? '' : ' hidden';
				$auth_disabled  = $auth_on ? '' : ' disabled="disabled"';
				$redis_username = get_option( 'shift64_woo_search_redis_username', '' );
				$redis_password = get_option( 'shift64_woo_search_redis_password', '' );
				?>
				<tr>
					<th><label for="shift64_woo_search_redis_auth_enabled"><?php esc_html_e( 'Authentication', 'shift64-woo-search' ); ?></label></th>
					<td>
						<label>
							<input type="hidden" name="shift64_woo_search_redis_auth_enabled" value="no" />
							<input type="checkbox" id="shift64_woo_search_redis_auth_enabled" name="shift64_woo_search_redis_auth_enabled" value="yes" <?php checked( $auth_on ); ?> />
							<?php esc_html_e( 'Use username and password (Redis 6+ ACL)', 'shift64-woo-search' ); ?>
						</label>
					</td>
				</tr>
				<tr class="s64ws-redis-auth-row"<?php echo $auth_row_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?>>
					<th><label for="shift64_woo_search_redis_username"><?php esc_html_e( 'Username', 'shift64-woo-search' ); ?></label></th>
					<td>
						<input type="text" id="shift64_woo_search_redis_username" name="shift64_woo_search_redis_username" value="<?php echo esc_attr( $redis_username ); ?>" class="regular-text" autocomplete="off"<?php echo $auth_disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?> />
					</td>
				</tr>
				<tr class="s64ws-redis-auth-row"<?php echo $auth_row_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?>>
					<th><label for="shift64_woo_search_redis_password"><?php esc_html_e( 'Password', 'shift64-woo-search' ); ?></label></th>
					<td>
						<input type="password" id="shift64_woo_search_redis_password" name="shift64_woo_search_redis_password" value="<?php echo esc_attr( $redis_password ); ?>" class="regular-text" autocomplete="new-password"<?php echo $auth_disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?> />
					</td>
				</tr>
				<?php
				$this->render_text_field( 'shift64_woo_search_redis_db', __( 'Database', 'shift64-woo-search' ), '0', '', 'number' );
				$this->render_text_field( 'shift64_woo_search_redis_prefix', __( 'Key Prefix', 'shift64-woo-search' ), 'shift64_woo_search', __( 'Isolates keys per site', 'shift64-woo-search' ) );
				?>
				<tr>
					<th></th>
					<td>
						<button type="button" id="s64ws-test-connection" class="button"><?php esc_html_e( 'Test Connection', 'shift64-woo-search' ); ?></button>
						<span id="s64ws-connection-status" class="shift64-woo-search-status"></span>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-settings-status" class="shift64-woo-search-status"></span>
			</p>
		</form>
		<?php
	}

	// ── System › Security & Traffic ────────────────────────────

	/**
	 * Render the Security & Traffic section — the request budget per visitor.
	 *
	 * Relocated from the legacy Redis tab, where it sat below the connection
	 * credentials for no better reason than both being "infrastructure". Capping
	 * requests per IP is a traffic-policy decision, so it earns its own section — and
	 * its own form, which submits exactly one key and therefore can never overwrite a
	 * Redis credential owned by the Connection section.
	 */
	private function render_system_security_section() {
		?>
		<form id="s64ws-settings-form" class="shift64-woo-search-settings">

			<h3><?php esc_html_e( 'Rate Limiting', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_text_field( 'shift64_woo_search_rate_limit', __( 'Max requests/sec per IP', 'shift64-woo-search' ), '30', __( '0 = disabled.', 'shift64-woo-search' ), 'number', '0', '1000', '1' );
				?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-settings-status" class="shift64-woo-search-status"></span>
			</p>
		</form>
		<?php
	}

	// ── System › Diagnostics ───────────────────────────────────

	/**
	 * Render the Diagnostics section — state of the generated SHORTINIT config.
	 *
	 * Moved verbatim from the legacy Redis tab. The plugin writes a small config file
	 * into `mu-plugins/` so the fast SHORTINIT search endpoint can boot without
	 * loading all of WordPress. That file is generated, not edited, so what a merchant
	 * needs for it is a status readout and a way to rebuild it — never a form field.
	 *
	 * The wrapper is still `#s64ws-settings-form`: the admin script binds the
	 * Regenerate button inside its settings-form initializer, so the button has to
	 * live inside that form for the existing JavaScript to find it. Keeping the ids
	 * identical is what lets this section move without a single line of script change.
	 *
	 * The section also owns the storefront debug panel switch. That panel is a
	 * diagnostic readout of how a search query was answered, so it belongs next to
	 * the other diagnostics rather than in Results & Filters with the settings that
	 * decide what shoppers actually see.
	 */
	private function render_system_diagnostics_section() {
		?>
		<form id="s64ws-settings-form" class="shift64-woo-search-settings">

			<h3><?php esc_html_e( 'SHORTINIT Config', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Generated config', 'shift64-woo-search' ); ?></th>
					<td>
						<button type="button" id="s64ws-regen-config" class="button"><?php esc_html_e( 'Regenerate SHORTINIT Config', 'shift64-woo-search' ); ?></button>
						<span id="s64ws-connection-status" class="shift64-woo-search-status"></span>
						<?php
						$config_file = WP_CONTENT_DIR . '/mu-plugins/shift64-woo-search/config.php';
						if ( file_exists( $config_file ) ) {
							$config_mtime = filemtime( $config_file );
							$local_time   = wp_date( 'Y-m-d H:i:s', $config_mtime );
							// Read the header line to get the version + generation timestamp.
							$header = '';
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read.
							$first_lines = file_get_contents( $config_file, false, null, 0, 200 );
							if ( preg_match( '/Auto-generated by (.+)/', $first_lines, $m ) ) {
								$header = $m[1];
							}
							echo '<br><small style="color:#666">';
							echo esc_html( 'SHORTINIT config: ' . ( $header ? $header : 'generated ' . $local_time ) );
							echo '</small>';
						} else {
							echo '<br><small style="color:#a00">';
							esc_html_e( 'SHORTINIT config not found — click Regenerate.', 'shift64-woo-search' );
							echo '</small>';
						}
						?>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Storefront Debug', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_checkbox_field(
					'shift64_woo_search_archive_debug_enabled',
					__( 'Archive Debug Panel', 'shift64-woo-search' ),
					'no',
					__( 'Show a debug bar on product search results pages listing how the query was answered — matched terms, Redis passes, timings, and facet computation. Visible only to users who can manage WooCommerce, never to shoppers. Leave this off on production.', 'shift64-woo-search' )
				);
				?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-settings-status" class="shift64-woo-search-status"></span>
			</p>
		</form>
		<?php
	}

	// ── Relevance › Basic Ranking ──────────────────────────────

	/**
	 * Render the Basic Ranking section — how a match is decided and ordered.
	 *
	 * Relocated from the legacy Search tab. These four controls answer the two
	 * questions merchants ask first: must a product match every typed word or just
	 * one of them, and what happens to products that are out of stock. Typo
	 * tolerance and the fallback passes live one section over, in Matching &
	 * Fallback, because tuning those is a different job done at a different time.
	 *
	 * The form carries only these four keys, so saving it can never overwrite a
	 * setting owned by another section.
	 */
	private function render_relevance_basic_section() {
		?>
		<form id="s64ws-settings-form" class="shift64-woo-search-settings">

			<h3><?php esc_html_e( 'Search Behavior', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_select_field(
					'shift64_woo_search_logic',
					__( 'Search Logic', 'shift64-woo-search' ),
					array(
						'AND' => __( 'AND — all terms must match (recommended)', 'shift64-woo-search' ),
						'OR'  => __( 'OR — any term matches', 'shift64-woo-search' ),
					),
					__( 'OR retrieval ranks a product matching two of three words above one matching all three. Use it only when recall matters more than precision.', 'shift64-woo-search' ),
					Shift64_Woo_Search_Deprecations::for_option( 'shift64_woo_search_logic' )
				);
				$this->render_select_field(
					'shift64_woo_search_strategy',
					__( 'Search Mode', 'shift64-woo-search' ),
					array(
						'mixed'        => __( 'Mixed — every word as prefix or fuzzy, one query (recommended)', 'shift64-woo-search' ),
						'strict_first' => __( 'Strict first, fuzzy fallback', 'shift64-woo-search' ),
					),
					__( 'Mixed repairs a typo in one word while still requiring the others. Strict-first runs a clean prefix query and only widens when a pass fails to match every word.', 'shift64-woo-search' )
				);
				?>
			</table>

			<h3><?php esc_html_e( 'Out-of-Stock Products', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_select_field(
					'shift64_woo_search_outofstock_mode',
					__( 'Mode', 'shift64-woo-search' ),
					array(
						'exclude' => __( 'Exclude — never show', 'shift64-woo-search' ),
						'show'    => __( 'Show — same ranking', 'shift64-woo-search' ),
						'demote'  => __( 'Demote — lower score', 'shift64-woo-search' ),
					)
				);
				$this->render_text_field( 'shift64_woo_search_outofstock_demote_factor', __( 'Demote Factor', 'shift64-woo-search' ), '0.3', __( '0.0–1.0.', 'shift64-woo-search' ), 'number', '0', '1', '0.1' );
				?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-settings-status" class="shift64-woo-search-status"></span>
			</p>
		</form>
		<?php
	}

	// ── Relevance › Matching & Fallback ────────────────────────

	/**
	 * Render the Matching & Fallback section — what happens when the query is messy.
	 *
	 * Relocated from the legacy Search tab. Everything here exists for one reason:
	 * shoppers mistype, abbreviate, and pad queries with filler words. The controls
	 * are grouped by the pass they govern — fuzzy distance and when a fallback pass
	 * fires, which filler tokens may be dropped, and how diacritics and synonyms are
	 * normalized before matching.
	 *
	 * `shift64_woo_search_full_limit` sits here rather than in Search Experience: it
	 * sizes the full search endpoint, not the autocomplete dropdown, so it belongs
	 * with the advanced matching behavior it bounds. Its label and semantics are
	 * unchanged by the move.
	 */
	private function render_relevance_matching_section() {
		?>
		<form id="s64ws-settings-form" class="shift64-woo-search-settings">

			<h3><?php esc_html_e( 'Fuzzy Matching', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_text_field( 'shift64_woo_search_fuzzy_level', __( 'Fuzzy Level', 'shift64-woo-search' ), '1', __( 'Levenshtein distance 1-3.', 'shift64-woo-search' ), 'number' );
				$this->render_select_field(
					'shift64_woo_search_fallback_trigger',
					__( 'Fallback Trigger', 'shift64-woo-search' ),
					array(
						'low_score'  => __( 'No results, or no result matched every word (recommended)', 'shift64-woo-search' ),
						'no_results' => __( 'Only when no results', 'shift64-woo-search' ),
					),
					__( 'When a pass hands over to the next one. Applies to Strict-first only.', 'shift64-woo-search' ),
					Shift64_Woo_Search_Deprecations::for_option( 'shift64_woo_search_fallback_trigger' )
				);
				$this->render_text_field( 'shift64_woo_search_fallback_score_threshold', __( 'Score Threshold', 'shift64-woo-search' ), '0.5', __( 'Minimum score a match from the final all-fuzzy fallback pass needs to be shown. Strict-first only — the per-token fuzzy pass and Mixed mode are never score-filtered.', 'shift64-woo-search' ), 'number', '0', '10', '0.1' );
				$this->render_select_field(
					'shift64_woo_search_fallback_fuzzy_level',
					__( 'Fallback Fuzzy Level', 'shift64-woo-search' ),
					array(
						'1' => '1 — conservative',
						'2' => '2 — moderate',
						'3' => '3 — aggressive',
					)
				);
				?>
			</table>

			<h3><?php esc_html_e( 'Token Reduction', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_checkbox_field( 'shift64_woo_search_token_reduction_enabled', __( 'Token Reduction', 'shift64-woo-search' ), 'yes', __( 'Drop weak trailing tokens before fuzzy fallback.', 'shift64-woo-search' ) );
				$this->render_text_field( 'shift64_woo_search_weak_tokens', __( 'Weak Tokens', 'shift64-woo-search' ), 'do,na,z,i,w,od,po,za,ze,we,o,u,a,e', __( 'Comma-separated.', 'shift64-woo-search' ) );
				$this->render_checkbox_field( 'shift64_woo_search_drop_trailing_weak_token_only', __( 'Drop Trailing Only', 'shift64-woo-search' ), 'yes' );
				?>
			</table>

			<h3><?php esc_html_e( 'Normalization', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_checkbox_field( 'shift64_woo_search_diacritics_normalization', __( 'Diacritics Normalization', 'shift64-woo-search' ), 'yes', __( 'Match Polish chars without diacritics (ł→l, ó→o, etc). Requires rebuild.', 'shift64-woo-search' ) );
				$this->render_checkbox_field( 'shift64_woo_search_fuzzy_synonyms', __( 'Fuzzy Synonyms', 'shift64-woo-search' ), 'no', __( 'Match synonyms even with typos or partial input (prefix + Levenshtein).', 'shift64-woo-search' ) );
				?>
			</table>

			<h3><?php esc_html_e( 'Result Limits', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_text_field( 'shift64_woo_search_full_limit', __( 'Full Search Results Limit', 'shift64-woo-search' ), '20', __( 'Max products for full search page.', 'shift64-woo-search' ), 'number' );
				?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-settings-status" class="shift64-woo-search-status"></span>
			</p>
		</form>
		<?php
	}

	// ── Relevance › Merchandising ──────────────────────────────

	/**
	 * Render the Merchandising section — deliberate thumbs on the ranking scale.
	 *
	 * Relocated from the legacy Search tab. This is the one place where a merchant
	 * overrides organic ranking on purpose, which is why it is its own section
	 * rather than a block at the bottom of Basic Ranking.
	 *
	 * These rules boost *products* by their category or tag. The similarly named
	 * editors under Search Experience › Category Suggestions shape the category list
	 * inside the autocomplete dropdown instead — different option, different surface.
	 */
	private function render_relevance_merchandising_section() {
		?>
		<form id="s64ws-settings-form" class="shift64-woo-search-settings">

			<h3><?php esc_html_e( 'Relevance Boosts', 'shift64-woo-search' ); ?></h3>
			<table class="form-table">
				<?php
				$this->render_textarea_field(
					'shift64_woo_search_category_boost_rules',
					__( 'Category / Tag Boost Rules', 'shift64-woo-search' ),
					'',
					__( 'One rule per line. Format: category-or-tag|factor (global) or query|category-or-tag|factor (query-specific). Lines starting with # are comments. Factor is clamped to 1-200, but factors compound with promoted (×1.5) and title-start (×2/×3) — values above ~5 can crowd out organic ranking, so use sparingly. Example: Sale|1.2 or shirt|Sale|1.5. No index rebuild required.', 'shift64-woo-search' )
				);
				?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-settings-status" class="shift64-woo-search-status"></span>
			</p>
		</form>
		<?php
	}

	// ── Search Experience › Search Field ───────────────────────

	/**
	 * Render the Search Field section — where the search box lives and how it reacts.
	 *
	 * Relocated from the legacy Frontend tab. The form carries only these four keys,
	 * so submitting it can never touch a setting owned by another section.
	 */
	private function render_experience_search_field_section() {
		?>
		<form id="s64ws-settings-form" class="shift64-woo-search-settings">

			<table class="form-table">
				<?php
				$this->render_text_field( 'shift64_woo_search_debounce', __( 'Debounce (ms)', 'shift64-woo-search' ), '150', '', 'number' );
				$this->render_text_field( 'shift64_woo_search_input_selector', __( 'Input Selector', 'shift64-woo-search' ), '.shift64-woo-search-field__input' );
				$this->render_text_field( 'shift64_woo_search_additional_selectors', __( 'Additional Selectors', 'shift64-woo-search' ), '', __( 'Comma-separated.', 'shift64-woo-search' ) );
				$this->render_text_field( 'shift64_woo_search_button_selector', __( 'Search Button Selector', 'shift64-woo-search' ), '', __( 'CSS selector for the search submit button, e.g. .search-submit', 'shift64-woo-search' ) );
				?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-settings-status" class="shift64-woo-search-status"></span>
			</p>
		</form>
		<?php
	}

	// ── Search Experience › Autocomplete ───────────────────────

	/**
	 * Render the Autocomplete section — what the dropdown shows while shoppers type.
	 *
	 * Relocated from the legacy Search tab. `shift64_woo_search_full_limit` stays
	 * behind with matching behaviour: it sizes the full results page, not the
	 * dropdown, so it belongs with Relevance rather than here.
	 */
	private function render_experience_autocomplete_section() {
		?>
		<form id="s64ws-settings-form" class="shift64-woo-search-settings">

			<table class="form-table">
				<?php
				$this->render_text_field( 'shift64_woo_search_min_query', __( 'Min Query Length', 'shift64-woo-search' ), '2', '', 'number' );
				$this->render_text_field( 'shift64_woo_search_autocomplete_limit', __( 'Quick Search Results Limit', 'shift64-woo-search' ), '7', __( 'Max products shown in the dropdown.', 'shift64-woo-search' ), 'number' );
				$this->render_select_field(
					'shift64_woo_search_dropdown_width_mode',
					__( 'Dropdown Width', 'shift64-woo-search' ),
					array(
						'field'  => __( 'Match the search field', 'shift64-woo-search' ),
						'custom' => __( 'Custom width', 'shift64-woo-search' ),
					),
					__( 'The results tray is as wide as the search field unless you set a custom width.', 'shift64-woo-search' )
				);
				$this->render_text_field( 'shift64_woo_search_dropdown_width', __( 'Custom Width (px)', 'shift64-woo-search' ), '645', __( 'Applies only when Dropdown Width is set to Custom. Between 320 and 1200; mobile stays full-width regardless.', 'shift64-woo-search' ), 'number', '320', '1200' );
				$this->render_checkbox_field( 'shift64_woo_search_show_sku', __( 'Show SKU', 'shift64-woo-search' ), 'yes', __( 'Include the product SKU in the result meta line.', 'shift64-woo-search' ) );
				$this->render_checkbox_field( 'shift64_woo_search_show_category', __( 'Show Category', 'shift64-woo-search' ), 'yes', __( 'Include the most specific product category in the result meta line.', 'shift64-woo-search' ) );
				$this->render_checkbox_field( 'shift64_woo_search_show_brand', __( 'Show Brand', 'shift64-woo-search' ), 'yes', __( 'Include the product brand in the result meta line. Turn options off to reduce clutter, or stack any combination.', 'shift64-woo-search' ) );
				$this->render_checkbox_field( 'shift64_woo_search_category_suggest_fuzzy', __( 'Category Suggestion Fuzzy', 'shift64-woo-search' ), 'no', __( 'Allow typo-tolerant matching in the autocomplete category section. Uses conservative Levenshtein distance 1 on category-name tokens; no index rebuild required.', 'shift64-woo-search' ) );
				$this->render_checkbox_field( 'shift64_woo_search_brand_suggest_enabled', __( 'Brand Suggestions', 'shift64-woo-search' ), 'yes', __( 'Show a Brands section in the autocomplete dropdown. The section hides itself on stores that have no brands.', 'shift64-woo-search' ) );
				?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'shift64-woo-search' ); ?></button>
				<span id="s64ws-settings-status" class="shift64-woo-search-status"></span>
			</p>
		</form>
		<?php
	}

	// ── Product Metabox ────────────────────────────────────────

	/**
	 * Register the product edit metabox.
	 */
	public function add_product_metabox() {
		add_meta_box(
			'shift64_woo_search_product',
			__( 'Shift64 Woo Search', 'shift64-woo-search' ),
			array( $this, 'render_product_metabox' ),
			'product',
			'side'
		);
	}

	/**
	 * Render the product metabox with promoted/excluded checkboxes.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_product_metabox( $post ) {
		wp_nonce_field( 'shift64_woo_search_product_metabox', 'shift64_woo_search_metabox_nonce' );
		$promoted = get_post_meta( $post->ID, '_shift64_woo_search_promoted', true );
		$excluded = get_post_meta( $post->ID, '_shift64_woo_search_excluded', true );
		?>
		<p>
			<label><input type="checkbox" name="shift64_woo_search_promoted" value="1" <?php checked( $promoted, '1' ); ?> />
			<?php esc_html_e( 'Promoted in search', 'shift64-woo-search' ); ?></label>
		</p>
		<p>
			<label><input type="checkbox" name="shift64_woo_search_excluded" value="1" <?php checked( $excluded, '1' ); ?> />
			<?php esc_html_e( 'Excluded from search', 'shift64-woo-search' ); ?></label>
		</p>
		<?php
	}

	/**
	 * Save promoted/excluded flags from the product metabox.
	 *
	 * @param int $post_id Post ID being saved.
	 */
	public function save_product_metabox( $post_id ) {
		if ( ! isset( $_POST['shift64_woo_search_metabox_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['shift64_woo_search_metabox_nonce'] ) ), 'shift64_woo_search_product_metabox' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_shift64_woo_search_promoted', ! empty( $_POST['shift64_woo_search_promoted'] ) ? '1' : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		update_post_meta( $post_id, '_shift64_woo_search_excluded', ! empty( $_POST['shift64_woo_search_excluded'] ) ? '1' : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	}

	// ── Dashboard Widget ───────────────────────────────────────

	/**
	 * Register the dashboard widget.
	 */
	public function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		wp_add_dashboard_widget(
			'shift64_woo_search_dashboard',
			__( 'Shift64 Woo Search', 'shift64-woo-search' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the dashboard widget with 7-day search summary.
	 */
	public function render_dashboard_widget() {
		$total_7d = Shift64_Woo_Search_Stats::get_search_count( 7 );
		$avg_time = Shift64_Woo_Search_Stats::get_avg_response_time( 7 );
		$top      = Shift64_Woo_Search_Stats::get_top_searches( 7, 5 );
		$zero     = Shift64_Woo_Search_Stats::get_zero_result_searches( 7, 5 );
		?>
		<div style="display:flex;gap:16px;margin-bottom:12px">
			<div><strong><?php echo esc_html( $total_7d ); ?></strong> <?php esc_html_e( 'searches (7d)', 'shift64-woo-search' ); ?></div>
			<div><strong><?php echo esc_html( round( $avg_time, 1 ) ); ?>ms</strong> <?php esc_html_e( 'avg response', 'shift64-woo-search' ); ?></div>
		</div>
		<?php if ( ! empty( $top ) ) : ?>
			<p><strong><?php esc_html_e( 'Top searches:', 'shift64-woo-search' ); ?></strong></p>
			<ul style="margin:0">
				<?php foreach ( $top as $row ) : ?>
					<li><?php echo esc_html( $row->query_normalized ); ?> (<?php echo esc_html( $row->search_count ); ?>)</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( ! empty( $zero ) ) : ?>
			<p><strong><?php esc_html_e( 'Zero-result searches:', 'shift64-woo-search' ); ?></strong></p>
			<ul style="margin:0;color:#d63638">
				<?php foreach ( $zero as $row ) : ?>
					<li><?php echo esc_html( $row->query_normalized ); ?> (<?php echo esc_html( $row->search_count ); ?>)</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php // Canonical route. The legacy `tab=stats` alias still resolves here, but internal links point at the destination, not the alias. ?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=shift64-woo-search&tab=insights&section=statistics' ) ); ?>"><?php esc_html_e( 'View full statistics', 'shift64-woo-search' ); ?> &rarr;</a></p>
		<?php
	}

	// ── Field Renderers ────────────────────────────────────────

	/**
	 * Render a text/number input field row.
	 *
	 * @param string $name    Option name.
	 * @param string $label   Field label.
	 * @param string $default_value Default value.
	 * @param string $desc          Description text.
	 * @param string $type          Input type.
	 * @param string $min           Min attribute.
	 * @param string $max           Max attribute.
	 * @param string $step          Step attribute.
	 */
	private function render_text_field( $name, $label, $default_value = '', $desc = '', $type = 'text', $min = '', $max = '', $step = '' ) {
		$value = get_option( $name, $default_value );
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $name ); ?>"
						name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text"
						<?php echo '' !== $min ? 'min="' . esc_attr( $min ) . '"' : ''; ?>
						<?php echo '' !== $max ? 'max="' . esc_attr( $max ) . '"' : ''; ?>
						<?php echo '' !== $step ? 'step="' . esc_attr( $step ) . '"' : ''; ?> />
				<?php
				if ( $desc ) :
					?>
					<p class="description"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a textarea field row.
	 *
	 * @param string $name          Option name.
	 * @param string $label         Field label.
	 * @param string $default_value Default value.
	 * @param string $desc          Description text.
	 */
	private function render_textarea_field( $name, $label, $default_value = '', $desc = '' ) {
		$value = get_option( $name, $default_value );
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<textarea id="<?php echo esc_attr( $name ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						class="large-text code"
						rows="5"><?php echo esc_textarea( $value ); ?></textarea>
				<?php
				if ( $desc ) :
					?>
					<p class="description"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a select field row.
	 *
	 * `$deprecated` marks individual *values* as on their way out without
	 * changing what they do. A marked choice keeps its place in the dropdown —
	 * a merchant comparing settings must still be able to switch back — but its
	 * label carries the verdict, so the deprecation is announced by the control
	 * itself rather than only by help text underneath it. The reason paragraph
	 * is rendered only for the store that actually has the value stored; the
	 * rest get the label marker and nothing more.
	 *
	 * The suffix is composed with `sprintf()` rather than baked into each
	 * caller's option label so translators handle one "deprecated" string
	 * instead of one per value.
	 *
	 * @param string $name       Option name.
	 * @param string $label      Field label.
	 * @param array  $options    Associative array of value => label.
	 * @param string $desc       Description text.
	 * @param array  $deprecated Optional. Deprecated value => merchant-facing reason.
	 */
	private function render_select_field( $name, $label, $options, $desc = '', $deprecated = array() ) {
		$value = get_option( $name, '' );
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<?php
					foreach ( $options as $key => $text ) :
						if ( isset( $deprecated[ $key ] ) ) {
							$text = sprintf(
								/* translators: %s: the option's own label, e.g. "OR — any term matches". */
								__( '%s — deprecated', 'shift64-woo-search' ),
								$text
							);
						}
						?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php
				if ( $desc ) :
					?>
					<p class="description"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
				<?php if ( isset( $deprecated[ $value ] ) ) : ?>
					<p class="description shift64-woo-search-admin__deprecated-note">
						<?php
						printf(
							/* translators: %s: why the currently selected value is deprecated and what to use instead. */
							esc_html__( 'Deprecated: %s', 'shift64-woo-search' ),
							esc_html( $deprecated[ $value ] )
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a checkbox field row.
	 *
	 * @param string $name    Option name.
	 * @param string $label   Field label.
	 * @param string $default_value Default value.
	 * @param string $desc          Description text.
	 */
	private function render_checkbox_field( $name, $label, $default_value = 'no', $desc = '' ) {
		$value = get_option( $name, $default_value );
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<label>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="no" />
					<input type="checkbox" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"
							value="yes" <?php checked( $value, 'yes' ); ?> />
					<?php esc_html_e( 'Enabled', 'shift64-woo-search' ); ?>
				</label>
				<?php
				if ( $desc ) :
					?>
					<p class="description"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	// ── AJAX Handlers ──────────────────────────────────────────

	/**
	 * AJAX handler for test search queries.
	 */
	public function ajax_test_query() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$mode  = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'autocomplete';
		$limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 10;

		if ( empty( $query ) ) {
			wp_send_json_error( array( 'message' => 'Empty query.' ) );
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			wp_send_json_error( array( 'message' => 'Redis unavailable: ' . $redis->get_last_error() ) );
		}

		$config  = $this->build_search_config();
		$search  = new Shift64_Woo_Search_Query( $redis, $config );
		$results = $search->search( $query, $mode, $limit );

		// Enrich results with stock status and flags (raw_score comes from search() now).
		if ( 'autocomplete' === $mode && ! empty( $results['results'] ) ) {
			foreach ( $results['results'] as &$r ) {
				$stock             = get_post_meta( $r['id'], '_stock_status', true );
				$r['stock_status'] = $stock ? $stock : 'instock';
				$r['promoted']     = (bool) get_post_meta( $r['id'], '_shift64_woo_search_promoted', true );
				$r['excluded']     = (bool) get_post_meta( $r['id'], '_shift64_woo_search_excluded', true );
			}
			unset( $r );
		}

		$results['outofstock_mode'] = $config['outofstock_mode'];
		$results['demote_factor']   = $config['outofstock_demote_factor'];

		wp_send_json_success( $results );
	}

	/**
	 * AJAX handler for tuning comparison queries.
	 */
	public function ajax_tuning_query() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 5;

		if ( empty( $query ) ) {
			wp_send_json_error( array( 'message' => 'Empty query.' ) );
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			wp_send_json_error( array( 'message' => 'Redis unavailable: ' . $redis->get_last_error() ) );
		}

		$config = $this->build_search_config();
		$search = new Shift64_Woo_Search_Query( $redis, $config );
		$passes = $search->search_all_passes( $query, 'autocomplete', $limit );

		// Enrich with promoted/excluded flags.
		foreach ( $passes as &$pass ) {
			if ( ! empty( $pass['results'] ) ) {
				foreach ( $pass['results'] as &$r ) {
					$r['promoted'] = (bool) get_post_meta( $r['id'], '_shift64_woo_search_promoted', true );
					$r['excluded'] = (bool) get_post_meta( $r['id'], '_shift64_woo_search_excluded', true );
				}
				unset( $r );
			}
		}
		unset( $pass );

		wp_send_json_success(
			array(
				'query'  => $query,
				'passes' => $passes,
			)
		);
	}

	/**
	 * AJAX handler for toggling product promoted/excluded flags.
	 */
	public function ajax_toggle_product_flag() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$flag       = isset( $_POST['flag'] ) ? sanitize_text_field( wp_unslash( $_POST['flag'] ) ) : '';
		$value      = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '0';

		if ( ! $product_id || ! in_array( $flag, array( 'promoted', 'excluded' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters.' ) );
		}

		$meta_key = '_shift64_woo_search_' . $flag;
		update_post_meta( $product_id, $meta_key, '1' === $value ? '1' : '' );

		// Re-index to update Redis immediately.
		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( $redis->is_available() ) {
			$indexer = new Shift64_Woo_Search_Indexer( $redis );
			$indexer->index_product( $product_id );
		}

		wp_send_json_success(
			array(
				'product_id' => $product_id,
				'flag'       => $flag,
				'value'      => '1' === $value,
			)
		);
	}

	// ── Synonyms AJAX ──────────────────────────────────────────

	/**
	 * AJAX handler for adding a synonym group.
	 */
	public function ajax_synonys64ws_add() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$terms = isset( $_POST['terms'] ) ? sanitize_text_field( wp_unslash( $_POST['terms'] ) ) : '';
		$id    = Shift64_Woo_Search_Synonyms::add( $terms );

		if ( false === $id ) {
			wp_send_json_error( array( 'message' => 'At least 2 terms required.' ) );
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( $redis->is_available() ) {
			Shift64_Woo_Search_Synonyms::sync_to_redis( $redis );
		}

		wp_send_json_success( array( 'synonyms' => Shift64_Woo_Search_Synonyms::get_all() ) );
	}

	/**
	 * AJAX handler for updating a synonym group.
	 */
	public function ajax_synonys64ws_update() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$terms = isset( $_POST['terms'] ) ? sanitize_text_field( wp_unslash( $_POST['terms'] ) ) : '';

		if ( ! Shift64_Woo_Search_Synonyms::update( $index, $terms ) ) {
			wp_send_json_error( array( 'message' => 'Update failed. At least 2 terms required.' ) );
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( $redis->is_available() ) {
			Shift64_Woo_Search_Synonyms::sync_to_redis( $redis );
		}

		wp_send_json_success( array( 'synonyms' => Shift64_Woo_Search_Synonyms::get_all() ) );
	}

	/**
	 * AJAX handler for removing a synonym group.
	 */
	public function ajax_synonys64ws_remove() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;

		if ( ! Shift64_Woo_Search_Synonyms::remove( $index ) ) {
			wp_send_json_error( array( 'message' => 'Remove failed.' ) );
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( $redis->is_available() ) {
			Shift64_Woo_Search_Synonyms::sync_to_redis( $redis );
		}

		wp_send_json_success( array( 'synonyms' => Shift64_Woo_Search_Synonyms::get_all() ) );
	}

	/**
	 * AJAX handler for exporting synonym groups as text.
	 */
	public function ajax_synonys64ws_export() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		wp_send_json_success( array( 'text' => Shift64_Woo_Search_Synonyms::export() ) );
	}

	/**
	 * AJAX handler for importing synonym groups from text.
	 */
	public function ajax_synonys64ws_import() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intentional: text contains =>, which sanitize_text_field may alter.
		$text    = isset( $_POST['text'] ) ? wp_unslash( $_POST['text'] ) : '';
		$replace = ! empty( $_POST['replace'] );
		$result  = Shift64_Woo_Search_Synonyms::import( $text, $replace );

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( $redis->is_available() ) {
			Shift64_Woo_Search_Synonyms::sync_to_redis( $redis );
		}

		wp_send_json_success(
			array(
				'imported' => $result['imported'],
				'skipped'  => $result['skipped'],
				'synonyms' => Shift64_Woo_Search_Synonyms::get_all(),
			)
		);
	}

	// ── Suggestions AJAX ───────────────────────────────────────

	/**
	 * AJAX handler for adding a suggestion.
	 */
	public function ajax_suggestions_add() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$text = isset( $_POST['text'] ) ? sanitize_text_field( wp_unslash( $_POST['text'] ) ) : '';
		$id   = Shift64_Woo_Search_Suggestions::add( $text );

		if ( false === $id ) {
			wp_send_json_error( array( 'message' => 'Text required.' ) );
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( $redis->is_available() ) {
			Shift64_Woo_Search_Suggestions::sync_to_redis( $redis );
		}

		wp_send_json_success( array( 'suggestions' => Shift64_Woo_Search_Suggestions::get_all() ) );
	}

	/**
	 * AJAX handler for updating a suggestion.
	 */
	public function ajax_suggestions_update() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$text  = isset( $_POST['text'] ) ? sanitize_text_field( wp_unslash( $_POST['text'] ) ) : '';

		if ( ! Shift64_Woo_Search_Suggestions::update( $index, $text ) ) {
			wp_send_json_error( array( 'message' => 'Update failed.' ) );
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( $redis->is_available() ) {
			Shift64_Woo_Search_Suggestions::sync_to_redis( $redis );
		}

		wp_send_json_success( array( 'suggestions' => Shift64_Woo_Search_Suggestions::get_all() ) );
	}

	/**
	 * AJAX handler for removing a suggestion.
	 */
	public function ajax_suggestions_remove() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;

		if ( ! Shift64_Woo_Search_Suggestions::remove( $index ) ) {
			wp_send_json_error( array( 'message' => 'Remove failed.' ) );
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( $redis->is_available() ) {
			Shift64_Woo_Search_Suggestions::sync_to_redis( $redis );
		}

		wp_send_json_success( array( 'suggestions' => Shift64_Woo_Search_Suggestions::get_all() ) );
	}

	/**
	 * AJAX handler for exporting suggestions as text.
	 */
	public function ajax_suggestions_export() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		wp_send_json_success( array( 'text' => Shift64_Woo_Search_Suggestions::export() ) );
	}

	/**
	 * AJAX handler for importing suggestions from text.
	 */
	public function ajax_suggestions_import() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$text    = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$replace = ! empty( $_POST['replace'] );
		$result  = Shift64_Woo_Search_Suggestions::import( $text, $replace );

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( $redis->is_available() ) {
			Shift64_Woo_Search_Suggestions::sync_to_redis( $redis );
		}

		wp_send_json_success(
			array(
				'imported'    => $result['imported'],
				'suggestions' => Shift64_Woo_Search_Suggestions::get_all(),
			)
		);
	}

	// ── Stats AJAX ─────────────────────────────────────────────

	/**
	 * AJAX handler for fetching search statistics.
	 */
	public function ajax_get_stats() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$days     = isset( $_POST['days'] ) ? (int) $_POST['days'] : 30;
		$total    = Shift64_Woo_Search_Stats::get_search_count( $days );
		$total_7d = Shift64_Woo_Search_Stats::get_search_count( 7 );
		$avg_time = Shift64_Woo_Search_Stats::get_avg_response_time( $days );
		$daily    = Shift64_Woo_Search_Stats::get_daily_counts( $days );
		$top      = Shift64_Woo_Search_Stats::get_top_searches( $days, 20 );
		$zero     = Shift64_Woo_Search_Stats::get_zero_result_searches( $days, 20 );

		$zero_total = 0;
		foreach ( $zero as $z ) {
			$zero_total += (int) $z->search_count;
		}

		wp_send_json_success(
			array(
				'total'     => $total,
				'total_7d'  => $total_7d,
				'avg_time'  => round( $avg_time, 1 ),
				'zero_rate' => $total > 0 ? round( $zero_total / $total * 100, 1 ) : 0,
				'daily'     => $daily,
				'top'       => $top,
				'zero'      => $zero,
			)
		);
	}

	/**
	 * AJAX handler for cleaning up old statistics.
	 */
	public function ajax_cleanup_stats() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '90';
		if ( 'all' === $mode ) {
			Shift64_Woo_Search_Stats::cleanup_all();
			wp_send_json_success( array( 'message' => 'All stats deleted.' ) );
		}
		$deleted = Shift64_Woo_Search_Stats::cleanup_old( (int) $mode );
		wp_send_json_success( array( 'message' => sprintf( '%d old records deleted.', (int) $deleted ) ) );
	}

	// ── Weights AJAX ───────────────────────────────────────────

	/**
	 * AJAX handler for saving field weights.
	 */
	public function ajax_save_weights() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are sanitized in the loop below.
		$weights   = isset( $_POST['weights'] ) ? wp_unslash( $_POST['weights'] ) : array();
		$defaults  = Shift64_Woo_Search_Schema::get_default_weights();
		$sanitized = array();
		foreach ( $defaults as $field => $default_val ) {
			$sanitized[ $field ] = isset( $weights[ $field ] ) ? max( 0, min( 20, (int) $weights[ $field ] ) ) : $default_val;
		}

		update_option( 'shift64_woo_search_weights', $sanitized );
		Shift64_Woo_Search_Plugin::get_instance()->generate_mu_plugin_config();

		wp_send_json_success( array( 'message' => 'Weights saved. Starting rebuild...' ) );
	}

	// ── Settings AJAX ──────────────────────────────────────────

	/**
	 * AJAX handler for saving plugin settings.
	 *
	 * Request plumbing only — allowlisting, sanitization, and the credential
	 * cleanup live in Shift64_Woo_Search_Admin_Settings::persist(), which any
	 * section form can therefore call with just its own fields.
	 */
	public function ajax_save_setting() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are sanitized by the persistence seam below.
		$settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			wp_send_json_error( array( 'message' => 'No settings provided.' ) );
		}

		$saved = Shift64_Woo_Search_Admin_Settings::persist( $settings );

		Shift64_Woo_Search_Redis::reset_instance();
		Shift64_Woo_Search_Plugin::get_instance()->generate_mu_plugin_config();

		wp_send_json_success( array( 'message' => sprintf( '%d settings saved. SHORTINIT config regenerated.', $saved ) ) );
	}

	/**
	 * AJAX handler — save faceted filter configuration.
	 */
	public function ajax_save_filters() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$attributes = array();
		if ( isset( $_POST['filter_attributes'] ) && is_array( $_POST['filter_attributes'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			$attributes = array_map( 'sanitize_text_field', wp_unslash( $_POST['filter_attributes'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		update_option( 'shift64_woo_search_filter_attributes', $attributes );

		// Category filter toggle.
		if ( isset( $_POST['shift64_woo_search_filter_categories_enabled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			update_option( 'shift64_woo_search_filter_categories_enabled', sanitize_text_field( wp_unslash( $_POST['shift64_woo_search_filter_categories_enabled'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// Brand filter toggle. Absent from the POST when the taxonomy is missing
		// (the checkbox renders disabled and its hidden companion is omitted),
		// so the stored value is left alone rather than silently reset.
		if ( isset( $_POST['shift64_woo_search_filter_brands_enabled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			$brands_enabled = sanitize_text_field( wp_unslash( $_POST['shift64_woo_search_filter_brands_enabled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_option( 'shift64_woo_search_filter_brands_enabled', 'yes' === $brands_enabled ? 'yes' : 'no' );
		}

		$excluded_categories = array();
		if ( isset( $_POST['shift64_woo_search_filter_categories_excluded'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			$raw = sanitize_text_field( wp_unslash( $_POST['shift64_woo_search_filter_categories_excluded'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $token ) {
				$term = ctype_digit( $token )
					? get_term( (int) $token, 'product_cat' )
					: get_term_by( 'slug', $token, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$excluded_categories[] = (int) $term->term_id;
				}
			}
		}
		update_option( 'shift64_woo_search_filter_categories_excluded', array_values( array_unique( $excluded_categories ) ) );

		wp_send_json_success( array( 'message' => __( 'Filter settings saved. Rebuild index only if you changed filter attributes.', 'shift64-woo-search' ) ) );
	}

	/**
	 * Re-cache the category blob so per-category boost changes take effect
	 * without a full index rebuild.
	 */
	private function refresh_category_cache() {
		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( $redis->is_available() ) {
			Shift64_Woo_Search_Rebuild::cache_blobs( $redis );
		}
	}

	/**
	 * AJAX handler — add or update a per-category autocomplete boost.
	 */
	public function ajax_save_category_boost() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$token  = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$factor = isset( $_POST['factor'] ) ? (float) $_POST['factor'] : 0.0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Category required.', 'shift64-woo-search' ) ) );
		}
		if ( $factor <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Boost must be greater than 0.', 'shift64-woo-search' ) ) );
		}
		// Clamp to the same ceiling as product boost rules to keep ordering sane.
		$factor = min( 200.0, $factor );

		// Resolve the typed token to a product_cat term (id, slug, then name).
		$term = ctype_digit( $token )
			? get_term( (int) $token, 'product_cat' )
			: get_term_by( 'slug', $token, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			$term = get_term_by( 'name', $token, 'product_cat' );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => __( 'Category not found.', 'shift64-woo-search' ) ) );
		}

		$boosts = get_option( 'shift64_woo_search_category_boosts', array() );
		if ( ! is_array( $boosts ) ) {
			$boosts = array();
		}
		$boosts[ (int) $term->term_id ] = $factor;
		update_option( 'shift64_woo_search_category_boosts', $boosts );

		$this->refresh_category_cache();

		wp_send_json_success(
			array(
				'message' => __( 'Boost saved.', 'shift64-woo-search' ),
				'rows'    => $this->get_category_boost_rows(),
			)
		);
	}

	/**
	 * AJAX handler — remove a per-category autocomplete boost.
	 */
	public function ajax_remove_category_boost() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.

		$boosts = get_option( 'shift64_woo_search_category_boosts', array() );
		if ( ! is_array( $boosts ) ) {
			$boosts = array();
		}
		unset( $boosts[ $term_id ] );
		update_option( 'shift64_woo_search_category_boosts', $boosts );

		$this->refresh_category_cache();

		wp_send_json_success(
			array(
				'message' => __( 'Boost removed.', 'shift64-woo-search' ),
				'rows'    => $this->get_category_boost_rows(),
			)
		);
	}

	/**
	 * Read the suggestion-exclusion option as a clean list of integer term IDs.
	 *
	 * @return array<int,int> Exclusion list (may be empty).
	 */
	private static function get_exclusion_ids() {
		$excluded = get_option( 'shift64_woo_search_category_suggest_exclude', array() );
		return is_array( $excluded ) ? array_map( 'intval', $excluded ) : array();
	}

	/**
	 * Resolve an admin-typed category token to a product_cat term ID.
	 *
	 * Resolution order mirrors the boost handler: numeric token → term ID,
	 * otherwise slug, then display name. Kept as a static helper (no HTTP
	 * concerns) so the id/slug/name resolution is unit-testable without the
	 * WP AJAX harness — the CI suite does not run the `ajax` test group.
	 *
	 * @param string $token Raw token (ID, slug or name).
	 * @return int Resolved term ID, or 0 when the token is empty/unresolvable.
	 */
	public static function resolve_category_term_id( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return 0;
		}
		$term = ctype_digit( $token )
			? get_term( (int) $token, 'product_cat' )
			: get_term_by( 'slug', $token, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			$term = get_term_by( 'name', $token, 'product_cat' );
		}
		return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
	}

	/**
	 * Add a term ID to the suggestion-exclusion list (deduped, idempotent).
	 *
	 * @param int $term_id Category term ID to hide.
	 * @return array<int,int> The updated exclusion list.
	 */
	public static function add_category_exclusion( $term_id ) {
		$excluded   = self::get_exclusion_ids();
		$excluded[] = (int) $term_id;
		$excluded   = array_values( array_unique( $excluded ) );
		update_option( 'shift64_woo_search_category_suggest_exclude', $excluded );
		return $excluded;
	}

	/**
	 * Remove a term ID from the suggestion-exclusion list (no-op if absent).
	 *
	 * @param int $term_id Category term ID to restore.
	 * @return array<int,int> The updated exclusion list.
	 */
	public static function remove_category_exclusion( $term_id ) {
		$excluded = array_values(
			array_diff( self::get_exclusion_ids(), array( (int) $term_id ) )
		);
		update_option( 'shift64_woo_search_category_suggest_exclude', $excluded );
		return $excluded;
	}

	/**
	 * AJAX handler — hide a category from autocomplete suggestions.
	 *
	 * Thin HTTP shell over resolve_category_term_id() + add_category_exclusion();
	 * both are unit-tested directly.
	 */
	public function ajax_add_category_exclude() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$token = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Category required.', 'shift64-woo-search' ) ) );
		}

		$term_id = self::resolve_category_term_id( $token );
		if ( 0 === $term_id ) {
			wp_send_json_error( array( 'message' => __( 'Category not found.', 'shift64-woo-search' ) ) );
		}

		self::add_category_exclusion( $term_id );
		$this->refresh_category_cache();

		wp_send_json_success(
			array(
				'message' => __( 'Category hidden.', 'shift64-woo-search' ),
				'rows'    => $this->get_category_exclude_rows(),
			)
		);
	}

	/**
	 * AJAX handler — un-hide a category, restoring it to the suggestion list.
	 */
	public function ajax_remove_category_exclude() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.

		self::remove_category_exclusion( $term_id );
		$this->refresh_category_cache();

		wp_send_json_success(
			array(
				'message' => __( 'Category restored.', 'shift64-woo-search' ),
				'rows'    => $this->get_category_exclude_rows(),
			)
		);
	}

	/**
	 * Batched rebuild: JS sends step + offset, PHP processes one chunk per request.
	 */
	public function ajax_rebuild_index() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		wp_raise_memory_limit( 'admin' );

		$step       = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : 'prepare';
		$offset     = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$batch_size = 50;

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			wp_send_json_error( array( 'message' => 'Redis unavailable.' ) );
		}

		if ( 'prepare' === $step ) {
			if ( Shift64_Woo_Search_Schema::index_exists( $redis ) ) {
				Shift64_Woo_Search_Schema::drop_index( $redis );
			}
			if ( ! Shift64_Woo_Search_Schema::create_index( $redis ) ) {
				wp_send_json_error( array( 'message' => 'Failed to create index.' ) );
			}
			Shift64_Woo_Search_Synonyms::sync_to_redis( $redis );
			Shift64_Woo_Search_Suggestions::sync_to_redis( $redis );
			Shift64_Woo_Search_Rebuild::cache_blobs( $redis );
			$plugin = Shift64_Woo_Search_Plugin::get_instance();
			$plugin->install_mu_plugin();
			$plugin->generate_mu_plugin_config();

			$wc_counts = wp_count_posts( 'product' );
			$total     = isset( $wc_counts->publish ) ? (int) $wc_counts->publish : 0;

			wp_send_json_success(
				array(
					'step'   => 'index',
					'offset' => 0,
					'total'  => $total,
				)
			);
		}

		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => $batch_size,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$indexer = new Shift64_Woo_Search_Indexer( $redis );
		$client  = $redis->get_client();
		$indexed = 0;

		if ( ! empty( $products ) && $client ) {
			try {
				$client->multi( Redis::PIPELINE );
				foreach ( $products as $product ) {
					$data = $indexer->build_product_data( $product );
					if ( $data ) {
						$client->hMSet( $redis->get_product_key( $product->get_id() ), array_map( 'strval', $data ) );
						++$indexed;
					}
				}
				$client->exec();
			} catch ( \RedisException $e ) {
				foreach ( $products as $product ) {
					if ( $indexer->index_product( $product->get_id() ) ) {
						++$indexed;
					}
				}
			}
		}

		$new_offset = $offset + $batch_size;
		$total      = isset( $_POST['total'] ) ? (int) $_POST['total'] : 0;

		if ( empty( $products ) || $new_offset >= $total ) {
			wp_send_json_success(
				array(
					'step'    => 'done',
					'indexed' => $offset + $indexed,
					'total'   => $total,
					'message' => sprintf( 'Rebuild complete. %d products indexed.', $offset + $indexed ),
				)
			);
		}

		wp_send_json_success(
			array(
				'step'    => 'index',
				'offset'  => $new_offset,
				'indexed' => $offset + $indexed,
				'total'   => $total,
			)
		);
	}

	/**
	 * AJAX handler for testing Redis connection.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$this->save_posted_settings();
		Shift64_Woo_Search_Redis::reset_instance();
		$redis = Shift64_Woo_Search_Redis::get_instance();

		if ( $redis->ping() ) {
			wp_send_json_success( array( 'message' => 'Connection successful.' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Connection failed: ' . $redis->get_last_error() ) );
		}
	}

	/**
	 * AJAX handler for regenerating the SHORTINIT config file.
	 */
	public function ajax_regen_config() {
		check_ajax_referer( 'shift64_woo_search_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$plugin = Shift64_Woo_Search_Plugin::get_instance();
		$plugin->install_mu_plugin();
		$plugin->generate_mu_plugin_config();
		wp_send_json_success( array( 'message' => 'SHORTINIT config regenerated.' ) );
	}

	// ── Helpers ────────────────────────────────────────────────

	/**
	 * Persist Redis connection settings from POST data. Called from nonce-verified AJAX handlers.
	 * Skips empty host/port to avoid overwriting CLI-configured values with blank form fields.
	 */
	private function save_posted_settings() {
		$fields = array( 'shift64_woo_search_redis_host', 'shift64_woo_search_redis_port', 'shift64_woo_search_redis_auth_enabled', 'shift64_woo_search_redis_username', 'shift64_woo_search_redis_password', 'shift64_woo_search_redis_db', 'shift64_woo_search_redis_prefix' );
		foreach ( $fields as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in calling AJAX handler.
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			// Don't overwrite host/port with empty values.
			if ( '' === $value && in_array( $key, array( 'shift64_woo_search_redis_host', 'shift64_woo_search_redis_port' ), true ) ) {
				continue;
			}
			update_option( $key, $value );
		}

		// Stored-state credential wipe is intentional here (unlike the payload-scoped
		// guard in Shift64_Woo_Search_Admin_Settings::persist()): the only caller is
		// ajax_test_connection(), whose Connection form always posts auth_enabled and
		// writes it above, so the stored value it reads back is this request's value.
		if ( 'no' === get_option( 'shift64_woo_search_redis_auth_enabled', 'no' ) ) {
			update_option( 'shift64_woo_search_redis_username', '' );
			update_option( 'shift64_woo_search_redis_password', '' );
		}
	}

	/**
	 * Build the search configuration array from stored options.
	 *
	 * @return array Search configuration.
	 */
	private function build_search_config() {
		return Shift64_Woo_Search_Settings::search_config();
	}
}
