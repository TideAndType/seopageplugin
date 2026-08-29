<?php
/**
 * WordPress content analyzer.
 *
 * Enumerates posts/pages/CPTs via WP_Query and computes per-URL SEO signals
 * (headings, links, images, word count, metadata, schema, Elementor usage).
 * This is the authoritative, network-free analysis path.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyzer.
 */
class SCC_Analyzer {

	const THIN_CONTENT_WORDS = 300;

	/** Max rendered-page fetches per analysis (to confirm H1 on themed pages). */
	const RENDER_BUDGET = 60;

	/** @var int Remaining rendered fetches for the current run. */
	protected $render_budget = 0;

	/** @var bool Whether to deep-scan (fetch rendered pages) for every post. */
	protected $deep = false;

	/**
	 * Post types to analyze.
	 *
	 * @return string[]
	 */
	public static function analyzable_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		/**
		 * Filter the analyzable post types.
		 *
		 * @param string[] $types Post type names.
		 */
		return apply_filters( 'scc_analyzable_post_types', array_values( $types ) );
	}

	/**
	 * Run an analysis and persist it.
	 *
	 * @param array $args {
	 *     @type string[] $post_types Post types (default: analyzable).
	 *     @type int      $limit      Max posts to analyze (default 200).
	 * }
	 * @return array Summary with analysis_id.
	 */
	public function run( array $args = array() ) {
		$post_types = ! empty( $args['post_types'] ) ? array_map( 'sanitize_key', (array) $args['post_types'] ) : self::analyzable_post_types();
		$limit      = isset( $args['limit'] ) ? SCC_Security::sanitize_int( $args['limit'], 1, 2000 ) : 200;

		// Rendered-page verification lets us confirm H1s that themes/Elementor
		// output outside post_content. "deep" fetches every post; otherwise we
		// fetch only when a page would otherwise be flagged, within a budget.
		$this->deep          = ! empty( $args['deep'] );
		$this->render_budget = self::RENDER_BUDGET;

		$analysis_id = SCC_DB::insert(
			'analyses',
			array(
				'created_at' => current_time( 'mysql' ),
				'status'     => 'running',
				'type'       => 'site',
			),
			array( '%s', '%s', '%s' )
		);

		$query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'ignore_sticky_posts' => true,
			)
		);

		$totals = array(
			'pages'              => 0,
			'posts'              => 0,
			'other'              => 0,
			'analyzed'           => 0,
			'internal_links'     => 0,
			'missing_meta'       => 0,
			'thin_content'       => 0,
			'no_h1'              => 0,
			'images_missing_alt' => 0,
			'elementor_pages'    => 0,
			'has_schema'         => 0,
		);

		$title_map = array(); // For simple cannibalization heuristic.

		foreach ( $query->posts as $post ) {
			$item = $this->analyze_post( $post );

			SCC_DB::insert(
				'analysis_items',
				array(
					'analysis_id'        => $analysis_id,
					'post_id'            => $item['post_id'],
					'url'                => $item['url'],
					'post_type'          => $item['post_type'],
					'title'              => $item['title'],
					'h1'                 => $item['h1'],
					'meta_title'         => $item['meta_title'],
					'meta_description'   => $item['meta_description'],
					'word_count'         => $item['word_count'],
					'internal_links'     => $item['internal_links'],
					'external_links'     => $item['external_links'],
					'images'             => $item['images'],
					'images_missing_alt' => $item['images_missing_alt'],
					'has_schema'         => $item['has_schema'],
					'is_elementor'       => $item['is_elementor'],
					'flags'              => wp_json_encode( $item['flags'] ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
			);

			// Totals.
			$totals['analyzed']++;
			if ( 'page' === $item['post_type'] ) {
				$totals['pages']++;
			} elseif ( 'post' === $item['post_type'] ) {
				$totals['posts']++;
			} else {
				$totals['other']++;
			}
			$totals['internal_links'] += $item['internal_links'];
			$totals['images_missing_alt'] += $item['images_missing_alt'];
			if ( in_array( 'missing_meta', $item['flags'], true ) ) {
				$totals['missing_meta']++;
			}
			if ( in_array( 'thin_content', $item['flags'], true ) ) {
				$totals['thin_content']++;
			}
			if ( in_array( 'no_h1', $item['flags'], true ) ) {
				$totals['no_h1']++;
			}
			if ( $item['is_elementor'] ) {
				$totals['elementor_pages']++;
			}
			if ( $item['has_schema'] ) {
				$totals['has_schema']++;
			}

			$norm = $this->normalize_title( $item['title'] );
			if ( '' !== $norm ) {
				$title_map[ $norm ][] = array( 'post_id' => $item['post_id'], 'title' => $item['title'], 'url' => $item['url'] );
			}
		}

		// Simple cannibalization heuristic: same normalized title on 2+ URLs.
		$cannibalization = array();
		foreach ( $title_map as $norm => $group ) {
			if ( count( $group ) > 1 ) {
				$cannibalization[] = array( 'topic' => $norm, 'pages' => $group );
			}
		}

		$summary = array(
			'totals'          => $totals,
			'cannibalization' => $cannibalization,
			'orphans'         => $this->find_orphans( $analysis_id ),
			'seo_plugin'      => SCC_SEO_Meta::label( SCC_SEO_Meta::detect() ),
		);

		SCC_DB::update(
			'analyses',
			array(
				'status'  => 'complete',
				'summary' => wp_json_encode( $summary ),
				'totals'  => wp_json_encode( $totals ),
			),
			array( 'id' => $analysis_id )
		);

		SCC_Logger::info( 'analyzer', 'Analysis complete', array( 'analysis_id' => $analysis_id, 'analyzed' => $totals['analyzed'] ) );

		return array(
			'analysis_id' => $analysis_id,
			'summary'     => $summary,
		);
	}

	/**
	 * Analyze a single post object.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public function analyze_post( $post ) {
		$content_raw = $post->post_content;
		$content     = $this->maybe_elementor_text( $post, $content_raw );
		$is_elementor = (bool) get_post_meta( $post->ID, '_elementor_edit_mode', true );

		// Word count from rendered text.
		$text       = wp_strip_all_tags( $content );
		$word_count = str_word_count( $text );

		// Parse HTML for headings/links/images.
		$parsed = $this->parse_html( $content_raw, $post->ID );

		// Elementor pages keep their headings in _elementor_data, not in
		// post_content — pull H1 heading widgets from there too.
		if ( $is_elementor && empty( $parsed['h1'] ) ) {
			$el_h1 = $this->extract_elementor_headings( $post->ID );
			if ( ! empty( $el_h1 ) ) {
				$parsed['h1'] = $el_h1;
			}
		}

		// Rendered-page verification: themes commonly output the page title as
		// the <h1> (outside post_content/Elementor data). Confirm against the
		// real rendered HTML — always in deep mode, otherwise only when the page
		// would otherwise be flagged and we still have fetch budget. Also picks
		// up schema output by the theme/SEO plugin.
		$need_render = $this->deep || ( empty( $parsed['h1'] ) && 'publish' === $post->post_status );
		if ( $need_render && $this->render_budget > 0 ) {
			$rendered = $this->rendered_signals( $post );
			if ( is_array( $rendered ) ) {
				if ( ! empty( $rendered['h1'] ) ) {
					$parsed['h1'] = $rendered['h1'];
				}
				if ( ! empty( $rendered['has_schema'] ) ) {
					$parsed['has_schema'] = true;
				}
			}
		}

		$meta_title = SCC_SEO_Meta::get_title( $post->ID );
		$meta_desc  = SCC_SEO_Meta::get_description( $post->ID );

		$flags = array();
		if ( '' === trim( $meta_desc ) ) {
			$flags[] = 'missing_meta';
		}
		if ( $word_count < self::THIN_CONTENT_WORDS ) {
			$flags[] = 'thin_content';
		}
		if ( empty( $parsed['h1'] ) ) {
			$flags[] = 'no_h1';
		}
		if ( 0 === $parsed['internal_links'] ) {
			$flags[] = 'no_internal_links';
		}

		return array(
			'post_id'            => (int) $post->ID,
			'url'                => get_permalink( $post ),
			'post_type'          => $post->post_type,
			'title'              => get_the_title( $post ),
			'h1'                 => ! empty( $parsed['h1'] ) ? $parsed['h1'][0] : '',
			'meta_title'         => $meta_title,
			'meta_description'   => $meta_desc,
			'word_count'         => $word_count,
			'internal_links'     => $parsed['internal_links'],
			'external_links'     => $parsed['external_links'],
			'images'             => $parsed['images'],
			'images_missing_alt' => $parsed['images_missing_alt'],
			'has_schema'         => $parsed['has_schema'] ? 1 : 0,
			'is_elementor'       => $is_elementor ? 1 : 0,
			'flags'              => $flags,
		);
	}

	/**
	 * Extract H1 heading texts from a post's Elementor data.
	 *
	 * Looks for heading widgets whose header_size is h1, plus Elementor's page/
	 * post title widgets (which render as h1 by default).
	 *
	 * @param int $post_id Post id.
	 * @return string[]
	 */
	protected function extract_elementor_headings( $post_id ) {
		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $data ) || ! is_string( $data ) ) {
			return array();
		}
		$decoded = json_decode( $data, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$h1 = array();
		$this->walk_elementor_headings( $decoded, $h1, get_the_title( $post_id ) );
		return $h1;
	}

	/**
	 * Recursively collect H1 headings from an Elementor element tree.
	 *
	 * @param array  $elements   Elements.
	 * @param array  $h1         Collected H1 texts (by ref).
	 * @param string $post_title Current post title (for title widgets).
	 */
	protected function walk_elementor_headings( array $elements, array &$h1, $post_title = '' ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$widget   = isset( $el['widgetType'] ) ? $el['widgetType'] : '';
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();

			if ( 'heading' === $widget ) {
				$size  = isset( $settings['header_size'] ) ? strtolower( (string) $settings['header_size'] ) : 'h2';
				$title = isset( $settings['title'] ) ? trim( wp_strip_all_tags( (string) $settings['title'] ) ) : '';
				if ( 'h1' === $size && '' !== $title ) {
					$h1[] = $title;
				}
			} elseif ( in_array( $widget, array( 'theme-page-title', 'theme-post-title' ), true ) ) {
				// Elementor title widgets render the current post title as h1
				// unless the size is overridden.
				$size = isset( $settings['header_size'] ) ? strtolower( (string) $settings['header_size'] ) : 'h1';
				if ( 'h1' === $size && '' !== $post_title ) {
					$h1[] = $post_title;
				}
			}

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$this->walk_elementor_headings( $el['elements'], $h1, $post_title );
			}
		}
	}

	/**
	 * Fetch the rendered permalink and read authoritative on-page signals
	 * (real H1s, schema). Consumes the per-run fetch budget.
	 *
	 * @param WP_Post $post Post.
	 * @return array|null {h1:string[], has_schema:bool}
	 */
	protected function rendered_signals( $post ) {
		$url = get_permalink( $post );
		if ( ! $url ) {
			return null;
		}
		$this->render_budget--;

		$crawler = new SCC_Crawler();
		// Internal URL: no robots restriction needed.
		$data = $crawler->fetch( $url, false );
		if ( is_wp_error( $data ) ) {
			return null;
		}
		return array(
			'h1'         => isset( $data['h1'] ) ? (array) $data['h1'] : array(),
			'has_schema' => ! empty( $data['schema_types'] ),
		);
	}

	/**
	 * If the post is built with Elementor, extract readable text from its data.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $default Default content.
	 * @return string
	 */
	protected function maybe_elementor_text( $post, $default ) {
		$data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( empty( $data ) ) {
			return $default;
		}
		$decoded = json_decode( $data, true );
		if ( ! is_array( $decoded ) ) {
			return $default;
		}
		$text = $this->collect_elementor_text( $decoded );
		return $text ? $text : $default;
	}

	/**
	 * Recursively collect text-like settings from Elementor element tree.
	 *
	 * @param array $elements Elements.
	 * @return string
	 */
	protected function collect_elementor_text( array $elements ) {
		$buffer = '';
		foreach ( $elements as $el ) {
			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
				foreach ( $el['settings'] as $key => $value ) {
					if ( is_string( $value ) && in_array( $key, array( 'title', 'editor', 'text', 'description', 'heading_title', 'title_text', 'description_text' ), true ) ) {
						$buffer .= ' ' . $value;
					}
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$buffer .= ' ' . $this->collect_elementor_text( $el['elements'] );
			}
		}
		return $buffer;
	}

	/**
	 * Parse post HTML for headings, links, images, schema.
	 *
	 * @param string $html    HTML content.
	 * @param int    $post_id Post id (for internal-host comparison).
	 * @return array
	 */
	protected function parse_html( $html, $post_id ) {
		$result = array(
			'h1'                 => array(),
			'internal_links'     => 0,
			'external_links'     => 0,
			'images'             => 0,
			'images_missing_alt' => 0,
			'has_schema'         => false,
		);
		if ( '' === trim( (string) $html ) ) {
			return $result;
		}

		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xpath = new DOMXPath( $dom );

		foreach ( $xpath->query( '//h1' ) as $node ) {
			$result['h1'][] = trim( $node->textContent );
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $xpath->query( '//a[@href]' ) as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( 0 === strpos( $href, '#' ) ) {
				continue;
			}
			$link_host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $link_host || $link_host === $home_host || 0 === strpos( $href, '/' ) ) {
				$result['internal_links']++;
			} else {
				$result['external_links']++;
			}
		}

		$imgs = $xpath->query( '//img' );
		$result['images'] = $imgs ? $imgs->length : 0;
		foreach ( $imgs as $img ) {
			if ( '' === trim( $img->getAttribute( 'alt' ) ) ) {
				$result['images_missing_alt']++;
			}
		}

		// Schema present either inline in content or provided by SEO plugin.
		if ( $xpath->query( '//script[@type="application/ld+json"]' )->length > 0 ) {
			$result['has_schema'] = true;
		}

		return $result;
	}

	/**
	 * Find orphan posts (no internal links pointing to them from analyzed set).
	 * Heuristic based on this analysis run's items.
	 *
	 * @param int $analysis_id Analysis id.
	 * @return array List of {post_id,title,url}.
	 */
	protected function find_orphans( $analysis_id ) {
		global $wpdb;
		$table = SCC_DB::table( 'analysis_items' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, title, url FROM {$table} WHERE analysis_id = %d AND internal_links = 0 AND post_id > 0 LIMIT 50", $analysis_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows ? $rows : array();
	}

	/**
	 * Normalize a title for cannibalization comparison.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	protected function normalize_title( $title ) {
		$title = strtolower( wp_strip_all_tags( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9 ]+/', '', $title );
		$title = preg_replace( '/\b(the|a|an|and|or|for|of|in|to|services?|company)\b/', '', $title );
		return trim( preg_replace( '/\s+/', ' ', $title ) );
	}

	/**
	 * Fetch the latest completed analysis summary + items.
	 *
	 * @return array|null
	 */
	public static function latest() {
		global $wpdb;
		$analyses = SCC_DB::table( 'analyses' );
		$row = $wpdb->get_row( "SELECT * FROM {$analyses} WHERE status = 'complete' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$items_table = SCC_DB::table( 'analysis_items' );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items_table} WHERE analysis_id = %d ORDER BY word_count ASC LIMIT 200", (int) $row['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$row['summary_data'] = json_decode( $row['summary'], true );
		$row['items'] = $items ? $items : array();
		return $row;
	}
}
