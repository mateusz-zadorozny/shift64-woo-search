<?php
/**
 * Diagnostic helper for category facet debugging.
 *
 * Usage:
 *   wp --allow-root --path=/path/to/wp eval-file bin/diagnose-category-facets.php "dozownik" [limit]
 *
 * Outputs:
 * - search query + terms
 * - raw category facet payload from Redis aggregation
 * - top result IDs from Redis search
 * - for each result: real WP product_cat terms and raw indexed `categories` field
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$argv = array_map( 'sanitize_text_field', wp_unslash( $_SERVER['argv'] ?? array() ) );
$file = 'bin/diagnose-category-facets.php';
$pos  = array_search( $file, $argv, true );

if ( false === $pos ) {
	foreach ( $argv as $index => $arg ) {
		if ( str_ends_with( (string) $arg, '/bin/diagnose-category-facets.php' ) ) {
			$pos = $index;
			break;
		}
	}
}

$user_args = false === $pos ? array() : array_slice( $argv, $pos + 1 );
$query     = $user_args[0] ?? '';
$limit     = isset( $user_args[1] ) ? max( 1, (int) $user_args[1] ) : 12;

if ( '' === trim( $query ) ) {
	echo "Usage: wp --allow-root --path=/path/to/wp eval-file bin/diagnose-category-facets.php \"query\" [limit]\n";
	return;
}

$redis = Shift64_Woo_Search_Redis::get_instance();
if ( ! $redis->is_available() ) {
	echo "Redis is unavailable.\n";
	return;
}

$search_query = new Shift64_Woo_Search_Query( $redis );
$sanitized    = $search_query->sanitize_query( $query );
$terms        = $search_query->get_search_terms( $sanitized );
$facet_data   = Shift64_Woo_Search_Facets::compute( $search_query, array(), array(), $terms );
$search_data  = $search_query->search( $query, 'full', $limit, array() );
$post_ids     = $search_data['post_ids'] ?? array();

$report = array(
	'query'               => $query,
	'sanitized_query'     => $sanitized,
	'terms'               => $terms,
	'result_count'        => $search_data['count'] ?? 0,
	'search_pass'         => $search_data['search_pass'] ?? '',
	'debug_queries'       => $search_data['debug_queries'] ?? array(),
	'raw_category_facets' => $facet_data['categories'] ?? array(),
	'products'            => array(),
);

foreach ( $post_ids as $post_id ) {
	$terms         = get_the_terms( $post_id, 'product_cat' );
	$wp_categories = array();
	if ( $terms && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$wp_categories[] = array(
				'term_id' => (int) $term->term_id,
				'name'    => $term->name,
				'slug'    => $term->slug,
				'parent'  => (int) $term->parent,
			);
		}
	}

	$indexed_categories = $redis->raw_command(
		'HGET',
		$redis->get_product_key( $post_id ),
		'categories'
	);

	$indexed_categories_text = $redis->raw_command(
		'HGET',
		$redis->get_product_key( $post_id ),
		'categories_text'
	);

	$report['products'][] = array(
		'post_id'                 => (int) $post_id,
		'title'                   => get_the_title( $post_id ),
		'wp_product_cat_terms'    => $wp_categories,
		'indexed_categories_raw'  => $indexed_categories,
		'indexed_categories_list' => empty( $indexed_categories ) ? array() : explode( '|', $indexed_categories ),
		'indexed_categories_text' => $indexed_categories_text,
	);
}

echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
