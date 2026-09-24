<?php
/**
 * Native Elementor professional design renderer.
 *
 * Converts controlled TideOrbit design components into real Elementor
 * containers and widgets. The content is already complete before it reaches
 * this class; this layer only controls presentation.
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
		if ( class_exists( 'SCC_Page_Architect' ) ) {
			$id = SCC_Page_Architect::base_block( $id );
		}
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

	protected static function supports( $widget ) {
		if ( class_exists( 'SCC_Elementor_Widget_Catalog' ) ) {
			return SCC_Elementor_Widget_Catalog::supports( $widget );
		}
		return SCC_Elementor_Capabilities::supports_widget( $widget );
	}

	protected static function id() {
		return substr( md5( uniqid( 'scc', true ) ), 0, 8 );
	}

	protected static function widget( $type, array $settings ) {
		if ( ! self::supports( $type ) ) { return null; }
		return array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	protected static function dims( $top, $right, $bottom, $left ) {
		return array(
			'unit' => 'px',
			'top' => (string) $top,
			'right' => (string) $right,
			'bottom' => (string) $bottom,
			'left' => (string) $left,
			'isLinked' => false,
		);
	}

	protected static function size( $value, $unit = 'px' ) {
		return array( 'unit' => $unit, 'size' => (float) $value, 'sizes' => array() );
	}

	protected static function gap( $size ) {
		return array(
			'unit' => 'px',
			'size' => (int) $size,
			'sizes' => array(),
			'column' => (string) (int) $size,
			'row' => (string) (int) $size,
			'isLinked' => true,
		);
	}

	protected static function container( array $children, array $settings = array(), $inner = false ) {
		return array(
			'id'       => self::id(),
			'elType'   => 'container',
			'settings' => $settings,
			'elements' => array_values( array_filter( $children ) ),
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
			'padding' => self::dims(
				self::spacing( 'section_y', 72 ),
				24,
				self::spacing( 'section_y', 72 ),
				24
			),
		);
		if ( '' !== $bg ) {
			$settings['background_background'] = 'classic';
			$settings['background_color'] = $bg;
		}
		return self::container( $children, array_merge( $settings, $extra ), false );
	}

	protected static function inner( array $children, $direction = 'column', array $extra = array() ) {
		$p = self::profile();
		$width = (int) ( $p['layout']['content_width'] ?? 1140 );
		if ( $width < 760 || $width > 1800 ) { $width = 1140; }

		$settings = array(
			'content_width' => 'boxed',
			'boxed_width' => self::size( $width ),
			'flex_direction' => $direction,
			'flex_gap' => self::gap( self::spacing( 'gap', 24 ) ),
		);
		return self::container( $children, array_merge( $settings, $extra ), true );
	}

	protected static function heading( $text, $size = 'h2', $align = '' ) {
		$text = trim( (string) $text );
		if ( '' === $text ) { return null; }

		$settings = array(
			'title' => $text,
			'header_size' => $size,
			'title_color' => self::color( 'heading', self::color( 'text', '#1f2937' ) ),
		);
		if ( '' !== $align ) { $settings['align'] = $align; }
		return self::widget( 'heading', $settings );
	}

	protected static function text( $html, $align = '' ) {
		$html = trim( (string) $html );
		if ( '' === $html || '' === trim( wp_strip_all_tags( $html ) ) ) { return null; }

		$settings = array(
			'editor' => wp_kses_post( $html ),
			'text_color' => self::color( 'text', '#374151' ),
		);
		if ( '' !== $align ) { $settings['align'] = $align; }
		return self::widget( 'text-editor', $settings );
	}

	protected static function button( $label, $url, $align = '' ) {
		$label = trim( (string) $label );
		$url   = esc_url_raw( (string) $url );
		if ( '' === $label || '' === $url ) { return null; }

		$settings = array(
			'text' => $label,
			'link' => array( 'url' => $url, 'is_external' => '', 'nofollow' => '' ),
			'background_color' => self::color( 'primary', '#4f46e5' ),
		);
		if ( '' !== $align ) { $settings['align'] = $align; }
		return self::widget( 'button', $settings );
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

		return self::widget( 'image', array(
			'image' => array( 'url' => $url, 'id' => $id ),
			'image_size' => 'large',
			'border_radius' => self::dims(
				self::spacing( 'radius', 12 ),
				self::spacing( 'radius', 12 ),
				self::spacing( 'radius', 12 ),
				self::spacing( 'radius', 12 )
			),
		) );
	}

	protected static function icon_list( array $items ) {
		if ( ! self::supports( 'icon-list' ) ) { return null; }
		$rows = array();
		foreach ( $items as $item ) {
			$text = trim( wp_strip_all_tags( is_array( $item ) ? (string) ( $item['text'] ?? '' ) : (string) $item ) );
			if ( '' === $text ) { continue; }
			$rows[] = array(
				'_id' => self::id(),
				'text' => $text,
				'selected_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ),
				'link' => array( 'url' => '', 'is_external' => '', 'nofollow' => '' ),
			);
		}
		if ( empty( $rows ) ) { return null; }

		return self::widget( 'icon-list', array(
			'view' => 'traditional',
			'icon_list' => $rows,
			'icon_color' => self::color( 'primary', '#4f46e5' ),
			'text_color' => self::color( 'text', '#374151' ),
			'space_between' => self::size( 14 ),
		) );
	}

	protected static function accordion( array $items ) {
		if ( ! self::supports( 'accordion' ) ) { return null; }
		$tabs = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$q = trim( (string) ( $item['question'] ?? '' ) );
			$a = trim( (string) ( $item['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) { continue; }
			$tabs[] = array(
				'_id' => self::id(),
				'tab_title' => $q,
				'tab_content' => wp_kses_post( wpautop( $a ) ),
			);
		}
		if ( empty( $tabs ) ) { return null; }

		return self::widget( 'accordion', array(
			'tabs' => $tabs,
			'title_html_tag' => 'h3',
			'faq_schema' => '',
			'selected_icon' => array( 'value' => 'fas fa-plus', 'library' => 'fa-solid' ),
			'selected_active_icon' => array( 'value' => 'fas fa-minus', 'library' => 'fa-solid' ),
			'title_color' => self::color( 'heading', '#1f2937' ),
			'content_color' => self::color( 'text', '#374151' ),
			'border_color' => self::color( 'border', '#e5e7eb' ),
		) );
	}

	protected static function counter( $value, $label ) {
		if ( ! self::supports( 'counter' ) ) { return null; }
		$parsed = self::parse_number( $value );
		if ( ! $parsed ) { return null; }

		return self::widget( 'counter', array(
			'starting_number' => 0,
			'ending_number' => $parsed['number'],
			'prefix' => $parsed['prefix'],
			'suffix' => $parsed['suffix'],
			'duration' => 1400,
			'thousand_separator' => 'yes',
			'title' => trim( (string) $label ),
		) );
	}

	protected static function parse_number( $value ) {
		$value = trim( wp_strip_all_tags( (string) $value ) );
		if ( ! preg_match( '/^([^0-9\-]*)(-?[0-9][0-9,]*(?:\.[0-9]+)?)(.*)$/', $value, $m ) ) {
			return null;
		}
		$number = str_replace( ',', '', $m[2] );
		if ( ! is_numeric( $number ) ) { return null; }
		return array(
			'number' => (float) $number,
			'prefix' => trim( $m[1] ),
			'suffix' => trim( $m[3] ),
		);
	}

	protected static function hero( array $block ) {
		$v       = (array) ( $block['vars'] ?? array() );
		$variant = (string) ( $block['variant'] ?? 'centered' );
		$align   = 'centered' === $variant ? 'center' : 'left';

		$copy = self::container( array(
			! empty( $v['HERO_EYEBROW'] ) ? self::text( '<strong>' . esc_html( (string) $v['HERO_EYEBROW'] ) . '</strong>', $align ) : null,
			self::heading( $v['HERO_TITLE'] ?? '', 'h1', $align ),
			self::text( '<p>' . esc_html( (string) ( $v['HERO_SUBTITLE'] ?? '' ) ) . '</p>', $align ),
			self::button( $v['CTA_TEXT'] ?? '', $v['CTA_URL'] ?? '', $align ),
		), array(
			'flex_direction' => 'column',
			'flex_gap' => self::gap( 18 ),
			'width' => self::size( '55', '%' ),
			'width_mobile' => self::size( '100', '%' ),
		), true );

		if ( 'split-image' === $variant && ! empty( $v['HERO_IMAGE'] ) ) {
			$img = self::image( $v['HERO_IMAGE'] );
			if ( $img ) {
				$media = self::container( array( $img ), array(
					'flex_direction' => 'column',
					'width' => self::size( '42', '%' ),
					'width_mobile' => self::size( '100', '%' ),
				), true );
				$row = self::inner( array( $copy, $media ), 'row', array(
					'flex_align_items' => 'center',
					'flex_justify_content' => 'space-between',
					'flex_gap' => self::gap( 48 ),
					'flex_direction_mobile' => 'column',
				) );
				return array( self::outer( array( $row ), 'tint', array( 'min_height' => self::size( 520 ) ) ) );
			}
		}

		unset( $copy['settings']['width'], $copy['settings']['width_mobile'] );
		$copy['settings']['flex_align_items'] = 'centered' === $variant ? 'center' : 'flex-start';
		$copy['settings']['width'] = self::size( '78', '%' );
		$copy['settings']['width_mobile'] = self::size( '100', '%' );

		return array( self::outer( array(
			self::inner( array( $copy ), 'column', array(
				'flex_align_items' => 'centered' === $variant ? 'center' : 'flex-start',
			) )
		), 'tint' ) );
	}

	protected static function intro( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$copy = self::text( '<p>' . esc_html( (string) ( $v['INTRO'] ?? '' ) ) . '</p>' );
		return $copy ? array( self::outer( array(
			self::inner( array( $copy ), 'column', array( 'boxed_width' => self::size( 820 ) ) )
		) ) ) : array();
	}

	protected static function content( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$sections = (array) ( $v['CONTENT_SECTIONS'] ?? array() );
		if ( empty( $sections ) && ! empty( $v['CONTENT'] ) && class_exists( 'SCC_Design_Handoff' ) ) {
			$sections = SCC_Design_Handoff::sections( (string) $v['CONTENT'] );
			if ( class_exists( 'SCC_Design_Composer' ) ) {
				$sections = SCC_Design_Composer::decorate_sections( $sections );
			}
		}

		$out = array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) { continue; }
			$node = self::content_section( $section );
			if ( $node ) { $out[] = $node; }
		}
		return $out;
	}

	protected static function content_section( array $section ) {
		$heading = (string) ( $section['heading'] ?? '' );
		$html    = (string) ( $section['html'] ?? '' );
		$layout  = (string) ( $section['layout'] ?? 'editorial' );
		$surface = (string) ( $section['surface'] ?? 'plain' );

		if ( '' === trim( $heading . wp_strip_all_tags( $html ) ) ) { return null; }

		if ( in_array( $layout, array( 'media-left', 'media-right' ), true ) && ! empty( $section['images'][0] ) ) {
			$img = self::image( $section['images'][0] );
			$clean_html = self::remove_first_image( $html );
			$copy = self::container( array(
				self::heading( $heading, 'h2' ),
				self::text( $clean_html ),
			), array(
				'flex_direction' => 'column',
				'flex_gap' => self::gap( 18 ),
				'width' => self::size( 54, '%' ),
				'width_mobile' => self::size( 100, '%' ),
			), true );
			$media = self::container( array( $img ), array(
				'width' => self::size( 40, '%' ),
				'width_mobile' => self::size( 100, '%' ),
			), true );

			$children = 'media-left' === $layout ? array( $media, $copy ) : array( $copy, $media );
			return self::outer( array(
				self::inner( $children, 'row', array(
					'flex_align_items' => 'center',
					'flex_justify_content' => 'space-between',
					'flex_gap' => self::gap( 48 ),
					'flex_direction_mobile' => 'column',
				) )
			), $surface );
		}

		if ( 'split-list' === $layout ) {
			$list = self::extract_first_list( $html );
			if ( ! empty( $list['items'] ) ) {
				$left = self::container( array(
					self::heading( $heading, 'h2' ),
					self::text( $list['before'] ),
				), array(
					'flex_direction' => 'column',
					'flex_gap' => self::gap( 16 ),
					'width' => self::size( 38, '%' ),
					'width_mobile' => self::size( 100, '%' ),
				), true );
				$right = self::container( array(
					self::icon_list( $list['items'] ),
					self::text( $list['after'] ),
				), array(
					'flex_direction' => 'column',
					'flex_gap' => self::gap( 16 ),
					'width' => self::size( 56, '%' ),
					'width_mobile' => self::size( 100, '%' ),
					'padding' => self::dims( 28, 28, 28, 28 ),
					'background_background' => 'classic',
					'background_color' => self::color( 'card', '#ffffff' ),
					'border_border' => 'solid',
					'border_width' => self::dims( 1, 1, 1, 1 ),
					'border_color' => self::color( 'border', '#e5e7eb' ),
					'border_radius' => self::dims(
						self::spacing( 'radius', 12 ),
						self::spacing( 'radius', 12 ),
						self::spacing( 'radius', 12 ),
						self::spacing( 'radius', 12 )
					),
				), true );
				return self::outer( array(
					self::inner( array( $left, $right ), 'row', array(
						'flex_align_items' => 'flex-start',
						'flex_justify_content' => 'space-between',
						'flex_gap' => self::gap( 44 ),
						'flex_direction_mobile' => 'column',
					) )
				), $surface );
			}
		}

		$width = 'wide' === $layout ? (int) ( self::profile()['layout']['content_width'] ?? 1140 ) : 860;
		$box_settings = array( 'boxed_width' => self::size( $width ) );
		if ( 'callout' === $layout ) {
			$box_settings = array_merge( $box_settings, array(
				'padding' => self::dims( 34, 34, 34, 34 ),
				'background_background' => 'classic',
				'background_color' => self::color( 'card', '#ffffff' ),
				'border_border' => 'solid',
				'border_width' => self::dims( 1, 1, 1, 1 ),
				'border_color' => self::color( 'border', '#e5e7eb' ),
				'border_radius' => self::dims(
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 )
				),
			) );
		}

		return self::outer( array(
			self::inner( array(
				self::heading( $heading, 'h2' ),
				self::text( $html ),
			), 'column', $box_settings )
		), $surface );
	}

	protected static function remove_first_image( $html ) {
		$html = preg_replace( '#<p[^>]*>\s*<img\b[^>]*>\s*</p>#is', '', (string) $html, 1 );
		return (string) preg_replace( '#<img\b[^>]*>#is', '', $html, 1 );
	}

	protected static function extract_first_list( $html ) {
		$html = (string) $html;
		if ( ! preg_match( '#<(ul|ol)\b[^>]*>(.*?)</\1>#is', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return array( 'before' => $html, 'after' => '', 'items' => array() );
		}

		$full = $m[0][0];
		$offset = $m[0][1];
		$inside = $m[2][0];
		$items = array();
		if ( preg_match_all( '#<li\b[^>]*>(.*?)</li>#is', $inside, $li ) ) {
			foreach ( $li[1] as $item ) {
				$text = trim( wp_strip_all_tags( $item ) );
				if ( '' !== $text ) { $items[] = array( 'text' => $text ); }
			}
		}
		return array(
			'before' => trim( substr( $html, 0, $offset ) ),
			'after' => trim( substr( $html, $offset + strlen( $full ) ) ),
			'items' => $items,
		);
	}

	protected static function benefits( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$items = (array) ( $v['BENEFIT_ITEMS'] ?? array() );
		if ( empty( $items ) ) { return array(); }

		$list = self::icon_list( $items );
		if ( $list ) {
			$copy = self::container( array(
				self::heading( $v['SECTION_TITLE'] ?? 'Benefits', 'h2' ),
				$list,
			), array(
				'flex_direction' => 'column',
				'flex_gap' => self::gap( 18 ),
				'width' => self::size( 760 ),
			), true );
			return array( self::outer( array( self::inner( array( $copy ) ) ), 'tint' ) );
		}

		$fallback = array();
		foreach ( $items as $item ) {
			$fallback[] = self::text( '<p><strong>✓</strong> ' . esc_html( is_array( $item ) ? (string) ( $item['text'] ?? '' ) : (string) $item ) . '</p>' );
		}
		return array( self::outer( array(
			self::inner( array_merge( array( self::heading( $v['SECTION_TITLE'] ?? 'Benefits' ) ), $fallback ) )
		), 'tint' ) );
	}

	protected static function cards( array $block ) {
		$v       = (array) ( $block['vars'] ?? array() );
		$variant = (string) ( $block['variant'] ?? 'cards' );
		$cards   = array();
		$index   = 0;

		foreach ( (array) ( $v['CARDS'] ?? array() ) as $card ) {
			$title = (string) ( $card['SERVICE_TITLE'] ?? $card['title'] ?? '' );
			$desc  = (string) ( $card['SERVICE_DESCRIPTION'] ?? $card['description'] ?? '' );
			$url   = (string) ( $card['SERVICE_URL'] ?? $card['url'] ?? '' );
			if ( '' === trim( $title ) ) { continue; }

			$width = 'bento' === $variant && 0 === $index ? 65 : ( 'bento' === $variant ? 31 : 31 );
			$children = array(
				self::heading( $title, 'h3' ),
				self::text( '<p>' . esc_html( $desc ) . '</p>' ),
			);
			if ( '' !== $url ) { $children[] = self::button( __( 'Learn more', 'seo-command-center' ), $url ); }

			$cards[] = self::container( $children, array(
				'flex_direction' => 'column',
				'flex_gap' => self::gap( 14 ),
				'padding' => self::dims( 30, 30, 30, 30 ),
				'background_background' => 'classic',
				'background_color' => self::color( 'card', '#ffffff' ),
				'border_border' => 'solid',
				'border_width' => self::dims( 1, 1, 1, 1 ),
				'border_color' => self::color( 'border', '#e5e7eb' ),
				'border_radius' => self::dims(
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 )
				),
				'width' => self::size( $width, '%' ),
				'width_tablet' => self::size( 48, '%' ),
				'width_mobile' => self::size( 100, '%' ),
			), true );
			$index++;
		}
		if ( empty( $cards ) ) { return array(); }

		$grid = self::container( $cards, array(
			'flex_direction' => 'row',
			'flex_wrap' => 'wrap',
			'flex_gap' => self::gap( 24 ),
			'flex_justify_content' => 'space-between',
		), true );

		return array( self::outer( array(
			self::inner( array( self::heading( $v['SECTION_TITLE'] ?? '' ), $grid ) )
		), 'plain' ) );
	}

	protected static function stats( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$items = array();
		foreach ( (array) ( $v['STATS'] ?? array() ) as $stat ) {
			$value = is_array( $stat ) ? (string) ( $stat['value'] ?? $stat['number'] ?? '' ) : '';
			$label = is_array( $stat ) ? (string) ( $stat['label'] ?? $stat['text'] ?? '' ) : (string) $stat;
			if ( '' === trim( $value ) ) { continue; }

			$counter = self::counter( $value, $label );
			if ( $counter ) {
				$items[] = self::container( array( $counter ), array(
					'width' => self::size( 23, '%' ),
					'width_tablet' => self::size( 48, '%' ),
					'width_mobile' => self::size( 100, '%' ),
				), true );
			} else {
				$items[] = self::container( array(
					self::heading( $value, 'h3', 'center' ),
					self::text( '<p>' . esc_html( $label ) . '</p>', 'center' ),
				), array(
					'flex_direction' => 'column',
					'flex_align_items' => 'center',
					'flex_gap' => self::gap( 8 ),
					'width' => self::size( 23, '%' ),
					'width_tablet' => self::size( 48, '%' ),
					'width_mobile' => self::size( 100, '%' ),
				), true );
			}
		}
		if ( empty( $items ) ) { return array(); }

		$row = self::container( $items, array(
			'flex_direction' => 'row',
			'flex_wrap' => 'wrap',
			'flex_justify_content' => 'space-between',
			'flex_gap' => self::gap( 24 ),
		), true );

		return array( self::outer( array(
			self::inner( array(
				self::heading( $v['SECTION_TITLE'] ?? '', 'h2', 'center' ),
				$row,
			) )
		), 'tint' ) );
	}

	protected static function steps( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$items = array();
		$i = 1;

		foreach ( (array) ( $v['STEPS'] ?? array() ) as $step ) {
			$title = is_array( $step ) ? (string) ( $step['title'] ?? $step['name'] ?? '' ) : '';
			$text  = is_array( $step ) ? (string) ( $step['description'] ?? $step['text'] ?? '' ) : (string) $step;
			if ( '' === trim( $title . $text ) ) { continue; }

			$items[] = self::container( array(
				self::heading( sprintf( '%02d', $i ), 'h3' ),
				'' !== $title ? self::heading( $title, 'h3' ) : null,
				self::text( '<p>' . esc_html( $text ) . '</p>' ),
			), array(
				'flex_direction' => 'column',
				'flex_gap' => self::gap( 10 ),
				'padding' => self::dims( 28, 28, 28, 28 ),
				'background_background' => 'classic',
				'background_color' => self::color( 'card', '#ffffff' ),
				'border_border' => 'solid',
				'border_width' => self::dims( 1, 1, 1, 1 ),
				'border_color' => self::color( 'border', '#e5e7eb' ),
				'border_radius' => self::dims(
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 )
				),
				'width' => self::size( 31, '%' ),
				'width_tablet' => self::size( 48, '%' ),
				'width_mobile' => self::size( 100, '%' ),
			), true );
			$i++;
		}
		if ( empty( $items ) ) { return array(); }

		$row = self::container( $items, array(
			'flex_direction' => 'row',
			'flex_wrap' => 'wrap',
			'flex_gap' => self::gap( 20 ),
			'flex_justify_content' => 'space-between',
		), true );

		return array( self::outer( array(
			self::inner( array( self::heading( $v['SECTION_TITLE'] ?? '' ), $row ) )
		), 'plain' ) );
	}

	protected static function faq( array $block ) {
		$v       = (array) ( $block['vars'] ?? array() );
		$variant = (string) ( $block['variant'] ?? 'stacked' );
		$faqs    = (array) ( $v['FAQ_ITEMS'] ?? array() );
		if ( empty( $faqs ) ) { return array(); }

		if ( 'accordion' === $variant ) {
			$accordion = self::accordion( $faqs );
			if ( $accordion ) {
				return array( self::outer( array(
					self::inner( array(
						self::heading( $v['SECTION_TITLE'] ?? '' ),
						$accordion,
					), 'column', array( 'boxed_width' => self::size( 900 ) ) )
				), 'tint' ) );
			}
		}

		$items = array();
		foreach ( $faqs as $faq ) {
			if ( ! is_array( $faq ) ) { continue; }
			$items[] = self::container( array(
				self::heading( $faq['question'] ?? '', 'h3' ),
				self::text( '<p>' . esc_html( (string) ( $faq['answer'] ?? '' ) ) . '</p>' ),
			), array(
				'flex_direction' => 'column',
				'flex_gap' => self::gap( 8 ),
				'padding' => self::dims( 24, 24, 24, 24 ),
				'background_background' => 'classic',
				'background_color' => self::color( 'card', '#ffffff' ),
				'border_border' => 'solid',
				'border_width' => self::dims( 1, 1, 1, 1 ),
				'border_color' => self::color( 'border', '#e5e7eb' ),
				'border_radius' => self::dims(
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 )
				),
			), true );
		}

		return array( self::outer( array(
			self::inner( array_merge( array( self::heading( $v['SECTION_TITLE'] ?? '' ) ), $items ), 'column', array(
				'boxed_width' => self::size( 900 ),
			) )
		), 'tint' ) );
	}

	protected static function related( array $block ) {
		$v       = (array) ( $block['vars'] ?? array() );
		$variant = (string) ( $block['variant'] ?? 'cards' );
		$raw     = (array) ( $v['RELATED_ITEMS'] ?? $v['AREA_ITEMS'] ?? $v['AREAS'] ?? array() );
		$items   = array();
		$index   = 0;

		foreach ( $raw as $item ) {
			$title = is_array( $item ) ? (string) ( $item['title'] ?? $item['text'] ?? $item['name'] ?? '' ) : (string) $item;
			$url   = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : '';
			if ( '' === trim( $title ) ) { continue; }

			$width = 'bento' === $variant && 0 === $index ? 48 : ( 'bento' === $variant ? 23 : 31 );
			$children = array( self::heading( $title, 'h3' ) );
			if ( '' !== $url ) { $children[] = self::button( __( 'View', 'seo-command-center' ), $url ); }

			$items[] = self::container( $children, array(
				'flex_direction' => 'column',
				'flex_gap' => self::gap( 12 ),
				'padding' => self::dims( 24, 24, 24, 24 ),
				'background_background' => 'classic',
				'background_color' => self::color( 'card', '#ffffff' ),
				'border_border' => 'solid',
				'border_width' => self::dims( 1, 1, 1, 1 ),
				'border_color' => self::color( 'border', '#e5e7eb' ),
				'border_radius' => self::dims(
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 ),
					self::spacing( 'radius', 12 )
				),
				'width' => self::size( $width, '%' ),
				'width_tablet' => self::size( 48, '%' ),
				'width_mobile' => self::size( 100, '%' ),
			), true );
			$index++;
		}
		if ( empty( $items ) ) { return array(); }

		$row = self::container( $items, array(
			'flex_direction' => 'row',
			'flex_wrap' => 'wrap',
			'flex_gap' => self::gap( 20 ),
			'flex_justify_content' => 'space-between',
		), true );

		return array( self::outer( array(
			self::inner( array( self::heading( $v['SECTION_TITLE'] ?? '' ), $row ) )
		), 'plain' ) );
	}

	protected static function cta( array $block ) {
		$v       = (array) ( $block['vars'] ?? array() );
		$variant = (string) ( $block['variant'] ?? 'split' );

		$title = self::heading( $v['CTA_TITLE'] ?? '', 'h2', 'left' );
		if ( is_array( $title ) ) { $title['settings']['title_color'] = '#ffffff'; }

		$button = self::button( $v['CTA_TEXT'] ?? '', $v['CTA_URL'] ?? '', 'right' );
		if ( is_array( $button ) ) {
			$button['settings']['background_color'] = '#ffffff';
			$button['settings']['button_text_color'] = self::color( 'primary', '#4f46e5' );
		}

		if ( 'split' === $variant ) {
			$left = self::container( array( $title ), array(
				'width' => self::size( 62, '%' ),
				'width_mobile' => self::size( 100, '%' ),
			), true );
			$right = self::container( array( $button ), array(
				'width' => self::size( 32, '%' ),
				'width_mobile' => self::size( 100, '%' ),
				'flex_align_items' => 'flex-end',
				'flex_align_items_mobile' => 'flex-start',
			), true );

			return array( self::outer( array(
				self::inner( array( $left, $right ), 'row', array(
					'flex_align_items' => 'center',
					'flex_justify_content' => 'space-between',
					'flex_direction_mobile' => 'column',
				) )
			), 'brand' ) );
		}

		if ( is_array( $title ) ) { $title['settings']['align'] = 'center'; }
		if ( is_array( $button ) ) { $button['settings']['align'] = 'center'; }
		return array( self::outer( array(
			self::inner( array( $title, $button ), 'column', array( 'flex_align_items' => 'center' ) )
		), 'brand' ) );
	}

	protected static function generic_text( array $block ) {
		$v = (array) ( $block['vars'] ?? array() );
		$title = (string) ( $v['SECTION_TITLE'] ?? $v['HIGHLIGHT_TITLE'] ?? '' );
		$html = '';

		if ( isset( $v['COMPARISON_HTML'] ) ) {
			$html = (string) $v['COMPARISON_HTML'];
		} elseif ( isset( $v['HIGHLIGHT_TEXT'] ) ) {
			$html = '<p>' . esc_html( (string) $v['HIGHLIGHT_TEXT'] ) . '</p>';
		} elseif ( isset( $v['TOC_ITEMS'] ) ) {
			$html = '<ul>';
			foreach ( (array) $v['TOC_ITEMS'] as $it ) {
				$txt = is_array( $it ) ? (string) ( $it['text'] ?? '' ) : (string) $it;
				$anchor = is_array( $it ) ? sanitize_title( (string) ( $it['anchor'] ?? '' ) ) : '';
				$html .= '<li><a href="#' . esc_attr( $anchor ) . '">' . esc_html( $txt ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		if ( '' === trim( $title . wp_strip_all_tags( $html ) ) ) { return array(); }

		return array( self::outer( array(
			self::inner( array(
				self::heading( $title ),
				self::text( $html ),
			), 'column', array( 'boxed_width' => self::size( 900 ) ) )
		), 'plain' ) );
	}
}
