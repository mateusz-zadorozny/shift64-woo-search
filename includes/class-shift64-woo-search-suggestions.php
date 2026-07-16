<?php
/**
 * Search suggestions management — stores in wp_options, syncs to Redis as JSON.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages admin-curated search suggestions for autocomplete dropdown.
 */
class Shift64_Woo_Search_Suggestions {

	private const OPTION_KEY = 'shift64_woo_search_suggestions';

	/**
	 * Get all suggestions.
	 *
	 * @return string[] Flat array of suggestion strings.
	 */
	public static function get_all() {
		$suggestions = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $suggestions ) ) {
			return array();
		}
		return array_values( array_filter( $suggestions, 'is_string' ) );
	}

	/**
	 * Add a suggestion.
	 *
	 * @param string $text Suggestion text.
	 * @return int|false Index of added suggestion, or false.
	 */
	public static function add( $text ) {
		$text = trim( $text );
		if ( '' === $text ) {
			return false;
		}

		$suggestions   = self::get_all();
		$suggestions[] = $text;
		update_option( self::OPTION_KEY, $suggestions );

		return count( $suggestions ) - 1;
	}

	/**
	 * Remove a suggestion by index.
	 *
	 * @param int $index Suggestion index.
	 * @return bool
	 */
	public static function remove( $index ) {
		$suggestions = self::get_all();
		if ( ! isset( $suggestions[ $index ] ) ) {
			return false;
		}

		array_splice( $suggestions, $index, 1 );
		update_option( self::OPTION_KEY, $suggestions );

		return true;
	}

	/**
	 * Update a suggestion.
	 *
	 * @param int    $index Suggestion index.
	 * @param string $text  New suggestion text.
	 * @return bool
	 */
	public static function update( $index, $text ) {
		$text = trim( $text );
		if ( '' === $text ) {
			return false;
		}

		$suggestions = self::get_all();
		if ( ! isset( $suggestions[ $index ] ) ) {
			return false;
		}

		$suggestions[ $index ] = $text;
		update_option( self::OPTION_KEY, $suggestions );

		return true;
	}

	/**
	 * Sync suggestions to Redis as JSON.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @return int Number of suggestions synced.
	 */
	public static function sync_to_redis( $redis ) {
		$suggestions = self::get_all();
		$key         = $redis->get_prefix() . ':suggestions';
		$redis->raw_command( 'SET', $key, wp_json_encode( $suggestions, JSON_UNESCAPED_UNICODE ) );

		return count( $suggestions );
	}

	/**
	 * Export all suggestions as text (one per line).
	 *
	 * @return string
	 */
	public static function export() {
		return implode( "\n", self::get_all() );
	}

	/**
	 * Import suggestions from text (one per line).
	 *
	 * @param string $text    Text with one suggestion per line.
	 * @param bool   $replace Whether to replace existing (true) or append (false).
	 * @return array { imported: int, skipped: int }
	 */
	public static function import( $text, $replace = true ) {
		$lines   = explode( "\n", $text );
		$items   = array();
		$skipped = 0;

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$items[] = $line;
		}

		if ( $replace ) {
			update_option( self::OPTION_KEY, $items );
		} else {
			$existing = self::get_all();
			update_option( self::OPTION_KEY, array_merge( $existing, $items ) );
		}

		return array(
			'imported' => count( $items ),
			'skipped'  => $skipped,
		);
	}
}
