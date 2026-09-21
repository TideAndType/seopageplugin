<?php
/**
 * Native Elementor block renderer.
 *
 * Emits real Elementor containers + core widgets for supported TideOrbit blocks.
 * It never executes model-provided Elementor JSON. The semantic block/variant
 * plan is validated first, then deterministic PHP constructs the document.
 *
 * Unsupported or unavailable widgets return an empty result so the existing
 * HTML-widget renderer remains a safe fallback.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Native_Elementor_Blocks {

	public static function render_block( array $block, array $context = array() ) {
		if ( ! class_exists( 'SCC_Elementor_Capabilities' ) || ! SCC_Elementor_Capabilities::supports_containers() ) {
			return array();
		}
		$id = sanitize_key( (string) ( $block['id'] ?? '' ) );
		switch ( $id ) {
			case 'hero':            return self::hero( $block );
			case 'content-intro':   return self::intro( $block );
			case 'content':         return self::content( $block );
			case 'benefits':        return self::benefits( $block );
			case 'feature-grid':
			case 'service-grid':
			case 'service-cards':   return self::cards( $block );
			case 'stats':           return self::stats( $block );
			case 'process-steps':   return self::steps( $block );
			case 'faq':             return self::faq( $block );
			case 'related-content':
			case 'blog-grid':
			case 'location-grid':
			case 'service-area':    return self::related( $block );
			case 'cta':             return self::cta( $block );
			case 'comparison':
			case 'highlight-box':
			case 'toc':             return self::generic_text( $block );
			default:                return array();
		}
	}

	protected static function profile() {
		return class_exists( 'SCC_Design_Intel' ) ? SCC_Design_Intel::profile() : array();
	}

	protected static function color( $key, $fallback ) {
		$p = self::profile();
		$v = (string) ( $p['colors'][ $key ] ?? '' );
		return '' !== $v ? $v : $fallback;
	}

	protected static function spacing( $key, $fallback ) {
		$p = self::profile();
		$v = (int) ( $p['spacing'][ $key ] ?? 0 );
		return $v > 0 ? $v : $fallback;
	}

	protected static function id() {
		return substr( md5( uniqid( 'scc', true ) ), 0, 8 );
	}

	protected static function widget( $type, array $settings ) {
		if ( ! SCC_Elementor_Capabilities::supports_widget( $type ) ) {
			return null;
		}
		return array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	protected static function dims( $top, $right, $bottom, $left ) {
		return array( 'unit' => 'px', 'top' => (string) $top, 'right' => (string) $right, 'bottom' => (string) $bottom, 'left' => (string) $left, 'isLinked' => false );
	}

	protected static function gap( $size ) {
		return array( 'unit' => 'px', 'size' => (int) $size, 'sizes' => array() );
	}

	protected static function container( array $children, array $settings = array(), $inner = false ) {
		$children = array_values( array_filter( $children ) );
		return array(
			'id'       => self::id(),
			'elType'   => 'container',
			'settings' => $settings,
			'elements' => $children,
			'isInner'  => (bool) $inner,
		);
	}

	protected static function outer( array $children, $style = 'plain', array $extra = array() ) {
		$bg = '';
		if ( 'tint' === $style ) {
			$bg = self::color( 'surface', '#f7f8fb' );
		} elseif ( 'brand' === $style ) {
			$bg = self::color( 'primary', '#4f46e5' );
		}
		$settings = array(
			'content_width' => 'full',
			'padding'       => self::dims( self::spacing( 'section_y', 64 ), 24, self::spacing( 'section_y', 64 ), 24 ),
		);
		if ( '' !== $bg ) {
			$settings['background_background'] = 'classic';
			$settings['background_color'] = $bg;
		}
		$settings = array_merge( $settings, $extra );
		return self::container( $children, $settings, false );
	}

	protected static function inner( array $children, $direction = 'column', array $extra = array() ) {
		$p = self::profile();
		$width = (int) ( $p['layout']['content_width'] ?? 1140 );
		if ( $width < 760 || $width > 1800 ) { $width = 1140; }
		$settings = array(
			'content_width' => 'boxed',
			'boxed_width'   => array( 'unit' => 'px', 'size' => $width, 'sizes' => array() ),
			'flex_direction'=> $direction,
			'gap'           => self::gap( self::spacing( 'gap', 24 ) ),
		);
		return self::container( $children, array_merge( $settings, $extra ), true );
	}

	protected static function heading( $text, $size = 'h2', $align = '' ) {
		$text = trim( (string) $text );
		if ( '' === $text ) { return null; }
		$s = array( 'title' => $text, 'header_size' => $size );
		if ( '' !== $align ) { $s['align'] = $align; }
		$s['title_color'] = self::color( 'heading', self::color( 'text', '#1f2937' ) );
		return self::widget( 'heading', $s );
	}

	protected static function text( $html, $align = '' ) {
		$html = trim( (string) $html );
		if ( '' === $html ) { return null; }
		$s = array( 'editor' => wp_kses_post( $html ), 'text_color' => self::color( 'text', '#374151' ) );
		if ( '' !== $align ) { $s['align'] = $align; }
		return self::widget( 'text-editor', $s );
	}

	protected static function button( $label, $url, $align = '' ) {
		$label = trim( (string) $label );
		$url   = esc_url_raw( (string) $url );
		if ( '' === $label || '' === $url ) { return null; }
		$s = array(
			'text' => $label,
			'link' => array( 'url' => $url, 'is_external' => '', 'nofollow' => '' ),
			'background_color' => self::color( 'primary', '#4f46e5' ),
		);
		if ( '' !== $align ) { $s['align'] = $align; }
		return self::widget( 'button', $s );
	}

	protected static function image( $image ) {
		$url = '';
		$id  = 0;
		if ( is_array( $image ) ) {
			$url = esc_url_raw( (string) ( $image['url'] ?? '' ) );
			$id  = (int) ( $image['id'] ?? 0 );
		} else {
			$url = esc_url_raw( (string) $image );
		}
		if ( '' === $url ) { return null; }
		return self::widget( 'image', array( 'image' => array( 'url' => $url, 'id' => $id ), 'image_size' => 'large' ) );
	}

	protected static function hero( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$variant = (string) ( $block['variant'] ?? 'centered' );
		$title = self::heading( $v['HERO_TITLE'] ?? '', 'h1', 'left' );
		$eyebrow = ! empty( $v['HERO_EYEBROW'] ) ? self::text( '<strong>' . esc_html( $v['HERO_EYEBROW'] ) . '</strong>' ) : null;
		$sub = self::text( '<p>' . esc_html( (string) ( $v['HERO_SUBTITLE'] ?? '' ) ) . '</p>' );
		$cta = self::button( $v['CTA_TEXT'] ?? '', $v['CTA_URL'] ?? '' );
		$copy = self::container( array( $eyebrow, $title, $sub, $cta ), array( 'flex_direction' => 'column', 'gap' => self::gap( 18 ) ), true );

		if ( 'split-image' === $variant && ! empty( $v['HERO_IMAGE'] ) ) {
			$img = self::image( $v['HERO_IMAGE'] );
			if ( $img ) {
				$media = self::container( array( $img ), array( 'flex_direction' => 'column' ), true );
				$row = self::inner( array( $copy, $media ), 'row', array( 'align_items' => 'center', 'flex_direction_mobile' => 'column' ) );
				return array( self::outer( array( $row ), 'tint' ) );
			}
		}

		$align = 'editorial' === $variant ? 'left' : 'center';
		$copy['settings']['align_items'] = 'center' === $align ? 'center' : 'flex-start';
		return array( self::outer( array( self::inner( array( $copy ), 'column' ) ), 'tint' ) );
	}

	protected static function intro( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		return array( self::outer( array( self::inner( array( self::text( '<p>' . esc_html( (string) ( $v['INTRO'] ?? '' ) ) . '</p>' ) ) ) ) ) );
	}

	protected static function content( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$html = (string) ( $v['CONTENT'] ?? '' );
		if ( '' === trim( $html ) ) { return array(); }

		$sections = class_exists( 'SCC_Block_Elementor_Renderer' ) ? SCC_Block_Elementor_Renderer::split_sections( $html ) : array( array( 'style' => 'plain', 'html' => $html ) );
		$out = array();
		foreach ( $sections as $sec ) {
			$segment = trim( (string) ( $sec['html'] ?? '' ) );
			if ( '' === $segment ) { continue; }
			$children = array();
			if ( preg_match( '#^<h2\\b[^>]*>(.*?)</h2>#is', $segment, $m ) ) {
				$children[] = self::heading( wp_strip_all_tags( $m[1] ), 'h2' );
				$segment = preg_replace( '#^<h2\\b[^>]*>.*?</h2>#is', '', $segment, 1 );
			}
			$children[] = self::text( $segment );
			$out[] = self::outer( array( self::inner( $children ) ), (string) ( $sec['style'] ?? 'plain' ) );
		}
		return $out;
	}

	protected static function benefits( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$items = array();
		foreach ( (array) ( $v['BENEFIT_ITEMS'] ?? array() ) as $item ) {
			$items[] = self::text( '<p><strong>✓</strong> ' . esc_html( is_array( $item ) ? (string) ( $item['text'] ?? '' ) : (string) $item ) . '</p>' );
		}
		if ( empty( $items ) ) { return array(); }
		return array( self::outer( array( self::inner( array_merge( array( self::heading( $v['SECTION_TITLE'] ?? 'Benefits' ) ), $items ) ) ), 'tint' ) );
	}

	protected static function cards( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$cards = array();
		foreach ( (array) ( $v['CARDS'] ?? array() ) as $card ) {
			$title = (string) ( $card['SERVICE_TITLE'] ?? $card['title'] ?? '' );
			$desc  = (string) ( $card['SERVICE_DESCRIPTION'] ?? $card['description'] ?? '' );
			$url   = (string) ( $card['SERVICE_URL'] ?? $card['url'] ?? '' );
			$children = array( self::heading( $title, 'h3' ), self::text( '<p>' . esc_html( $desc ) . '</p>' ) );
			if ( '' !== $url ) { $children[] = self::button( __( 'Learn more', 'seo-command-center' ), $url ); }
			$cards[] = self::container( $children, array(
				'flex_direction' => 'column',
				'gap' => self::gap( 14 ),
				'padding' => self::dims( 28, 28, 28, 28 ),
				'background_background' => 'classic',
				'background_color' => self::color( 'surface', '#ffffff' ),
				'border_border' => 'solid',
				'border_width' => self::dims( 1, 1, 1, 1 ),
				'border_color' => self::color( 'border', '#e5e7eb' ),
				'border_radius' => self::dims( self::spacing( 'radius', 12 ), self::spacing( 'radius', 12 ), self::spacing( 'radius', 12 ), self::spacing( 'radius', 12 ) ),
				'width' => array( 'unit' => '%', 'size' => 31, 'sizes' => array() ),
			), true );
		}
		if ( empty( $cards ) ) { return array(); }
		$grid = self::container( $cards, array( 'flex_direction' => 'row', 'flex_wrap' => 'wrap', 'gap' => self::gap( 24 ) ), true );
		return array( self::outer( array( self::inner( array( self::heading( $v['SECTION_TITLE'] ?? '' ), $grid ) ) ), 'plain' ) );
	}

	protected static function stats( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$items = array();
		foreach ( (array) ( $v['STATS'] ?? array() ) as $stat ) {
			$value = is_array( $stat ) ? (string) ( $stat['value'] ?? $stat['number'] ?? '' ) : '';
			$label = is_array( $stat ) ? (string) ( $stat['label'] ?? $stat['text'] ?? '' ) : (string) $stat;
			$items[] = self::container( array( self::heading( $value, 'h3', 'center' ), self::text( '<p>' . esc_html( $label ) . '</p>', 'center' ) ), array( 'flex_direction' => 'column', 'align_items' => 'center', 'gap' => self::gap( 8 ) ), true );
		}
		if ( empty( $items ) ) { return array(); }
		$row = self::container( $items, array( 'flex_direction' => 'row', 'flex_wrap' => 'wrap', 'justify_content' => 'space-between', 'gap' => self::gap( 24 ) ), true );
		return array( self::outer( array( self::inner( array( self::heading( $v['SECTION_TITLE'] ?? '', 'h2', 'center' ), $row ) ) ), 'tint' ) );
	}

	protected static function steps( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$items = array();
		$i = 1;
		foreach ( (array) ( $v['STEPS'] ?? array() ) as $step ) {
			$title = is_array( $step ) ? (string) ( $step['title'] ?? $step['name'] ?? '' ) : '';
			$text  = is_array( $step ) ? (string) ( $step['description'] ?? $step['text'] ?? '' ) : (string) $step;
			$items[] = self::container( array(
				self::heading( sprintf( '%02d', $i ), 'h3' ),
				'' !== $title ? self::heading( $title, 'h3' ) : null,
				self::text( '<p>' . esc_html( $text ) . '</p>' ),
			), array( 'flex_direction' => 'column', 'gap' => self::gap( 10 ), 'padding' => self::dims( 24, 24, 24, 24 ) ), true );
			$i++;
		}
		if ( empty( $items ) ) { return array(); }
		$row = self::container( $items, array( 'flex_direction' => 'row', 'flex_wrap' => 'wrap', 'gap' => self::gap( 20 ) ), true );
		return array( self::outer( array( self::inner( array( self::heading( $v['SECTION_TITLE'] ?? '' ), $row ) ) ), 'plain' ) );
	}

	protected static function faq( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$items = array();
		foreach ( (array) ( $v['FAQ_ITEMS'] ?? array() ) as $faq ) {
			if ( ! is_array( $faq ) ) { continue; }
			$items[] = self::container( array(
				self::heading( $faq['question'] ?? '', 'h3' ),
				self::text( '<p>' . esc_html( (string) ( $faq['answer'] ?? '' ) ) . '</p>' ),
			), array(
				'flex_direction' => 'column',
				'gap' => self::gap( 8 ),
				'padding' => self::dims( 22, 22, 22, 22 ),
				'background_background' => 'classic',
				'background_color' => self::color( 'surface', '#ffffff' ),
			), true );
		}
		if ( empty( $items ) ) { return array(); }
		return array( self::outer( array( self::inner( array_merge( array( self::heading( $v['SECTION_TITLE'] ?? '' ) ), $items ) ) ), 'tint' ) );
	}

	protected static function related( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$raw = (array) ( $v['RELATED_ITEMS'] ?? $v['AREA_ITEMS'] ?? $v['AREAS'] ?? array() );
		$items = array();
		foreach ( $raw as $item ) {
			$title = is_array( $item ) ? (string) ( $item['title'] ?? $item['text'] ?? $item['name'] ?? '' ) : (string) $item;
			$url = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : '';
			$children = array( self::heading( $title, 'h3' ) );
			if ( '' !== $url ) { $children[] = self::button( __( 'View', 'seo-command-center' ), $url ); }
			$items[] = self::container( $children, array( 'flex_direction' => 'column', 'gap' => self::gap( 10 ), 'padding' => self::dims( 20, 20, 20, 20 ) ), true );
		}
		if ( empty( $items ) ) { return array(); }
		$row = self::container( $items, array( 'flex_direction' => 'row', 'flex_wrap' => 'wrap', 'gap' => self::gap( 20 ) ), true );
		return array( self::outer( array( self::inner( array( self::heading( $v['SECTION_TITLE'] ?? '' ), $row ) ) ), 'plain' ) );
	}

	protected static function cta( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$title = self::heading( $v['CTA_TITLE'] ?? '', 'h2', 'center' );
		if ( is_array( $title ) ) { $title['settings']['title_color'] = '#ffffff'; }
		$text = ! empty( $v['CTA_TEXT_BODY'] ) ? self::text( '<p>' . esc_html( (string) $v['CTA_TEXT_BODY'] ) . '</p>', 'center' ) : null;
		if ( is_array( $text ) ) { $text['settings']['text_color'] = '#ffffff'; }
		$button = self::button( $v['CTA_TEXT'] ?? '', $v['CTA_URL'] ?? '', 'center' );
		$box = self::inner( array( $title, $text, $button ), 'column', array( 'align_items' => 'center' ) );
		return array( self::outer( array( $box ), 'brand' ) );
	}

	protected static function generic_text( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$title = (string) ( $v['SECTION_TITLE'] ?? $v['HIGHLIGHT_TITLE'] ?? '' );
		$html = '';
		if ( isset( $v['COMPARISON_HTML'] ) ) { $html = (string) $v['COMPARISON_HTML']; }
		elseif ( isset( $v['HIGHLIGHT_TEXT'] ) ) { $html = '<p>' . esc_html( (string) $v['HIGHLIGHT_TEXT'] ) . '</p>'; }
		elseif ( isset( $v['TOC_ITEMS'] ) ) {
			$html = '<ul>';
			foreach ( (array) $v['TOC_ITEMS'] as $it ) {
				$txt = is_array( $it ) ? (string) ( $it['text'] ?? '' ) : (string) $it;
				$anchor = is_array( $it ) ? sanitize_title( (string) ( $it['anchor'] ?? '' ) ) : '';
				$html .= '<li><a href="#' . esc_attr( $anchor ) . '">' . esc_html( $txt ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		if ( '' === trim( $title . wp_strip_all_tags( $html ) ) ) { return array(); }
		return array( self::outer( array( self::inner( array( self::heading( $title ), self::text( $html ) ) ) ), 'plain' ) );
	}
}
