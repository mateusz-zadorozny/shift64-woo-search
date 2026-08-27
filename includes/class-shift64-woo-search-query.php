<?php
/**
 * Search query builder and executor.
 *
 * Self-contained — works in SHORTINIT (no WP dependencies beyond $wpdb for stats).
 *
 * Supports two strategies:
 * - 'mixed': one query, every token matched as prefix OR fuzzy (default)
 * - 'strict_first': strict prefix → token reduction → per-token fuzzy →
 *   OR prefix → fuzzy, advancing whenever a pass fails to cover every term
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search query builder and executor.
 */
class Shift64_Woo_Search_Query {

	/**
	 * WooCommerce catalog visibility values stored in the Redis TAG field.
	 *
	 * @var string[]
	 */
	private const VISIBILITY_VALUES = array( 'visible', 'catalog', 'search', 'hidden' );

	/**
	 * Redis connection instance.
	 *
	 * @var Shift64_Woo_Search_Redis
	 */
	private $redis;

	/**
	 * Search configuration.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Synonym lookup map: term to group array.
	 *
	 * @var array|null
	 */
	private $synonym_map = null;

	/**
	 * Parsed category/tag boost rules.
	 *
	 * @var array|null
	 */
	private $category_boost_rules = null;

	/**
	 * Constructor.
	 *
	 * @param Shift64_Woo_Search_Redis $redis  Redis connection instance.
	 * @param array                    $config Search configuration overrides.
	 */
	public function __construct( $redis, $config = array() ) {
		$this->redis  = $redis;
		$this->config = array_merge(
			array(
				'min_query_length'              => 2,
				'autocomplete_limit'            => 7,
				'full_limit'                    => 20,
				'outofstock_mode'               => 'exclude',
				'outofstock_demote_factor'      => 0.3,
				'fuzzy_level'                   => 1,
				'logic'                         => 'AND',
				// Phase 4: Search strategy settings.
				'strategy'                      => 'mixed',
				'fallback_trigger'              => 'low_score',
				'fallback_score_threshold'      => 0.5,
				'fallback_fuzzy_level'          => 1,
				'token_reduction_enabled'       => true,
				'weak_tokens'                   => 'do,na,z,i,w,od,po,za,ze,we,o,u,a,e',
				'drop_trailing_weak_token_only' => true,
				'diacritics_normalization'      => true,
				'fuzzy_synonyms'                => false,
				'category_boost_rules'          => '',
			),
			$config
		);
	}

	/**
	 * Main search method.
	 *
	 * @param string   $query   User's search query.
	 * @param string   $mode    'autocomplete' or 'full'.
	 * @param int|null $limit   Override default limit.
	 * @param array    $filters Additional filters (category, etc.).
	 * @return array
	 */
	public function search( $query, $mode = 'autocomplete', $limit = null, $filters = array() ) {
		$start_time = microtime( true );

		$query             = $this->sanitize_query( $query );
		$visibility_policy = in_array( $mode, array( 'autocomplete', 'full' ), true ) ? 'search' : null;

		if ( mb_strlen( $query ) < $this->config['min_query_length'] ) {
			return $this->empty_response( $query, $mode, 0.0 );
		}

		// Defensive heal: WP Redis Object Cache flush silently drops RediSearch indexes
		// (FLUSHDB is instance-wide for RediSearch). The proactive on_object_cache_flush
		// hook handles most cases, but this is a per-request backstop in case the hook
		// missed (cron disabled, fatal in handler, etc.). Static cache → runs once per request.
		if ( class_exists( 'Shift64_Woo_Search_Schema' ) ) {
			Shift64_Woo_Search_Schema::ensure_index_healthy( $this->redis );
		}

		if ( null === $limit ) {
			$limit = ( 'autocomplete' === $mode ) ? $this->config['autocomplete_limit'] : $this->config['full_limit'];
		}
		$limit = max( 1, min( 200, (int) $limit ) );

		$stock_mode = $this->config['outofstock_mode'] ?? 'exclude';
		// Over-fetch from Redis so PHP re-ranking (title-start, category/tag boost,
		// SKU, promoted) has enough candidates. Without this, boosted results can
		// be missed before PHP gets a chance to re-rank them.
		$fetch_limit = ( 'autocomplete' === $mode )
			? max( $limit * 20, 300 )
			: max( $limit * 5, 100 );
		if ( $this->has_category_boost_rules() ) {
			$fetch_limit = min( 500, max( $fetch_limit, $limit * 20, 300 ) );
		}

		$strategy = $this->config['strategy'] ?? 'mixed';
		$terms    = $this->get_search_terms( $query );
		$or_logic = ( 'OR' === strtoupper( $this->config['logic'] ) );

		if ( 'mixed' === $strategy ) {
			// One query: every token matches as prefix OR fuzzy.
			$ft_query      = $this->build_hybrid_query( $terms, $filters, null, $visibility_policy );
			$results       = $this->execute_ft_search( $ft_query, $fetch_limit );
			$search_pass   = 'mixed';
			$debug_queries = array( 'mixed' => $ft_query );
			if ( $or_logic ) {
				// min_ratio 0: 'mixed' is single-pass, so a dropped row has no
				// later pass to catch it. Re-rank by term coverage, but keep a
				// product whose only match is in the description.
				$results = self::boost_term_match_count( $terms, $results, 0.0 );
			}
		} else {
			// Strict-first, fuzzy-fallback.
			$debug_queries = array();
			$search_pass   = 'strict';

			// Pass 1: Strict (prefix only).
			$ft_query                = $this->build_strict_query( $terms, $filters, null, $visibility_policy );
			$debug_queries['strict'] = $ft_query;
			$results                 = $this->execute_ft_search( $ft_query, $fetch_limit );
			if ( $or_logic ) {
				// OR retrieval alone ranks a two-of-three-token match above a
				// three-of-three one; term coverage has to drive the order.
				$results = self::boost_term_match_count( $terms, $results );
			}

			$resolved = ! $this->should_fallback( $results, $terms );

			// Pass 2: Token reduction (if enabled, multi-word, and fallback needed).
			if ( ! $resolved && ! empty( $this->config['token_reduction_enabled'] ) && count( $terms ) > 1 ) {
				$reduced = $this->reduce_tokens( $terms );
				if ( count( $reduced ) < count( $terms ) && ! empty( $reduced ) ) {
					$ft_query                       = $this->build_strict_query( $reduced, $filters, null, $visibility_policy );
					$debug_queries['token_reduced'] = $ft_query;
					$candidate                      = $this->execute_ft_search( $ft_query, $fetch_limit );
					if ( $or_logic ) {
						$candidate = self::boost_term_match_count( $reduced, $candidate );
					}
					if ( ! $this->should_fallback( $candidate, $reduced ) ) {
						$results     = $candidate;
						$search_pass = 'token_reduced';
						$resolved    = true;
					}
				}
			}

			// Pass 3: Per-token fuzzy. Each token matches as prefix OR fuzzy under
			// the configured logic, so a typo in one word of a multi-word query is
			// repaired without discarding the words the shopper spelled correctly.
			// The result set is a superset of pass 1, so accepting any hit here can
			// only add recall.
			if ( ! $resolved ) {
				$ft_query                     = $this->build_hybrid_query( $terms, $filters, $this->resolve_fuzzy_level(), $visibility_policy );
				$debug_queries['token_fuzzy'] = $ft_query;
				$candidate                    = $this->execute_ft_search( $ft_query, $fetch_limit );
				if ( ! empty( $candidate ) ) {
					$results     = $or_logic ? self::boost_term_match_count( $terms, $candidate, 0.0 ) : $candidate;
					$search_pass = 'token_fuzzy';
					$resolved    = true;
				}
			}

			// Pass 4: OR prefix fallback (relax AND → OR, only when logic is AND).
			if ( ! $resolved && ! $or_logic ) {
				$ft_query                   = $this->build_strict_query( $terms, $filters, 'OR', $visibility_policy );
				$debug_queries['or_prefix'] = $ft_query;
				$results                    = $this->execute_ft_search( $ft_query, $fetch_limit );
				$results                    = self::boost_term_match_count( $terms, $results );
				if ( ! $this->should_fallback( $results, $terms ) ) {
					$search_pass = 'or_prefix';
					$resolved    = true;
				}
			}

			// Pass 5: Fuzzy fallback — every token fuzzed, nothing left to relax.
			if ( ! $resolved ) {
				$ft_query               = $this->build_fuzzy_query( $terms, $filters, null, $visibility_policy );
				$debug_queries['fuzzy'] = $ft_query;
				$results                = $this->execute_ft_search( $ft_query, $fetch_limit );
				$search_pass            = 'fuzzy';
			}
		}

		// Filter out low-score garbage from fuzzy passes only. Prefix passes
		// are exact matches — see filter_low_scores(). The archive path
		// (Shift64_Woo_Search_Archive::execute_search) applies the same rule,
		// so both modes agree on which products match.
		if ( self::pass_is_fuzzy( $search_pass ) ) {
			$results = self::filter_low_scores( $results, (float) ( $this->config['fallback_score_threshold'] ?? 0.5 ) );
		}

		$elapsed = ( microtime( true ) - $start_time ) * 1000;

		if ( empty( $results ) ) {
			$response                  = $this->empty_response( $query, $mode, $elapsed );
			$response['search_pass']   = $search_pass;
			$response['debug_queries'] = $debug_queries;
			return $response;
		}

		// Preserve raw Redis scores before any PHP re-ranking.
		foreach ( $results as &$r ) {
			$r['_raw_score'] = $r['_score'];
		}
		unset( $r );

		$results = $this->rank_relevance_results( $query, $terms, $results, $stock_mode );

		// Trim to requested limit after re-ranking.
		$results = array_slice( $results, 0, $limit );

		if ( 'autocomplete' === $mode ) {
			$response = $this->format_autocomplete_results( $query, $results, $elapsed );
		} else {
			$response = $this->format_full_results( $query, $results, $elapsed );
		}

		$response['search_pass']   = $search_pass;
		$response['debug_queries'] = $debug_queries;

		return $response;
	}

	/**
	 * Apply the shared non-Redis relevance ranking chain.
	 *
	 * Term coverage is applied by each retrieval ladder before it decides
	 * whether to accept or replace a pass. This method owns the remaining
	 * ordering rules so search() and Product Collection cannot drift apart.
	 *
	 * @param string $query      Sanitized search query.
	 * @param array  $terms      Normalized search terms.
	 * @param array  $results    Parsed scored Redis results.
	 * @param string $stock_mode Out-of-stock handling mode.
	 * @param string $score_key  Score field to sort and modify.
	 * @return array
	 */
	public function rank_relevance_results( $query, $terms, $results, $stock_mode = null, $score_key = '_score' ) {
		if ( empty( $results ) ) {
			return $results;
		}

		if ( null === $stock_mode ) {
			$stock_mode = $this->config['outofstock_mode'] ?? 'exclude';
		}

		// Out-of-stock products do not deserve later boosts.
		if ( 'demote' === $stock_mode ) {
			$results = $this->demote_outofstock( $results, $score_key );
		}
		$results = $this->boost_title_start( $terms, $results, $score_key );
		// Category/tag boost runs BEFORE SKU reranking on purpose because
		// rerank_sku_matches() uses (max_score + 100) for an exact SKU match.
		$results = $this->boost_configured_categories( $query, $results, $score_key );
		$results = $this->rerank_sku_matches( $query, $results, $terms, $score_key );
		$results = $this->boost_promoted( $results, $score_key );

		return $results;
	}

