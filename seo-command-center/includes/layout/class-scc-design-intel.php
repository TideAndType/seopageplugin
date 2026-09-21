<?php
/**
 * Design Intelligence — reads the site's existing Elementor design language so
 * generated layouts inherit the site's visual system rather than looking like a
 * generic TideOrbit preset.
 *
 * The profile is intentionally deterministic and read-only: active Kit colors,
 * typography, content width, plus conservative spacing signals sampled from a
 * handful of existing Elementor pages. Missing data simply falls back to safe
 * defaults. No AI call is involved.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Design_Intel {

	const CACHE_KEY = 'scc_design_intel';
	const TTL       = 43200;

	public static function profile() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['colors'], $cached['layout'] ) ) {
			return $cached;
		}

		$out = array(
			'has_kit'    => false,
			'kit_id'     => 0,
			'colors'     => array(
				'primary'   => '',
				'secondary' => '',
				'text'      => '',
				'heading'   => '',
				'accent'    => '',
				'surface'   => '#ffffff',
				'border'    => '#e5e7eb',
			),
			'typography' => array(
				'heading_family' => '',
				'body_family'    => '',
			),
			'layout'     => array(
				'content_width' => 1140,
			),
			'spacing'    => array(
				'section_y' => 64,
				'gap'       => 24,
				'radius'    => 12,
			),
			'elementor'  => class_exists( 'SCC_Elementor_Capabilities' ) ? SCC_Elementor_Capabilities::snapshot() : array(),
		);

		if ( class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_active() ) {
			$kit_id   = (int) get_option( 'elementor_active_kit' );
			$settings = $kit_id ? get_post_meta( $kit_id, '_elementor_page_settings', true ) : array();
			if ( is_array( $settings ) ) {
				$out['has_kit'] = true;
				$out['kit_id']  = $kit_id;
				self::read_colors( $settings, $out );
				self::read_typography( $settings, $out );
				self::read_layout( $settings, $out );
			}
			self::read_page_spacing( $out );
		}

		if ( '' === $out['colors']['heading'] ) {
			$out['colors']['heading'] = $out['colors']['secondary'] ?: $out['colors']['text'];
		}
		if ( '' === $out['colors']['text'] ) {
			$out['colors']['text'] = '#374151';
		}
		if ( '' === $out['colors']['heading'] ) {
			$out['colors']['heading'] = '#1f2937';
		}

		set_transient( self::CACHE_KEY, $out, self::TTL );
		return $out;
	}

	protected static function read_colors( array $settings, array &$out ) {
		$sets = array();
		if ( ! empty( $settings['system_colors'] ) && is_array( $settings['system_colors'] ) ) {
			$sets = array_merge( $sets, $settings['system_colors'] );
		}
		if ( ! empty( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] ) ) {
			$sets = array_merge( $sets, $settings['custom_colors'] );
		}
		foreach ( $sets as $c ) {
			if ( ! is_array( $c ) ) { continue; }
			$id    = strtolower( (string) ( $c['_id'] ?? '' ) );
			$title = strtolower( (string) ( $c['title'] ?? '' ) );
			$hex   = self::sanitize_hex( (string) ( $c['color'] ?? '' ) );
			if ( '' === $hex ) { continue; }
			$key = $id;
			if ( ! in_array( $key, array( 'primary', 'secondary', 'text', 'accent' ), true ) ) {
				foreach ( array( 'primary', 'secondary', 'text', 'accent' ) as $candidate ) {
					if ( false !== strpos( $title, $candidate ) ) { $key = $candidate; break; }
				}
			}
			if ( isset( $out['colors'][ $key ] ) && '' === $out['colors'][ $key ] ) {
				$out['colors'][ $key ] = $hex;
			}
		}
	}

	protected static function read_typography( array $settings, array &$out ) {
		$sets = array();
		if ( ! empty( $settings['system_typography'] ) && is_array( $settings['system_typography'] ) ) {
			$sets = array_merge( $sets, $settings['system_typography'] );
		}
		if ( ! empty( $settings['custom_typography'] ) && is_array( $settings['custom_typography'] ) ) {
			$sets = array_merge( $sets, $settings['custom_typography'] );
		}
		foreach ( $sets as $t ) {
			if ( ! is_array( $t ) ) { continue; }
			$id = strtolower( (string) ( $t['_id'] ?? '' ) );
			$family = trim( (string) ( $t['typography_font_family'] ?? $t['font_family'] ?? '' ) );
			if ( '' === $family ) { continue; }
			if ( in_array( $id, array( 'primary', 'secondary' ), true ) && '' === $out['typography']['heading_family'] ) {
				$out['typography']['heading_family'] = $family;
			}
			if ( in_array( $id, array( 'text', 'accent' ), true ) && '' === $out['typography']['body_family'] ) {
				$out['typography']['body_family'] = $family;
			}
		}
	}

	protected static function read_layout( array $settings, array &$out ) {
		foreach ( array( 'container_width', 'content_width' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$value = self::size_value( $settings[ $key ] );
				if ( $value >= 760 && $value <= 1800 ) {
					$out['layout']['content_width'] = $value;
					break;
				}
			}
		}
	}

	protected static function read_page_spacing( array &$out ) {
		if ( ! class_exists( 'WP_Query' ) ) { return; }
		$q = new WP_Query( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'meta_key'       => '_elementor_edit_mode',
			'meta_value'     => 'builder',
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		$ys = array();
		$gaps = array();
		$radii = array();
		foreach ( (array) $q->posts as $post_id ) {
			$raw = get_post_meta( (int) $post_id, '_elementor_data', true );
			$data = is_string( $raw ) ? json_decode( wp_unslash( $raw ), true ) : $raw;
			if ( is_array( $data ) ) {
				self::collect_spacing( $data, $ys, $gaps, $radii );
			}
		}
		if ( ! empty( $ys ) ) { $out['spacing']['section_y'] = self::median_clamped( $ys, 36, 120, 64 ); }
		if ( ! empty( $gaps ) ) { $out['spacing']['gap'] = self::median_clamped( $gaps, 12, 48, 24 ); }
		if ( ! empty( $radii ) ) { $out['spacing']['radius'] = self::median_clamped( $radii, 0, 40, 12 ); }
	}

	protected static function collect_spacing( array $elements, array &$ys, array &$gaps, array &$radii ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) { continue; }
			$s = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			if ( isset( $s['padding'] ) && is_array( $s['padding'] ) ) {
				foreach ( array( 'top', 'bottom' ) as $side ) {
					$v = isset( $s['padding'][ $side ] ) ? (int) $s['padding'][ $side ] : 0;
					if ( $v > 0 ) { $ys[] = $v; }
				}
			}
			if ( isset( $s['gap'] ) ) {
				$v = self::size_value( $s['gap'] );
				if ( $v > 0 ) { $gaps[] = $v; }
			}
			if ( isset( $s['border_radius'] ) && is_array( $s['border_radius'] ) ) {
				$v = isset( $s['border_radius']['top'] ) ? (int) $s['border_radius']['top'] : 0;
				if ( $v >= 0 ) { $radii[] = $v; }
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::collect_spacing( $el['elements'], $ys, $gaps, $radii );
			}
		}
	}

	protected static function size_value( $value ) {
		if ( is_array( $value ) ) {
			if ( isset( $value['size'] ) ) { return (int) $value['size']; }
			if ( isset( $value['value'] ) ) { return (int) $value['value']; }
		}
		return is_numeric( $value ) ? (int) $value : 0;
	}

	protected static function median_clamped( array $values, $min, $max, $fallback ) {
		$values = array_values( array_filter( array_map( 'intval', $values ), function ( $v ) use ( $min, $max ) {
			return $v >= $min && $v <= $max;
		} ) );
		if ( empty( $values ) ) { return (int) $fallback; }
		sort( $values, SORT_NUMERIC );
		$n = count( $values );
		return (int) $values[ (int) floor( ( $n - 1 ) / 2 ) ];
	}

	public static function palette() {
		$p = self::profile();
		return array(
			'has_kit'    => ! empty( $p['has_kit'] ),
			'primary'    => (string) ( $p['colors']['primary'] ?? '' ),
			'secondary'  => (string) ( $p['colors']['secondary'] ?? '' ),
			'text'       => (string) ( $p['colors']['text'] ?? '' ),
			'accent'     => (string) ( $p['colors']['accent'] ?? '' ),
		);
	}

	public static function css_vars() {
		$p = self::profile();
		$accent = (string) ( $p['colors']['primary'] ?: $p['colors']['accent'] );
		$text   = (string) ( $p['colors']['text'] ?? '' );
		$vars   = '';
		if ( '' !== $accent ) { $vars .= '--scc-accent:' . $accent . ';--scc-brand:' . $accent . ';'; }
		if ( '' !== $text ) { $vars .= '--scc-text:' . $text . ';'; }
		return $vars;
	}

	public static function sanitize_hex( $hex ) {
		$hex = trim( (string) $hex );
		return preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex ) ? $hex : '';
	}

	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}
}
