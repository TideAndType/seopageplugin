<?php
/**
 * Live Elementor widget schema discovery for the AI design agent.
 *
 * TideOrbit reads the widgets that are actually registered on the current
 * WordPress install (Elementor Free/Pro and third-party add-ons) and exposes a
 * compact, read-only schema to the model. The model never writes raw Elementor
 * JSON; these schemas are used only to validate the controlled composition DSL.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Elementor_Widget_Schema {

	const MAX_SUMMARY_WIDGETS = 80;
	const MAX_SCHEMA_WIDGETS  = 22;
	const MAX_CONTROLS        = 44;

	/**
	 * Core controls retained as a fallback when Elementor has not finished
	 * registering widgets yet. Runtime controls always win when available.
	 */
	public static function core_fallbacks() {
		return array(
			'heading' => array(
				'title' => array( 'type' => 'text' ),
				'header_size' => array( 'type' => 'select', 'options' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ) ),
				'align' => array( 'type' => 'choose', 'options' => array( 'left', 'center', 'right', 'justify' ) ),
			),
			'text-editor' => array(
				'editor' => array( 'type' => 'wysiwyg' ),
				'align' => array( 'type' => 'choose', 'options' => array( 'left', 'center', 'right', 'justify' ) ),
			),
			'button' => array(
				'text' => array( 'type' => 'text' ),
				'link' => array( 'type' => 'url' ),
				'align' => array( 'type' => 'choose', 'options' => array( 'left', 'center', 'right', 'justify' ) ),
				'size' => array( 'type' => 'select' ),
			),
			'image' => array(
				'image' => array( 'type' => 'media' ),
				'image_size' => array( 'type' => 'select' ),
				'caption_source' => array( 'type' => 'select' ),
			),
			'icon-list' => array(
				'icon_list' => array( 'type' => 'repeater' ),
				'view' => array( 'type' => 'select' ),
			),
			'accordion' => array(
				'tabs' => array( 'type' => 'repeater' ),
				'title_html_tag' => array( 'type' => 'select' ),
			),
			'counter' => array(
				'starting_number' => array( 'type' => 'number' ),
				'ending_number' => array( 'type' => 'number' ),
				'prefix' => array( 'type' => 'text' ),
				'suffix' => array( 'type' => 'text' ),
				'title' => array( 'type' => 'text' ),
			),
			'image-box' => array(
				'image' => array( 'type' => 'media' ),
				'title_text' => array( 'type' => 'text' ),
				'description_text' => array( 'type' => 'textarea' ),
				'link' => array( 'type' => 'url' ),
			),
			'testimonial' => array(
				'testimonial_content' => array( 'type' => 'textarea' ),
				'testimonial_name' => array( 'type' => 'text' ),
				'testimonial_job' => array( 'type' => 'text' ),
				'testimonial_image' => array( 'type' => 'media' ),
			),
			'video' => array(
				'youtube_url' => array( 'type' => 'text' ),
				'vimeo_url' => array( 'type' => 'text' ),
			),
			'form' => array(),
			'call-to-action' => array(),
			'loop-grid' => array(),
		);
	}

	public static function summaries() {
		$out = array();
		$widgets = self::widget_objects();
		foreach ( $widgets as $id => $widget ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || self::is_risky_widget( $id, $widget ) ) { continue; }
			$title = method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : $id;
			$categories = method_exists( $widget, 'get_categories' ) ? array_values( (array) $widget->get_categories() ) : array();
			$out[] = array(
				'id' => $id,
				'title' => wp_strip_all_tags( $title ),
				'categories' => array_slice( array_map( 'sanitize_key', $categories ), 0, 8 ),
				'source' => self::source_for( $widget ),
			);
			if ( count( $out ) >= self::MAX_SUMMARY_WIDGETS ) { break; }
		}

		// Early-boot/test fallback so the agent can still reason about core widgets.
		if ( empty( $out ) ) {
			foreach ( array_keys( self::core_fallbacks() ) as $id ) {
				$out[] = array( 'id' => $id, 'title' => ucwords( str_replace( '-', ' ', $id ) ), 'categories' => array( 'basic' ), 'source' => 'Elementor core' );
			}
		}
		return $out;
	}

	public static function schema( $widget_id ) {
		$widget_id = sanitize_key( (string) $widget_id );
		$controls  = array();
		$widget    = self::widget_object( $widget_id );

		if ( $widget && method_exists( $widget, 'get_controls' ) ) {
			try {
				foreach ( (array) $widget->get_controls() as $name => $control ) {
					if ( ! is_array( $control ) || count( $controls ) >= self::MAX_CONTROLS ) { continue; }
					$name = sanitize_key( (string) $name );
					if ( '' === $name || 0 === strpos( $name, '_' ) ) { continue; }
					$type = sanitize_key( (string) ( $control['type'] ?? '' ) );
					if ( in_array( $type, array( 'section', 'tab', 'raw_html', 'popover_toggle', 'hidden' ), true ) ) { continue; }
					$row = array(
						'type' => $type ?: 'text',
						'label' => sanitize_text_field( (string) ( $control['label'] ?? $name ) ),
					);
					if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
						$row['options'] = array_slice( array_map( 'strval', array_keys( $control['options'] ) ), 0, 24 );
					}
					$controls[ $name ] = $row;
				}
			} catch ( \Throwable $e ) {
				$controls = array();
			}
		}

		if ( empty( $controls ) ) {
			$fallback = self::core_fallbacks();
			$controls = isset( $fallback[ $widget_id ] ) ? $fallback[ $widget_id ] : array();
		}

		return array(
			'id' => $widget_id,
			'title' => $widget && method_exists( $widget, 'get_title' ) ? wp_strip_all_tags( (string) $widget->get_title() ) : ucwords( str_replace( '-', ' ', $widget_id ) ),
			'source' => $widget ? self::source_for( $widget ) : 'Elementor core',
			'controls' => $controls,
		);
	}

	public static function control( $widget_id, $control_name ) {
		$schema = self::schema( $widget_id );
		$key = sanitize_key( (string) $control_name );
		return isset( $schema['controls'][ $key ] ) ? $schema['controls'][ $key ] : null;
	}

	/**
	 * Compact widget payload for the design-model prompt. Core widgets are
	 * always included, then installed add-ons are ranked against the user's
	 * visual prompt and useful design-role terms.
	 */
	public static function agent_catalog( $prompt = '' ) {
		$summaries = self::summaries();
		$core = array( 'heading', 'text-editor', 'button', 'image', 'icon-list', 'accordion', 'counter', 'image-box', 'testimonial', 'video', 'image-carousel', 'google_maps', 'form', 'call-to-action', 'loop-grid' );
		$tokens = preg_split( '/[^a-z0-9]+/i', strtolower( (string) $prompt . ' form testimonial carousel gallery pricing cards accordion map tabs icon feature CTA' ), -1, PREG_SPLIT_NO_EMPTY );
		$tokens = array_values( array_unique( $tokens ) );

		foreach ( $summaries as &$row ) {
			$hay = strtolower( $row['id'] . ' ' . $row['title'] . ' ' . implode( ' ', (array) $row['categories'] ) );
			$score = in_array( $row['id'], $core, true ) ? 100 : 0;
			foreach ( $tokens as $t ) {
				if ( strlen( $t ) >= 3 && false !== strpos( $hay, $t ) ) { $score += 8; }
			}
			$row['_score'] = $score;
		}
		unset( $row );
		usort( $summaries, function ( $a, $b ) {
			if ( $a['_score'] === $b['_score'] ) { return strcmp( $a['id'], $b['id'] ); }
			return $b['_score'] <=> $a['_score'];
		} );

		$selected = array_slice( $summaries, 0, self::MAX_SCHEMA_WIDGETS );
		$schemas = array();
		foreach ( $selected as $row ) {
			$schemas[ $row['id'] ] = self::schema( $row['id'] );
			unset( $row['_score'] );
		}
		foreach ( $summaries as &$row ) { unset( $row['_score'] ); }
		unset( $row );

		return array(
			'available' => $summaries,
			'schemas' => $schemas,
		);
	}

	public static function supports( $widget_id ) {
		$widget_id = sanitize_key( (string) $widget_id );
		if ( '' === $widget_id || self::is_risky_widget( $widget_id ) ) { return false; }
		if ( class_exists( 'SCC_Elementor_Capabilities' ) && SCC_Elementor_Capabilities::supports_widget( $widget_id ) ) {
			return true;
		}
		return isset( self::core_fallbacks()[ $widget_id ] );
	}

	public static function is_risky_widget( $widget_id, $widget = null ) {
		$id = strtolower( (string) $widget_id );
		$title = ( $widget && method_exists( $widget, 'get_title' ) ) ? strtolower( wp_strip_all_tags( (string) $widget->get_title() ) ) : '';
		$hay = $id . ' ' . $title;
		foreach ( array( 'html', 'shortcode', 'code', 'script', 'template', 'sidebar', 'wp-widget', 'wordpress-widget' ) as $term ) {
			if ( false !== strpos( $hay, $term ) ) { return true; }
		}
		return false;
	}

	protected static function widget_objects() {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) { return array(); }
		try {
			$plugin = \Elementor\Plugin::$instance;
			if ( $plugin && isset( $plugin->widgets_manager ) && is_object( $plugin->widgets_manager ) && method_exists( $plugin->widgets_manager, 'get_widget_types' ) ) {
				return (array) $plugin->widgets_manager->get_widget_types();
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return array();
	}

	protected static function widget_object( $widget_id ) {
		$all = self::widget_objects();
		return isset( $all[ $widget_id ] ) && is_object( $all[ $widget_id ] ) ? $all[ $widget_id ] : null;
	}

	protected static function source_for( $widget ) {
		if ( ! is_object( $widget ) ) { return 'unknown'; }
		$class = get_class( $widget );
		if ( 0 === strpos( $class, 'ElementorPro\\' ) ) { return 'Elementor Pro'; }
		if ( 0 === strpos( $class, 'Elementor\\' ) ) { return 'Elementor core'; }
		$parts = explode( '\\', trim( $class, '\\' ) );
		return sanitize_text_field( (string) ( $parts[0] ?? 'third-party add-on' ) );
	}
}