	/**
	 * Run all search passes for a given query (used by Tuning tab).
	 *
	 * Returns results from every pass regardless of whether earlier passes had results.
	 *
	 * @param string   $query   User's search query.
	 * @param string   $mode    'autocomplete' or 'full'.
	 * @param int|null $limit   Override default limit.
	 * @param array    $filters Additional filters.
	 * @return array   Keyed by pass name: strict, token_reduced, token_fuzzy, or_prefix, fuzzy.
	 */
	public function search_all_passes( $query, $mode = 'autocomplete', $limit = null, $filters = array() ) {
		$query             = $this->sanitize_query( $query );
		$terms             = $this->get_search_terms( $query );
		$visibility_policy = in_array( $mode, array( 'autocomplete', 'full' ), true ) ? 'search' : null;

		if ( null === $limit ) {
			$limit = ( 'autocomplete' === $mode ) ? $this->config['autocomplete_limit'] : $this->config['full_limit'];
		}
		$limit = max( 1, min( 200, (int) $limit ) );

		$stock_mode  = $this->config['outofstock_mode'] ?? 'exclude';
		$fetch_limit = ( 'autocomplete' === $mode )
			? max( $limit * 20, 300 )
			: max( $limit * 5, 100 );
		if ( $this->has_category_boost_rules() ) {
			$fetch_limit = min( 500, max( $fetch_limit, $limit * 20, 300 ) );
		}

		$passes = array();

		// Strict pass.
		$passes['strict'] = $this->run_tuning_pass(
			$this->build_strict_query( $terms, $filters, null, $visibility_policy ),
			$terms,
			$query,
			$fetch_limit,
			$limit,
			$stock_mode
		);

		// Token reduced pass (only if multi-word).
		if ( count( $terms ) > 1 ) {
			$reduced = $this->reduce_tokens( $terms );
			if ( count( $reduced ) < count( $terms ) && ! empty( $reduced ) ) {
				$passes['token_reduced'] = $this->run_tuning_pass(
					$this->build_strict_query( $reduced, $filters, null, $visibility_policy ),
					$terms,
					$query,
					$fetch_limit,
					$limit,
					$stock_mode
				);
			}
		}

		// Per-token fuzzy pass — the shape the 'mixed' strategy runs on its own.
		$passes['token_fuzzy'] = $this->run_tuning_pass(
			$this->build_hybrid_query( $terms, $filters, $this->resolve_fuzzy_level(), $visibility_policy ),
			$terms,
			$query,
			$fetch_limit,
			$limit,
			$stock_mode
		);

		// OR prefix pass (only when logic is AND).
		if ( 'AND' === strtoupper( $this->config['logic'] ) ) {
			$passes['or_prefix'] = $this->run_tuning_pass(
				$this->build_strict_query( $terms, $filters, 'OR', $visibility_policy ),
				$terms,
				$query,
				$fetch_limit,
				$limit,
				$stock_mode,
				true
			);
		}

		// Fuzzy pass.
		$passes['fuzzy'] = $this->run_tuning_pass(
			$this->build_fuzzy_query( $terms, $filters, null, $visibility_policy ),
			$terms,
			$query,
			$fetch_limit,
			$limit,
			$stock_mode
		);

		return $passes;
	}

	/**
	 * Execute a single tuning pass: search, re-rank, format.
	 *
	 * @param string $ft_query    FT.SEARCH query string.
	 * @param array  $terms       Search terms.
	 * @param string $query       Original sanitized query string.
	 * @param int    $fetch_limit Redis fetch limit.
	 * @param int    $limit       Display limit.
	 * @param string $stock_mode  Out-of-stock handling mode.
	 * @param bool   $or_mode     Apply term-match-count boost/filter.
	 * @return array Pass data with ft_query, time_ms, count, results.
	 */
	private function run_tuning_pass( $ft_query, $terms, $query, $fetch_limit, $limit, $stock_mode, $or_mode = false ) {
		$start   = microtime( true );
		$results = $this->execute_ft_search( $ft_query, $fetch_limit );
		$elapsed = ( microtime( true ) - $start ) * 1000;

		if ( $or_mode ) {
			$results = self::boost_term_match_count( $terms, $results );
		}
		if ( 'demote' === $stock_mode ) {
			$results = $this->demote_outofstock( $results );
		}
		$results = $this->boost_title_start( $terms, $results );
		// Mirror search()'s ordering: category boost before SKU rerank so
		// max(scores) still includes any factor inflation; SKU exact match
		// then wins via max+100. Keep these two lines in this order.
		$results = $this->boost_configured_categories( $query, $results );
		$results = $this->rerank_sku_matches( $query, $results, $terms );
		$results = $this->boost_promoted( $results );
		$results = array_slice( $results, 0, $limit );

		return array(
			'ft_query' => $ft_query,
			'time_ms'  => round( $elapsed, 2 ),
			'count'    => count( $results ),
			'results'  => $this->format_pass_results( $results ),
		);
	}

	/**
	 * Sanitize user query — strip RediSearch-special chars.
	 *
	 * @param string $query Raw user query.
	 * @return string
	 */
	public function sanitize_query( $query ) {
		// Replace RediSearch operators and indexing punctuation with spaces.
		// Removing them outright would incorrectly join tokens: "T-Shirt"
		// would become "tshirt", while RediSearch indexes it as "t" + "shirt".
		// Underscores intentionally remain because RediSearch preserves them.
		$separators = ',.<>{}[]"\'`:;!@#$%^&*()-+=~\\/?|';
		$query      = preg_replace( '/[' . preg_quote( $separators, '/' ) . ']+/u', ' ', $query );
		// Collapse whitespace, trim.
		$query = preg_replace( '/\s+/', ' ', trim( $query ) );
		// Lowercase.
		$query = mb_strtolower( $query );
		// Max 200 chars.
		$query = mb_substr( $query, 0, 200 );

		return $query;
	}

	/**
	 * Split sanitized query into search terms (min 2 chars each).
	 *
	 * @param string $query Sanitized query.
	 * @return array
	 */
	public function get_search_terms( $query ) {
		$terms    = explode( ' ', $query );
		$filtered = array();
		foreach ( $terms as $term ) {
			$term = trim( $term );
			if ( mb_strlen( $term ) >= 2 ) {
				$filtered[] = $term;
			}
		}
		return $filtered;
	}

	/**
	 * Detect SKU-like pattern (letters + digits separated by space) and return concatenated form.
	 *
	 * Matches patterns like "djm 201", "DNE 120", "MPA 72" — common in B2B catalogs
	 * where users add a space between the letter prefix and numeric suffix of a SKU.
	 *
	 * @internal Public only for unit testing — not part of the plugin's public API.
	 *
	 * @param array $terms Search terms (already split and filtered).
	 * @return string|false Concatenated SKU candidate, or false if pattern not detected.
	 */
	public static function detect_sku_concatenation( $terms ) {
		if ( 2 !== count( $terms ) ) {
			return false;
		}

		// 2-4 ASCII letters followed by 1-4 digits.
		if ( preg_match( '/^[a-zA-Z]{2,4}$/', $terms[0] ) && preg_match( '/^[0-9]{1,4}$/', $terms[1] ) ) {
			return $terms[0] . $terms[1];
		}

		return false;
	}

	/**
	 * Diacritics to ASCII mapping.
	 *
	 * @var array
	 */
	private static $diacritics_map = array(
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

	/**
	 * Strip diacritics from text.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private function strip_diacritics( $text ) {
		return strtr( $text, self::$diacritics_map );
	}

	/**
	 * Strip diacritics without requiring an instance.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private static function strip_diacritics_static( $text ) {
		return strtr( $text, self::$diacritics_map );
	}

	/**
	 * Load synonym groups from Redis and build a lookup map.
	 */
	private function load_synonyms() {
		if ( null !== $this->synonym_map ) {
			return;
		}
		$this->synonym_map = array();

		$key  = $this->redis->get_prefix() . ':synonyms';
		$json = $this->redis->raw_command( 'GET', $key );
		if ( empty( $json ) ) {
			return;
		}

		$groups = json_decode( $json, true );
		if ( ! is_array( $groups ) ) {
			return;
		}

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			// One-way: a "from" phrase is REPLACED by its "to" alternatives — the
			// source term is not searched. "wózki => wózek" searches only wózek*,
			// which avoids both the source's own noise (e.g. a broad category the
			// plural token lives in) and the TFIDF-collapsing "(wózki*|wózek*)"
			// union. Matches Solr/Elasticsearch `=>` semantics.
			if ( isset( $group['from'] ) && isset( $group['to'] ) ) {
				foreach ( $group['from'] as $term ) {
					$key = $this->normalize_synonym_key( $term );
					if ( '' !== $key ) {
						$this->synonym_map[ $key ] = $group['to'];
					}
				}
				continue;
			}

			// Bidirectional: every term maps to the whole group.
			if ( count( $group ) < 2 ) {
				continue;
			}
			foreach ( $group as $term ) {
				$key = $this->normalize_synonym_key( $term );
				if ( '' !== $key ) {
					$this->synonym_map[ $key ] = $group;
				}
			}
		}
	}

	/**
	 * Normalize a synonym source phrase into a lookup key.
	 *
	 * The key MUST be tokenized identically to the user query (sanitize +
	 * get_search_terms), otherwise a source phrase can never match. In
	 * particular get_search_terms drops sub-2-char tokens, so a source like
	 * "kosz z pedałem" would key as "kosz z pedałem" while the query tokenizes
	 * to ['kosz','pedałem'] — the single-letter weak token "z" is stripped on
	 * the query side but not the key side, and the phrase never matches.
	 * Normalizing the key through the same pipeline keys it as "kosz pedałem",
	 * consistent with how the query is tokenized. Returns '' if nothing survives.
	 *
	 * @param string $phrase Raw synonym source phrase.
	 * @return string Space-joined normalized key, or '' if empty after filtering.
	 */
	private function normalize_synonym_key( $phrase ) {
		$terms = $this->get_search_terms( $this->sanitize_query( $phrase ) );
		return implode( ' ', $terms );
	}

	/**
	 * Expand search terms using synonym groups.
	 *
	 * A source phrase (single- or multi-word, greedily matched longest-first) is
	 * replaced by its synonym alternatives as a single alternatives element:
	 * one-way `=>` groups expand to their "to" side only, bidirectional groups to
	 * the whole group. Multi-word alternatives ("kosz pedałowy") are kept — the
	 * builders render them as grouped AND-prefix sub-queries via render_variant(),
	 * e.g. "(kosz* pedałowy*)", so the space no longer breaks FT.SEARCH syntax.
	 *
	 * @param array $terms Search terms.
	 * @return array Each element is either a string (plain term) or an array of strings (synonym alternatives, each possibly multi-word).
	 */
	private function expand_terms( $terms ) {
		$this->load_synonyms();
		if ( empty( $this->synonym_map ) ) {
			return $terms;
		}

		$result = array();
		$i      = 0;
		$count  = count( $terms );

		while ( $i < $count ) {
			$matched = false;

			// Try longest multi-word match first (max 4 words).
			$max_len = min( $count - $i, 4 );
			for ( $len = $max_len; $len >= 1; $len-- ) {
				$phrase = mb_strtolower( implode( ' ', array_slice( $terms, $i, $len ) ) );

				if ( isset( $this->synonym_map[ $phrase ] ) ) {
					// Keep the whole group, including multi-word alternatives —
					// the builders render each variant via render_variant().
					$result[] = $this->synonym_map[ $phrase ];
					$i       += $len;
					$matched  = true;
					break;
				}
			}

			if ( ! $matched ) {
				// Try fuzzy synonym lookup (prefix + Levenshtein on single terms).
				if ( ! empty( $this->config['fuzzy_synonyms'] ) ) {
					$fuzzy_key = $this->fuzzy_synonym_lookup( $terms[ $i ] );
					if ( null !== $fuzzy_key ) {
						$result[] = $this->synonym_map[ $fuzzy_key ];
						++$i;
						continue;
					}
				}
				$result[] = $terms[ $i ];
				++$i;
			}
		}

		return $result;
	}

	/**
	 * Render one synonym alternative (possibly a multi-word phrase) as a single
	 * FT.SEARCH sub-query.
	 *
	 * Single words are passed straight to $format. Multi-word phrases become a
	 * parenthesised AND of each word's rendering — "(kosz* pedałowy*)" — so the
	 * whole phrase acts as one atomic alternative inside the surrounding OR group,
	 * regardless of the query's global AND/OR logic. Words shorter than 2 chars
	 * are dropped; returns null when nothing usable remains.
	 *
	 * @param string   $variant Alternative term, may contain spaces.
	 * @param callable $format  Maps a single word to its FT token (e.g. adds '*').
	 * @return string|null
	 */
	private function render_variant( $variant, $format ) {
		$variant = trim( $variant );
		if ( '' === $variant ) {
			return null;
		}

		if ( false === mb_strpos( $variant, ' ' ) ) {
			return mb_strlen( $variant ) >= 2 ? call_user_func( $format, $variant ) : null;
		}

		$words = array();
		foreach ( preg_split( '/\s+/', $variant ) as $word ) {
			if ( mb_strlen( $word ) >= 2 ) {
				$words[] = call_user_func( $format, $word );
			}
		}

		if ( empty( $words ) ) {
			return null;
		}

		return '(' . implode( ' ', $words ) . ')';
	}

