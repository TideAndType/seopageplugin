<?php
/**
 * Block → Elementor renderer.
 *
 * Turns the mapped blocks into a valid Elementor element tree (flex containers +
 * core widgets: heading, text-editor, button, html) and a crawlable native HTML
 * fallback (post_content). It NEVER executes AI output — it only assembles known
 * Elementor structures from validated, mapped content.
 *
 * A block may also be mapped to an EXISTING Elementor template (option
 * `scc_block_template_map`: block id => template post id). When present and
 * Elementor is active, that template's own design is cloned and its {{TOKENS}}
 * filled from the block's variables — so the site's real design language is
 * reused rather than a generic structure.
 *
 * The generated page remains fully editable in Elementor.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block-based Elementor renderer.
 */
class SCC_Block_Elementor_Renderer {

	/**
	 * Build the Elementor element tree + native HTML from mapped blocks.
	 *
	 * @param array $blocks  Mapped blocks (from SCC_Content_Mapper::map()).
	 * @param array $context Optional { palette_css: string }.
	 * @return array { elementor: array, html: string }
	 */
	public static function render( array $blocks, array $context = array() ) {
		$palette_css = isset( $context['palette_css'] ) ? (string) $context['palette_css'] : SCC_Design_Intel::css_vars();
		$map         = self::template_map();

		$elements = array();
		$html     = array();

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['empty'] ) ) {
				continue; // Never render an empty section.
			}
			$id   = (string) $block['id'];
			$vars = (array) $block['vars'];

			// 1) Existing-template reuse takes priority (use the site's design).
			if ( isset( $map[ $id ] ) && class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_active() ) {
				$tpl = self::render_template_block( (int) $map[ $id ], $vars );
				if ( ! empty( $tpl ) ) {
					$elements = array_merge( $elements, $tpl );
					$html[]   = self::block_html( $block, $palette_css );
					continue;
				}
			}

			// 2) Native structure from the controlled library.
			$el = self::block_elements( $block, $palette_css );
			foreach ( $el as $node ) {
				$elements[] = $node;
			}
			$html[] = self::block_html( $block, $palette_css );
		}

		return array(
			'elementor' => $elements,
			'html'      => implode( "\n", array_filter( $html ) ),
		);
	}

	/**
	 * Apply a rendered layout to a post: write Elementor meta + a native content
	 * fallback so the page is crawlable and survives Elementor being removed.
	 *
	 * @param int   $post_id Target post id.
	 * @param array $blocks  Mapped blocks.
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	public static function apply_to_post( $post_id, array $blocks, array $context = array() ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'scc_no_post', __( 'Target post not found.', 'seo-command-center' ) );
		}
		if ( ! class_exists( 'SCC_Elementor' ) || ! SCC_Elementor::is_active() ) {
			return new WP_Error( 'scc_no_elementor', __( 'Elementor is not active.', 'seo-command-center' ) );
		}

		$built = self::render( $blocks, $context );
		if ( empty( $built['elementor'] ) ) {
			return new WP_Error( 'scc_empty_layout', __( 'The layout produced no renderable blocks.', 'seo-command-center' ) );
		}

		$elementor_json = wp_json_encode( $built['elementor'] );
		if ( ! is_string( $elementor_json ) || '' === $elementor_json ) {
			return new WP_Error( 'scc_elementor_encode', __( 'Could not encode the Elementor layout.', 'seo-command-center' ) );
		}

		// Capture the existing document before touching any Elementor or content
		// fields. This snapshot survives a successful apply as the user's one-click
		// recovery point and is also used automatically if any write below fails.
		$snapshot = self::snapshot_post( $post_id );
		if ( ! self::update_meta_verified( $post_id, '_scc_elementor_backup', $snapshot, $snapshot ) ) {
			return new WP_Error( 'scc_backup_failed', __( 'Could not create a rollback snapshot, so the existing page was left unchanged.', 'seo-command-center' ) );
		}
		if ( function_exists( 'wp_save_post_revision' ) ) {
			wp_save_post_revision( $post_id );
		}

		$post_type = get_post_type( $post_id );
		$writes    = array(
			array( '_elementor_data', wp_slash( $elementor_json ), $elementor_json ),
			array( '_elementor_edit_mode', 'builder', 'builder' ),
			array( '_elementor_template_type', 'page' === $post_type ? 'wp-page' : 'wp-post', 'page' === $post_type ? 'wp-page' : 'wp-post' ),
		);
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$writes[] = array( '_elementor_version', ELEMENTOR_VERSION, ELEMENTOR_VERSION );
		}

		// Full-width Elementor layout so the design spans the page (filterable).
		$page_tpl = (string) apply_filters( 'scc_elementor_page_template', 'elementor_header_footer', $post_type, $post_type );
		if ( '' !== $page_tpl && 'default' !== $page_tpl ) {
			$writes[] = array( '_wp_page_template', $page_tpl, $page_tpl );
		}

		// Mark as SCC-generated so the front-end component styles load.
		if ( '' === (string) get_post_meta( $post_id, '_scc_generated', true ) ) {
			$generated = current_time( 'mysql' );
			$writes[]  = array( '_scc_generated', $generated, $generated );
		}

		foreach ( $writes as $write ) {
			if ( ! self::update_meta_verified( $post_id, $write[0], $write[1], $write[2] ) ) {
				self::restore_snapshot( $post_id, $snapshot );
				return new WP_Error(
					'scc_elementor_write',
					sprintf(
						/* translators: %s: post-meta key */
						__( 'Could not save Elementor field %s. The previous page was restored.', 'seo-command-center' ),
						$write[0]
					)
				);
			}
		}

		// Keep a crawlable native copy in post_content. Ask WordPress for a real
		// WP_Error so a failed content write cannot be reported as success.
		$updated = wp_update_post( array( 'ID' => $post_id, 'post_content' => $built['html'] ), true );
		if ( is_wp_error( $updated ) || ! $updated ) {
			self::restore_snapshot( $post_id, $snapshot );
			$message = is_wp_error( $updated ) ? $updated->get_error_message() : __( 'Unknown WordPress update error.', 'seo-command-center' );
			return new WP_Error(
				'scc_post_update',
				sprintf(
					/* translators: %s: WordPress error message */
					__( 'Could not update the page content (%s). The previous page was restored.', 'seo-command-center' ),
					$message
				)
			);
		}

		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \\Elementor\\Plugin::$instance->files_manager ) ) {
			\\Elementor\\Plugin::$instance->files_manager->clear_cache();
		}
		if ( class_exists( 'SCC_Logger' ) ) {
			SCC_Logger::info( 'layout', 'Elementor layout applied', array( 'post_id' => $post_id, 'blocks' => count( $built['elementor'] ) ) );
		}
		return true;
	}

	/**
	 * Capture the page fields TideOrbit mutates during an Elementor apply.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	protected static function snapshot_post( $post_id ) {
		$post = get_post( (int) $post_id );
		$keys = array(
			'_elementor_data',
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_version',
			'_wp_page_template',
			'_scc_generated',
		);
		$meta = array();
		foreach ( $keys as $key ) {
			$meta[ $key ] = array(
				'exists' => metadata_exists( 'post', (int) $post_id, $key ),
				'value'  => get_post_meta( (int) $post_id, $key, true ),
			);
		}
		return array(
			'created_at'   => current_time( 'mysql' ),
			'post_content' => $post ? (string) $post->post_content : '',
			'meta'         => $meta,
		);
	}

	/**
	 * Update post meta and distinguish a real failure from WordPress returning
	 * false because the requested value was already stored.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $key      Meta key.
	 * @param mixed  $value    Value passed to update_post_meta().
	 * @param mixed  $expected Value expected back from get_post_meta().
	 * @return bool
	 */
	protected static function update_meta_verified( $post_id, $key, $value, $expected ) {
		$written = update_post_meta( (int) $post_id, $key, $value );
		if ( false !== $written ) {
			return true;
		}
		return get_post_meta( (int) $post_id, $key, true ) === $expected;
	}

	/**
	 * Restore a previously captured Elementor/page snapshot.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $snapshot Snapshot from snapshot_post().
	 * @return void
	 */
	protected static function restore_snapshot( $post_id, array $snapshot ) {
		foreach ( (array) ( $snapshot['meta'] ?? array() ) as $key => $state ) {
			if ( ! empty( $state['exists'] ) ) {
				$value = $state['value'] ?? '';
				if ( '_elementor_data' === $key && is_string( $value ) ) {
					$value = wp_slash( $value );
				}
				update_post_meta( (int) $post_id, $key, $value );
			} else {
				delete_post_meta( (int) $post_id, $key );
			}
		}
		wp_update_post(
			array(
				'ID'           => (int) $post_id,
				'post_content' => (string) ( $snapshot['post_content'] ?? '' ),
			),
			true
		);
	}

	/* ---------------------------------------------------------------------
	 * Elementor element builders
	 * ------------------------------------------------------------------- */

	/**
	 * Build the Elementor element(s) for one block (a single top container).
	 *
	 * @param array  $block       Mapped block.
	 * @param string $palette_css Palette CSS-var string.
	 * @return array Top-level element nodes.
	 */
	protected static function block_elements( array $block, $palette_css ) {
		// Prefer real Elementor containers/widgets. The native renderer is
		// deterministic and capability-gated: it only emits structures TideOrbit
		// knows how to build for the installed Elementor runtime.
		if ( class_exists( 'SCC_Native_Elementor_Blocks' ) ) {
			$native = SCC_Native_Elementor_Blocks::render_block(
				$block,
				array(
					'palette_css' => $palette_css,
					'profile'     => class_exists( 'SCC_Design_Intel' ) ? SCC_Design_Intel::profile() : array(),
				)
			);
			if ( ! empty( $native ) ) {
				return $native;
			}
		}

		// Compatibility fallback: unsupported/third-party blocks still render
		// through the proven semantic HTML-widget path.
		$markup = self::block_html( $block, $palette_css );
		if ( '' === trim( $markup ) ) {
			return array();
		}
		return array( self::container_full( array( self::widget( 'html', array( 'html' => $markup ) ) ) ) );
	}

	/**
	 * A full-width Elementor container (no boxed width, no padding — the plugin
	 * CSS handles bands and spacing). Only the stable `content_width: full`
	 * setting is used.
	 *
	 * @param array $children Child widgets.
	 * @return array
	 */
	protected static function container_full( array $children ) {
		return array(
			'id'       => self::new_id(),
			'elType'   => 'container',
			'settings' => array(
				'content_width' => 'full',
				'padding'       => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ),
			),
			'elements' => array_values( $children ),
			'isInner'  => false,
		);
	}

	/**
	 * Split article HTML at each <h2> into section segments with an alternating
	 * background style (plain / tint) for visual rhythm. The lead text before the
	 * first H2 is its own plain segment. Pure.
	 *
	 * @param string $html Body HTML (FAQ/CTA already lifted out).
	 * @return array List of { style: 'plain'|'tint', html: string }.
	 */
	public static function split_sections( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return array();
		}
		// Keep the <h2> as the start of each section.
		$parts = preg_split( '/(?=<h2\b)/i', $html );
		$out   = array();
		$n     = 0;
		foreach ( (array) $parts as $seg ) {
			$seg = trim( (string) $seg );
			if ( '' === $seg || '' === trim( wp_strip_all_tags( $seg ) ) ) {
				continue;
			}
			$has_h2 = (bool) preg_match( '/^<h2\b/i', $seg );
			// Lead paragraph (no H2) stays plain; H2 sections alternate plain/tint.
			$style  = $has_h2 ? ( ( $n % 2 === 1 ) ? 'tint' : 'plain' ) : 'plain';
			$out[]  = array( 'style' => $style, 'html' => $seg );
			if ( $has_h2 ) {
				$n++;
			}
		}
		return $out;
	}

	/**
	 * The design treatment for a section — a controlled "web-designer" rule set
	 * that gives the page rhythm: a soft feature band for the hero, a brand band
	 * for the CTA, tinted bands for proof/FAQ, plain elsewhere. Pure.
	 *
	 * @param string $id Block id.
	 * @return string 'feature' | 'brand' | 'tint' | 'plain'
	 */
	public static function section_style( $id ) {
		switch ( $id ) {
			case 'hero':
				return 'feature';
			case 'cta':
				return 'brand';
			case 'stats':
			case 'faq':
			case 'testimonial':
				return 'tint';
			default:
				return 'plain';
		}
	}

	/**
	 * A flex container element wrapping child widgets, with a full-width design
	 * band (background + generous padding) per style. Only stable Elementor
	 * container settings are used so it renders reliably across Elementor 3.x.
	 *
	 * @param array  $children Child elements.
	 * @param string $style    Section style from section_style().
	 * @return array
	 */
	protected static function container( array $children, $style = 'plain', $extra_class = '' ) {
		$palette = class_exists( 'SCC_Design_Intel' ) ? SCC_Design_Intel::palette() : array();
		$brand   = ! empty( $palette['primary'] ) ? $palette['primary'] : '#4f46e5';

		$classes = 'scc-sec scc-sec--' . $style . ( '' !== $extra_class ? ' scc-sec--' . $extra_class : '' );
		$settings = array(
			'content_width' => 'boxed',
			'_css_classes'  => $classes,
			'padding'       => array( 'unit' => 'px', 'top' => '56', 'right' => '20', 'bottom' => '56', 'left' => '20', 'isLinked' => false ),
		);

		if ( 'brand' === $style ) {
			$settings['background_background'] = 'classic';
			$settings['background_color']      = $brand;
		} elseif ( 'tint' === $style || 'feature' === $style ) {
			$settings['background_background'] = 'classic';
			$settings['background_color']      = ( 'feature' === $style ) ? '#f3f5fb' : '#f7f8fb';
		}

		return array(
			'id'       => self::new_id(),
			'elType'   => 'container',
			'settings' => $settings,
			'elements' => array_values( $children ),
			'isInner'  => false,
		);
	}

	/**
	 * A widget element.
	 *
	 * @param string $type     Elementor widget type.
	 * @param array  $settings Widget settings.
	 * @return array
	 */
	protected static function widget( $type, array $settings ) {
		return array(
			'id'         => self::new_id(),
			'elType'     => 'widget',
			'widgetType' => (string) $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * A button widget with a validated link.
	 *
	 * @param string $text Button label.
	 * @param string $url  URL.
	 * @return array
	 */
	protected static function button( $text, $url ) {
		$url = esc_url_raw( (string) $url );
		return self::widget( 'button', array(
			'text' => (string) $text,
			'link' => array( 'url' => $url, 'is_external' => '', 'nofollow' => '' ),
		) );
	}

	/**
	 * Render an existing Elementor template as a block: clone its data, fill
	 * {{TOKENS}} from the block variables, regenerate IDs. Returns top-level
	 * element nodes (or empty on failure).
	 *
	 * @param int   $template_id Template post id.
	 * @param array $vars        Block variables.
	 * @return array
	 */
	protected static function render_template_block( $template_id, array $vars ) {
		$data = SCC_Elementor::get_data( $template_id );
		if ( ! is_array( $data ) || ! class_exists( 'SCC_Placeholders' ) ) {
			return array();
		}
		// Flatten block variables into scalar token replacements.
		$repl = array();
		foreach ( $vars as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$repl[ $k ] = (string) $v;
			}
		}
		$filled = SCC_Placeholders::replace( $data, $repl );
		return self::regenerate_ids( is_array( $filled ) ? $filled : array() );
	}

	/* ---------------------------------------------------------------------
	 * Semantic HTML (native fallback + html-widget content)
	 * ------------------------------------------------------------------- */

	/**
	 * The semantic HTML for a block, wrapped in a section for the native copy.
	 *
	 * @param array  $block       Mapped block.
	 * @param string $palette_css Palette CSS vars.
	 * @return string
	 */
	protected static function block_html( array $block, $palette_css ) {
		$styleAttr = '' !== $palette_css ? ' style="' . esc_attr( $palette_css ) . '"' : '';
		$preset    = self::preset_class();

		// The content block becomes multiple alternating section bands.
		if ( 'content' === $block['id'] ) {
			$out = '';
			foreach ( self::split_sections( (string) ( $block['vars']['CONTENT'] ?? '' ) ) as $sec ) {
				$out .= '<section class="scc-block scc-block--content scc-sec scc-sec--' . esc_attr( $sec['style'] ) . ' scc-sec--readable ' . esc_attr( $preset ) . '"' . $styleAttr . '><div class="scc-sec__in">' . $sec['html'] . '</div></section>';
			}
			return $out;
		}

		$inner = self::inner_html( $block, $palette_css );
		if ( '' === trim( $inner ) ) {
			return '';
		}
		$secType = self::section_style( $block['id'] );
		$cls     = 'scc-block scc-block--' . $block['id'] . ' scc-sec scc-sec--' . $secType . ' ' . $preset;
		return '<section class="' . esc_attr( $cls ) . '"' . $styleAttr . '>' . '<div class="scc-sec__in">' . $inner . '</div></section>';
	}

	/**
	 * The design-preset CSS class ("modern" default). Tokens per preset live in
	 * the plugin front CSS.
	 *
	 * @return string e.g. "scc-preset--modern"
	 */
	public static function preset_class() {
		$p = class_exists( 'SCC_Settings' ) ? (string) SCC_Settings::get( 'layout_design_preset', 'modern' ) : 'modern';
		$p = sanitize_key( $p );
		$allowed = array( 'modern', 'professional', 'bold' );
		if ( ! in_array( $p, $allowed, true ) ) {
			$p = 'modern';
		}
		return 'scc-preset--' . $p;
	}

	/**
	 * The inner markup for a block (no section wrapper).
	 *
	 * @param array  $block       Mapped block.
	 * @param string $palette_css Palette CSS vars (unused inline here).
	 * @return string
	 */
	protected static function inner_html( array $block, $palette_css ) {
		$vars   = (array) $block['vars'];
		$render = (string) $block['render'];

		switch ( $render ) {
			case 'hero':
				$h = '';
				if ( '' !== trim( (string) ( $vars['HERO_EYEBROW'] ?? '' ) ) ) {
					$h .= '<p class="scc-eyebrow">' . esc_html( (string) $vars['HERO_EYEBROW'] ) . '</p>';
				}
				$h .= '<h1 class="scc-hero__title">' . esc_html( (string) $vars['HERO_TITLE'] ) . '</h1>';
				if ( '' !== trim( (string) ( $vars['HERO_SUBTITLE'] ?? '' ) ) ) {
					$h .= '<p class="scc-hero__sub">' . esc_html( (string) $vars['HERO_SUBTITLE'] ) . '</p>';
				}
				$h .= '<div class="scc-hero__cta">' . self::cta_link( (string) $vars['CTA_TEXT'], (string) $vars['CTA_URL'] ) . '</div>';
				return '<div class="scc-hero">' . $h . '</div>';

			case 'text':
				return '<p>' . esc_html( (string) ( $vars['INTRO'] ?? '' ) ) . '</p>';

			case 'html':
				if ( 'content' === $block['id'] ) {
					return (string) ( $vars['CONTENT'] ?? '' );
				}
				if ( isset( $vars['COMPARISON_HTML'] ) ) {
					return self::heading( $vars['SECTION_TITLE'] ?? '' ) . '<figure class="scc-table"><div class="scc-table__scroll">' . wp_kses_post( (string) $vars['COMPARISON_HTML'] ) . '</div></figure>';
				}
				if ( isset( $vars['HIGHLIGHT_TEXT'] ) ) {
					return '<aside class="scc-callout scc-callout--key"><p class="scc-callout__label">' . esc_html( (string) $vars['HIGHLIGHT_TITLE'] ) . '</p><p class="scc-callout__body">' . esc_html( (string) $vars['HIGHLIGHT_TEXT'] ) . '</p></aside>';
				}
				if ( isset( $vars['TOC_ITEMS'] ) ) {
					return self::toc_html( (array) $vars['TOC_ITEMS'] );
				}
				if ( isset( $vars['SPLIT_TEXT'] ) ) {
					$img = '' !== (string) ( $vars['SPLIT_IMAGE'] ?? '' ) ? '<img src="' . esc_url( (string) $vars['SPLIT_IMAGE'] ) . '" alt="' . esc_attr( (string) ( $vars['SPLIT_TITLE'] ?? '' ) ) . '">' : '';
					return '<div class="scc-split">' . $img . '<div class="scc-split__body">' . self::heading( $vars['SPLIT_TITLE'] ?? '', 'h2' ) . '<p>' . esc_html( (string) $vars['SPLIT_TEXT'] ) . '</p></div></div>';
				}
				if ( isset( $vars['QUOTE'] ) ) {
					return '' !== trim( (string) $vars['QUOTE'] ) ? '<blockquote class="scc-quote"><p>' . esc_html( (string) $vars['QUOTE'] ) . '</p><cite>' . esc_html( (string) ( $vars['ATTRIBUTION'] ?? '' ) ) . '</cite></blockquote>' : '';
				}
				return '';

			case 'list': // benefits
				$items = '';
				foreach ( (array) ( $vars['BENEFIT_ITEMS'] ?? array() ) as $b ) {
					$items .= '<li>' . esc_html( (string) $b ) . '</li>';
				}
				return '' !== $items ? self::heading( $vars['SECTION_TITLE'] ?? '' ) . '<ul class="scc-benefits">' . $items . '</ul>' : '';

			case 'grid': // service / feature cards
				$cards = '';
				$n     = 0;
				foreach ( (array) ( $vars['CARDS'] ?? array() ) as $c ) {
					$title = esc_html( (string) ( $c['SERVICE_TITLE'] ?? '' ) );
					if ( '' === $title ) {
						continue;
					}
					$desc = '' !== (string) ( $c['SERVICE_DESCRIPTION'] ?? '' ) ? '<p>' . esc_html( (string) $c['SERVICE_DESCRIPTION'] ) . '</p>' : '';
					$link = '' !== (string) ( $c['SERVICE_URL'] ?? '' ) ? '<a class="scc-card__link" href="' . esc_url( (string) $c['SERVICE_URL'] ) . '">' . esc_html__( 'Learn more', 'seo-command-center' ) . '</a>' : '';
					$cards .= '<div class="scc-card-item"><h3>' . $title . '</h3>' . $desc . $link . '</div>';
					$n++;
				}
				if ( '' === $cards ) {
					return '';
				}
				// 2 cards → two columns, 3 → three columns, 4+ → a swipeable
				// carousel (CSS scroll-snap, no JS, no Elementor Pro).
				if ( $n >= 4 ) {
					$mod  = 'scc-cards--carousel';
					$wrap = '<div class="scc-cards ' . $mod . '" role="list">' . $cards . '</div>'
						. '<p class="scc-carousel__hint">' . esc_html__( 'Swipe or scroll to see more →', 'seo-command-center' ) . '</p>';
				} else {
					$mod  = 'scc-cards--' . max( 1, min( 3, $n ) );
					$wrap = '<div class="scc-cards ' . $mod . '">' . $cards . '</div>';
				}
				return self::heading( $vars['SECTION_TITLE'] ?? '' ) . $wrap;

			case 'stats':
				$cells = '';
				foreach ( (array) ( $vars['STATS'] ?? array() ) as $s ) {
					$val = esc_html( (string) ( $s['value'] ?? '' ) );
					if ( '' === $val ) {
						continue;
					}
					$lbl = '' !== (string) ( $s['label'] ?? '' ) ? '<span class="scc-stat__label">' . esc_html( (string) $s['label'] ) . '</span>' : '';
					$cells .= '<div class="scc-stat"><span class="scc-stat__value">' . $val . '</span>' . $lbl . '</div>';
				}
				return '' !== $cells ? self::heading( $vars['SECTION_TITLE'] ?? '' ) . '<div class="scc-stats">' . $cells . '</div>' : '';

			case 'steps':
				$items = '';
				$count = 0;
				foreach ( (array) ( $vars['STEPS'] ?? array() ) as $st ) {
					$t = esc_html( (string) ( $st['title'] ?? '' ) );
					if ( '' === $t ) {
						continue;
					}
					$d = '' !== (string) ( $st['description'] ?? '' ) ? '<span>' . esc_html( (string) $st['description'] ) . '</span>' : '';
					$items .= '<li><strong>' . $t . '</strong>' . $d . '</li>';
					$count++;
				}
				// 3–4 short steps read best as a connected horizontal row; more (or
				// longer) steps stack as a numbered vertical list.
				$row = ( $count >= 3 && $count <= 4 ) ? ' scc-steps--row' : '';
				return '' !== $items ? self::heading( $vars['SECTION_TITLE'] ?? '' ) . '<ol class="scc-steps' . $row . '">' . $items . '</ol>' : '';

			case 'faq':
				return self::faq_html( (array) ( $vars['FAQ_ITEMS'] ?? array() ), (string) ( $vars['SECTION_TITLE'] ?? '' ) );

			case 'related':
				$items = '';
				$list  = ! empty( $vars['RELATED_ITEMS'] ) ? (array) $vars['RELATED_ITEMS'] : (array) ( $vars['AREA_ITEMS'] ?? array() );
				foreach ( $list as $r ) {
					$title = esc_html( (string) ( $r['title'] ?? '' ) );
					if ( '' === $title ) {
						continue;
					}
					$url = (string) ( $r['url'] ?? '' );
					$items .= '' !== $url
						? '<li><a href="' . esc_url( $url ) . '">' . $title . '</a></li>'
						: '<li>' . $title . '</li>';
				}
				return '' !== $items ? self::heading( $vars['SECTION_TITLE'] ?? '' ) . '<ul class="scc-related">' . $items . '</ul>' : '';

			case 'cta':
				$body = '' !== (string) ( $vars['CTA_TEXT'] ?? '' ) ? '<p>' . esc_html( (string) $vars['CTA_TEXT'] ) . '</p>' : '';
				return '<div class="scc-cta">' . self::heading( $vars['CTA_TITLE'] ?? '', 'h2' ) . $body . self::cta_link( (string) $vars['CTA_TEXT'], (string) $vars['CTA_URL'] ) . '</div>';
		}
		return '';
	}

	/**
	 * FAQ accordions (accessible, CSS-only) matching the plugin's front styles.
	 *
	 * @param array  $faqs  FAQ items.
	 * @param string $title Section title.
	 * @return string
	 */
	protected static function faq_html( array $faqs, $title ) {
		$items = '';
		foreach ( $faqs as $f ) {
			$q = esc_html( (string) ( $f['question'] ?? '' ) );
			$a = esc_html( (string) ( $f['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$items .= '<details class="scc-faq__item"><summary class="scc-faq__q">' . $q . '</summary><div class="scc-faq__a"><p>' . $a . '</p></div></details>';
		}
		if ( '' === $items ) {
			return '';
		}
		return self::heading( $title ) . '<div class="scc-faq">' . $items . '</div>';
	}

	/**
	 * Table-of-contents nav from H2 anchors.
	 *
	 * @param array $items [{text, anchor}].
	 * @return string
	 */
	protected static function toc_html( array $items ) {
		$li = '';
		foreach ( $items as $it ) {
			$text = esc_html( (string) ( $it['text'] ?? '' ) );
			$anch = sanitize_title( (string) ( $it['anchor'] ?? $text ) );
			if ( '' === $text || '' === $anch ) {
				continue;
			}
			$li .= '<li><a href="#' . esc_attr( $anch ) . '">' . $text . '</a></li>';
		}
		return '' !== $li ? '<nav class="scc-toc" aria-label="' . esc_attr__( 'Table of contents', 'seo-command-center' ) . '"><p class="scc-toc__title">' . esc_html__( 'On this page', 'seo-command-center' ) . '</p><ul>' . $li . '</ul></nav>' : '';
	}

	/**
	 * A heading, escaped.
	 *
	 * @param string $text Text.
	 * @param string $tag  h2/h3.
	 * @return string
	 */
	protected static function heading( $text, $tag = 'h2' ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$tag = in_array( $tag, array( 'h2', 'h3' ), true ) ? $tag : 'h2';
		return '<' . $tag . ' class="scc-block__title">' . esc_html( $text ) . '</' . $tag . '>';
	}

	/**
	 * A CTA link (button) — validated URL, never empty text.
	 *
	 * @param string $text Text.
	 * @param string $url  URL.
	 * @return string
	 */
	protected static function cta_link( $text, $url ) {
		$url  = esc_url( (string) $url );
		$text = trim( (string) $text );
		if ( '' === $url || '' === $text ) {
			return '';
		}
		return '<a class="scc-btn" href="' . $url . '">' . esc_html( $text ) . '</a>';
	}

	/* --------------------------------------------------------------------- */

	/**
	 * The block → Elementor template map (option), sanitized to {block: id}.
	 *
	 * @return array<string,int>
	 */
	public static function template_map() {
		$raw = get_option( 'scc_block_template_map', array() );
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $block => $tid ) {
				$block = sanitize_key( (string) $block );
				$tid   = (int) $tid;
				if ( '' !== $block && $tid > 0 && SCC_Block_Registry::exists( $block ) ) {
					$out[ $block ] = $tid;
				}
			}
		}
		return $out;
	}

	/**
	 * Recursively assign fresh Elementor element IDs.
	 *
	 * @param array $elements Elements.
	 * @return array
	 */
	protected static function regenerate_ids( array $elements ) {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) ) {
				$el['id'] = self::new_id();
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::regenerate_ids( $el['elements'] );
			}
		}
		return $elements;
	}

	/**
	 * Elementor-style 7-char hex element id.
	 *
	 * @return string
	 */
	protected static function new_id() {
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
	}
}
