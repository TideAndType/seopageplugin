<?php
/**
 * Detects the active SEO plugin and reads its metadata non-destructively.
 *
 * Supports Yoast SEO, Rank Math, and All in One SEO. Falls back to plugin-owned
 * meta keys when none is active. Never writes without explicit approval (writes
 * arrive in Phase 3).
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO metadata bridge.
 */
class SCC_SEO_Meta {

	const PLUGIN_YOAST    = 'yoast';
	const PLUGIN_RANKMATH = 'rankmath';
	const PLUGIN_AIOSEO   = 'aioseo';
	const PLUGIN_NONE     = 'none';

	/**
	 * Detect the active SEO plugin.
	 *
	 * @return string One of the PLUGIN_* constants.
	 */
	public static function detect() {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return self::PLUGIN_YOAST;
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return self::PLUGIN_RANKMATH;
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return self::PLUGIN_AIOSEO;
		}
		return self::PLUGIN_NONE;
	}

	/**
	 * Human label for the detected plugin.
	 *
	 * @param string $plugin Plugin key.
	 * @return string
	 */
	public static function label( $plugin ) {
		switch ( $plugin ) {
			case self::PLUGIN_YOAST:
				return 'Yoast SEO';
			case self::PLUGIN_RANKMATH:
				return 'Rank Math';
			case self::PLUGIN_AIOSEO:
				return 'All in One SEO';
			default:
				return __( 'None (SEO Command Center will store metadata itself)', 'seo-command-center' );
		}
	}

	/**
	 * Read the SEO title for a post.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function get_title( $post_id ) {
		switch ( self::detect() ) {
			case self::PLUGIN_YOAST:
				return (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
			case self::PLUGIN_RANKMATH:
				return (string) get_post_meta( $post_id, 'rank_math_title', true );
			case self::PLUGIN_AIOSEO:
				return self::aioseo_field( $post_id, 'title' );
			default:
				return (string) get_post_meta( $post_id, '_scc_meta_title', true );
		}
	}

	/**
	 * Read the meta description for a post.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function get_description( $post_id ) {
		switch ( self::detect() ) {
			case self::PLUGIN_YOAST:
				return (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
			case self::PLUGIN_RANKMATH:
				return (string) get_post_meta( $post_id, 'rank_math_description', true );
			case self::PLUGIN_AIOSEO:
				return self::aioseo_field( $post_id, 'description' );
			default:
				return (string) get_post_meta( $post_id, '_scc_meta_description', true );
		}
	}

	/**
	 * Read an AIOSEO field from its custom table (read-only).
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   'title' or 'description'.
	 * @return string
	 */
	protected static function aioseo_field( $post_id, $field ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		// Only query if the table exists.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
		if ( $exists !== $table ) {
			return '';
		}
		$column = ( 'description' === $field ) ? 'description' : 'title';
		// Column is from a fixed whitelist above.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE post_id = %d", (int) $post_id ) ); // phpcs:ignore WordPress.DB
		return (string) $value;
	}
}