	/**
	 * Find the best fuzzy match for a term in the synonym map.
	 *
	 * Tries prefix matching first, then Levenshtein distance (diacritics-stripped).
	 *
	 * @param string $term Search term.
	 * @return string|null Matched synonym map key, or null.
	 */
	private function fuzzy_synonym_lookup( $term ) {
		$term_lower    = mb_strtolower( $term );
		$term_stripped = $this->strip_diacritics( $term_lower );
		$term_len      = mb_strlen( $term_stripped );

		if ( $term_len < 3 ) {
			return null;
		}

		$best_key  = null;
		$best_dist = PHP_INT_MAX;

		foreach ( $this->synonym_map as $key => $group ) {
			// Skip multi-word keys.
			if ( false !== mb_strpos( $key, ' ' ) ) {
				continue;
			}

			$key_stripped = $this->strip_diacritics( $key );
			$key_len      = mb_strlen( $key_stripped );

			// Prefix match: input is a prefix of the key (e.g. "dyspense" → "dyspenser").
			if ( $key_len > $term_len && 0 === mb_strpos( $key_stripped, $term_stripped ) ) {
				if ( null === $best_key || $key_len < mb_strlen( $this->strip_diacritics( $best_key ) ) ) {
					$best_key  = $key;
					$best_dist = 0;
				}
				continue;
			}

			// Levenshtein: only for similar-length terms.
			if ( abs( $key_len - $term_len ) > 2 ) {
				continue;
			}

			$dist     = levenshtein( $term_stripped, $key_stripped );
			$max_dist = ( $term_len <= 5 ) ? 1 : 2;

			if ( $dist <= $max_dist && $dist < $best_dist ) {
				$best_dist = $dist;
				$best_key  = $key;
			}
		}

		return $best_key;
	}

	/**
	 * Deduplicate a list of terms case-insensitively (first occurrence wins).
	 *
	 * This used to also append diacritics-stripped ASCII variants, OR-ed into
	 * the query as `(mydło*|mydlo*)`. That is gone on purpose: an explicit
	 * UNION node collapses RediSearch TFIDF scoring (IDF is computed
	 * differently than for a lone prefix), dropping scores ~5× and inverting
	 * the ranking — for "mydło" soaps fell below dispensers and the 0.5
	 * score threshold then wiped the result list. ASCII input still matches
	 * diacritic content via the indexed *_ascii fields, and the fuzzy pass
	 * catches stray ASCII-typo content for diacritic input.
	 *
	 * @param array $terms  Array of strings.
	 * @return array
	 */
	private function dedupe_terms( $terms ) {
		$result = array();
		$seen   = array();
		foreach ( $terms as $t ) {
			$lower = mb_strtolower( $t );
			if ( ! isset( $seen[ $lower ] ) ) {
				$result[]       = $t;
				$seen[ $lower ] = true;
			}
		}
		return $result;
	}

	/**
	 * Build a strict FT.SEARCH query (prefix only, no fuzzy).
	 *
	 * @param array                $terms             Search terms.
	 * @param array                $filters           Additional filters.
	 * @param string|null          $logic_override    Force 'AND'/'OR' logic for this query.
	 * @param string|string[]|null $visibility_policy Visibility context or explicit exclusions.
	 * @param array                $filter_operators   Per-filter `and`/`or` operators.
	 * @return string
	 */
	public function build_strict_query( $terms, $filters = array(), $logic_override = null, $visibility_policy = null, $filter_operators = array() ) {
		return $this->build_term_query(
			$terms,
			$filters,
			$logic_override,
			$visibility_policy,
			$filter_operators,
			function ( $word ) {
				return $word . '*';
			}
		);
	}

	/**
	 * Build an FT.SEARCH query from pre-split terms with a per-word renderer.
	 *
	 * The strict, fuzzy, and hybrid builders differ only in how a single word
	 * becomes a RediSearch fragment; everything else — synonym expansion,
	 * multi-word variants, SKU concatenation, filters — is shared.
	 *
	 * @param array                $terms             Search terms.
	 * @param array                $filters           Additional filters.
	 * @param string|null          $logic_override    Force 'AND'/'OR' logic for this query.
	 * @param string|string[]|null $visibility_policy Visibility context or explicit exclusions.
	 * @param array                $filter_operators  Per-filter `and`/`or` operators.
	 * @param callable             $render_word       Turns one word into a query fragment.
	 * @return string
	 */
	private function build_term_query( $terms, $filters, $logic_override, $visibility_policy, $filter_operators, $render_word ) {
		$logic    = null !== $logic_override ? strtoupper( $logic_override ) : strtoupper( $this->config['logic'] );
		$operator = ( 'AND' === $logic ) ? ' ' : '|';
		$expanded = $this->expand_terms( $terms );

		$term_queries = array();
		foreach ( $expanded as $item ) {
			if ( is_array( $item ) ) {
				$variants = $this->dedupe_terms( $item );
				$sub      = array();
				foreach ( $variants as $syn ) {
					$frag = $this->render_variant( $syn, $render_word );
					if ( null !== $frag ) {
						$sub[] = $frag;
					}
				}
				if ( ! empty( $sub ) ) {
					$term_queries[] = '(' . implode( '|', $sub ) . ')';
				}
			} elseif ( mb_strlen( $item ) >= 2 ) {
				$term_queries[] = call_user_func( $render_word, $item );
			}
		}

		$parts = array();
		if ( ! empty( $term_queries ) ) {
			$text_query = '(' . implode( $operator, $term_queries ) . ')';

			// SKU concatenation: "djm 201" → also search for "djm201" in @sku TAG and sku_text.
			$sku_concat = self::detect_sku_concatenation( $terms );
			if ( false !== $sku_concat ) {
				// Safe to interpolate without RediSearch escaping: detect_sku_concatenation
				// guarantees the value matches /^[a-zA-Z]{2,4}[0-9]{1,4}$/ — no special chars.
				$sku_lower = strtolower( $sku_concat );
				$parts[]   = '((' . $text_query . ')|(@sku:{' . $sku_lower . '})|(' . call_user_func( $render_word, $sku_lower ) . '))';
			} else {
				$parts[] = $text_query;
			}
		}

		$parts = array_merge( $parts, $this->build_filter_parts( $filters, $visibility_policy, $filter_operators ) );

		return implode( ' ', $parts );
	}

	/**
	 * Build a fuzzy FT.SEARCH query (fuzzy only, no prefix).
	 *
	 * @param array                $terms             Search terms.
	 * @param array                $filters           Additional filters.
	 * @param int|null             $level             Fuzzy level override.
	 * @param string|string[]|null $visibility_policy Visibility context or explicit exclusions.
	 * @param array                $filter_operators   Per-filter `and`/`or` operators.
	 * @return string
	 */
	public function build_fuzzy_query( $terms, $filters = array(), $level = null, $visibility_policy = null, $filter_operators = array() ) {
		$fuzz = str_repeat( '%', $this->resolve_fuzzy_level( $level ) );

		return $this->build_term_query(
			$terms,
			$filters,
			null,
			$visibility_policy,
			$filter_operators,
			function ( $word ) use ( $fuzz ) {
				return $fuzz . $word . $fuzz;
			}
		);
	}

	/**
	 * Build a hybrid FT.SEARCH query: every token matches as prefix OR fuzzy.
	 *
	 * This is the shape the 'mixed' strategy runs on its own and the shape
	 * 'strict_first' falls back to before it relaxes term logic. Short tokens
	 * stay prefix-only — see add_fuzzy() for why.
	 *
	 * @param array                $terms             Search terms.
	 * @param array                $filters           Additional filters.
	 * @param int|null             $level             Fuzzy level override.
	 * @param string|string[]|null $visibility_policy Visibility context or explicit exclusions.
	 * @param array                $filter_operators  Per-filter `and`/`or` operators.
	 * @return string
	 */
	public function build_hybrid_query( $terms, $filters = array(), $level = null, $visibility_policy = null, $filter_operators = array() ) {
		$level = ( null === $level )
			? max( 1, min( 3, (int) ( $this->config['fuzzy_level'] ?? 1 ) ) )
			: max( 1, min( 3, (int) $level ) );

		return $this->build_term_query(
			$terms,
			$filters,
			null,
			$visibility_policy,
			$filter_operators,
			function ( $word ) use ( $level ) {
				return $this->add_fuzzy( $word, $level );
			}
		);
	}

	/**
	 * Resolve the fuzzy level used by fallback passes.
	 *
	 * @param int|null $level Explicit override, or null to read the config.
	 * @return int
	 */
	private function resolve_fuzzy_level( $level = null ) {
		if ( null === $level ) {
			$level = $this->config['fallback_fuzzy_level'] ?? $this->config['fuzzy_level'] ?? 1;
		}

		return max( 1, min( 3, (int) $level ) );
	}

	/**
	 * Build the FT.SEARCH query string (legacy mixed mode).
	 *
	 * Superseded by build_hybrid_query(), which takes pre-split terms and
	 * supports filter operators and SKU concatenation. Kept for callers that
	 * still hand over a raw query string.
	 *
	 * @param string               $query             Sanitized query.
	 * @param array                $filters           Additional filters.
	 * @param string|string[]|null $visibility_policy Visibility context or explicit exclusions.
	 * @return string
	 */
	public function build_ft_query( $query, $filters = array(), $visibility_policy = null ) {
		$terms    = explode( ' ', $query );
		$operator = ( 'AND' === strtoupper( $this->config['logic'] ) ) ? ' ' : '|';

		$expanded     = $this->expand_terms( $terms );
		$term_queries = array();
		foreach ( $expanded as $item ) {
			if ( is_array( $item ) ) {
				$variants = $this->dedupe_terms( $item );
				$sub      = array();
				foreach ( $variants as $syn ) {
					$frag = $this->render_variant( $syn, array( $this, 'add_fuzzy' ) );
					if ( null !== $frag ) {
						$sub[] = $frag;
					}
				}
				if ( ! empty( $sub ) ) {
					$term_queries[] = '(' . implode( '|', $sub ) . ')';
				}
			} else {
				$item = trim( $item );
				if ( mb_strlen( $item ) < 2 ) {
					continue;
				}
				$term_queries[] = $this->add_fuzzy( $item );
			}
		}

		$parts = array();
		if ( ! empty( $term_queries ) ) {
			$parts[] = '(' . implode( $operator, $term_queries ) . ')';
		}

		$parts = array_merge( $parts, $this->build_filter_parts( $filters, $visibility_policy ) );

		return implode( ' ', $parts );
	}

	/**
	 * Resolve a closed visibility context or validated explicit exclusion set.
	 *
	 * A missing policy preserves the compatibility behavior used by callers
	 * outside product search, such as taxonomy archives. Explicit values are
	 * accepted only when every item is a known WooCommerce catalog visibility
	 * value.
	 *
	 * @param string|string[]|null $policy Named context or explicit exclusions.
	 * @return string[] Validated visibility values to exclude.
	 */
	public static function resolve_visibility_exclusions( $policy = null ) {
		if ( null === $policy || '' === $policy ) {
			return array( 'hidden' );
		}

		if ( 'search' === $policy ) {
			return array( 'hidden', 'catalog' );
		}

		if ( is_array( $policy ) && ! empty( $policy ) ) {
			$exclusions = array();
			foreach ( $policy as $value ) {
				if ( ! is_string( $value ) || ! in_array( $value, self::VISIBILITY_VALUES, true ) ) {
					self::log_invalid_visibility_policy();
					return array( 'hidden' );
				}
				if ( ! in_array( $value, $exclusions, true ) ) {
					$exclusions[] = $value;
				}
			}
			return $exclusions;
		}

		self::log_invalid_visibility_policy();
		return array( 'hidden' );
	}

