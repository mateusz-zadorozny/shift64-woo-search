<?php
/**
 * Shift64 Woo Search — SHORTINIT endpoint.
 *
 * Ultra-fast search endpoint that bootstraps minimal WordPress for maximum performance.
 * Loaded directly via URL: /wp-content/mu-plugins/shift64-woo-search/endpoint.php?q=...&mode=autocomplete
 *
 * @package Shift64_Woo_Search
 */

// SHORTINIT = true tells WordPress to load only the essentials ($wpdb, constants).
define( 'SHORTINIT', true );

// Find wp-load.php.
$wp_load = dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
    http_response_code( 500 );
    echo json_encode( array( 'success' => false, 'error' => 'WordPress not found.' ) );
    exit;
}
require_once $wp_load;

// Load auto-generated config (Redis credentials, plugin path).
$config_file = __DIR__ . '/config.php';
if ( file_exists( $config_file ) ) {
    require_once $config_file;
}

// Validate that plugin path is configured.
if ( ! defined( 'SHIFT64_WOO_SEARCH_PLUGIN_PATH' ) ) {
    http_response_code( 500 );
    echo json_encode( array( 'success' => false, 'error' => 'Plugin not configured. Activate Shift64 Woo Search plugin first.' ) );
    exit;
}

// Load self-contained classes.
require_once SHIFT64_WOO_SEARCH_PLUGIN_PATH . 'includes/class-shift64-woo-search-redis.php';
require_once SHIFT64_WOO_SEARCH_PLUGIN_PATH . 'includes/class-shift64-woo-search-query.php';
// Schema needed for Shift64_Woo_Search_Schema::ensure_index_healthy() in the lazy-heal path.
require_once SHIFT64_WOO_SEARCH_PLUGIN_PATH . 'includes/class-shift64-woo-search-schema.php';
// Pure category-suggestion ranking (pins + boost + relevance), unit-tested standalone.
require_once SHIFT64_WOO_SEARCH_PLUGIN_PATH . 'includes/class-shift64-woo-search-category-suggest.php';

// Set JSON headers.
header( 'Content-Type: application/json; charset=utf-8' );
header( 'X-Robots-Tag: noindex, nofollow' );
header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );

// Allow CORS from same origin.
if ( defined( 'WP_HOME' ) ) {
    header( 'Access-Control-Allow-Origin: ' . WP_HOME );
}

$start_time = microtime( true );

// Parse and validate input.
$query = isset( $_GET['q'] ) ? trim( $_GET['q'] ) : '';
$mode  = isset( $_GET['mode'] ) ? trim( $_GET['mode'] ) : 'autocomplete';
$limit = isset( $_GET['limit'] ) ? (int) $_GET['limit'] : null;

if ( ! in_array( $mode, array( 'autocomplete', 'full', 'suggestions' ), true ) ) {
    $mode = 'autocomplete';
}

// Suggestions mode: return random suggestions without requiring a query.
if ( 'suggestions' === $mode ) {
    $redis = Shift64_Woo_Search_Redis::get_instance();
    $suggestions = array();
    if ( $redis->is_available() ) {
        $sug_key = $redis->get_prefix() . ':suggestions';
        $sug_raw = $redis->raw_command( 'GET', $sug_key );
        $all_sug = $sug_raw ? json_decode( $sug_raw, true ) : array();
        if ( is_array( $all_sug ) && ! empty( $all_sug ) ) {
            shuffle( $all_sug );
            $suggestions = array_slice( $all_sug, 0, 3 );
        }
    }
    echo json_encode( array(
        'success'     => true,
        'suggestions' => $suggestions,
        'categories'  => array(),
        'results'     => array(),
        'count'       => 0,
        'query'       => '',
        'time_ms'     => round( ( microtime( true ) - $start_time ) * 1000, 2 ),
    ), JSON_UNESCAPED_UNICODE );
    exit;
}

if ( empty( $query ) || mb_strlen( $query ) < 2 ) {
    echo json_encode( array(
        'success'     => true,
        'count'       => 0,
        'query'       => $query,
        'time_ms'     => 0,
        'suggestions' => array(),
        'categories'  => array(),
        'results'     => array(),
    ) );
    exit;
}

// Connect to Redis.
$redis = Shift64_Woo_Search_Redis::get_instance();

if ( ! $redis->is_available() ) {
    // Fallback: return empty results with redirect to WP search.
    $fallback_query = urlencode( $query );
    echo json_encode( array(
        'success'  => false,
        'count'    => 0,
        'query'    => $query,
        'time_ms'  => 0,
        'results'  => array(),
        'fallback' => '/?s=' . $fallback_query . '&post_type=product',
    ) );
    exit;
}

