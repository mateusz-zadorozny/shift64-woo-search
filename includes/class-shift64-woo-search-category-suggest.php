<?php
/**
 * Shift64 Woo Search — category autocomplete suggestion ranking.
 *
 * Pure, WordPress-free ranking for the category section of the autocomplete
 * dropdown. Kept dependency-free so it can be loaded inside the SHORTINIT
 * endpoint (which cannot boot full WordPress) and unit-tested in isolation.
 *
 * The category list does NOT go through RediSearch or the product re-ranking
 * pipeline. The endpoint reads a precomputed JSON blob ({prefix}:categories),
 * substring-matches it, and ranks with a lexicographic tuple — most significant
 * first: pinned > relevance (exact/prefix/substring/fuzzy) > per-category boost >
 * product count. The cap to N is applied AFTER sorting, so the cap keeps the
 * best matches rather than whichever happen to appear first in stored order.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Category suggestion ranking.
 */
class Shift64_Woo_Search_Category_Suggest {

	/**
	 * Max suggestions returned to the dropdown.
	 */
	const LIMIT = 5;

	/**
	 * Strip Polish (and a few common Latin) diacritics for accent-insensitive
	 * matching. Mirrors Shift64_Woo_Search_Indexer::strip_diacritics(), duplicated
	 * here so this class stays loadable without the full WP indexer.
	 *
	 * @param string $text Raw text.
	 * @return string ASCII-folded text.
	 */
	public static function strip_diacritics( $text ) {
		static $map = array(
			'ą' => 'a',
			'ć' => 'c',
			'ę' => 'e',
			'ł' => 'l',
			'ń' => 'n',
			'ó' => 'o',
			'ś' => 's',
			'ź' => 'z',
			'ż' => 'z',
			'Ą' => 'A',
			'Ć' => 'C',
			'Ę' => 'E',
			'Ł' => 'L',
			'Ń' => 'N',
			'Ó' => 'O',
			'Ś' => 'S',
			'Ź' => 'Z',
			'Ż' => 'Z',
			'à' => 'a',
			'â' => 'a',
			'ä' => 'a',
			'é' => 'e',
			'è' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'ï' => 'i',
			'î' => 'i',
			'ô' => 'o',
			'ù' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'ÿ' => 'y',
			'ç' => 'c',
			'ñ' => 'n',
			'ß' => 'ss',
		);
		return strtr( (string) $text, $map );
	}

	/**
	 * Normalize a string for category matching: lowercase + diacritics-stripped.
	 *
	 * @param string $text Raw text.
	 * @return string Lowercased, ASCII-folded text.
	 */
	public static function normalize( $text ) {
		return self::strip_diacritics( mb_strtolower( (string) $text ) );
	}

