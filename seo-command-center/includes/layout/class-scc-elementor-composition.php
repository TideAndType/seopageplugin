<?php
/**
 * Controlled Elementor composition DSL validator + compiler.
 *
 * The AI may describe nested containers, real installed widgets, styling and
 * responsive behavior, but this class is the only code allowed to turn that
 * description into Elementor document data.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Elementor_Composition {

	const VERSION   = 1;
	const MAX_ROOTS = 24;
	const MAX_NODES = 140;
	const MAX_DEPTH = 8;

	public static function validate( $composition, array $bank ) {
		if ( ! is_array( $composition ) ) {
			return new WP_Error( 'scc_composition_invalid', __( 'The design agent did not return a composition object.', 'seo-command-center' ) );
		}
		$nodes = isset( $composition['nodes'] ) && is_array( $composition['nodes'] ) ? $composition['nodes'] : array();
		if ( empty( $nodes ) || count( $nodes ) > self::MAX_ROOTS ) {
			return new WP_Error( 'scc_composition_roots', __( 'The design composition has an invalid number of root sections.', 'seo-command-center' ) );
		}

		$state = array(
			'count' => 0,
			'ids' => array(),
			'refs' => array(),
		);
		$clean = array();
		foreach ( $nodes as $node ) {
			$result = self::clean_node( $node, $bank, $state, 0, true );
			if ( is_wp_error( $result ) ) { return $result; }
			$clean[] = $result;
		}

		$missing = array_values( array_diff( SCC_Elementor_Content_Bank::required_keys( $bank ), array_keys( $state['refs'] ) ) );
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'scc_composition_missing_content',
				sprintf(
					/* translators: %s: comma-separated content reference keys */
					__( 'The design omitted required finished content: %s', 'seo-command-center' ),
					implode( ', ', array_slice( $missing, 0, 12 ) )
				),
				array( 'missing_refs' => $missing )
			);
		}
		if ( isset( $bank['hero.title'] ) && 1 !== (int) ( $state['refs']['hero.title'] ?? 0 ) ) {
			return new WP_Error( 'scc_composition_h1', __( 'The design must use the page headline exactly once.', 'seo-command-center' ) );
		}

		return array(
			'version' => self::VERSION,
			'name' => sanitize_text_field( (string) ( $composition['name'] ?? 'AI Elementor composition' ) ),
			'nodes' => $clean,
			'meta' => array(
				'node_count' => (int) $state['count'],
				'content_refs' => array_keys( $state['refs'] ),
			),
		);
	}

	protected static function clean_node( $node, array $bank, array &$state, $depth, $root = false ) {
		if ( ! is_array( $node ) || $depth > self::MAX_DEPTH ) {
			return new WP_Error( 'scc_composition_depth', __( 'The composition is malformed or nested too deeply.', 'seo-command-center' ) );
		}
		$state['count']++;
		if ( $state['count'] > self::MAX_NODES ) {
			return new WP_Error( 'scc_composition_size', __( 'The composition contains too many elements.', 'seo-command-center' ) );
		}

		$type = sanitize_key( (string) ( $node['type'] ?? '' ) );
		if ( ! in_array( $type, array( 'container', 'widget', 'collection' ), true ) ) {
			return new WP_Error( 'scc_composition_type', __( 'The composition contains an unsupported element type.', 'seo-command-center' ) );
		}
		if ( $root && 'widget' === $type ) {
			return new WP_Error( 'scc_composition_root_widget', __( 'Root-level widgets must be wrapped in a container.', 'seo-command-center' ) );
		}

		$id = sanitize_key( (string) ( $node['id'] ?? '' ) );
		if ( '' === $id ) { $id = $type . '-' . $state['count']; }
		if ( isset( $state['ids'][ $id ] ) ) {
			return new WP_Error( 'scc_composition_duplicate_id', sprintf( __( 'The composition repeats element id %s.', 'seo-command-center' ), $id ) );
		}
		$state['ids'][ $id ] = true;

		$base = array(
			'id' => $id,
			'type' => $type,
			'label' => sanitize_text_field( (string) ( $node['label'] ?? ucwords( str_replace( '-', ' ', $id ) ) ) ),
		);

		if ( 'container' === $type ) {
			$base['layout'] = self::clean_layout( $node['layout'] ?? array() );
			$base['style'] = self::clean_style( $node['style'] ?? array() );
			$base['responsive'] = self::clean_responsive( $node['responsive'] ?? array() );
			$base['children'] = array();
			foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
				$clean_child = self::clean_node( $child, $bank, $state, $depth + 1, false );
				if ( is_wp_error( $clean_child ) ) { return $clean_child; }
				$base['children'][] = $clean_child;
			}
			if ( empty( $base['children'] ) ) {
				return new WP_Error( 'scc_composition_empty_container', sprintf( __( 'Container %s is empty.', 'seo-command-center' ), $id ) );
			}
			return $base;
		}

		if ( 'collection' === $type ) {
			$ref = (string) ( $node['collection'] ?? '' );
			$entry = SCC_Elementor_Content_Bank::get( $bank, $ref );
			if ( ! $entry || 'collection' !== (string) ( $entry['type'] ?? '' ) ) {
				return new WP_Error( 'scc_composition_collection', sprintf( __( 'Collection %s is not available.', 'seo-command-center' ), $ref ) );
			}
			$state['refs'][ $ref ] = 1 + (int) ( $state['refs'][ $ref ] ?? 0 );
			$base['collection'] = $ref;
			$base['layout'] = self::clean_layout( $node['layout'] ?? array(), true );
			$base['style'] = self::clean_style( $node['style'] ?? array() );
			$base['item_style'] = self::clean_style( $node['item_style'] ?? array() );
			$base['responsive'] = self::clean_responsive( $node['responsive'] ?? array() );
			return $base;
		}

		$widget = sanitize_key( (string) ( $node['widget'] ?? '' ) );
		if ( '' === $widget || ! class_exists( 'SCC_Elementor_Widget_Schema' ) || ! SCC_Elementor_Widget_Schema::supports( $widget ) ) {
			return new WP_Error( 'scc_composition_widget', sprintf( __( 'Widget %s is not safely available on this site.', 'seo-command-center' ), $widget ?: '?' ) );
		}
		$schema = SCC_Elementor_Widget_Schema::schema( $widget );
		$controls = (array) ( $schema['controls'] ?? array() );
		$base['widget'] = $widget;
		$base['settings'] = array();

		foreach ( (array) ( $node['settings'] ?? array() ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! isset( $controls[ $key ] ) ) {
				return new WP_Error( 'scc_composition_setting', sprintf( __( 'Setting %1$s is not valid for widget %2$s.', 'seo-command-center' ), $key, $widget ) );
			}
			$control = (array) $controls[ $key ];
			if ( self::content_control( $control ) ) {
				return new WP_Error( 'scc_composition_literal_copy', sprintf( __( 'Content setting %s must use a content-bank binding instead of model-written copy.', 'seo-command-center' ), $key ) );
			}
			$base['settings'][ $key ] = self::clean_control_value( $value, $control );
		}

		$base['bindings'] = array();
		foreach ( (array) ( $node['bindings'] ?? array() ) as $key => $ref ) {
			$key = sanitize_key( (string) $key );
			$ref = (string) $ref;
			if ( '' === $key || ! isset( $controls[ $key ] ) ) {
				return new WP_Error( 'scc_composition_binding_control', sprintf( __( 'Binding target %1$s is not a valid control for %2$s.', 'seo-command-center' ), $key, $widget ) );
			}
			$entry = SCC_Elementor_Content_Bank::get( $bank, $ref );
			if ( ! $entry || 'collection' === (string) ( $entry['type'] ?? '' ) ) {
				return new WP_Error( 'scc_composition_binding_ref', sprintf( __( 'Content reference %s is not available for a widget binding.', 'seo-command-center' ), $ref ) );
			}
			$base['bindings'][ $key ] = $ref;
			$state['refs'][ $ref ] = 1 + (int) ( $state['refs'][ $ref ] ?? 0 );
		}
		if ( empty( $base['bindings'] ) && empty( $base['settings'] ) ) {
			return new WP_Error( 'scc_composition_empty_widget', sprintf( __( 'Widget %s has no usable settings or content binding.', 'seo-command-center' ), $id ) );
		}

		$base['style'] = self::clean_style( $node['style'] ?? array() );
		$base['responsive'] = self::clean_responsive( $node['responsive'] ?? array() );
		return $base;
	}

	protected static function content_control( array $control ) {
		return in_array( sanitize_key( (string) ( $control['type'] ?? '' ) ), array( 'text', 'textarea', 'wysiwyg', 'url', 'media', 'repeater', 'code' ), true );
	}

	protected static function clean_control_value( $value, array $control ) {
		$type = sanitize_key( (string) ( $control['type'] ?? '' ) );
		if ( in_array( $type, array( 'number', 'slider' ), true ) && is_numeric( $value ) ) {
			return (float) $value;
		}
		if ( 'switcher' === $type ) {
			return ! empty( $value ) && ! in_array( $value, array( 'no', 'false', '0' ), true ) ? 'yes' : '';
		}
		if ( isset( $control['options'] ) && is_array( $control['options'] ) && ! empty( $control['options'] ) ) {
			$value = (string) $value;
			return in_array( $value, $control['options'], true ) ? $value : reset( $control['options'] );
		}
		if ( is_scalar( $value ) ) { return sanitize_text_field( (string) $value ); }
		return '';
	}

	protected static function clean_layout( $layout, $collection = false ) {
		$layout = is_array( $layout ) ? $layout : array();
		$out = array();
		$enums = array(
			'direction' => array( 'row', 'column', 'row-reverse', 'column-reverse' ),
			'justify' => array( 'flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly' ),
			'align' => array( 'stretch', 'flex-start', 'center', 'flex-end' ),
			'wrap' => array( 'nowrap', 'wrap', 'wrap-reverse' ),
			'content_width' => array( 'full', 'boxed' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $layout[ $key ] ) && in_array( (string) $layout[ $key ], $allowed, true ) ) { $out[ $key ] = (string) $layout[ $key ]; }
		}
		foreach ( array( 'gap' => array( 0, 160 ), 'max_width' => array( 320, 1920 ), 'min_height' => array( 0, 1400 ) ) as $key => $range ) {
			if ( isset( $layout[ $key ] ) && is_numeric( $layout[ $key ] ) ) {
				$out[ $key ] = max( $range[0], min( $range[1], (float) $layout[ $key ] ) );
			}
		}
		if ( isset( $layout['width'] ) && ( is_numeric( $layout['width'] ) || is_string( $layout['width'] ) ) ) {
			$out['width'] = self::clean_size_literal( $layout['width'] );
		}
		if ( $collection && isset( $layout['columns'] ) ) {
			$out['columns'] = max( 1, min( 6, (int) $layout['columns'] ) );
		}
		return $out;
	}

	protected static function clean_style( $style ) {
		$style = is_array( $style ) ? $style : array();
		$out = array();
		foreach ( array( 'background', 'background_color', 'color', 'border_color' ) as $key ) {
			if ( isset( $style[ $key ] ) && is_scalar( $style[ $key ] ) ) {
				$out[ $key ] = substr( sanitize_text_field( (string) $style[ $key ] ), 0, 96 );
			}
		}
		foreach ( array(
			'gap' => array( 0, 160 ),
			'border_width' => array( 0, 24 ),
			'border_radius' => array( 0, 120 ),
			'font_size' => array( 8, 180 ),
			'font_weight' => array( 100, 900 ),
			'line_height' => array( 0.8, 4 ),
			'letter_spacing' => array( -12, 32 ),
			'opacity' => array( 0, 1 ),
			'z_index' => array( -10, 999 ),
		) as $key => $range ) {
			if ( isset( $style[ $key ] ) && is_numeric( $style[ $key ] ) ) {
				$out[ $key ] = max( $range[0], min( $range[1], (float) $style[ $key ] ) );
			}
		}
		foreach ( array( 'padding', 'margin' ) as $key ) {
			if ( isset( $style[ $key ] ) ) {
				$dims = self::clean_dims( $style[ $key ] );
				if ( $dims ) { $out[ $key ] = $dims; }
			}
		}
		if ( isset( $style['width'] ) ) { $out['width'] = self::clean_size_literal( $style['width'] ); }
		if ( isset( $style['text_align'] ) && in_array( (string) $style['text_align'], array( 'left', 'center', 'right', 'justify' ), true ) ) {
			$out['text_align'] = (string) $style['text_align'];
		}
		if ( isset( $style['border_style'] ) && in_array( (string) $style['border_style'], array( 'solid', 'dashed', 'dotted', 'double', 'none' ), true ) ) {
			$out['border_style'] = (string) $style['border_style'];
		}
		if ( isset( $style['overflow'] ) && in_array( (string) $style['overflow'], array( 'visible', 'hidden', 'auto' ), true ) ) {
			$out['overflow'] = (string) $style['overflow'];
		}
		if ( isset( $style['box_shadow'] ) && is_array( $style['box_shadow'] ) ) {
			$shadow = $style['box_shadow'];
			$out['box_shadow'] = array(
				'horizontal' => max( -100, min( 100, (float) ( $shadow['x'] ?? 0 ) ) ),
				'vertical' => max( -100, min( 100, (float) ( $shadow['y'] ?? 18 ) ) ),
				'blur' => max( 0, min( 160, (float) ( $shadow['blur'] ?? 50 ) ) ),
				'spread' => max( -60, min( 80, (float) ( $shadow['spread'] ?? -20 ) ) ),
				'color' => substr( sanitize_text_field( (string) ( $shadow['color'] ?? 'rgba(0,0,0,.18)' ) ), 0, 96 ),
			);
		}
		return $out;
	}

	protected static function clean_responsive( $responsive ) {
		$responsive = is_array( $responsive ) ? $responsive : array();
		$out = array();
		foreach ( array( 'tablet', 'mobile' ) as $device ) {
			if ( empty( $responsive[ $device ] ) || ! is_array( $responsive[ $device ] ) ) { continue; }
			$out[ $device ] = array(
				'layout' => self::clean_layout( $responsive[ $device ]['layout'] ?? array(), true ),
				'style' => self::clean_style( $responsive[ $device ]['style'] ?? array() ),
			);
		}
		return $out;
	}

	protected static function clean_dims( $value ) {
		if ( is_numeric( $value ) ) {
			$v = max( -200, min( 300, (float) $value ) );
			return array( $v, $v, $v, $v );
		}
		if ( is_array( $value ) ) {
			$vals = array_values( $value );
			if ( empty( $vals ) ) { return null; }
			$vals = array_map( function ( $v ) { return max( -200, min( 300, is_numeric( $v ) ? (float) $v : 0 ) ); }, array_slice( $vals, 0, 4 ) );
			if ( 1 === count( $vals ) ) { return array( $vals[0], $vals[0], $vals[0], $vals[0] ); }
			if ( 2 === count( $vals ) ) { return array( $vals[0], $vals[1], $vals[0], $vals[1] ); }
			if ( 3 === count( $vals ) ) { return array( $vals[0], $vals[1], $vals[2], $vals[1] ); }
			return array( $vals[0], $vals[1], $vals[2], $vals[3] );
		}
		return null;
	}

	protected static function clean_size_literal( $value ) {
		if ( is_numeric( $value ) ) { return (float) $value; }
		$value = trim( (string) $value );
		return preg_match( '/^-?[0-9]+(?:\.[0-9]+)?(?:px|%|rem|em|vw|vh)$/', $value ) ? $value : '';
	}

	/* ------------------------------------------------------------------ */

	public static function compile( array $composition, array $bank, array $profile = array() ) {
		if ( empty( $profile ) && class_exists( 'SCC_Design_Intel' ) ) { $profile = SCC_Design_Intel::profile(); }
		$out = array();
		foreach ( (array) ( $composition['nodes'] ?? array() ) as $node ) {
			$compiled = self::compile_node( $node, $bank, $profile );
			if ( $compiled ) { $out[] = $compiled; }
		}
		return $out;
	}

	protected static function compile_node( array $node, array $bank, array $profile ) {
		$type = (string) ( $node['type'] ?? '' );
		if ( 'container' === $type ) {
			$children = array();
			foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
				$c = self::compile_node( $child, $bank, $profile );
				if ( $c ) { $children[] = $c; }
			}
			return self::elementor_container( $children, $node, $profile );
		}
		if ( 'collection' === $type ) {
			return self::compile_collection( $node, $bank, $profile );
		}
		if ( 'widget' === $type ) {
			return self::compile_widget( $node, $bank, $profile );
		}
		return null;
	}

	protected static function compile_widget( array $node, array $bank, array $profile ) {
		$widget = (string) ( $node['widget'] ?? '' );
		$settings = (array) ( $node['settings'] ?? array() );
		foreach ( (array) ( $node['bindings'] ?? array() ) as $control_name => $ref ) {
			$entry = SCC_Elementor_Content_Bank::get( $bank, $ref );
			if ( ! $entry ) { continue; }
			$control = SCC_Elementor_Widget_Schema::control( $widget, $control_name );
			$settings[ $control_name ] = self::binding_value( $entry, is_array( $control ) ? $control : array(), $control_name );
		}
		$settings = array_merge( $settings, self::widget_style_settings( $widget, (array) ( $node['style'] ?? array() ), $profile ) );
		foreach ( (array) ( $node['responsive'] ?? array() ) as $device => $cfg ) {
			$settings = array_merge( $settings, self::widget_style_settings( $widget, (array) ( $cfg['style'] ?? array() ), $profile, '_' . $device ) );
		}
		return array(
			'id' => self::new_id(),
			'elType' => 'widget',
			'widgetType' => $widget,
			'settings' => $settings,
			'elements' => array(),
		);
	}

	protected static function binding_value( array $entry, array $control, $control_name ) {
		$type = (string) ( $entry['type'] ?? 'text' );
		$value = $entry['value'] ?? '';
		$control_type = sanitize_key( (string) ( $control['type'] ?? '' ) );
		if ( 'image' === $type || 'media' === $control_type ) {
			$image = is_array( $value ) ? $value : array( 'url' => (string) $value, 'id' => 0 );
			return array( 'url' => esc_url_raw( (string) ( $image['url'] ?? '' ) ), 'id' => (int) ( $image['id'] ?? 0 ) );
		}
		if ( 'url' === $type || 'url' === $control_type || 'link' === $control_name ) {
			return array( 'url' => esc_url_raw( (string) $value ), 'is_external' => '', 'nofollow' => '' );
		}
		if ( 'wysiwyg' === $control_type || 'textarea' === $control_type || 'editor' === $control_name ) {
			return 'html' === $type ? wp_kses_post( (string) $value ) : (string) $value;
		}
		if ( 'number' === $control_type && is_numeric( $value ) ) { return (float) $value; }
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	protected static function elementor_container( array $children, array $node, array $profile ) {
		$settings = self::container_layout_settings( (array) ( $node['layout'] ?? array() ) );
		$settings = array_merge( $settings, self::container_style_settings( (array) ( $node['style'] ?? array() ), $profile ) );
		foreach ( (array) ( $node['responsive'] ?? array() ) as $device => $cfg ) {
			$suffix = '_' . $device;
			$settings = array_merge( $settings, self::suffix_settings( self::container_layout_settings( (array) ( $cfg['layout'] ?? array() ) ), $suffix ) );
			$settings = array_merge( $settings, self::suffix_settings( self::container_style_settings( (array) ( $cfg['style'] ?? array() ), $profile ), $suffix ) );
		}
		return array(
			'id' => self::new_id(),
			'elType' => 'container',
			'settings' => $settings,
			'elements' => array_values( array_filter( $children ) ),
			'isInner' => false,
		);
	}

	protected static function container_layout_settings( array $layout ) {
		$out = array();
		if ( isset( $layout['content_width'] ) ) { $out['content_width'] = $layout['content_width']; }
		if ( isset( $layout['direction'] ) ) { $out['flex_direction'] = $layout['direction']; }
		if ( isset( $layout['justify'] ) ) { $out['flex_justify_content'] = $layout['justify']; }
		if ( isset( $layout['align'] ) ) { $out['flex_align_items'] = $layout['align']; }
		if ( isset( $layout['wrap'] ) ) { $out['flex_wrap'] = $layout['wrap']; }
		if ( isset( $layout['gap'] ) ) { $out['flex_gap'] = self::gap( $layout['gap'] ); }
		if ( isset( $layout['width'] ) && '' !== $layout['width'] ) { $out['width'] = self::size( $layout['width'], '%' ); }
		if ( isset( $layout['max_width'] ) ) { $out['boxed_width'] = self::size( $layout['max_width'], 'px' ); $out['content_width'] = 'boxed'; }
		if ( isset( $layout['min_height'] ) ) { $out['min_height'] = self::size( $layout['min_height'], 'px' ); }
		return $out;
	}

	protected static function container_style_settings( array $style, array $profile ) {
		$out = array();
		$background = $style['background_color'] ?? $style['background'] ?? '';
		if ( '' !== (string) $background ) {
			$out['background_background'] = 'classic';
			$out['background_color'] = self::color( $background, $profile );
		}
		if ( isset( $style['padding'] ) ) { $out['padding'] = self::dims( $style['padding'] ); }
		if ( isset( $style['margin'] ) ) { $out['margin'] = self::dims( $style['margin'] ); }
		if ( isset( $style['border_style'] ) && 'none' !== $style['border_style'] ) { $out['border_border'] = $style['border_style']; }
		if ( isset( $style['border_width'] ) ) { $out['border_width'] = self::dims( array( $style['border_width'], $style['border_width'], $style['border_width'], $style['border_width'] ) ); }
		if ( isset( $style['border_color'] ) ) { $out['border_color'] = self::color( $style['border_color'], $profile ); }
		if ( isset( $style['border_radius'] ) ) { $out['border_radius'] = self::dims( array( $style['border_radius'], $style['border_radius'], $style['border_radius'], $style['border_radius'] ) ); }
		if ( isset( $style['box_shadow'] ) ) {
			$out['box_shadow_box_shadow_type'] = 'yes';
			$out['box_shadow_box_shadow'] = array_merge( $style['box_shadow'], array( 'position' => 'outline' ) );
		}
		if ( isset( $style['overflow'] ) ) { $out['overflow'] = $style['overflow']; }
		if ( isset( $style['opacity'] ) ) { $out['opacity'] = self::size( $style['opacity'], '' ); }
		if ( isset( $style['z_index'] ) ) { $out['z_index'] = (int) $style['z_index']; }
		return $out;
	}

	protected static function widget_style_settings( $widget, array $style, array $profile, $suffix = '' ) {
		$out = array();
		$color = isset( $style['color'] ) ? self::color( $style['color'], $profile ) : '';
		if ( '' !== $color ) {
			if ( 'heading' === $widget ) { $out['title_color' . $suffix] = $color; }
			elseif ( 'text-editor' === $widget ) { $out['text_color' . $suffix] = $color; }
			elseif ( 'button' === $widget ) { $out['button_text_color' . $suffix] = $color; }
			else { $out['_color' . $suffix] = $color; }
		}
		$background = $style['background_color'] ?? $style['background'] ?? '';
		if ( '' !== (string) $background ) {
			if ( 'button' === $widget ) { $out['background_color' . $suffix] = self::color( $background, $profile ); }
			else { $out['_background_background' . $suffix] = 'classic'; $out['_background_color' . $suffix] = self::color( $background, $profile ); }
		}
		if ( isset( $style['text_align'] ) ) { $out['align' . $suffix] = $style['text_align']; }
		if ( isset( $style['font_size'] ) ) { $out['typography_typography'] = 'custom'; $out['typography_font_size' . $suffix] = self::size( $style['font_size'], 'px' ); }
		if ( isset( $style['font_weight'] ) ) { $out['typography_typography'] = 'custom'; $out['typography_font_weight' . $suffix] = (string) (int) $style['font_weight']; }
		if ( isset( $style['line_height'] ) ) { $out['typography_typography'] = 'custom'; $out['typography_line_height' . $suffix] = self::size( $style['line_height'], 'em' ); }
		if ( isset( $style['letter_spacing'] ) ) { $out['typography_typography'] = 'custom'; $out['typography_letter_spacing' . $suffix] = self::size( $style['letter_spacing'], 'px' ); }
		if ( isset( $style['padding'] ) ) { $out['_padding' . $suffix] = self::dims( $style['padding'] ); }
		if ( isset( $style['margin'] ) ) { $out['_margin' . $suffix] = self::dims( $style['margin'] ); }
		if ( isset( $style['border_radius'] ) ) {
			$key = 'button' === $widget ? 'border_radius' : ( 'image' === $widget ? 'image_border_radius' : '_border_radius' );
			$out[ $key . $suffix ] = self::dims( array_fill( 0, 4, $style['border_radius'] ) );
		}
		if ( isset( $style['width'] ) && '' !== $style['width'] ) {
			$key = 'image' === $widget ? 'width' : '_element_width';
			$out[ $key . $suffix ] = self::size( $style['width'], '%' );
		}
		return $out;
	}

	protected static function compile_collection( array $node, array $bank, array $profile ) {
		$entry = SCC_Elementor_Content_Bank::get( $bank, (string) $node['collection'] );
		$items = $entry ? array_values( (array) ( $entry['value'] ?? array() ) ) : array();
		$kind = (string) ( $entry['meta']['collection'] ?? '' );
		$layout = (array) ( $node['layout'] ?? array() );
		$columns = max( 1, min( 6, (int) ( $layout['columns'] ?? ( 'stats' === $kind ? min( 4, max( 1, count( $items ) ) ) : 3 ) ) ) );
		$children = array();

		if ( 'faq' === $kind && SCC_Elementor_Widget_Schema::supports( 'accordion' ) ) {
			$tabs = array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) { continue; }
				$q = trim( (string) ( $item['question'] ?? '' ) );
				$a = trim( (string) ( $item['answer'] ?? '' ) );
				if ( '' === $q || '' === $a ) { continue; }
				$tabs[] = array( '_id' => self::new_id(), 'tab_title' => $q, 'tab_content' => wp_kses_post( wpautop( $a ) ) );
			}
			if ( ! empty( $tabs ) ) {
				$children[] = array(
					'id' => self::new_id(), 'elType' => 'widget', 'widgetType' => 'accordion',
					'settings' => array( 'tabs' => $tabs, 'title_html_tag' => 'h3', 'faq_schema' => '' ),
					'elements' => array(),
				);
			}
		} else {
			foreach ( $items as $i => $item ) {
				$card_children = self::collection_item_widgets( $kind, $item, $i, $profile );
				if ( empty( $card_children ) ) { continue; }
				$card = array(
					'id' => self::new_id(),
					'elType' => 'container',
					'settings' => array_merge(
						array(
							'content_width' => 'full',
							'flex_direction' => 'column',
							'flex_gap' => self::gap( 14 ),
							'width' => self::size( 100 / $columns, '%' ),
							'width_mobile' => self::size( 100, '%' ),
							'padding' => self::dims( array( 28, 28, 28, 28 ) ),
							'background_background' => 'classic',
							'background_color' => self::color( 'card', $profile ),
							'border_border' => 'solid',
							'border_width' => self::dims( array( 1, 1, 1, 1 ) ),
							'border_color' => self::color( 'border', $profile ),
							'border_radius' => self::dims( array( 16, 16, 16, 16 ) ),
						),
						self::container_style_settings( (array) ( $node['item_style'] ?? array() ), $profile )
					),
					'elements' => $card_children,
					'isInner' => true,
				);
				$children[] = $card;
			}
		}

		$wrapper = $node;
		$wrapper['layout'] = array_merge( array(
			'direction' => 'row',
			'wrap' => 'wrap',
			'gap' => (float) ( $layout['gap'] ?? 24 ),
			'content_width' => 'boxed',
		), $layout );
		if ( 'faq' === $kind ) { $wrapper['layout']['direction'] = 'column'; }
		return self::elementor_container( $children, $wrapper, $profile );
	}

	protected static function collection_item_widgets( $kind, $item, $index, array $profile ) {
		if ( ! is_array( $item ) ) { $item = array( 'title' => (string) $item ); }
		$out = array();
		$heading = '';
		$body = '';
		$url = '';

		if ( 'stats' === $kind ) {
			$value = trim( (string) ( $item['value'] ?? '' ) );
			$label = trim( (string) ( $item['label'] ?? '' ) );
			$parsed = self::parse_number( $value );
			if ( $parsed && SCC_Elementor_Widget_Schema::supports( 'counter' ) ) {
				$out[] = array(
					'id' => self::new_id(), 'elType' => 'widget', 'widgetType' => 'counter',
					'settings' => array(
						'starting_number' => 0,
						'ending_number' => $parsed['number'],
						'prefix' => $parsed['prefix'],
						'suffix' => $parsed['suffix'],
						'title' => $label,
						'duration' => 1200,
					),
					'elements' => array(),
				);
				return $out;
			}
			$heading = $value;
			$body = $label;
		} elseif ( 'steps' === $kind ) {
			$title = trim( (string) ( $item['title'] ?? '' ) );
			$heading = sprintf( '%02d — %s', $index + 1, $title );
			$body = trim( (string) ( $item['description'] ?? '' ) );
		} elseif ( 'services' === $kind ) {
			$heading = trim( (string) ( $item['title'] ?? '' ) );
			$body = trim( (string) ( $item['description'] ?? '' ) );
			$url = esc_url_raw( (string) ( $item['url'] ?? '' ) );
		} elseif ( 'related' === $kind || 'areas' === $kind ) {
			$heading = trim( (string) ( $item['title'] ?? $item['name'] ?? '' ) );
			$url = esc_url_raw( (string) ( $item['url'] ?? '' ) );
		} elseif ( 'faq' === $kind ) {
			$heading = trim( (string) ( $item['question'] ?? '' ) );
			$body = trim( (string) ( $item['answer'] ?? '' ) );
		}

		if ( '' !== $heading && SCC_Elementor_Widget_Schema::supports( 'heading' ) ) {
			$out[] = array(
				'id' => self::new_id(), 'elType' => 'widget', 'widgetType' => 'heading',
				'settings' => array( 'title' => $heading, 'header_size' => 'h3', 'title_color' => self::color( 'heading', $profile ) ),
				'elements' => array(),
			);
		}
		if ( '' !== $body && SCC_Elementor_Widget_Schema::supports( 'text-editor' ) ) {
			$out[] = array(
				'id' => self::new_id(), 'elType' => 'widget', 'widgetType' => 'text-editor',
				'settings' => array( 'editor' => wpautop( wp_kses_post( $body ) ), 'text_color' => self::color( 'text', $profile ) ),
				'elements' => array(),
			);
		}
		if ( '' !== $url && '' !== $heading && SCC_Elementor_Widget_Schema::supports( 'button' ) ) {
			$out[] = array(
				'id' => self::new_id(), 'elType' => 'widget', 'widgetType' => 'button',
				'settings' => array(
					'text' => $heading,
					'link' => array( 'url' => $url, 'is_external' => '', 'nofollow' => '' ),
					'background_color' => self::color( 'primary', $profile ),
				),
				'elements' => array(),
			);
		}
		return $out;
	}

	public static function preview_sections( array $composition ) {
		$out = array();
		foreach ( (array) ( $composition['nodes'] ?? array() ) as $node ) {
			if ( ! is_array( $node ) ) { continue; }
			$out[] = array(
				'id' => (string) ( $node['id'] ?? '' ),
				'name' => (string) ( $node['label'] ?? ucwords( str_replace( '-', ' ', (string) ( $node['id'] ?? 'Section' ) ) ) ),
				'type' => (string) ( $node['type'] ?? 'container' ),
			);
		}
		return $out;
	}

	protected static function suffix_settings( array $settings, $suffix ) {
		$out = array();
		foreach ( $settings as $key => $value ) {
			// Some switches are not responsive controls; keeping their base value
			// is safer than inventing an unsupported suffixed key.
			if ( in_array( $key, array( 'content_width', 'background_background', 'border_border', 'box_shadow_box_shadow_type' ), true ) ) { continue; }
			$out[ $key . $suffix ] = $value;
		}
		return $out;
	}

	protected static function color( $value, array $profile ) {
		$value = trim( (string) $value );
		$colors = (array) ( $profile['colors'] ?? array() );
		if ( isset( $colors[ $value ] ) && '' !== (string) $colors[ $value ] ) { return (string) $colors[ $value ]; }
		if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) { return $value; }
		if ( preg_match( '/^(?:rgb|rgba|hsl|hsla)\([0-9.,%\s-]+\)$/i', $value ) ) { return $value; }
		return (string) ( $colors['primary'] ?? '#2563eb' );
	}

	protected static function dims( $values ) {
		$values = is_array( $values ) ? array_values( $values ) : array( $values, $values, $values, $values );
		while ( count( $values ) < 4 ) { $values[] = end( $values ); }
		return array(
			'unit' => 'px',
			'top' => (string) (float) $values[0],
			'right' => (string) (float) $values[1],
			'bottom' => (string) (float) $values[2],
			'left' => (string) (float) $values[3],
			'isLinked' => count( array_unique( array_map( 'strval', array_slice( $values, 0, 4 ) ) ) ) === 1,
		);
	}

	protected static function gap( $value ) {
		return array( 'unit' => 'px', 'size' => (float) $value, 'sizes' => array(), 'column' => (string) (float) $value, 'row' => (string) (float) $value, 'isLinked' => true );
	}

	protected static function size( $value, $default_unit = 'px' ) {
		$unit = $default_unit;
		$size = $value;
		if ( is_string( $value ) && preg_match( '/^(-?[0-9]+(?:\.[0-9]+)?)(px|%|rem|em|vw|vh)$/', trim( $value ), $m ) ) {
			$size = (float) $m[1];
			$unit = $m[2];
		} elseif ( is_numeric( $value ) ) {
			$size = (float) $value;
		}
		return array( 'unit' => $unit, 'size' => (float) $size, 'sizes' => array() );
	}

	protected static function parse_number( $value ) {
		$value = trim( wp_strip_all_tags( (string) $value ) );
		if ( ! preg_match( '/^([^0-9\-]*)(-?[0-9][0-9,]*(?:\.[0-9]+)?)(.*)$/', $value, $m ) ) { return null; }
		$number = str_replace( ',', '', $m[2] );
		if ( ! is_numeric( $number ) ) { return null; }
		return array( 'number' => (float) $number, 'prefix' => trim( $m[1] ), 'suffix' => trim( $m[3] ) );
	}

	protected static function new_id() {
		return substr( md5( uniqid( 'scc-agent-', true ) ), 0, 7 );
	}
}
