<?php
/**
 * Detects the active SEO plugin and reads its metadata non-destructively.
 *
 * Supports Yoast SEO, Rank Math, All in One SEO, and The SEO Framework. Falls back to plugin-owned
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
	const PLUGIN_TSF      = 'the-seo-framework';
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
		if (
			defined( 'THE_SEO_FRAMEWORK_VERSION' )
			|| defined( 'THE_SEO_FRAMEWORK_PRESENT' )
			|| function_exists( 'tsf' )
			|| function_exists( 'the_seo_framework' )
		) {
			return self::PLUGIN_TSF;
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
			case self::PLUGIN_TSF:
				return 'The SEO Framework';
			default:
				return __( 'None (TideOrbit will store metadata itself)', 'seo-command-center' );
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
			case self::PLUGIN_TSF:
				return (string) get_post_meta( $post_id, '_genesis_title', true );
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
			case self::PLUGIN_TSF:
				return (string) get_post_meta( $post_id, '_genesis_description', true );
			default:
				return (string) get_post_meta( $post_id, '_scc_meta_description', true );
		}
	}

	/**
	 * Whether the site owner has deliberately set this post to noindex in their
	 * SEO plugin (per post, or via that plugin's default for the post type).
	 * Such pages are kept out of every TideOrbit suggestion.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_noindex( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		$plugin    = self::detect();
		$post_type = (string) get_post_type( $post_id );
		$meta      = array();
		$defaults  = array();

		switch ( $plugin ) {
			case self::PLUGIN_YOAST:
				$meta['yoast']    = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
				$titles           = get_option( 'wpseo_titles', array() );
				$defaults['yoast'] = is_array( $titles ) && ! empty( $titles[ 'noindex-' . $post_type ] );
				break;
			case self::PLUGIN_RANKMATH:
				$meta['rankmath'] = get_post_meta( $post_id, 'rank_math_robots', true );
				$titles           = get_option( 'rank-math-options-titles', array() );
				if ( is_array( $titles ) && 'on' === ( $titles[ 'pt_' . $post_type . '_custom_robots' ] ?? '' ) ) {
					$defaults['rankmath'] = (array) ( $titles[ 'pt_' . $post_type . '_robots' ] ?? array() );
				}
				break;
			case self::PLUGIN_AIOSEO:
				$meta['aioseo'] = self::aioseo_robots( $post_id );
				break;
			case self::PLUGIN_TSF:
				$meta['tsf'] = (string) get_post_meta( $post_id, '_genesis_noindex', true );
				break;
		}

		$noindex = self::noindex_from( $plugin, $meta, $defaults );
		return (bool) apply_filters( 'scc_is_noindex', $noindex, $post_id, $plugin );
	}

	/**
	 * Decide noindex from raw SEO-plugin values. Pure — unit-tested.
	 *
	 * Yoast: meta '1' = noindex, '2' = index, '' = post-type default.
	 * Rank Math: robots array on the post, else the post-type default robots
	 * (only when that type uses custom robots).
	 * AIOSEO: row {robots_default, robots_noindex}; the default flag means "use
	 * the global setting", which is treated as indexable.
	 * The SEO Framework: _genesis_noindex '1'.
	 *
	 * @param string $plugin   PLUGIN_* constant.
	 * @param array  $meta     Raw per-post values.
	 * @param array  $defaults Post-type defaults.
	 * @return bool
	 */
	public static function noindex_from( $plugin, array $meta, array $defaults = array() ) {
		switch ( $plugin ) {
			case self::PLUGIN_YOAST:
				$value = (string) ( $meta['yoast'] ?? '' );
				if ( '1' === $value ) {
					return true;
				}
				if ( '2' === $value ) {
					return false;
				}
				return ! empty( $defaults['yoast'] );
			case self::PLUGIN_RANKMATH:
				$robots = $meta['rankmath'] ?? array();
				if ( is_string( $robots ) ) {
					$robots = array_filter( array_map( 'trim', explode( ',', $robots ) ) );
				}
				if ( empty( $robots ) ) {
					$robots = (array) ( $defaults['rankmath'] ?? array() );
				}
				return in_array( 'noindex', (array) $robots, true );
			case self::PLUGIN_AIOSEO:
				$row = (array) ( $meta['aioseo'] ?? array() );
				return empty( $row['robots_default'] ) && ! empty( $row['robots_noindex'] );
			case self::PLUGIN_TSF:
				return '1' === (string) ( $meta['tsf'] ?? '' );
		}
		return false;
	}

	/**
	 * AIOSEO robots settings for a post (read-only).
	 *
	 * @param int $post_id Post id.
	 * @return array {robots_default, robots_noindex}
	 */
	protected static function aioseo_robots( $post_id ) {
		global $wpdb;
		static $has_table = null;
		$table = $wpdb->prefix . 'aioseo_posts';
		if ( null === $has_table ) {
			// Checked once per request — this runs for every page during an analysis.
			$has_table = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ); // phpcs:ignore WordPress.DB
		}
		if ( ! $has_table ) {
			return array();
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT robots_default, robots_noindex FROM {$table} WHERE post_id = %d", (int) $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : array();
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
