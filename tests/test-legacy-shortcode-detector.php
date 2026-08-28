<?php
/**
 * Legacy shortcode detector tests.
 *
 * The detector's whole value is that it finds the tags a merchant would
 * otherwise discover as literal text on their storefront. Its whole risk is that
 * it reports the wrong thing — a tag that is not really there sends somebody
 * hunting through a post for nothing, and a missed one defeats the purpose.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Detection accuracy and reporting boundaries.
 */
class Shift64_Woo_Search_Legacy_Shortcode_Detector_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Shift64_Woo_Search_Legacy_Shortcodes::forget();
	}

	public function tear_down() {
		Shift64_Woo_Search_Legacy_Shortcodes::forget();

		parent::tear_down();
	}

	/**
	 * Create a post carrying the given content.
	 *
	 * @param string $content Post content.
	 * @param string $status  Post status.
	 * @return int Post ID.
	 */
	private function make_post( $content, $status = 'publish' ) {
		return self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => $status,
				'post_title'   => 'Detector fixture',
			)
		);
	}

	/**
	 * Each removed tag is detected on its own.
	 *
	 * @dataProvider removed_tag_provider
	 *
	 * @param string $tag Removed shortcode tag.
	 */
	public function test_each_removed_tag_is_detected( $tag ) {
		$id = $this->make_post( 'Before [' . $tag . '] after.' );

		$found = Shift64_Woo_Search_Legacy_Shortcodes::find( true );

		$this->assertSame( array( $id ), wp_list_pluck( $found, 'id' ) );
		$this->assertSame( array( $tag ), $found[0]['tags'] );
	}

	public function removed_tag_provider() {
		return array(
			'search'      => array( 'shift64_woo_search' ),
			'modal'       => array( 'shift64_woo_search_modal' ),
			'breadcrumbs' => array( 'shift64_woo_search_breadcrumbs' ),
		);
	}

	/**
	 * A longer tag is not reported as the shorter one it starts with.
	 *
	 * `shift64_woo_search` is a literal prefix of `shift64_woo_search_modal`, so
	 * a naive substring match reports both for a post that only carries the
	 * modal. The merchant then opens the post looking for a `[shift64_woo_search]`
	 * that is not in it.
	 */
	public function test_a_prefix_tag_is_not_reported_for_a_longer_one() {
		$this->make_post( 'Header: [shift64_woo_search_modal icon="alternative"]' );

		$found = Shift64_Woo_Search_Legacy_Shortcodes::find( true );

		$this->assertCount( 1, $found );
		$this->assertSame( array( 'shift64_woo_search_modal' ), $found[0]['tags'] );
	}

	/**
	 * A tag with attributes and a self-closing tag both match.
	 */
	public function test_tags_with_attributes_and_self_closing_forms_match() {
		$this->assertSame(
			array( 'shift64_woo_search' ),
			Shift64_Woo_Search_Legacy_Shortcodes::tags_in( '[shift64_woo_search placeholder="Find"]' )
		);
		$this->assertSame(
			array( 'shift64_woo_search' ),
			Shift64_Woo_Search_Legacy_Shortcodes::tags_in( '[shift64_woo_search /]' )
		);
	}

	/**
	 * Prose that merely mentions the plugin slug is not reported.
	 *
	 * A false positive here is worse than useless: it points somebody at a post
	 * that is already correct.
	 */
	public function test_prose_mentioning_the_slug_is_not_reported() {
		$this->make_post( 'We use the shift64_woo_search plugin, and the option shift64_woo_search_logic.' );

		$this->assertSame( array(), Shift64_Woo_Search_Legacy_Shortcodes::find( true ) );
	}

	/**
	 * A post carrying several tags reports all of them together.
	 */
	public function test_multiple_tags_in_one_post_are_reported_together() {
		$this->make_post( '[shift64_woo_search] and [shift64_woo_search_breadcrumbs]' );

		$found = Shift64_Woo_Search_Legacy_Shortcodes::find( true );

		$this->assertCount( 1, $found );
		$this->assertSame(
			array( 'shift64_woo_search', 'shift64_woo_search_breadcrumbs' ),
			$found[0]['tags']
		);
	}

	/**
	 * Unpublished content is reported too.
	 *
	 * A draft is exactly the case worth catching before it is published, and a
	 * scheduled post is one that will break the storefront on its own.
	 */
	public function test_drafts_and_scheduled_posts_are_reported() {
		$draft     = $this->make_post( '[shift64_woo_search]', 'draft' );
		$scheduled = $this->make_post( '[shift64_woo_search]', 'future' );

		$ids = wp_list_pluck( Shift64_Woo_Search_Legacy_Shortcodes::find( true ), 'id' );

		$this->assertContains( $draft, $ids );
		$this->assertContains( $scheduled, $ids );
	}

	/**
	 * Trashed content is not reported.
	 *
	 * It renders nowhere, so reporting it would be noise a merchant cannot act on.
	 */
	public function test_trashed_content_is_not_reported() {
		$this->make_post( '[shift64_woo_search]', 'trash' );

		$this->assertSame( array(), Shift64_Woo_Search_Legacy_Shortcodes::find( true ) );
	}

	/**
	 * The result is cached, and the cache can be dropped.
	 *
	 * The lookup is a `LIKE` over `post_content`. Running it on every admin page
	 * load of a large store would make the detector a performance problem in its
	 * own right, so the caching is part of the contract, not an optimization.
	 */
	public function test_the_scan_result_is_cached_until_forgotten() {
		$this->make_post( '[shift64_woo_search]' );
		$this->assertCount( 1, Shift64_Woo_Search_Legacy_Shortcodes::find( true ) );

		$this->make_post( '[shift64_woo_search_modal]' );
		$this->assertCount(
			1,
			Shift64_Woo_Search_Legacy_Shortcodes::find(),
			'A cached result must be served without rescanning.'
		);

		Shift64_Woo_Search_Legacy_Shortcodes::forget();
		$this->assertCount( 2, Shift64_Woo_Search_Legacy_Shortcodes::find() );
	}

	/**
	 * An empty result is cached too, so a clean site does not rescan every load.
	 */
	public function test_an_empty_result_is_cached() {
		$this->assertSame( array(), Shift64_Woo_Search_Legacy_Shortcodes::find( true ) );

		$this->assertIsArray(
			get_transient( Shift64_Woo_Search_Legacy_Shortcodes::TRANSIENT ),
			'A clean scan must still be cached, or every admin page load rescans.'
		);
	}

	/**
	 * Detecting a tag never renders it.
	 *
	 * The point of the release is that these tags are gone. A detector that
	 * quietly re-registered them to render "something" would put the classic
	 * frontend back.
	 */
	public function test_detection_does_not_register_or_render_the_tag() {
		$this->make_post( '[shift64_woo_search]' );
		Shift64_Woo_Search_Legacy_Shortcodes::find( true );

		foreach ( Shift64_Woo_Search_Legacy_Shortcodes::REMOVED_TAGS as $tag ) {
			$this->assertFalse( shortcode_exists( $tag ) );
		}

		$this->assertSame(
			'[shift64_woo_search]',
			do_shortcode( '[shift64_woo_search]' ),
			'A removed tag must stay literal text, never render.'
		);
	}
}
