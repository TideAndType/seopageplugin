<?php
/**
 * Elementor capability discovery.
 *
 * Reads the actually installed Elementor runtime instead of assuming a fixed
 * widget set. TideOrbit uses this as a safety boundary before emitting native
 * Elementor widgets. EMCP Tools is detected as an optional external agent
 * surface, never a runtime dependency.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Elementor_Capabilities {

	public static function available() {
		return class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_active();
	}

	public static function version() {
		return defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
	}

	public static function widget_types() {
		$types = array();
		if ( ! self::available() || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return $types;
		}
		try {
			$plugin = \Elementor\Plugin::$instance;
			if ( $plugin && isset( $plugin->widgets_manager ) && is_object( $plugin->widgets_manager ) && method_exists( $plugin->widgets_manager, 'get_widget_types' ) ) {
				foreach ( (array) $plugin->widgets_manager->get_widget_types() as $id => $widget ) {
					$types[] = (string) $id;
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return array_values( array_unique( array_filter( $types ) ) );
	}

	public static function supports_widget( $widget ) {
		$widget = sanitize_key( (string) $widget );
		if ( '' === $widget || ! self::available() ) {
			return false;
		}
		$types = self::widget_types();
		if ( ! empty( $types ) ) {
			return in_array( $widget, $types, true );
		}
		// Runtime discovery can be unavailable very early in boot. These are
		// Elementor core widgets TideOrbit safely knows how to emit.
		return in_array( $widget, array( 'heading', 'text-editor', 'button', 'image', 'html' ), true );
	}

	public static function supports_containers() {
		$v = self::version();
		return '' !== $v && version_compare( $v, '3.6.0', '>=' );
	}

	public static function supports_atomic() {
		$v = self::version();
		return '' !== $v && version_compare( $v, '4.0.0', '>=' );
	}

	public static function emcp() {
		return array(
			'available' => defined( 'EMCP_TOOLS_VERSION' ),
			'version'   => defined( 'EMCP_TOOLS_VERSION' ) ? (string) EMCP_TOOLS_VERSION : '',
		);
	}

	public static function snapshot() {
		return array(
			'elementor' => self::available(),
			'version'   => self::version(),
			'containers'=> self::supports_containers(),
			'atomic'    => self::supports_atomic(),
			'widgets'   => self::widget_types(),
			'emcp'      => self::emcp(),
		);
	}
}
