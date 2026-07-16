<?php
/**
 * Synonym management — stores in wp_options, pushes to RediSearch via FT.SYNUPDATE.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synonym group management for RediSearch.
 */
class Shift64_Woo_Search_Synonyms {

	private const OPTION_KEY = 'shift64_woo_search_synonyms';

	/**
	 * Check whether a synonym group uses one-way (=>) direction.
	 *
	 * @param mixed $group Synonym group.
	 * @return bool
	 */
	public static function is_oneway( $group ) {
		return is_array( $group ) && isset( $group['from'] ) && isset( $group['to'] );
	}

	/**
	 * Format a synonym group for display in the admin UI.
	 *
	 * @param array $group Synonym group (bidirectional array or one-way from/to).
	 * @return string
	 */
	public static function format_group( $group ) {
		if ( self::is_oneway( $group ) ) {
			return implode( ', ', $group['from'] ) . ' => ' . implode( ', ', $group['to'] );
		}
		return implode( ', ', $group );
	}

	/**
	 * Get all synonym groups.
	 *
	 * @return array Array of synonym groups (flat arrays for bidirectional, from/to objects for one-way).
	 */
	public static function get_all() {
		$synonyms = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $synonyms ) ) {
			return array();
		}
		$result = array();
		foreach ( $synonyms as $group ) {
			if ( self::is_oneway( $group ) ) {
				$result[] = array(
					'from' => array_values( $group['from'] ),
					'to'   => array_values( $group['to'] ),
				);
			} elseif ( is_array( $group ) ) {
				$result[] = array_values( $group );
			}
		}
		return $result;
	}

	/**
	 * Add a synonym group.
	 *
	 * @param string $terms Comma-separated terms, or one-way with =>.
	 * @return int|false Group ID (index) or false.
	 */
	public static function add( $terms ) {
		$group = self::parse_group( $terms );
		if ( false === $group ) {
			return false;
		}

		$synonyms   = self::get_all();
		$synonyms[] = $group;
		update_option( self::OPTION_KEY, $synonyms );

		return count( $synonyms ) - 1;
	}

	/**
	 * Remove a synonym group by index.
	 *
	 * @param int $index Group index to remove.
	 * @return bool
	 */
	public static function remove( $index ) {
		$synonyms = self::get_all();
		if ( ! isset( $synonyms[ $index ] ) ) {
			return false;
		}

		array_splice( $synonyms, $index, 1 );
		update_option( self::OPTION_KEY, $synonyms );

		return true;
	}

	/**
	 * Update a synonym group.
	 *
	 * @param int    $index Group index to update.
	 * @param string $terms Comma-separated terms, or one-way with =>.
	 * @return bool
	 */
	public static function update( $index, $terms ) {
		$group = self::parse_group( $terms );
		if ( false === $group ) {
			return false;
		}

		$synonyms = self::get_all();
		if ( ! isset( $synonyms[ $index ] ) ) {
			return false;
		}

		$synonyms[ $index ] = $group;
		update_option( self::OPTION_KEY, $synonyms );

		return true;
	}

	/**
	 * Push all synonym groups to RediSearch.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @return int Number of groups pushed.
	 */
	public static function sync_to_redis( $redis ) {
		$synonyms   = self::get_all();
		$index_name = $redis->get_index_name();
		$synced     = 0;

		// Store groups as JSON for SHORTINIT query expansion.
		$key = $redis->get_prefix() . ':synonyms';
		$redis->raw_command( 'SET', $key, wp_json_encode( $synonyms, JSON_UNESCAPED_UNICODE ) );

		foreach ( $synonyms as $i => $group ) {
			// One-way groups are handled by manual expansion only; FT.SYNUPDATE has no directionality.
			if ( self::is_oneway( $group ) ) {
				continue;
			}
			if ( count( $group ) < 2 ) {
				continue;
			}

			$group_id = 'syn_' . $i;
			$args     = array_merge(
				array( 'FT.SYNUPDATE', $index_name, $group_id ),
				$group
			);

			$result = $redis->raw_command( ...$args );
			if ( $result !== false ) {
				++$synced;
			}
		}

		return $synced;
	}

	/**
	 * Export all synonym groups as a text file (one group per line).
	 *
	 * @return string Text content using comma / => notation.
	 */
	public static function export() {
		$synonyms = self::get_all();
		$lines    = array();
		foreach ( $synonyms as $group ) {
			$lines[] = self::format_group( $group );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Import synonym groups from text (one group per line).
	 *
	 * @param string $text   Text content with one synonym group per line.
	 * @param bool   $replace Whether to replace existing synonyms (true) or append (false).
	 * @return array { imported: int, skipped: int[] (1-based line numbers) }
	 */
	public static function import( $text, $replace = true ) {
		$lines   = explode( "\n", $text );
		$groups  = array();
		$skipped = array();

		foreach ( $lines as $i => $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$group = self::parse_group( $line );
			if ( false === $group ) {
				$skipped[] = $i + 1;
				continue;
			}
			$groups[] = $group;
		}

		if ( $replace ) {
			update_option( self::OPTION_KEY, $groups );
		} else {
			$existing = self::get_all();
			update_option( self::OPTION_KEY, array_merge( $existing, $groups ) );
		}

		return array(
			'imported' => count( $groups ),
			'skipped'  => $skipped,
		);
	}

	/**
	 * Parse a synonym input string into a group structure.
	 *
	 * Detects one-way arrow (=>) and returns the appropriate format:
	 * - Bidirectional: flat array of terms ["a", "b"]
	 * - One-way: associative array {"from": ["a"], "to": ["b"]}
	 *
	 * @param string $input Raw input (comma-separated, optionally with =>).
	 * @return array|false Parsed group or false on invalid input.
	 */
	public static function parse_group( $input ) {
		if ( false !== mb_strpos( $input, '=>' ) ) {
			$parts = explode( '=>', $input, 2 );
			$from  = self::parse_terms( $parts[0] );
			$to    = self::parse_terms( $parts[1] );
			if ( empty( $from ) || empty( $to ) ) {
				return false;
			}
			return array(
				'from' => $from,
				'to'   => $to,
			);
		}

		$terms = self::parse_terms( $input );
		if ( count( $terms ) < 2 ) {
			return false;
		}
		return $terms;
	}

	/**
	 * Parse comma-separated terms into a clean array.
	 *
	 * @param string $terms Comma-separated synonym terms.
	 * @return array
	 */
	private static function parse_terms( $terms ) {
		$parts = explode( ',', $terms );
		$clean = array();

		foreach ( $parts as $part ) {
			$part = mb_strtolower( trim( $part ) );
			if ( ! empty( $part ) ) {
				$clean[] = $part;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