// Rate limiting — Redis INCR + EXPIRE per IP, 1-second window.
$rate_limit = defined( 'SHIFT64_WOO_SEARCH_RATE_LIMIT' ) ? (int) SHIFT64_WOO_SEARCH_RATE_LIMIT : 30;
if ( $rate_limit > 0 && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
    $rl_ip  = $_SERVER['REMOTE_ADDR'];
    $rl_key = $redis->get_prefix() . ':rl:' . md5( $rl_ip );
    $count  = $redis->raw_command( 'INCR', $rl_key );
    if ( 1 === (int) $count ) {
        $redis->raw_command( 'EXPIRE', $rl_key, '1' );
    }
    if ( (int) $count > $rate_limit ) {
        http_response_code( 429 );
        header( 'Retry-After: 1' );
        echo json_encode( array( 'success' => false, 'error' => 'Too many requests.' ) );
        exit;
    }
}

// Build search config from constants or defaults.
$config = array(
    'min_query_length'         => defined( 'SHIFT64_WOO_SEARCH_MIN_QUERY' ) ? (int) SHIFT64_WOO_SEARCH_MIN_QUERY : 2,
    'autocomplete_limit'       => defined( 'SHIFT64_WOO_SEARCH_AUTOCOMPLETE_LIMIT' ) ? (int) SHIFT64_WOO_SEARCH_AUTOCOMPLETE_LIMIT : 7,
    'full_limit'               => defined( 'SHIFT64_WOO_SEARCH_FULL_LIMIT' ) ? (int) SHIFT64_WOO_SEARCH_FULL_LIMIT : 20,
    'outofstock_mode'          => defined( 'SHIFT64_WOO_SEARCH_OUTOFSTOCK_MODE' ) ? SHIFT64_WOO_SEARCH_OUTOFSTOCK_MODE : 'exclude',
    'outofstock_demote_factor' => defined( 'SHIFT64_WOO_SEARCH_OUTOFSTOCK_DEMOTE_FACTOR' ) ? (float) SHIFT64_WOO_SEARCH_OUTOFSTOCK_DEMOTE_FACTOR : 0.3,
    'fuzzy_level'              => defined( 'SHIFT64_WOO_SEARCH_FUZZY_LEVEL' ) ? (int) SHIFT64_WOO_SEARCH_FUZZY_LEVEL : 1,
    'logic'                    => defined( 'SHIFT64_WOO_SEARCH_LOGIC' ) ? SHIFT64_WOO_SEARCH_LOGIC : 'OR',
    // Phase 4: Search strategy.
    'strategy'                      => defined( 'SHIFT64_WOO_SEARCH_STRATEGY' ) ? SHIFT64_WOO_SEARCH_STRATEGY : 'strict_first',
    'fallback_trigger'              => defined( 'SHIFT64_WOO_SEARCH_FALLBACK_TRIGGER' ) ? SHIFT64_WOO_SEARCH_FALLBACK_TRIGGER : 'low_score',
    'fallback_score_threshold'      => defined( 'SHIFT64_WOO_SEARCH_FALLBACK_SCORE_THRESHOLD' ) ? (float) SHIFT64_WOO_SEARCH_FALLBACK_SCORE_THRESHOLD : 0.5,
    'fallback_fuzzy_level'          => defined( 'SHIFT64_WOO_SEARCH_FALLBACK_FUZZY_LEVEL' ) ? (int) SHIFT64_WOO_SEARCH_FALLBACK_FUZZY_LEVEL : 1,
    'token_reduction_enabled'       => defined( 'SHIFT64_WOO_SEARCH_TOKEN_REDUCTION_ENABLED' ) ? SHIFT64_WOO_SEARCH_TOKEN_REDUCTION_ENABLED : true,
    'weak_tokens'                   => defined( 'SHIFT64_WOO_SEARCH_WEAK_TOKENS' ) ? SHIFT64_WOO_SEARCH_WEAK_TOKENS : 'do,na,z,i,w,od,po,za,ze,we,o,u,a,e',
    'drop_trailing_weak_token_only' => defined( 'SHIFT64_WOO_SEARCH_DROP_TRAILING_WEAK_TOKEN_ONLY' ) ? SHIFT64_WOO_SEARCH_DROP_TRAILING_WEAK_TOKEN_ONLY : true,
    'diacritics_normalization'      => defined( 'SHIFT64_WOO_SEARCH_DIACRITICS_NORMALIZATION' ) ? SHIFT64_WOO_SEARCH_DIACRITICS_NORMALIZATION : true,
    'fuzzy_synonyms'               => defined( 'SHIFT64_WOO_SEARCH_FUZZY_SYNONYMS' ) ? SHIFT64_WOO_SEARCH_FUZZY_SYNONYMS : false,
    'category_boost_rules'          => defined( 'SHIFT64_WOO_SEARCH_CATEGORY_BOOST_RULES' ) ? SHIFT64_WOO_SEARCH_CATEGORY_BOOST_RULES : '',
    'category_pin_rules'            => defined( 'SHIFT64_WOO_SEARCH_CATEGORY_PIN_RULES' ) ? SHIFT64_WOO_SEARCH_CATEGORY_PIN_RULES : '',
    'category_suggest_fuzzy'        => defined( 'SHIFT64_WOO_SEARCH_CATEGORY_SUGGEST_FUZZY' ) ? SHIFT64_WOO_SEARCH_CATEGORY_SUGGEST_FUZZY : false,
);

// Execute search.
$search  = new Shift64_Woo_Search_Query( $redis, $config );
$results = $search->search( $query, $mode, $limit );