	/**
	 * Record an invalid visibility policy only when WordPress debugging is on.
	 *
	 * The raw value is intentionally omitted so caller-controlled data cannot
	 * inject arbitrary content into the PHP error log.
	 */
	private static function log_invalid_visibility_policy() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Required debug-only compatibility notice.
			error_log( '[Shift64 Woo Search] Invalid visibility policy; using the hidden-only compatibility fallback.' );
		}
	}

	/**
	 * Build the filter parts shared by all query types.
	 *
	 * Supports static exclusions (stock, visibility, excluded flag) and dynamic
	 * filters (category, brand, per-attribute TAG fields for faceted search).
	 *
	 * @param array                $filters           Additional filters. Keys: 'category' (string|array),
	 *                                                'brand' (string|array), 'attr_{taxonomy}' (array of
	 *                                                term names).
	 * @param string|string[]|null $visibility_policy Visibility context or explicit exclusions.
	 * @param array                $filter_operators   Per-filter `and`/`or` operators.
	 * @return array
	 */
	public function build_filter_parts( $filters = array(), $visibility_policy = null, $filter_operators = array() ) {
		$parts = array();

		// Always exclude excluded products.
		$parts[] = '-@excluded:{yes}';

		// Filter: stock status.
		$stock_mode = $this->config['outofstock_mode'] ?? 'exclude';
		if ( 'exclude' === $stock_mode ) {
			$parts[] = '-@stock_status:{outofstock}';
		}

		// Filter: visibility.
		$visibility_exclusions = array_map(
			array( __CLASS__, 'escape_tag_value' ),
			self::resolve_visibility_exclusions( $visibility_policy )
		);
		$parts[]               = '-@visibility:{' . implode( '|', $visibility_exclusions ) . '}';

		// Filter: category (supports single string or array of names).
		if ( ! empty( $filters['category'] ) ) {
			$cat_values = (array) $filters['category'];
			$parts      = array_merge(
				$parts,
				$this->build_tag_filter_parts( 'categories', $cat_values, $filter_operators['category'] ?? 'or' )
			);
		}

		// Filter: brand (supports single string or array of names). The indexed
		// TAG value carries the ancestor chain, so filtering on a parent brand
		// also matches products assigned only to one of its sub-brands.
		if ( ! empty( $filters['brand'] ) ) {
			$brand_values = (array) $filters['brand'];
			$parts        = array_merge(
				$parts,
				$this->build_tag_filter_parts( 'brands', $brand_values, $filter_operators['brand'] ?? 'or' )
			);
		}

		// Filter: dynamic per-attribute TAG fields.
		$filter_attrs = ! empty( $this->config['filter_attributes'] )
			? $this->config['filter_attributes']
			: ( class_exists( 'Shift64_Woo_Search_Schema' ) ? Shift64_Woo_Search_Schema::get_filter_attributes() : array() );
		foreach ( $filter_attrs as $taxonomy ) {
			$filter_key = 'attr_' . $taxonomy;
			if ( ! empty( $filters[ $filter_key ] ) ) {
				// Escape hyphens in field name — DIALECT 2 treats '-' as negation.
				$escaped_field = str_replace( '-', '\\-', $filter_key );
				$parts         = array_merge(
					$parts,
					$this->build_tag_filter_parts(
						$escaped_field,
						(array) $filters[ $filter_key ],
						$filter_operators[ $filter_key ] ?? 'or'
					)
				);
			}
		}

		return $parts;
	}

	/**
	 * Build one OR clause or repeated AND clauses for a TAG field.
	 *
	 * @param string $field    Escaped Redis field name.
	 * @param array  $values   Validated term names.
	 * @param string $operator Canonical `and` or `or`.
	 * @return string[]
	 */
	private function build_tag_filter_parts( $field, array $values, $operator ) {
		$escaped = array_values( array_map( array( __CLASS__, 'escape_tag_value' ), $values ) );
		if ( 'and' === $operator ) {
			return array_map(
				static function ( $value ) use ( $field ) {
					return '@' . $field . ':{' . $value . '}';
				},
				$escaped
			);
		}
		return array( '@' . $field . ':{' . implode( '|', $escaped ) . '}' );
	}

	/**
	 * Execute a filter-only FT.SEARCH and return post IDs + total count.
	 *
	 * No text query, no relevance re-ranking — just "products matching these
	 * filters, paginated". Used by taxonomy archive interceptor where the
	 * archive scope (category, brand, etc.) drives the query instead of
	 * user-typed search terms.
	 *
	 * Scope and user filters are merged: both feed `build_filter_parts()`.
	 * The distinction exists so the renderer knows which filters are
	 * "user-active" (clickable pills with ×) vs "scope" (archive context).
	 *
	 * @param array                $scope_filters     Archive scope, e.g. ['category' => ['Shift64 Stella']].
	 * @param array                $user_filters      URL-selected filters, e.g. ['attr_pa_kolor' => ['bialy']].
	 * @param int                  $per_page          Results per page.
	 * @param int                  $paged             1-based page number.
	 * @param string               $sort_by           Optional SORTBY clause, e.g. 'price ASC'. Null = natural order.
	 * @param string|string[]|null $visibility_policy Visibility context or explicit exclusions.
	 * @param array                $filter_operators   Per-filter `and`/`or` operators.
	 * @return array{ids:int[],total:int,ok:bool} Result and command status.
	 */
	public function search_by_filters( $scope_filters, $user_filters, $per_page, $paged, $sort_by = null, $visibility_policy = null, $filter_operators = array() ) {
		$parts = array_merge(
			$this->build_filter_parts( (array) $scope_filters, $visibility_policy ),
			$this->build_filter_parts( (array) $user_filters, $visibility_policy, $filter_operators )
		);
		$parts = array_values( array_unique( $parts ) );

		// Dialect 2 accepts a query made only of negations, and rejects `*`
		// combined with anything else ("Syntax error at offset 2"). The wildcard
		// therefore stands in only for a query with no clauses at all — an
		// unfiltered archive, where every product matches.
		$ft_query = empty( $parts ) ? '*' : implode( ' ', $parts );

		$per_page = max( 1, (int) $per_page );
		$paged    = max( 1, (int) $paged );
		$offset   = ( $paged - 1 ) * $per_page;

		$index_name = $this->redis->get_index_name();

		if ( is_array( $sort_by ) && ! empty( $sort_by ) ) {
			return $this->execute_composite_catalog_query( $ft_query, $offset, $per_page, $sort_by );
		}

		$args = array( 'FT.SEARCH', $index_name, $ft_query, 'LIMIT', (string) $offset, (string) $per_page );
		if ( is_string( $sort_by ) && '' !== trim( $sort_by ) ) {
			$sort_parts = explode( ' ', trim( $sort_by ), 2 );
			$sort_field = $sort_parts[0];
			// Whitelist the field name shape to prevent Redis command injection.
			if ( preg_match( '/^[a-z_][a-z0-9_]*$/i', $sort_field ) ) {
				$args[] = 'SORTBY';
				$args[] = $sort_field;
				if ( isset( $sort_parts[1] ) ) {
					$args[] = strtoupper( $sort_parts[1] ) === 'DESC' ? 'DESC' : 'ASC';
				}
			}
		}
		$args[] = 'DIALECT';
		$args[] = '2';

		$raw = $this->redis->raw_command( ...$args );

		if ( false === $raw || ! is_array( $raw ) || empty( $raw ) ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'ok'    => false,
			);
		}

		$total = (int) array_shift( $raw );
		$ids   = array();

		// Response format (no WITHSCORES): pairs of [key, fields_array].
		$count = count( $raw );
		for ( $i = 0; $i < $count; $i += 2 ) {
			$key = $raw[ $i ] ?? null;
			if ( is_string( $key ) && preg_match( '/:(\d+)$/', $key, $m ) ) {
				$ids[] = (int) $m[1];
			}
		}

		return array(
			'ids'   => $ids,
			'total' => $total,
			'ok'    => true,
		);
	}

	/**
	 * Execute a paginated text query for a Product Collection.
	 *
	 * This is the rendering-neutral counterpart to the legacy archive
	 * interceptor. It returns only membership and total count, leaving
	 * WooCommerce to load and render products.
	 *
	 * @param string               $query Search text.
	 * @param array                $filters Validated Redis filters.
	 * @param int                  $per_page Products per page.
	 * @param int                  $paged Current page.
	 * @param string|array|null    $sort_by Optional Redis sort clause or composite fields array.
	 * @param string|string[]|null $visibility_policy Visibility context.
	 * @param array                $filter_operators Per-filter `and`/`or` operators.
	 * @return array{ids:int[],total:int,ok:bool}
	 */
	public function search_catalog( $query, array $filters, $per_page, $paged, $sort_by = null, $visibility_policy = 'search', $filter_operators = array() ) {
		$sanitized = $this->sanitize_query( $query );
		$terms     = $this->get_search_terms( $sanitized );
		if ( empty( $terms ) || mb_strlen( $sanitized ) < $this->config['min_query_length'] ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'ok'    => true,
			);
		}

		$per_page       = max( 1, (int) $per_page );
		$paged          = max( 1, (int) $paged );
		$offset         = ( $paged - 1 ) * $per_page;
		$strategy       = $this->config['strategy'] ?? 'mixed';
		$or_logic       = 'OR' === strtoupper( $this->config['logic'] ?? 'AND' );
		$relevance_mode = null === $sort_by;
		$fetch_limit    = max( $per_page * $paged * 3, 300 );
		if ( $this->has_category_boost_rules() ) {
			$fetch_limit = max( $fetch_limit, $per_page * $paged * 20 );
		}

		if ( 'mixed' === $strategy ) {
			$queries = array(
				array(
					'query'          => $this->build_hybrid_query( $terms, $filters, null, $visibility_policy, $filter_operators ),
					'coverage_terms' => $terms,
					'or_mode'        => $or_logic,
					'min_ratio'      => 0.0,
					'fuzzy'          => false,
				),
			);
		} else {
			$queries = array(
				array(
					'query'          => $this->build_strict_query( $terms, $filters, null, $visibility_policy, $filter_operators ),
					'coverage_terms' => $terms,
					'or_mode'        => $or_logic,
					'min_ratio'      => $or_logic ? 0.4 : 0.0,
					'fuzzy'          => false,
				),
			);

			if ( ! empty( $this->config['token_reduction_enabled'] ) && count( $terms ) > 1 ) {
				$reduced = $this->reduce_tokens( $terms );
				if ( ! empty( $reduced ) && count( $reduced ) < count( $terms ) ) {
					$queries[] = array(
						'query'          => $this->build_strict_query( $reduced, $filters, null, $visibility_policy, $filter_operators ),
						'coverage_terms' => $reduced,
						'or_mode'        => $or_logic,
						'min_ratio'      => $or_logic ? 0.4 : 0.0,
						'fuzzy'          => false,
					);
				}
			}
			$queries[] = array(
				'query'          => $this->build_hybrid_query( $terms, $filters, $this->resolve_fuzzy_level(), $visibility_policy, $filter_operators ),
				'coverage_terms' => $terms,
				'or_mode'        => $or_logic,
				'min_ratio'      => 0.0,
				'fuzzy'          => false,
			);
			if ( 'AND' === strtoupper( $this->config['logic'] ) ) {
				$queries[] = array(
					'query'          => $this->build_strict_query( $terms, $filters, 'OR', $visibility_policy, $filter_operators ),
					'coverage_terms' => $terms,
					'or_mode'        => true,
					'min_ratio'      => 0.4,
					'fuzzy'          => false,
				);
			}
			$queries[] = array(
				'query'          => $this->build_fuzzy_query( $terms, $filters, null, $visibility_policy, $filter_operators ),
				'coverage_terms' => $terms,
				'or_mode'        => false,
				'min_ratio'      => 0.0,
				'fuzzy'          => true,
			);
		}

		foreach ( $queries as $pass ) {
			$ft_query = $pass['query'];
			$result   = $relevance_mode
				? $this->execute_relevance_catalog_query(
					$ft_query,
					$fetch_limit,
					$sanitized,
					$terms,
					$pass['coverage_terms'],
					$pass['or_mode'],
					$pass['min_ratio'],
					$pass['fuzzy']
				)
				: ( is_array( $sort_by ) && ! empty( $sort_by )
					? $this->execute_composite_catalog_query( $ft_query, $offset, $per_page, $sort_by )
					: $this->execute_catalog_query( $ft_query, $offset, $per_page, $sort_by ) );
			if ( empty( $result['ok'] ) ) {
				return $result;
			}
			if ( $result['total'] > 0 ) {
				if ( $relevance_mode ) {
					$result['ids'] = array_slice( $result['ids'], $offset, $per_page );
				}
				return $result;
			}
		}

		return array(
			'ids'   => array(),
			'total' => 0,
			'ok'    => true,
		);
	}

	/**
	 * Execute one scored relevance pass for a Product Collection.
	 *
	 * Relevance must over-fetch and rank before pagination; otherwise a boosted
	 * product that Redis placed after the requested page can never be promoted.
	 *
	 * @param string $ft_query       RediSearch query.
	 * @param int    $limit           Candidate window size.
	 * @param string $query           Sanitized search query.
	 * @param array  $terms           Original search terms.
	 * @param array  $coverage_terms  Terms used for OR coverage ranking.
	 * @param bool   $or_mode         Apply OR term coverage ranking/filtering.
	 * @param float  $min_ratio       Minimum OR term coverage ratio.
	 * @param bool   $fuzzy            Whether this is the terminal fuzzy pass.
	 * @return array{ids:int[],total:int,ok:bool}
	 */
	private function execute_relevance_catalog_query( $ft_query, $limit, $query, $terms, $coverage_terms, $or_mode, $min_ratio, $fuzzy ) {
		$args = array(
			'FT.SEARCH',
			$this->redis->get_index_name(),
			$ft_query,
			'SCORER',
			'TFIDF',
			'LIMIT',
			'0',
			(string) $limit,
			'WITHSCORES',
			'RETURN',
			'13',
			'post_id',
			'title',
			'stock_status',
			'title_ascii',
			'sku_text',
			'categories_text',
			'brands_text',
			'attributes',
			'sku',
			'old_number',
			'categories',
			'tags',
			'promoted',
			'DIALECT',
			'2',
		);

		$raw = $this->redis->raw_command( ...$args );
		if ( false === $raw || ! is_array( $raw ) || empty( $raw ) ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'ok'    => false,
			);
		}

		$total   = max( 0, (int) ( $raw[0] ?? 0 ) );
		$results = $this->parse_ft_search_results( $raw );
		$fetched = count( $results );

		if ( $fuzzy ) {
			$results = self::filter_low_scores( $results, (float) ( $this->config['fallback_score_threshold'] ?? 0.5 ) );
		}
		if ( $or_mode ) {
			// Apply term coverage before title-start boosting so a complete
			// answer cannot be outranked merely because another title starts
			// with the query prefix.
			$results = self::boost_term_match_count( $coverage_terms, $results, $min_ratio );
		}

		$results = $this->rank_relevance_results( $query, $terms, $results );
		$dropped = $fetched - count( $results );
		if ( $dropped > 0 ) {
			$total = max( count( $results ), $total - $dropped );
		}

		$ids = array();
		foreach ( $results as $result ) {
			if ( isset( $result['post_id'] ) ) {
				$ids[] = (int) $result['post_id'];
			}
		}

		return array(
			'ids'   => $ids,
			'total' => $total,
			'ok'    => true,
		);
	}

	/**
	 * Execute one lightweight FT.SEARCH catalog pass.
	 *
	 * @param string      $ft_query RediSearch query.
	 * @param int         $offset Offset.
	 * @param int         $limit Page size.
	 * @param string|null $sort_by Sort clause.
	 * @return array{ids:int[],total:int,ok:bool}
	 */
	public function execute_catalog_query( $ft_query, $offset, $limit, $sort_by = null ) {
		$args = array( 'FT.SEARCH', $this->redis->get_index_name(), $ft_query );
		if ( is_string( $sort_by ) && preg_match( '/^([a-z_][a-z0-9_]*)\s+(ASC|DESC)$/i', trim( $sort_by ), $matches ) ) {
			$args[] = 'SORTBY';
			$args[] = $matches[1];
			$args[] = strtoupper( $matches[2] );
		}
		$args[] = 'LIMIT';
		$args[] = (string) $offset;
		$args[] = (string) $limit;
		$args[] = 'RETURN';
		$args[] = '1';
		$args[] = 'post_id';
		$args[] = 'DIALECT';
		$args[] = '2';

		$raw = $this->redis->raw_command( ...$args );
		if ( false === $raw || ! is_array( $raw ) || empty( $raw ) ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'ok'    => false,
			);
		}

		$total = max( 0, (int) array_shift( $raw ) );
		$ids   = array();
		$count = count( $raw );
		for ( $i = 0; $i < $count; $i += 2 ) {
			$fields = $raw[ $i + 1 ] ?? array();
			if ( is_array( $fields ) && isset( $fields[1] ) ) {
				$id = absint( $fields[1] );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return array(
			'ids'   => $ids,
			'total' => $total,
			'ok'    => true,
		);
	}

	/**
	 * Execute one composite FT.AGGREGATE catalog query.
	 *
	 * @param string               $ft_query    RediSearch query.
	 * @param int                  $offset      Offset.
	 * @param int                  $limit       Page size.
	 * @param array<string,string> $sort_fields Map of field => direction (e.g. ['menu_order' => 'ASC', 'title' => 'ASC']).
	 * @return array{ids:int[],total:int,ok:bool}
	 */
	public function execute_composite_catalog_query( $ft_query, $offset, $limit, array $sort_fields ) {
		$ft_query = '' === trim( $ft_query ) ? '*' : trim( $ft_query );

		$sortby_args = array();
		foreach ( $sort_fields as $field => $dir ) {
			$field_name    = '@' . ltrim( (string) $field, '@' );
			$sortby_args[] = $field_name;
			$sortby_args[] = strtoupper( (string) $dir ) === 'DESC' ? 'DESC' : 'ASC';
		}

		$args   = array(
			'FT.AGGREGATE',
			$this->redis->get_index_name(),
			$ft_query,
			'LOAD',
			'1',
			'@post_id',
			'SORTBY',
			(string) count( $sortby_args ),
		);
		$args   = array_merge( $args, $sortby_args );
		$args[] = 'LIMIT';
		$args[] = (string) $offset;
		$args[] = (string) $limit;
		// Same dialect as every FT.SEARCH the plugin issues. Under the default
		// dialect 1 the scope and exclusion clauses (`@categories:{…}`,
		// `-@visibility:{hidden}`) are not applied, so a category archive sorted
		// by menu_order would silently return the whole catalog.
		$args[] = 'DIALECT';
		$args[] = '2';

		$raw = $this->redis->raw_command( ...$args );
		if ( false === $raw || ! is_array( $raw ) || empty( $raw ) ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'ok'    => false,
			);
		}

		$total = max( 0, (int) array_shift( $raw ) );
		$ids   = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rc = count( $row );
			for ( $j = 0; $j < $rc - 1; $j += 2 ) {
				if ( 'post_id' === $row[ $j ] || '@post_id' === $row[ $j ] ) {
					$id = absint( $row[ $j + 1 ] );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
					break;
				}
			}
		}

		return array(
			'ids'   => $ids,
			'total' => $total,
			'ok'    => true,
		);
	}

	/**
	 * Fetch candidate product IDs from Redis without slicing or sorting.
	 *
	 * @param string $ft_query RediSearch query string.
	 * @param int    $limit    Maximum candidate IDs to fetch.
	 * @return array{ids:int[],total:int,ok:bool}
	 */
	public function fetch_candidate_ids( $ft_query, $limit ) {
		return $this->execute_catalog_query( $ft_query, 0, $limit, null );
	}

	/**
	 * Determine whether the current results should trigger a fallback to the next pass.
	 *
	 * 'no_results' — fallback only when results are empty.
	 * 'low_score'  — fallback when empty OR when no result covers every search
	 *                term. Term coverage replaced a raw-score threshold here:
	 *                RediSearch TFIDF is unbounded and routinely lands in the
	 *                tens, so comparing it against `fallback_score_threshold`
	 *                (0.5 by default) was true only for an empty result set,
	 *                which collapsed the ladder into 'no_results' and left a
	 *                typo in one word of a multi-word query uncorrectable.
	 *                `fallback_score_threshold` still gates fuzzy-pass output
	 *                through filter_low_scores().
	 *
	 * @param array $results Parsed FT.SEARCH results (with _score).
	 * @param array $terms   Search terms the pass was built from.
	 * @return bool
	 */
	private function should_fallback( $results, $terms = array() ) {
		if ( empty( $results ) ) {
			return true;
		}

		$trigger = $this->config['fallback_trigger'] ?? 'low_score';

		if ( 'no_results' === $trigger || empty( $terms ) ) {
			return false;
		}

		$needles = $this->term_coverage_needles( $terms );
		if ( empty( $needles ) ) {
			return false;
		}

		return self::best_term_coverage( $needles, $results ) < 1.0;
	}

	/**
	 * Literal forms that count as a match for each search term.
	 *
	 * One entry per term, each holding the term plus any synonym variant it
	 * expands to, so a result matched through a synonym still counts as
	 * covering the term the shopper typed.
	 *
	 * Public so the archive path can build the same needles and reach the same
	 * hand-over decision as the dropdown.
	 *
	 * @param array $terms Search terms.
	 * @return array<int,string[]>
	 */
	public function term_coverage_needles( $terms ) {
		$needles = array();

		foreach ( $this->expand_terms( $terms ) as $item ) {
			$variants = is_array( $item ) ? $item : array( $item );
			$accepted = array();

			foreach ( $variants as $variant ) {
				$variant = mb_strtolower( trim( (string) $variant ) );
				if ( mb_strlen( $variant ) >= 2 ) {
					$accepted[] = $variant;
				}
			}

			if ( ! empty( $accepted ) ) {
				$needles[] = array_values( array_unique( $accepted ) );
			}
		}

		return $needles;
	}

	/**
	 * Highest share of search terms any of the leading results actually contains.
	 *
	 * Only the head of the result set is inspected: a pass is judged by its
	 * best answer, and scanning every over-fetched candidate would cost more
	 * than the pass it guards.
	 *
	 * @param array<int,string[]> $needles Accepted literals per term, from term_coverage_needles().
	 * @param array               $results Parsed FT.SEARCH results.
	 * @param int                 $sample  How many leading results to inspect.
	 * @return float Coverage in the 0.0–1.0 range.
	 */
	public static function best_term_coverage( $needles, $results, $sample = 5 ) {
		if ( empty( $needles ) || empty( $results ) ) {
			return 0.0;
		}

		$total     = count( $needles );
		$best      = 0.0;
		$inspected = 0;

		foreach ( $results as $result ) {
			if ( $inspected >= $sample ) {
				break;
			}
			++$inspected;

			$text = self::searchable_text( $result );
			if ( '' === $text ) {
				continue;
			}

			$matched = 0;
			foreach ( $needles as $variants ) {
				foreach ( $variants as $variant ) {
					if ( false !== mb_strpos( $text, $variant ) ) {
						++$matched;
						break;
					}
				}
			}

			$coverage = (float) $matched / (float) $total;
			if ( $coverage > $best ) {
				$best = $coverage;
			}
			if ( $best >= 1.0 ) {
				break;
			}
		}

		return $best;
	}

	/**
	 * Concatenated, lowercased text of every indexed TEXT field on a result.
	 *
	 * Mirrors the schema's searchable fields so coverage and term-match
	 * ranking agree with what RediSearch could actually have matched on —
	 * a brand or category hit counts, not just the title.
	 *
	 * Scope 'identity' drops the free-text body fields. Coverage asks whether
	 * the engine could have matched a term at all, so it reads everything;
	 * ranking asks how strongly a product answers the query, and a term buried
	 * in a long description is weak evidence that would flatten the ordering.
	 *
	 * @param array  $result Parsed FT.SEARCH result row.
	 * @param string $scope  'all' for every TEXT field, 'identity' to skip descriptions.
	 * @return string
	 */
	private static function searchable_text( $result, $scope = 'all' ) {
		$fields = array( 'title', 'title_ascii', 'sku_text', 'categories_text', 'brands_text', 'attributes' );
		if ( 'all' === $scope ) {
			$fields[] = 'short_desc';
			$fields[] = 'description';
		}
		$parts = array();

		foreach ( $fields as $field ) {
			if ( isset( $result[ $field ] ) && is_string( $result[ $field ] ) && '' !== $result[ $field ] ) {
				$parts[] = $result[ $field ];
			}
		}

		return empty( $parts ) ? '' : mb_strtolower( implode( ' ', $parts ) );
	}

	/**
	 * Return the configured list of weak tokens.
	 *
	 * @return array
	 */
	public function get_weak_tokens() {
		$tokens_str = $this->config['weak_tokens'] ?? 'do,na,z,i,w,od,po,za,ze,we,o,u,a,e';
		return array_map( 'trim', explode( ',', $tokens_str ) );
	}

	/**
	 * Reduce search terms by dropping weak tokens.
	 *
	 * If 'drop_trailing_weak_token_only' is true, only trailing weak tokens are removed.
	 * Otherwise, all weak tokens are removed.
	 *
	 * @param array $terms Search terms.
	 * @return array Reduced terms (never empty — returns original if all tokens are weak).
	 */
	public function reduce_tokens( $terms ) {
		$weak          = $this->get_weak_tokens();
		$drop_trailing = ! empty( $this->config['drop_trailing_weak_token_only'] );

		if ( $drop_trailing ) {
			$reduced       = $terms;
			$reduced_count = count( $reduced );
			while ( $reduced_count > 1 ) {
				$last = end( $reduced );
				if ( in_array( mb_strtolower( $last ), $weak, true ) ) {
					array_pop( $reduced );
					--$reduced_count;
				} else {
					break;
				}
			}
			return $reduced;
		}

		// Drop all weak tokens.
		$reduced = array();
		foreach ( $terms as $term ) {
			if ( ! in_array( mb_strtolower( $term ), $weak, true ) ) {
				$reduced[] = $term;
			}
		}

		return ! empty( $reduced ) ? $reduced : $terms;
	}

	/**
	 * Execute a single FT.SEARCH query and return parsed results.
	 *
	 * @param string $ft_query    The FT.SEARCH query string.
	 * @param int    $fetch_limit Max results to fetch.
	 * @return array Parsed results (empty array on failure).
	 */
	private function execute_ft_search( $ft_query, $fetch_limit ) {
		$index_name = $this->redis->get_index_name();
		$args       = array( 'FT.SEARCH', $index_name, $ft_query, 'SCORER', 'TFIDF', 'LIMIT', '0', (string) $fetch_limit, 'WITHSCORES', 'DIALECT', '2' );

		$raw = $this->redis->raw_command( ...$args );

		if ( false === $raw || empty( $raw ) ) {
			return array();
		}

		return $this->parse_ft_search_results( $raw );
	}

	/**
	/**
	 * Add prefix + fuzzy matching to a search term (legacy mixed mode).
	 *
	 * Short terms (<=4 chars): prefix only — avoids noise from fuzzy on short strings.
	 * Longer terms (>4 chars): prefix + fuzzy — prefix catches incomplete words, fuzzy catches typos.
	 *
	 * @param string   $term  Search term.
	 * @param int|null $level Fuzzy level override; null reads `fuzzy_level`.
	 * @return string
	 */
	private function add_fuzzy( $term, $level = null ) {
		if ( mb_strlen( $term ) <= 4 ) {
			return $term . '*';
		}

		if ( null === $level ) {
			$level = $this->config['fuzzy_level'] ?? 1;
		}
		$fuzz = str_repeat( '%', max( 1, min( 3, (int) $level ) ) );
		return '(' . $term . '*|' . $fuzz . $term . $fuzz . ')';
	}

	/**
	 * Escape a value for use in a TAG filter.
	 *
	 * Every character outside letters, numbers, and underscore is escaped —
	 * RediSearch query syntax reserves most ASCII punctuation, and a partial
	 * list keeps regressing: `,` is the TAG alternation separator (bug #4,
	 * Polish decimals like "26,5"), and `&`/`;` broke every term name stored
	 * entity-encoded by WordPress ("Beauty &amp; Care" raised SEARCH_SYNTAX,
	 * turning the whole filtered query into a native fallback). Multibyte
	 * letters (Polish diacritics) are left untouched.
	 *
	 * @param string $value Tag value to escape.
	 * @return string
	 */
	public static function escape_tag_value( $value ) {
		$escaped = preg_replace( '/([^\p{L}\p{N}_])/u', '\\\\$1', (string) $value );
		if ( null === $escaped ) {
			// Invalid UTF-8 (legacy latin1 imports): /u refuses the subject.
			// Fall back to a byte-safe pass that escapes ASCII specials and
			// leaves high bytes untouched — never return null into a query.
			$escaped = preg_replace( '/([^A-Za-z0-9_\x80-\xFF])/', '\\\\$1', (string) $value );
		}
		return null === $escaped ? '' : $escaped;
	}

	/**
	 * Parse raw FT.SEARCH results (with WITHSCORES).
	 *
	 * @param array $raw Raw FT.SEARCH response.
	 * @return array
	 */
	private function parse_ft_search_results( $raw ) {
		$results = array();

		if ( ! is_array( $raw ) || count( $raw ) < 2 ) {
			return $results;
		}

		$i         = 1;
		$raw_count = count( $raw );

		while ( $i < $raw_count ) {
			$key        = $raw[ $i ] ?? null;
			$score      = $raw[ $i + 1 ] ?? 0;
			$fields_raw = $raw[ $i + 2 ] ?? array();

			$fields = array();
			if ( is_array( $fields_raw ) ) {
				$fields_raw_count = count( $fields_raw );
				for ( $j = 0; $j < $fields_raw_count - 1; $j += 2 ) {
					$fields[ $fields_raw[ $j ] ] = $fields_raw[ $j + 1 ];
				}
			}

			$fields['_key']   = $key;
			$fields['_score'] = (float) $score;
			$results[]        = $fields;

			$i += 3;
		}

		return $results;
	}

	/**
	 * Re-rank results: exact SKU matches go to the top.
	 *
	 * @param string     $query   User's search query.
	 * @param array      $results Search results.
	 * @param array|null $terms   Pre-split search terms. Pass null (default) to recompute via
	 *                            get_search_terms(); pass an explicit array (even empty) to use as-is.
	 * @param string     $score_key Score field to modify.
	 * @return array
	 */
	private function rerank_sku_matches( $query, $results, $terms = null, $score_key = '_score' ) {
		if ( empty( $results ) ) {
			return $results;
		}

		$query_upper = strtoupper( $query );

		// Also try concatenated form for SKU-like queries ("djm 201" → "DJM201").
		if ( null === $terms ) {
			$terms = $this->get_search_terms( $query );
		}
		$sku_concat = self::detect_sku_concatenation( $terms );

		// Build candidate needles: original query + optional concatenated SKU.
		$needles = array( $query_upper );
		if ( false !== $sku_concat ) {
			$needles[] = strtoupper( $sku_concat );
		}

		$max_score = 0;
		foreach ( $results as $r ) {
			if ( isset( $r[ $score_key ] ) && $r[ $score_key ] > $max_score ) {
				$max_score = $r[ $score_key ];
			}
		}

		foreach ( $results as &$r ) {
			$sku        = isset( $r['sku'] ) ? strtoupper( $r['sku'] ) : '';
			$old_number = isset( $r['old_number'] ) ? strtoupper( $r['old_number'] ) : '';

			$exact   = false;
			$partial = false;
			foreach ( $needles as $needle ) {
				if ( $sku === $needle || $old_number === $needle ) {
					$exact = true;
					break;
				}
				if ( ( '' !== $sku && false !== strpos( $sku, $needle ) )
					|| ( '' !== $old_number && false !== strpos( $old_number, $needle ) ) ) {
					$partial = true;
				}
			}

			if ( $exact ) {
				$r[ $score_key ] = $max_score + 100;
			} elseif ( $partial ) {
				$r[ $score_key ] = $max_score + 10;
			}
		}
		unset( $r );

		usort(
			$results,
			function ( $a, $b ) use ( $score_key ) {
				return $b[ $score_key ] <=> $a[ $score_key ];
			}
		);

		return $results;
	}

	/**
	 * Boost promoted products: multiply score by 1.5 and re-sort.
	 *
	 * @param array  $results   Search results.
	 * @param string $score_key Score field to modify.
	 * @return array
	 */
	private function boost_promoted( $results, $score_key = '_score' ) {
		if ( empty( $results ) ) {
			return $results;
		}

		$changed = false;
		foreach ( $results as &$r ) {
			$promoted = isset( $r['promoted'] ) ? $r['promoted'] : '';
			if ( 'yes' === $promoted || '1' === $promoted ) {
				$r[ $score_key ] = $r[ $score_key ] * 1.5;
				$changed         = true;
			}
		}
		unset( $r );

		if ( $changed ) {
			usort(
				$results,
				function ( $a, $b ) use ( $score_key ) {
					return $b[ $score_key ] <=> $a[ $score_key ];
				}
			);
		}

		return $results;
	}

	/**
	 * Apply configured category/tag boost rules from the current query config.
	 *
	 * @param string $query   Sanitized user query.
	 * @param array  $results   Search results.
	 * @param string $score_key Score field to modify.
	 * @return array
	 */
	private function boost_configured_categories( $query, $results, $score_key = '_score' ) {
		if ( empty( $results ) ) {
			return $results;
		}

		if ( ! $this->has_category_boost_rules() ) {
			return $results;
		}

		return self::apply_category_boost_rules( $query, $results, $this->category_boost_rules, $score_key );
	}

	/**
	 * Check whether any valid category/tag boost rules are configured.
	 *
	 * @return bool
	 */
	private function has_category_boost_rules() {
		if ( null === $this->category_boost_rules ) {
			$this->category_boost_rules = self::parse_category_boost_rules( $this->config['category_boost_rules'] ?? '' );
		}

		return ! empty( $this->category_boost_rules );
	}

	/**
	 * Parse category/tag boost rules.
	 *
	 * Supported formats:
	 * - category_or_tag|factor
	 * - query|category_or_tag|factor
	 *
	 * Lines starting with `#` are treated as comments and skipped.
	 *
	 * When a pre-parsed array is passed, each entry must already have
	 * `query`, `match`, `factor`, and `matchers` keys (in the shape this
	 * method emits). Malformed entries are dropped silently rather than
	 * trusted, so a caller can't accidentally bypass the factor clamp or
	 * the matcher normalization by handing in raw input.
	 *
	 * @param string|array $rules Raw rules text or already parsed rules.
	 * @return array
	 */
	public static function parse_category_boost_rules( $rules ) {
		if ( is_array( $rules ) ) {
			$validated = array();
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				if ( ! isset( $rule['query'], $rule['match'], $rule['factor'], $rule['matchers'] ) ) {
					continue;
				}
				if ( ! is_array( $rule['matchers'] ) ) {
					continue;
				}
				$validated[] = $rule;
			}
			return $validated;
		}

		$parsed = array();
		// `u` flag is required: without it `\R` matches raw byte 0x85 (NEL),
		// which is the second byte of UTF-8 `ą` (0xC4 0x85) — so a rule like
		// "Kremy pielęgnacyjne do rąk|200" gets split mid-character, leaving
		// `match` truncated to "k" and the factor lost.
		$lines = preg_split( '/\R/u', (string) $rules );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			$count = count( $parts );
			if ( 2 !== $count && 3 !== $count ) {
				continue;
			}

			$query    = 3 === $count ? $parts[0] : '';
			$category = 3 === $count ? $parts[1] : $parts[0];
			$factor   = 3 === $count ? $parts[2] : $parts[1];
			$factor   = (float) str_replace( ',', '.', $factor );
			$factor   = max( 1.0, min( 200.0, $factor ) );

			if ( '' === $category || $factor <= 1.0 ) {
				continue;
			}

			$parsed[] = array(
				'query'    => self::normalize_boost_rule_query( $query ),
				'match'    => $category,
				'factor'   => $factor,
				'matchers' => self::build_boost_match_keys( $category ),
			);
		}

		return $parsed;
	}

	/**
	 * Apply parsed category/tag boost rules to search results.
	 *
	 * @param string       $query     Sanitized user query.
	 * @param array        $results   Search results.
	 * @param string|array $rules     Raw rules text or parsed rules.
	 * @param string       $score_key Key name for the score field.
	 * @return array
	 */
	public static function apply_category_boost_rules( $query, $results, $rules, $score_key = '_score' ) {
		if ( empty( $results ) ) {
			return $results;
		}

		$rules = self::parse_category_boost_rules( $rules );
		if ( empty( $rules ) ) {
			return $results;
		}

		$normalized_query = self::normalize_boost_rule_query( $query );
		$changed          = false;

		foreach ( $results as &$r ) {
			$best_factor = 1.0;
			foreach ( $rules as $rule ) {
				if ( '' !== $rule['query'] && $rule['query'] !== $normalized_query ) {
					continue;
				}
				if ( ! self::result_matches_category_boost_rule( $r, $rule['matchers'] ) ) {
					continue;
				}

				$best_factor = max( $best_factor, $rule['factor'] );
			}

			if ( $best_factor > 1.0 ) {
				$r[ $score_key ] *= $best_factor;
				$changed          = true;
			}
		}
		unset( $r );

		if ( $changed ) {
			usort(
				$results,
				function ( $a, $b ) use ( $score_key ) {
					return $b[ $score_key ] <=> $a[ $score_key ];
				}
			);
		}

		return $results;
	}

	/**
	 * Check if a result belongs to a configured boosted category or tag.
	 *
	 * @param array $result   Search result.
	 * @param array $matchers Normalized matcher keys.
	 * @return bool
	 */
	private static function result_matches_category_boost_rule( $result, $matchers ) {
		if ( empty( $matchers ) ) {
			return false;
		}

		foreach ( array( 'categories', 'tags' ) as $field ) {
			if ( empty( $result[ $field ] ) ) {
				continue;
			}

			$values = explode( '|', (string) $result[ $field ] );
			foreach ( $values as $value ) {
				$value_keys = self::build_boost_match_keys( $value );
				if ( array_intersect( $matchers, $value_keys ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Normalize a query value from category boost rules.
	 *
	 * @param string $query Query text.
	 * @return string
	 */
	private static function normalize_boost_rule_query( $query ) {
		$query = mb_strtolower( trim( (string) $query ) );
		$query = self::strip_diacritics_static( $query );
		$query = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $query );
		return trim( preg_replace( '/\s+/u', ' ', $query ) );
	}

	/**
	 * Build comparable keys for category/tag names and simple slugs.
	 *
	 * @param string $value Category/tag label or slug.
	 * @return array
	 */
	private static function build_boost_match_keys( $value ) {
		$value = self::strip_diacritics_static( mb_strtolower( trim( (string) $value ) ) );
		if ( '' === $value ) {
			return array();
		}

		$spaced = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );
		$spaced = trim( preg_replace( '/\s+/u', ' ', $spaced ) );
		$slug   = preg_replace( '/[^\p{L}\p{N}]+/u', '-', $value );
		$slug   = trim( preg_replace( '/-+/u', '-', $slug ), '-' );

		return array_values( array_unique( array_filter( array( $spaced, $slug ) ) ) );
	}

	/**
	 * Boost products where the title starts with exact search word(s).
	 *
	 * Single-term queries get a modest boost only when the full word appears as
	 * title word 1 or 2. Multi-term queries get a stronger boost when the exact
	 * phrase starts at title word 1 or 2. Prefix-only matches like "papier" vs
	 * "papierowy" deliberately do not qualify.
	 *
	 * Public so the archive class can reuse the same logic (DRY).
	 *
	 * @param array  $terms     Search terms.
	 * @param array  $results   Search results (each must have 'title' and $score_key).
	 * @param string $score_key Key name for the score field (default '_score').
	 * @return array
	 */
	public static function boost_title_start( $terms, $results, $score_key = '_score' ) {
		if ( empty( $results ) || empty( $terms ) ) {
			return $results;
		}

		$boost_terms = self::normalize_title_boost_terms( $terms );
		if ( empty( $boost_terms ) ) {
			return $results;
		}

		$changed = false;
		foreach ( $results as &$r ) {
			$title = isset( $r['title'] ) ? $r['title'] : '';
			if ( empty( $title ) ) {
				continue;
			}

			$title_words = self::tokenize_title_boost_text( $title );
			if ( empty( $title_words ) ) {
				continue;
			}

			$multiplier = self::get_title_start_boost_multiplier( $boost_terms, $title_words );
			if ( $multiplier > 1.0 ) {
				$r[ $score_key ] *= $multiplier;
				$changed          = true;
			}
		}
		unset( $r );

		if ( $changed ) {
			usort(
				$results,
				function ( $a, $b ) use ( $score_key ) {
					return $b[ $score_key ] <=> $a[ $score_key ];
				}
			);
		}

		return $results;
	}

	/**
	 * Normalize query terms for exact title-start boost comparison.
	 *
	 * The >=2 filter intentionally lets short polish weak tokens through
	 * (e.g. "do", "na") so multi-term phrase boost can still match a
	 * full title prefix like "Papier do druku". Single-term boost has a
	 * stricter >=3 floor inside get_title_start_boost_multiplier() so a
	 * 2-letter solo query never triggers a ×2 boost on its own.
	 *
	 * @param array $terms Raw search terms.
	 * @return array
	 */
	private static function normalize_title_boost_terms( $terms ) {
		$normalized = array();
		foreach ( $terms as $term ) {
			foreach ( self::tokenize_title_boost_text( $term ) as $token ) {
				if ( mb_strlen( $token ) >= 2 ) {
					$normalized[] = $token;
				}
			}
		}
		return $normalized;
	}

	/**
	 * Tokenize text into lowercase alphanumeric words for title boost checks.
	 *
	 * @param string $text Input text.
	 * @return array
	 */
	private static function tokenize_title_boost_text( $text ) {
		$text = mb_strtolower( (string) $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Compute the title-start boost multiplier for normalized terms and title words.
	 *
	 * @param array $terms       Normalized query terms.
	 * @param array $title_words Normalized title words.
	 * @return float
	 */
	private static function get_title_start_boost_multiplier( $terms, $title_words ) {
		$term_count = count( $terms );
		if ( 1 === $term_count ) {
			if ( mb_strlen( $terms[0] ) < 3 ) {
				return 1.0;
			}
			return ( ( isset( $title_words[0] ) && $title_words[0] === $terms[0] )
				|| ( isset( $title_words[1] ) && $title_words[1] === $terms[0] ) )
				? 2.0
				: 1.0;
		}

		if ( self::title_words_match_at( $title_words, $terms, 0 )
			|| self::title_words_match_at( $title_words, $terms, 1 ) ) {
			return 3.0;
		}

		return 1.0;
	}

	/**
	 * Check whether a sequence of title words exactly matches the query terms.
	 *
	 * @param array $title_words Normalized title words.
	 * @param array $terms       Normalized query terms.
	 * @param int   $offset      Zero-based title word offset.
	 * @return bool
	 */
	private static function title_words_match_at( $title_words, $terms, $offset ) {
		$term_count = count( $terms );
		for ( $i = 0; $i < $term_count; $i++ ) {
			if ( ! isset( $title_words[ $offset + $i ] ) || $title_words[ $offset + $i ] !== $terms[ $i ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Boost results by term-match ratio and filter below minimum threshold.
	 *
	 * For OR queries that return many loose matches: count how many search terms
	 * appear in each result's title, boost score by (matched/total)^2, and drop
	 * results below min_ratio. Public+static so the archive class can reuse (DRY).
	 *
	 * @param array  $terms     Search terms (from get_search_terms).
	 * @param array  $results   Search results (each must have 'title' and $score_key).
	 * @param float  $min_ratio Minimum matched/total ratio to keep (default 0.4).
	 * @param string $score_key Key name for the score field (default '_score').
	 * @return array Filtered and re-sorted results.
	 */
	public static function boost_term_match_count( $terms, $results, $min_ratio = 0.4, $score_key = '_score' ) {
		if ( empty( $results ) || empty( $terms ) ) {
			return $results;
		}

		$total_terms = count( $terms );
		if ( $total_terms < 2 ) {
			return $results;
		}

		$min_matches = (int) ceil( $total_terms * $min_ratio );
		$filtered    = array();

		// Pre-compute lowercased terms once (avoids N×M mb_strtolower calls).
		$lower_terms = array();
		foreach ( $terms as $term ) {
			$low = mb_strtolower( $term );
			if ( mb_strlen( $low ) >= 2 ) {
				$lower_terms[] = $low;
			}
		}

		foreach ( $results as $r ) {
			$text = self::searchable_text( $r, 'identity' );
			if ( '' === $text ) {
				continue;
			}

			$matched = 0;
			foreach ( $lower_terms as $term_lower ) {
				if ( false !== mb_strpos( $text, $term_lower ) ) {
					++$matched;
				}
			}

			if ( $matched < $min_matches ) {
				continue;
			}

			$ratio           = $matched / $total_terms;
			$r[ $score_key ] = $r[ $score_key ] * ( $ratio * $ratio );
			$filtered[]      = $r;
		}

		usort(
			$filtered,
			function ( $a, $b ) use ( $score_key ) {
				return $b[ $score_key ] <=> $a[ $score_key ];
			}
		);

		return $filtered;
	}

	/**
	 * Whether a search pass produced fuzzy (approximate) matches.
	 *
	 * Only this pass is score-filtered. Prefix passes return exact lexical
	 * matches, whose TFIDF score reflects term rarity rather than match
	 * quality — filtering them would drop legitimate matches purely for
	 * being common. See filter_low_scores().
	 *
	 * `mixed` and `token_fuzzy` match every token as prefix OR fuzzy, so they
	 * carry exact prefix matches alongside approximate ones and fall under the
	 * same rule: filtering them re-opened #26 on the archive, where a common
	 * term ("series") scored under the threshold on an exact match and left the
	 * results page empty. Only `fuzzy`, where every token is fuzzed and nothing
	 * matched exactly, is approximate throughout.
	 *
	 * @param string $search_pass Pass name recorded by search().
	 * @return bool
	 */
	public static function pass_is_fuzzy( $search_pass ) {
		return 'fuzzy' === $search_pass;
	}

	/**
	 * Filter out results below the score threshold.
	 *
	 * Applies to fuzzy passes only. RediSearch TFIDF scores scale with term
	 * rarity, so a common-but-exact prefix match can score below the
	 * threshold while being a perfectly good result; suppressing it here
	 * made autocomplete disagree with the archive, which never filtered.
	 *
	 * @param array  $results   Search results.
	 * @param float  $threshold Minimum score to keep; <= 0 disables filtering.
	 * @param string $score_key Key holding the score.
	 * @return array
	 */
	public static function filter_low_scores( $results, $threshold, $score_key = '_score' ) {
		if ( empty( $results ) || $threshold <= 0 ) {
			return $results;
		}

		$filtered = array();
		foreach ( $results as $r ) {
			if ( isset( $r[ $score_key ] ) && $r[ $score_key ] >= $threshold ) {
				$filtered[] = $r;
			}
		}

		return $filtered;
	}

	/**
	 * Demote out-of-stock products by multiplying their score by a penalty factor.
	 *
	 * @param array  $results   Search results.
	 * @param string $score_key Score field to modify.
	 * @return array
	 */
	private function demote_outofstock( $results, $score_key = '_score' ) {
		if ( empty( $results ) ) {
			return $results;
		}

		$factor = isset( $this->config['outofstock_demote_factor'] )
			? (float) $this->config['outofstock_demote_factor']
			: 0.3;
		$factor = max( 0.0, min( 1.0, $factor ) );

		foreach ( $results as &$r ) {
			$stock = isset( $r['stock_status'] ) ? $r['stock_status'] : 'instock';
			if ( 'outofstock' === $stock ) {
				$r[ $score_key ] = $r[ $score_key ] * $factor;
			}
		}
		unset( $r );

		usort(
			$results,
			function ( $a, $b ) use ( $score_key ) {
				return $b[ $score_key ] <=> $a[ $score_key ];
			}
		);

		return $results;
	}

	/**
	 * Format top results for Tuning tab pass comparison.
	 *
	 * @param array $results Search results.
	 * @return array Top 5 results with id, title, sku, score.
	 */
	private function format_pass_results( $results ) {
		$formatted = array();
		foreach ( array_slice( $results, 0, 5 ) as $r ) {
			$formatted[] = array(
				'id'    => isset( $r['post_id'] ) ? (int) $r['post_id'] : 0,
				'title' => $r['title'] ?? '',
				'sku'   => $r['sku'] ?? '',
				'score' => $r['_score'] ?? 0,
			);
		}
		return $formatted;
	}

	/**
	 * Format results for autocomplete mode.
	 *
	 * @param string $query      User's search query.
	 * @param array  $results    Search results.
	 * @param float  $elapsed_ms Elapsed time in milliseconds.
	 * @return array
	 */
	private function format_autocomplete_results( $query, $results, $elapsed_ms ) {
		$formatted = array();

		foreach ( $results as $r ) {
			$formatted[] = array(
				'id'        => isset( $r['post_id'] ) ? (int) $r['post_id'] : 0,
				'title'     => $r['title'] ?? '',
				'sku'       => $r['sku'] ?? '',
				'image'     => $r['image_url'] ?? '',
				'url'       => $r['permalink'] ?? '',
				'category'  => $r['categories'] ?? '',
				'brand'     => $r['brands'] ?? '',
				'score'     => $r['_score'] ?? 0,
				'raw_score' => $r['_raw_score'] ?? $r['_score'] ?? 0,
			);
		}

		return array(
			'success' => true,
			'count'   => count( $formatted ),
			'query'   => $query,
			'time_ms' => round( $elapsed_ms, 2 ),
			'results' => $formatted,
		);
	}

	/**
	 * Format results for full search mode.
	 *
	 * @param string $query      User's search query.
	 * @param array  $results    Search results.
	 * @param float  $elapsed_ms Elapsed time in milliseconds.
	 * @return array
	 */
	private function format_full_results( $query, $results, $elapsed_ms ) {
		$post_ids = array();
		foreach ( $results as $r ) {
			if ( isset( $r['post_id'] ) ) {
				$post_ids[] = (int) $r['post_id'];
			}
		}

		$redirect_query = rawurlencode( $query );
		$ids_param      = implode( ',', $post_ids );

		return array(
			'success'  => true,
			'count'    => count( $post_ids ),
			'query'    => $query,
			'time_ms'  => round( $elapsed_ms, 2 ),
			'post_ids' => $post_ids,
			'redirect' => '/shop/?s=' . $redirect_query . '&post_type=product&orderby=relevance&shift64_woo_search_ids=' . $ids_param,
		);
	}

	/**
	 * Return an empty response.
	 *
	 * @param string $query      User's search query.
	 * @param string $mode       'autocomplete' or 'full'.
	 * @param float  $elapsed_ms Elapsed time in milliseconds.
	 * @return array
	 */
	private function empty_response( $query, $mode, $elapsed_ms ) {
		$response = array(
			'success' => true,
			'count'   => 0,
			'query'   => $query,
			'time_ms' => round( $elapsed_ms, 2 ),
		);

		if ( 'autocomplete' === $mode ) {
			$response['results'] = array();
		} else {
			$response['post_ids'] = array();
			$response['redirect'] = '';
		}

		return $response;
	}

	/**
	 * Execute FT.AGGREGATE to get facet counts for a given dimension.
	 *
	 * @param string $base_query    The FT query string (search terms + filters, excluding the target dimension).
	 * @param string $groupby_field The TAG field to aggregate on (e.g. 'attr_pa_kolor', 'categories').
	 * @return array Array of ['value' => string, 'count' => int], sorted by count DESC. Empty on failure.
	 */
	public function execute_ft_aggregate( $base_query, $groupby_field ) {
		$index_name = $this->redis->get_index_name();

		$args = array(
			'FT.AGGREGATE',
			$index_name,
			$base_query,
			'GROUPBY',
			'1',
			'@' . $groupby_field,
			'REDUCE',
			'COUNT',
			'0',
			'AS',
			'count',
			'SORTBY',
			'2',
			'@count',
			'DESC',
			'LIMIT',
			'0',
			'100',
			'DIALECT',
			'2',
		);

		$raw = $this->redis->raw_command( ...$args );

		if ( false === $raw || ! is_array( $raw ) || count( $raw ) < 2 ) {
			return array();
		}

		return $this->parse_ft_aggregate_results( $raw, $groupby_field );
	}

	/**
	 * Compute category facet counts from matched documents.
	 *
	 * We cannot use `FT.AGGREGATE GROUPBY @categories` here because RediSearch
	 * groups by the full stored TAG field payload (e.g. "Leaf|Parent") instead
	 * of producing one bucket per individual tag value. For category facets we
	 * need per-category counts, so fetch the matched docs' `categories` field
	 * and split the pipe-separated values in PHP.
	 *
	 * @param string $base_query FT query string (terms + filters, excluding category filter itself).
	 * @param int    $limit      Max matched documents to inspect.
	 * @return array Array of ['value' => string, 'count' => int], sorted by count DESC.
	 */
	public function execute_category_facet( $base_query, $limit = 10000 ) {
		return $this->execute_multi_value_tag_facet( $base_query, 'categories', $limit );
	}

	/**
	 * Compute brand facet counts from matched documents.
	 *
	 * Same constraint as categories: a product can carry several brands plus
	 * their ancestor chains in one pipe-separated TAG value, which rules out
	 * FT.AGGREGATE GROUPBY. Each brand is counted once per product.
	 *
	 * @param string $base_query FT query string (terms + filters, excluding the brand filter itself).
	 * @param int    $limit      Max matched documents to inspect.
	 * @return array Array of ['value' => string, 'count' => int], sorted by count DESC.
	 */
	public function execute_brand_facet( $base_query, $limit = 10000 ) {
		return $this->execute_multi_value_tag_facet( $base_query, 'brands', $limit );
	}

	/**
	 * Count individual values of a pipe-separated multi-value TAG field.
	 *
	 * Shared by the category and brand facets — see execute_category_facet()
	 * for why this cannot be an FT.AGGREGATE GROUPBY.
	 *
	 * @param string $base_query FT query string (terms + filters, excluding this dimension's own filter).
	 * @param string $field      Index field to read and split, e.g. 'categories' or 'brands'.
	 * @param int    $limit      Max matched documents to inspect.
	 * @return array Array of ['value' => string, 'count' => int], sorted by count DESC.
	 */
	private function execute_multi_value_tag_facet( $base_query, $field, $limit = 10000 ) {
		$index_name = $this->redis->get_index_name();
		$base_query = trim( $base_query );
		if ( '' === $base_query ) {
			// An entirely empty query needs the explicit match-all token. A
			// negative-only query is already valid RediSearch syntax; prefixing
			// it with `*` makes this deployment reject the query instead.
			$base_query = '*';
		}

		$args = array(
			'FT.SEARCH',
			$index_name,
			$base_query,
			'RETURN',
			'1',
			$field,
			'LIMIT',
			'0',
			(string) max( 1, (int) $limit ),
			'DIALECT',
			'2',
		);

		$raw = $this->redis->raw_command( ...$args );
		if ( false === $raw || ! is_array( $raw ) || count( $raw ) < 3 ) {
			return array();
		}

		$counts    = array();
		$raw_count = count( $raw );
		for ( $i = 1; $i < $raw_count; $i += 2 ) {
			$fields = $raw[ $i + 1 ] ?? null;
			if ( ! is_array( $fields ) ) {
				continue;
			}

			$raw_value = '';
			$fc        = count( $fields );
			for ( $j = 0; $j < $fc - 1; $j += 2 ) {
				if ( $field === $fields[ $j ] ) {
					$raw_value = (string) $fields[ $j + 1 ];
					break;
				}
			}

			if ( '' === $raw_value ) {
				continue;
			}

			$values = array_filter( array_map( 'trim', explode( '|', $raw_value ) ) );
			$values = array_unique( $values );
			foreach ( $values as $value ) {
				if ( ! isset( $counts[ $value ] ) ) {
					$counts[ $value ] = 0;
				}
				++$counts[ $value ];
			}
		}

		if ( empty( $counts ) ) {
			return array();
		}

		arsort( $counts, SORT_NUMERIC );

		$results = array();
		foreach ( $counts as $value => $count ) {
			$results[] = array(
				'value' => $value,
				'count' => $count,
			);
		}

		return $results;
	}

	/**
	 * Parse FT.AGGREGATE response (RESP2 format).
	 *
	 * RESP2 format: [total, [field1, val1, field2, val2, ...], [field1, val1, ...], ...]
	 *
	 * @param array  $raw           Raw response from rawCommand().
	 * @param string $groupby_field Field name to extract from each group.
	 * @return array Array of ['value' => string, 'count' => int].
	 */
	private function parse_ft_aggregate_results( $raw, $groupby_field ) {
		$results   = array();
		$raw_count = count( $raw );

		for ( $i = 1; $i < $raw_count; $i++ ) {
			$group = $raw[ $i ];
			if ( ! is_array( $group ) ) {
				continue;
			}

			$value = null;
			$count = 0;
			$gc    = count( $group );
			for ( $j = 0; $j < $gc - 1; $j += 2 ) {
				if ( $group[ $j ] === $groupby_field ) {
					$value = $group[ $j + 1 ];
				} elseif ( $group[ $j ] === 'count' ) {
					$count = (int) $group[ $j + 1 ];
				}
			}

			if ( null !== $value && '' !== $value ) {
				$results[] = array(
					'value' => $value,
					'count' => $count,
				);
			}
		}

		return $results;
	}

	/**
	 * Build the FT.AGGREGATE query for a facet dimension.
	 *
	 * Includes search terms + all active filters EXCEPT the given dimension,
	 * so users see all available values for the dimension they are filtering.
	 *
	 * @param array                $terms             Search terms.
	 * @param array                $filters           All active filters.
	 * @param string               $exclude_filter    Filter key to exclude (e.g. 'category', 'attr_pa_kolor').
	 * @param string|string[]|null $visibility_policy Visibility context or explicit exclusions.
	 * @param array                $filter_operators   Per-filter `and`/`or` operators.
	 * @param array                $scope_filters      Archive scope that is never excluded.
	 * @return string FT query string.
	 */
	public function build_facet_query( $terms, $filters, $exclude_filter, $visibility_policy = null, $filter_operators = array(), $scope_filters = array() ) {
		$expanded = $this->expand_terms( $terms );
		$logic    = strtoupper( $this->config['logic'] );
		$operator = ( 'AND' === $logic ) ? ' ' : '|';

		$term_queries = array();
		foreach ( $expanded as $item ) {
			if ( is_array( $item ) ) {
				$variants = $this->dedupe_terms( $item );
				$sub      = array();
				foreach ( $variants as $syn ) {
					$frag = $this->render_variant(
						$syn,
						function ( $word ) {
							return $word . '*';
						}
					);
					if ( null !== $frag ) {
						$sub[] = $frag;
					}
				}
				if ( ! empty( $sub ) ) {
					$term_queries[] = '(' . implode( '|', $sub ) . ')';
				}
			} elseif ( mb_strlen( $item ) >= 2 ) {
				$term_queries[] = $item . '*';
			}
		}

		$parts = array();
		if ( ! empty( $term_queries ) ) {
			$parts[] = '(' . implode( $operator, $term_queries ) . ')';
		}

		// Build filter parts excluding the target dimension.
		$filtered = $filters;
		unset( $filtered[ $exclude_filter ] );
		unset( $filter_operators[ $exclude_filter ] );
		$parts = array_merge(
			$parts,
			$this->build_filter_parts( $scope_filters, $visibility_policy ),
			$this->build_filter_parts( $filtered, $visibility_policy, $filter_operators )
		);
		$parts = array_values( array_unique( $parts ) );

		return implode( ' ', $parts );
	}
}
