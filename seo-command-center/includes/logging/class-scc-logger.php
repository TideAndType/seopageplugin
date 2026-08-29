<?php
/**
 * Diagnostic logger. Never logs API keys or other secrets.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured, secret-redacting logger backed by the scc_logs table.
 */
class SCC_Logger {

	const MAX_ROWS = 1000;

	/**
	 * Keys whose values must never be stored.
	 *
	 * @var string[]
	 */
	protected static $secret_keys = array( 'api_key', 'key', 'authorization', 'auth', 'token', 'secret', 'password', 'claude_key', 'openai_key', 'dataforseo_key', 'gsc_key' );

	/**
	 * Write a log entry.
	 *
	 * @param string $level   debug|info|warning|error.
	 * @param string $source  Component name.
	 * @param string $message Message.
	 * @param array  $context Extra context (redacted before store).
	 */
	public static function log( $level, $source, $message, array $context = array() ) {
		$context = self::redact( $context );

		SCC_DB::insert(
			'logs',
			array(
				'created_at' => current_time( 'mysql' ),
				'level'      => substr( (string) $level, 0, 20 ),
				'source'     => substr( (string) $source, 0, 60 ),
				'message'    => (string) $message,
				'context'    => empty( $context ) ? null : wp_json_encode( $context ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		self::maybe_prune();
	}

	/**
	 * Convenience: error level.
	 *
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( $source, $message, array $context = array() ) {
		self::log( 'error', $source, $message, $context );
	}

	/**
	 * Convenience: info level.
	 *
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( $source, $message, array $context = array() ) {
		self::log( 'info', $source, $message, $context );
	}

	/**
	 * Recursively redact secret-looking values from a context array.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	protected static function redact( array $context ) {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$lc = strtolower( (string) $key );
			$is_secret = false;
			foreach ( self::$secret_keys as $needle ) {
				if ( false !== strpos( $lc, $needle ) ) {
					$is_secret = true;
					break;
				}
			}
			if ( $is_secret ) {
				$clean[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = self::redact( $value );
			} elseif ( is_string( $value ) ) {
				// Also strip Bearer tokens that appear inside free-text values.
				$clean[ $key ] = preg_replace( '/(sk-[A-Za-z0-9\-_]{8,}|Bearer\s+\S+)/', '[redacted]', $value );
			} elseif ( is_scalar( $value ) ) {
				// Numbers/bools kept as-is.
				$clean[ $key ] = $value;
			} else {
				$clean[ $key ] = '[object]';
			}
		}
		return $clean;
	}

	/**
	 * Keep the log table bounded.
	 */
	protected static function maybe_prune() {
		global $wpdb;
		$table = SCC_DB::table( 'logs' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
		if ( $count <= self::MAX_ROWS ) {
			return;
		}
		$remove = $count - self::MAX_ROWS;
		// Delete oldest rows.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $remove ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Fetch recent log rows.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function recent( $limit = 100 ) {
		global $wpdb;
		$table = SCC_DB::table( 'logs' );
		$limit = SCC_Security::sanitize_int( $limit, 1, 500 );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