// Augment autocomplete response with suggestions + categories.
if ( 'autocomplete' === $mode ) {
    // Suggestions: prefix-match against stored suggestions.
    $matched_suggestions = array();
    $sug_key = $redis->get_prefix() . ':suggestions';
    $sug_raw = $redis->raw_command( 'GET', $sug_key );
    $all_sug = $sug_raw ? json_decode( $sug_raw, true ) : array();
    if ( is_array( $all_sug ) ) {
        $q_lower = mb_strtolower( $query );
        foreach ( $all_sug as $s ) {
            if ( mb_strpos( mb_strtolower( $s ), $q_lower ) === 0 ) {
                $matched_suggestions[] = $s;
            }
            if ( count( $matched_suggestions ) >= 3 ) {
                break;
            }
        }
    }
    $results['suggestions'] = $matched_suggestions;

    // Categories: read the precomputed blob and rank it. Scoring/sorting/cap and
    // the pin + boost logic live in Shift64_Woo_Search_Category_Suggest so they can be
    // unit-tested without booting the SHORTINIT endpoint.
    $cat_key  = $redis->get_prefix() . ':categories';
    $cat_raw  = $redis->raw_command( 'GET', $cat_key );
    $all_cats = $cat_raw ? json_decode( $cat_raw, true ) : array();
    $results['categories'] = Shift64_Woo_Search_Category_Suggest::rank( $all_cats, $query, $config['category_pin_rules'], $config['category_suggest_fuzzy'] );
}

// Output response.
echo json_encode( $results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

// Log stats (async-style: after output is sent).
if ( function_exists( 'fastcgi_finish_request' ) ) {
    fastcgi_finish_request();
}

// Log to MySQL stats table if $wpdb is available.
if ( isset( $GLOBALS['wpdb'] ) ) {
    $elapsed = ( microtime( true ) - $start_time ) * 1000;

    // Require stats class for logging.
    if ( file_exists( SHIFT64_WOO_SEARCH_PLUGIN_PATH . 'includes/class-shift64-woo-search-stats.php' ) ) {
        require_once SHIFT64_WOO_SEARCH_PLUGIN_PATH . 'includes/class-shift64-woo-search-stats.php';

        global $wpdb;

        // Role-based filtering: only log searches from customers/subscribers/guests.
        // In SHORTINIT, user functions aren't available, so we parse the auth cookie.
        $should_log      = true;
        $user_id_for_log = null;

        $logged_in_cookie = '';
        foreach ( $_COOKIE as $cookie_name => $cookie_value ) {
            if ( strpos( $cookie_name, 'wordpress_logged_in_' ) === 0 ) {
                $logged_in_cookie = $cookie_value;
                break;
            }
        }

        if ( ! empty( $logged_in_cookie ) ) {
            $cookie_parts = explode( '|', $logged_in_cookie );
            if ( count( $cookie_parts ) >= 4 ) {
                $username = $cookie_parts[0];
                $user_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1",
                    $username
                ) );
                if ( $user_row ) {
                    $user_id_for_log = (int) $user_row->ID;
                    $meta_key        = $wpdb->prefix . 'capabilities';
                    $caps_raw        = $wpdb->get_var( $wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
                        $user_id_for_log,
                        $meta_key
                    ) );
                    if ( $caps_raw ) {
                        $caps = @unserialize( $caps_raw );
                        if ( is_array( $caps ) ) {
                            $skip_roles = array( 'administrator', 'editor', 'shop_manager' );
                            foreach ( $skip_roles as $role ) {
                                if ( ! empty( $caps[ $role ] ) ) {
                                    $should_log = false;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        if ( $should_log ) {
            // In SHORTINIT, wp_salt() may not be available, so we hash IP manually.
            $ip_hash = null;
            if ( ! empty( $_SERVER['REMOTE_ADDR'] ) && defined( 'AUTH_SALT' ) ) {
                $ip_hash = hash( 'sha256', $_SERVER['REMOTE_ADDR'] . AUTH_SALT );
            }

            $table = $wpdb->prefix . 'shift64_woo_search_stats';

            $normalized = mb_strtolower( trim( $query ) );
            $normalized = preg_replace( '/\s+/', ' ', $normalized );
            $normalized = preg_replace( '/[^a-z0-9ąćęłńóśźżàâäéèêëïîôùûüÿœæ\s\-]/', '', $normalized );

            $wpdb->insert(
                $table,
                array(
                    'query_text'       => mb_substr( $query, 0, 255 ),
                    'query_normalized' => mb_substr( $normalized, 0, 255 ),
                    'results_count'    => $results['count'],
                    'response_time_ms' => $elapsed,
                    'search_mode'      => $mode,
                    'user_id'          => $user_id_for_log,
                    'session_id'       => null,
                    'ip_hash'          => $ip_hash ? mb_substr( $ip_hash, 0, 64 ) : null,
                    'created_at'       => gmdate( 'Y-m-d H:i:s' ),
                ),
                array( '%s', '%s', '%d', '%f', '%s', '%d', '%s', '%s', '%s' )
            );
        }
    }
}

exit;
