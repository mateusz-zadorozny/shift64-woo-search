<?php
/**
 * Finds content still containing the removed classic-theme shortcodes.
 *
 * When a shortcode tag stops being registered, WordPress does not warn anybody:
 * it simply prints the tag as literal text wherever it appears. A merchant who
 * upgrades with `[shift64_woo_search]` still sitting in a widget or a page gets
 * that bracketed string on their storefront and no clue where it came from.
 *
 * So for one release the plugin looks for those occurrences and reports them in
 * WP Admin. Three properties matter, and each is a deliberate constraint:
 *
 * 1. **It reports, it never renders.** Re-registering the tags to render
 *    "something" would resurrect the classic frontend this release removed.
 * 2. **It is admin-only.** No storefront request performs this scan, and no
 *    shopper ever sees its output.
 * 3. **It is cheap.** The lookup is a `LIKE` over `post_content`, which is a
 *    scan, so the result is cached and refreshed at most twice a day — a
 *    detector that slowed every admin page load would be a worse problem than
 *    the one it reports.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locates removed shortcode tags left behind in post content.
 */
class Shift64_Woo_Search_Legacy_Shortcodes {

	/**
	 * Shortcode tags this release removed.
	 */
	const REMOVED_TAGS = array(
		'shift64_woo_search',
		'shift64_woo_search_modal',
		'shift64_woo_search_breadcrumbs',
	);

	/**
	 * Cache key for the scan result.
	 */
	const TRANSIENT = 'shift64_woo_search_legacy_shortcode_scan';

	/**
	 * How long a scan result stays good.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Most occurrences to report.
	 *
	 * A merchant with hundreds does not need hundreds of links; they need to
	 * know it is widespread and to start working through them.
	 */
	const REPORT_LIMIT = 20;

	/**
	 * Find posts whose content still contains a removed shortcode tag.
	 *
	 * @param bool $refresh Bypass the cache and rescan.
	 * @return array<int,array{id:int,title:string,tags:string[]}> Occurrences,
	 *         newest first, capped at REPORT_LIMIT.
	 */
	public static function find( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$found = self::scan();

		set_transient( self::TRANSIENT, $found, self::CACHE_TTL );

		return $found;
	}

	/**
	 * Drop the cached scan result.
	 *
	 * Called after the plugin updates, so the first admin page load of a new
	 * version reports against current content rather than a pre-upgrade scan.
	 */
	public static function forget() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Run the content scan.
	 *
	 * `shift64_woo_search` is a prefix of the other two tags, so one `LIKE` finds
	 * every candidate row and a precise regex then decides which tags each row
	 * actually carries. Without that second pass a post containing only
	 * `[shift64_woo_search_modal]` would be reported as carrying
	 * `[shift64_woo_search]` as well, and the merchant would go looking for a tag
	 * that is not there. The tag must be followed by a space, a slash or the
	 * closing bracket, so prose mentioning the plugin slug never matches.
	 *
	 * @return array<int,array{id:int,title:string,tags:string[]}>
	 */
	private static function scan() {
		global $wpdb;

		// The status list and the placeholder list are both written out
		// literally. Building either one dynamically is correct at runtime but
		// unverifiable statically, and a `$wpdb->prepare()` call that the
		// tooling cannot check is exactly the kind of query that later grows an
		// interpolated value nobody notices.
		$sql = $wpdb->prepare(
			"SELECT ID, post_title, post_content FROM {$wpdb->posts}
			 WHERE post_status IN ( %s, %s, %s, %s, %s )
			   AND post_content LIKE %s
			 ORDER BY post_modified DESC
			 LIMIT %d",
			'publish',
			'draft',
			'pending',
			'private',
			'future',
			'%' . $wpdb->esc_like( '[shift64_woo_search' ) . '%',
			self::REPORT_LIMIT
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- content scan with no core API; find() caches the result in a transient.
		$rows = $wpdb->get_results( $sql );

		if ( empty( $rows ) ) {
			return array();
		}

		$found = array();

		foreach ( $rows as $row ) {
			$tags = self::tags_in( (string) $row->post_content );

			if ( empty( $tags ) ) {
				continue;
			}

			$found[] = array(
				'id'    => (int) $row->ID,
				'title' => (string) $row->post_title,
				'tags'  => $tags,
			);
		}

		return $found;
	}

	/**
	 * Which removed tags a piece of content actually contains.
	 *
	 * @param string $content Post content.
	 * @return string[] Removed tags present, in declaration order.
	 */
	public static function tags_in( $content ) {
		$tags = array();

		foreach ( self::REMOVED_TAGS as $tag ) {
			if ( preg_match( '/\[' . preg_quote( $tag, '/' ) . '(?=[\s\]\/])/', $content ) ) {
				$tags[] = $tag;
			}
		}

		return $tags;
	}
}
