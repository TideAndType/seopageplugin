<?php
/**
 * Security helpers: capability checks, nonces, and typed sanitizers.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central security helper.
 */
class SCC_Security {

	const NONCE_ACTION = 'scc_admin_action';

	/**
	 * The capability required to use the plugin.
	 *
	 * @return string
	 */
	public static function capability() {
		return apply_filters( 'scc_required_capability', 'manage_options' );
	}

	/**
	 * Whether the current user may use the plugin.
	 *
	 * @return bool
	 */
	public static function current_user_can() {
		return current_user_can( self::capability() );
	}

	/**
	 * REST permission callback.
	 *
	 * @return bool|WP_Error
	 */
	public static function rest_permission() {
		if ( self::current_user_can() ) {
			return true;
		}
		return new WP_Error(
			'scc_forbidden',
			__( 'You do not have permission to use SEO Command Center.', 'seo-command-center' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Sanitize a scalar text value.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function sanitize_text( $value ) {
		return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Sanitize a textarea / multi-line value.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function sanitize_textarea( $value ) {
		return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Sanitize an API key: trim, strip whitespace/control chars, cap length.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function sanitize_key_value( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( preg_replace( '/[\x00-\x1F\x7F\s]/', '', $value ) );
		return substr( $value, 0, 400 );
	}

	/**
	 * Sanitize a bounded integer.
	 *
	 * @param mixed $value Raw.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int
	 */
	public static function sanitize_int( $value, $min = 0, $max = PHP_INT_MAX ) {
		$value = (int) $value;
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Sanitize a bounded float.
	 *
	 * @param mixed $value Raw.
	 * @param float $min   Minimum.
	 * @param float $max   Maximum.
	 * @return float
	 */
	public static function sanitize_float( $value, $min = 0.0, $max = 1000000.0 ) {
		$value = (float) $value;
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Sanitize a boolean-ish value to a real bool.
	 *
	 * @param mixed $value Raw.
	 * @return bool
	 */
	public static function sanitize_bool( $value ) {
		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) || in_array( $value, array( 1, '1', true, 'true', 'on', 'yes' ), true );
	}

	/**
	 * Mask a secret, showing only the last 4 characters.
	 *
	 * @param string $secret Secret value.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = (string) $secret;
		if ( '' === $secret ) {
			return '';
		}
		$last = substr( $secret, -4 );
		return '••••' . $last;
	}
}
