<?php
/**
 * Design Intelligence (lightweight) — reads the site's existing Elementor design
 * language so generated blocks don't look foreign. It inspects the active
 * Elementor "kit" (global colors + typography) rather than doing any heavy
 * visual analysis, and caches the result. When no kit/Elementor is present it
 * returns safe empty values and the blocks simply inherit the theme.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site design intelligence.
 */
class SCC_Design_Intel {

	const CACHE_KEY = 'scc_design_intel';
	const TTL       = 43200; // 12 hours.

	/**
	 * The site's palette from the active Elementor kit (cached).
	 *
	 * @return array { has_kit:bool, primary:string, secondary:string, text:string, accent:string }
	 */
	public static function palette() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = array( 'has_kit' => false, 'primary' => '', 'secondary' => '', 'text' => '', 'accent' => '' );

		if ( class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_active() ) {
			$kit_id   = (int) get_option( 'elementor_active_kit' );
			$settings = $kit_id ? get_post_meta( $kit_id, '_elementor_page_settings', true ) : array();
			if ( is_array( $settings ) && ! empty( $settings['system_colors'] ) && is_array( $settings['system_colors'] ) ) {
				$out['has_kit'] = true;
				foreach ( $settings['system_colors'] as $c ) {
					$id  = strtolower( (string) ( $c['_id'] ?? '' ) );
					$hex = self::sanitize_hex( (string) ( $c['color'] ?? '' ) );
					if ( '' === $hex ) {
						continue;
					}
					if ( 'primary' === $id ) {
						$out['primary'] = $hex;
					} elseif ( 'secondary' === $id ) {
						$out['secondary'] = $hex;
					} elseif ( 'text' === $id ) {
						$out['text'] = $hex;
					} elseif ( 'accent' === $id ) {
						$out['accent'] = $hex;
					}
				}
			}
		}

		set_transient( self::CACHE_KEY, $out, self::TTL );
		return $out;
	}

	/**
	 * A style attribute value that maps the plugin component CSS variables to the
	 * site's palette, so generated HTML blocks adopt the site's colours. Empty
	 * when no palette is known (blocks then inherit the theme defaults).
	 *
	 * @return string e.g. "--scc-accent:#0a7;"
	 */
	public static function css_vars() {
		$p = self::palette();
		$accent = $p['primary'] ?: $p['accent'];
		return '' !== $accent ? '--scc-accent:' . $accent . ';--scc-brand:' . $accent . ';' : '';
	}

	/**
	 * Validate a hex colour (#rgb / #rrggbb), else empty.
	 *
	 * @param string $hex Colour.
	 * @return string
	 */
	public static function sanitize_hex( $hex ) {
		$hex = trim( (string) $hex );
		return preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex ) ? $hex : '';
	}

	/**
	 * Clear the cached palette (after kit changes / on demand).
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}
}
