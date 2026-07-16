<?php
/**
 * Redis connection manager (singleton).
 *
 * Works in both full WP context (reads wp_options) and SHORTINIT (reads constants).
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redis connection manager (singleton).
 */
class Shift64_Woo_Search_Redis {

	/**
	 * Singleton instance.
	 *
	 * @var Shift64_Woo_Search_Redis|null
	 */
	private static $instance = null;

	/**
	 * Redis client instance.
	 *
	 * @var Redis|null
	 */
	private $client = null;

	/**
	 * Redis host.
	 *
	 * @var string
	 */
	private $host = '127.0.0.1';

	/**
	 * Redis port.
	 *
	 * @var int
	 */
	private $port = 6379;

	/**
	 * Redis ACL username (Redis 6+). Empty string disables ACL auth and falls back to legacy single-arg AUTH.
	 *
	 * @var string
	 */
	private $username = '';

	/**
	 * Redis password.
	 *
	 * @var string
	 */
	private $password = '';

	/**
	 * Redis database index.
	 *
	 * @var int
	 */
	private $db = 0;

	/**
	 * Key prefix.
	 *
	 * @var string
	 */
	private $prefix = 'shift64_woo_search';

	/**
	 * Connection timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 2;

	/**
	 * Whether the client is connected.
	 *
	 * @var bool
	 */
	private $connected = false;

	/**
	 * Last connection error message.
	 *
	 * @var string|null
	 */
	private $last_error = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Shift64_Woo_Search_Redis
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Loads Redis config.
	 */
	private function __construct() {
		$this->load_config();
	}

	/**
	 * Load Redis connection config from constants or wp_options.
	 */
	private function load_config() {
		// SHORTINIT mode: read from constants (set by mu-plugin config.php).
		if ( defined( 'SHIFT64_WOO_SEARCH_REDIS_HOST' ) ) {
			$this->host     = SHIFT64_WOO_SEARCH_REDIS_HOST;
			$this->port     = defined( 'SHIFT64_WOO_SEARCH_REDIS_PORT' ) ? (int) SHIFT64_WOO_SEARCH_REDIS_PORT : 6379;
			$this->username = defined( 'SHIFT64_WOO_SEARCH_REDIS_USERNAME' ) ? SHIFT64_WOO_SEARCH_REDIS_USERNAME : '';
			$this->password = defined( 'SHIFT64_WOO_SEARCH_REDIS_PASSWORD' ) ? SHIFT64_WOO_SEARCH_REDIS_PASSWORD : '';
			$this->db       = defined( 'SHIFT64_WOO_SEARCH_REDIS_DB' ) ? (int) SHIFT64_WOO_SEARCH_REDIS_DB : 0;
			$this->prefix   = defined( 'SHIFT64_WOO_SEARCH_REDIS_PREFIX' ) ? SHIFT64_WOO_SEARCH_REDIS_PREFIX : 'shift64_woo_search';
			return;
		}

		// Full WP mode: read from options (no defaults — must be explicitly configured).
		if ( function_exists( 'get_option' ) ) {
			$this->host     = get_option( 'shift64_woo_search_redis_host', '' );
			$this->port     = (int) get_option( 'shift64_woo_search_redis_port', 0 );
			$this->username = get_option( 'shift64_woo_search_redis_username', '' );
			$this->password = get_option( 'shift64_woo_search_redis_password', '' );
			$this->db       = (int) get_option( 'shift64_woo_search_redis_db', 0 );
			$this->prefix   = get_option( 'shift64_woo_search_redis_prefix', 'shift64_woo_search' );
		}
	}

	/**
	 * Check whether Redis host and port are configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->host ) && $this->port > 0;
	}

	/**
	 * Connect to Redis.
	 *
	 * @return bool
	 */
	public function connect() {
		if ( $this->connected && $this->client ) {
			return true;
		}

		if ( ! $this->is_configured() ) {
			$this->last_error = 'Redis connection not configured. Run: wp shift64-woo-search setup';
			return false;
		}

		if ( ! class_exists( 'Redis' ) ) {
			$this->last_error = 'phpredis extension is not installed.';
			return false;
		}

		try {
			$this->client = new Redis();
			$this->client->connect( $this->host, $this->port, $this->timeout );

			if ( ! empty( $this->password ) ) {
				// Redis 6+ ACL: when a username is configured, AUTH takes [username, password].
				// Without username, fall back to legacy single-arg AUTH (default user).
				if ( '' !== $this->username ) {
					$this->client->auth( array( $this->username, $this->password ) );
				} else {
					$this->client->auth( $this->password );
				}
			}

			if ( $this->db > 0 ) {
				$this->client->select( $this->db );
			}

			// Force RESP2 protocol — RESP3 (default in Redis 7+) turns map keys
			// into booleans, breaking FT.INFO and other rawCommand parsing.
			$this->client->rawCommand( 'HELLO', '2' );

			$this->connected  = true;
			$this->last_error = null;
			return true;
		} catch ( RedisException $e ) {
			$this->last_error = $e->getMessage();
			$this->client     = null;
			$this->connected  = false;
			return false;
		}
	}

	/**
	 * Check whether Redis is available by attempting to connect.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->connect();
	}

	/**
	 * Get the Redis client instance.
	 *
	 * @return Redis|null
	 */
	public function get_client() {
		if ( $this->connect() ) {
			return $this->client;
		}
		return null;
	}

	/**
	 * Get the key prefix.
	 *
	 * @return string
	 */
	public function get_prefix() {
		return $this->prefix;
	}

	/**
	 * Get the RediSearch index name.
	 *
	 * @return string
	 */
	public function get_index_name() {
		return $this->prefix . '_product_idx';
	}

	/**
	 * Get the Redis key for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public function get_product_key( $product_id ) {
		return $this->prefix . ':product:' . $product_id;
	}

	/**
	 * Ping the Redis server.
	 *
	 * @return bool
	 */
	public function ping() {
		try {
			if ( $this->connect() && $this->client ) {
				return '+PONG' === $this->client->ping( '+PONG' );
			}
		} catch ( RedisException $e ) {
			$this->last_error = $e->getMessage();
		}
		return false;
	}

	/**
	 * Get the last connection error message.
	 *
	 * @return string|null
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Execute a raw Redis command (needed for RediSearch FT.* commands).
	 *
	 * @param string ...$args Command and arguments.
	 * @return mixed
	 */
	public function raw_command( ...$args ) {
		if ( ! $this->connect() || ! $this->client ) {
			return false;
		}

		try {
			return $this->client->rawCommand( ...$args );
		} catch ( RedisException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Reset the singleton (useful for testing).
	 */
	public static function reset_instance() {
		if ( self::$instance && self::$instance->client ) {
			try {
				self::$instance->client->close();
			} catch ( RedisException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Ignore close errors.
			}
		}
		self::$instance = null;
	}
}