	/**
	 * Parse the query→category pin rules textarea into structured rules.
	 *
	 * Format, one rule per line: `query|category` or `query|category|priority`.
	 * Lines starting with `#` are comments. `query` and `category` are stored
	 * normalized. `category` may be a category name or slug. Priority defaults
	 * to 1 (clamped to a minimum of 1); higher wins.
	 *
	 * @param string $raw Raw textarea content.
	 * @return array<int,array{query:string,category:string,priority:int}>
	 */
	public static function parse_pins( $raw ) {
		$rules = array();
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $rules;
		}
		$lines = preg_split( '/\R/u', $raw );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 2 || '' === $parts[0] || '' === $parts[1] ) {
				continue;
			}
			$priority = ( isset( $parts[2] ) && is_numeric( $parts[2] ) ) ? (int) $parts[2] : 1;
			if ( $priority < 1 ) {
				$priority = 1;
			}
			$rules[] = array(
				'query'    => self::normalize( $parts[0] ),
				'category' => self::normalize( $parts[1] ),
				'priority' => $priority,
			);
		}
		return $rules;
	}

	/**
	 * Test whether a cached category entry is a pin rule's target.
	 *
	 * Matches on normalized name, raw slug, or slug with dashes turned to
	 * spaces — so an admin can reference a category by display name or slug.
	 *
	 * @param array  $cat        One entry from the {prefix}:categories blob.
	 * @param string $pin_target Normalized category token from a pin rule.
	 * @return bool
	 */
	public static function is_pin_target( $cat, $pin_target ) {
		$name_ascii  = isset( $cat['name_ascii'] ) ? $cat['name_ascii'] : self::normalize( isset( $cat['name'] ) ? $cat['name'] : '' );
		$slug        = isset( $cat['slug'] ) ? strtolower( $cat['slug'] ) : '';
		$slug_spaced = str_replace( '-', ' ', $slug );
		return ( $name_ascii === $pin_target || $slug === $pin_target || $slug_spaced === $pin_target );
	}

	/**
	 * Split normalized text into searchable tokens.
	 *
	 * @param string $text Normalized text.
	 * @return array<int,string>
	 */
	private static function tokenize_ascii( $text ) {
		$tokens = preg_split( '/[^a-z0-9]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Test whether a query token fuzzily matches a category-name token.
	 *
	 * The fuzzy path is intentionally conservative: tokens shorter than four
	 * characters must match exactly/prefix-wise, while longer tokens may match
	 * with Levenshtein distance 1.
	 *
	 * @param string $query_token Query token.
	 * @param string $name_token  Category name token.
	 * @return bool
	 */
	private static function token_matches_fuzzy( $query_token, $name_token ) {
		if ( $query_token === $name_token ) {
			return true;
		}
		if ( 0 === strpos( $name_token, $query_token ) || 0 === strpos( $query_token, $name_token ) ) {
			return true;
		}
		if ( strlen( $query_token ) < 4 || strlen( $name_token ) < 4 ) {
			return false;
		}
		return levenshtein( $query_token, $name_token ) <= 1;
	}

	/**
	 * Score whether all query tokens match category-name tokens with typo tolerance.
	 *
	 * A fuzzy match on the first category-name token gets a stronger fuzzy tier
	 * than a later-token match, but both stay below normal substring relevance.
	 *
	 * @param string $query_ascii Normalized query.
	 * @param string $name_ascii  Normalized category name.
	 * @return int 0 for no match, 2 for first-token fuzzy match, 1 for later-token fuzzy match.
	 */
	private static function fuzzy_name_score( $query_ascii, $name_ascii ) {
		$query_tokens = self::tokenize_ascii( $query_ascii );
		$name_tokens  = self::tokenize_ascii( $name_ascii );
		if ( empty( $query_tokens ) || empty( $name_tokens ) ) {
			return 0;
		}

		foreach ( $query_tokens as $query_token ) {
			$matched = false;
			foreach ( $name_tokens as $name_token ) {
				if ( self::token_matches_fuzzy( $query_token, $name_token ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return 0;
			}
		}

		return self::token_matches_fuzzy( $query_tokens[0], $name_tokens[0] ) ? 2 : 1;
	}

	/**
	 * Rank category suggestions for a query.
	 *
	 * Scores every candidate, sorts, then caps to LIMIT — so the cap keeps the
	 * best matches, not whichever appear first in stored order. A pin can inject
	 * a category that does not textually match the query.
	 *
	 * @param array  $all_cats  Decoded {prefix}:categories blob (list of entries
	 *                          with name, name_ascii, slug, url, count, optional boost).
	 * @param string $query     Raw search query.
	 * @param string $pin_rules     Raw pin-rules textarea content.
	 * @param bool   $fuzzy_enabled Whether typo-tolerant category matching is enabled.
	 * @return array<int,array{name:string,url:string,count:int}> Up to LIMIT rows.
	 */
	public static function rank( $all_cats, $query, $pin_rules = '', $fuzzy_enabled = false ) {
		$out = array();
		if ( ! is_array( $all_cats ) || empty( $all_cats ) ) {
			return $out;
		}

		$q_lower = mb_strtolower( (string) $query );
		$q_ascii = self::strip_diacritics( $q_lower );

		// Active pins: a rule fires when the typed query and the rule query
		// share a prefix (either direction), so pins surface while still typing.
		$active_pins = array();
		foreach ( self::parse_pins( $pin_rules ) as $rule ) {
			if ( '' !== $q_ascii
				&& ( 0 === strpos( $rule['query'], $q_ascii ) || 0 === strpos( $q_ascii, $rule['query'] ) ) ) {
				$active_pins[] = $rule;
			}
		}

		$candidates = array();
		foreach ( $all_cats as $idx => $cat ) {
			if ( ! isset( $cat['name'] ) ) {
				continue;
			}
			$name_lower = mb_strtolower( $cat['name'] );
			$name_ascii = isset( $cat['name_ascii'] ) ? $cat['name_ascii'] : self::normalize( $cat['name'] );

			// Relevance tier from the strongest textual match on name or ASCII name.
			$relevance = 0;
			if ( $name_lower === $q_lower || $name_ascii === $q_ascii ) {
				$relevance = 5;
			} elseif ( 0 === mb_strpos( $name_lower, $q_lower ) || 0 === strpos( $name_ascii, $q_ascii ) ) {
				$relevance = 4;
			} elseif ( mb_strpos( $name_lower, $q_lower ) !== false || ( '' !== $q_ascii && strpos( $name_ascii, $q_ascii ) !== false ) ) {
				$relevance = 3;
			} elseif ( $fuzzy_enabled ) {
				$relevance = self::fuzzy_name_score( $q_ascii, $name_ascii );
			}

			// Pin priority (0 if no active pin targets this category).
			$pinned = 0;
			foreach ( $active_pins as $rule ) {
				if ( self::is_pin_target( $cat, $rule['category'] ) && $rule['priority'] > $pinned ) {
					$pinned = $rule['priority'];
				}
			}

			// Keep textual matches and pin-injected categories; skip the rest.
			if ( 0 === $relevance && 0 === $pinned ) {
				continue;
			}

			$candidates[] = array(
				'name'      => $cat['name'],
				'url'       => isset( $cat['url'] ) ? $cat['url'] : '',
				'count'     => isset( $cat['count'] ) ? (int) $cat['count'] : 0,
				'boost'     => isset( $cat['boost'] ) ? (float) $cat['boost'] : 1.0,
				'relevance' => $relevance,
				'pinned'    => $pinned,
				'order'     => $idx,
			);
		}

		// Lexicographic sort: pinned > relevance > boost > count, stable on stored order.
		usort(
			$candidates,
			function ( $a, $b ) {
				if ( $a['pinned'] !== $b['pinned'] ) {
					return $b['pinned'] - $a['pinned'];
				}
				if ( $a['relevance'] !== $b['relevance'] ) {
					return $b['relevance'] - $a['relevance'];
				}
				if ( $a['boost'] !== $b['boost'] ) {
					return ( $b['boost'] < $a['boost'] ) ? -1 : 1;
				}
				if ( $a['count'] !== $b['count'] ) {
					return $b['count'] - $a['count'];
				}
				return $a['order'] - $b['order'];
			}
		);

		// Cap AFTER sorting, and expose only the public fields.
		foreach ( array_slice( $candidates, 0, self::LIMIT ) as $cand ) {
			$out[] = array(
				'name'  => $cand['name'],
				'url'   => $cand['url'],
				'count' => $cand['count'],
			);
		}
		return $out;
	}
}
