<?php
/**
 * Answer Engine Optimization (AEO) Expert.
 *
 * A factual, measurable AI-citation readiness layer. It does not pretend to know
 * whether ChatGPT, Google AI Overviews, Gemini, Perplexity or Copilot cited a
 * page unless a real provider supplies that measurement. Instead it audits the
 * conditions site owners can control: crawl access, indexability, answer-ready
 * structure, entity clarity, structured data, source/evidence signals,
 * freshness, authorship and discoverability.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_AEO_Expert {

	const ROBOTS_CACHE = 'scc_aeo_robots_cache';

	/**
	 * Site-level AEO report.
	 *
	 * @param int $limit Max published pages to inspect.
	 * @return array
	 */
	public static function site_report( $limit = 60 ) {
		$limit = max( 1, min( 200, (int) $limit ) );
		$crawl = self::crawlability();
		$pages = array();

		if ( class_exists( 'SCC_Content_Index' ) && class_exists( 'WP_Query' ) && 0 === SCC_Content_Index::count() ) {
			SCC_Content_Index::reindex_all( max( 100, $limit * 3 ) );
		}
		if ( class_exists( 'SCC_Content_Index' ) ) {
			foreach ( SCC_Content_Index::all( max( 100, $limit * 3 ) ) as $row ) {
				if ( count( $pages ) >= $limit ) {
					break;
				}
				$post_id = (int) ( $row['post_id'] ?? 0 );
				$post = $post_id ? get_post( $post_id ) : null;
				if ( ! $post || 'publish' !== (string) $post->post_status ) {
					continue;
				}
				$pages[] = self::page_report( $post_id, $row );
			}
		}

		$factor_totals = array();
		$factor_counts = array();
		$total = 0;
		foreach ( $pages as $page ) {
			$total += (int) ( $page['score'] ?? 0 );
			foreach ( (array) ( $page['factors'] ?? array() ) as $key => $factor ) {
				$factor_totals[ $key ] = ( $factor_totals[ $key ] ?? 0 ) + (int) ( $factor['score'] ?? 0 );
				$factor_counts[ $key ] = ( $factor_counts[ $key ] ?? 0 ) + 1;
			}
		}

		$factor_summary = array();
		$labels = self::factor_labels();
		foreach ( $labels as $key => $label ) {
			$count = (int) ( $factor_counts[ $key ] ?? 0 );
			$factor_summary[ $key ] = array(
				'label' => $label,
				'score' => $count ? (int) round( $factor_totals[ $key ] / $count ) : 0,
			);
		}

		$score = count( $pages ) ? (int) round( $total / count( $pages ) ) : 0;
		if ( empty( $crawl['wordpress_indexable'] ) ) {
			$score = min( $score, 20 );
		}
		if ( isset( $crawl['oai_searchbot_allowed'] ) && false === $crawl['oai_searchbot_allowed'] ) {
			$score = max( 0, $score - 8 );
		}

		usort(
			$pages,
			function ( $a, $b ) {
				return (int) ( $a['score'] ?? 0 ) <=> (int) ( $b['score'] ?? 0 );
			}
		);

		$entities = class_exists( 'SCC_Entity_Graph' ) ? SCC_Entity_Graph::build() : array();
		$recommendations = self::site_recommendations( $crawl, $factor_summary, $entities, $pages );

		return array(
			'score'           => $score,
			'label'           => self::score_label( $score ),
			'crawlability'    => $crawl,
			'factors'         => $factor_summary,
			'pages_analyzed'  => count( $pages ),
			'weakest_pages'   => array_slice( $pages, 0, 12 ),
			'recommendations' => $recommendations,
			'entity_coverage' => isset( $entities['coverage'] ) ? (int) $entities['coverage'] : null,
			'guidance'        => self::guidance(),
			'disclaimer'      => __( 'AEO Readiness is a TideOrbit diagnostic, not a promise of inclusion or citation by any AI system. Measured citation counts require a first-party/provider integration.', 'seo-command-center' ),
		);
	}

	/**
	 * Audit one page.
	 *
	 * @param int        $post_id Post id.
	 * @param array|null $indexed Optional decoded Content Index row.
	 * @return array
	 */
	public static function page_report( $post_id, $indexed = null ) {
		$post_id = (int) $post_id;
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'post_id' => $post_id, 'score' => 0, 'factors' => array(), 'recommendations' => array() );
		}
		if ( null === $indexed && class_exists( 'SCC_Content_Index' ) ) {
			$indexed = SCC_Content_Index::get( $post_id );
		}
		$indexed = is_array( $indexed ) ? $indexed : array();

		$html = self::page_html( $post );
		$text = class_exists( 'SCC_Content_Index' ) ? SCC_Content_Index::get_plain_text( $post ) : wp_strip_all_tags( $html );
		$words = str_word_count( $text );
		$headings = (array) ( $indexed['headings'] ?? array() );
		$questions = 0;
		foreach ( $headings as $heading ) {
			$h = trim( (string) $heading );
			if ( false !== strpos( $h, '?' ) || preg_match( '/^(what|why|how|when|where|who|which|can|should|does|do|is|are)\b/i', $h ) ) {
				$questions++;
			}
		}

		$external_citations = self::external_links( $html );
		$schema_raw = get_post_meta( $post_id, '_scc_schema', true );
		$has_tideorbit_schema = ! empty( $schema_raw );
		$seo_plugin = class_exists( 'SCC_SEO_Meta' ) ? SCC_SEO_Meta::detect() : '';
		$has_schema_provider = $has_tideorbit_schema || ( '' !== (string) $seo_plugin && 'none' !== (string) $seo_plugin );

		$business = class_exists( 'SCC_Schema_Engine' ) ? SCC_Schema_Engine::business() : array();
		$org = trim( (string) ( $business['organization_name'] ?? '' ) );
		$default_author = trim( (string) ( $business['default_author'] ?? '' ) );
		$author_name = function_exists( 'get_the_author_meta' ) ? trim( (string) get_the_author_meta( 'display_name', $post->post_author ) ) : '';
		$has_author = '' !== $default_author || '' !== $author_name || '' !== $org;

		$modified = (string) ( $post->post_modified_gmt ?? '' );
		$days_old = null;
		if ( '' !== $modified && '0000-00-00 00:00:00' !== $modified ) {
			$ts = strtotime( $modified . ' UTC' );
			if ( $ts ) {
				$days_old = max( 0, (int) floor( ( time() - $ts ) / DAY_IN_SECONDS ) );
			}
		}

		$brand = class_exists( 'SCC_Brand_Brain' ) ? SCC_Brand_Brain::profile() : array();
		$proof = array_merge(
			(array) ( $brand['proof_points'] ?? array() ),
			(array) ( $brand['credentials'] ?? array() )
		);
		$proof_present = false;
		foreach ( $proof as $item ) {
			$item = trim( wp_strip_all_tags( (string) $item ) );
			if ( strlen( $item ) >= 8 && false !== stripos( $text, $item ) ) {
				$proof_present = true;
				break;
			}
		}

		$signals = array(
			'word_count'          => $words,
			'heading_count'       => count( $headings ),
			'question_headings'   => $questions,
			'external_citations'  => count( $external_citations ),
			'has_schema'          => $has_schema_provider,
			'has_author'          => $has_author,
			'days_since_modified' => $days_old,
			'has_first_party_proof'=> $proof_present,
			'has_clear_title'     => '' !== trim( get_the_title( $post_id ) ),
			'is_published'        => 'publish' === (string) $post->post_status,
		);

		$analysis = self::analyze_signals( $signals );
		$analysis['post_id'] = $post_id;
		$analysis['title']   = get_the_title( $post_id );
		$analysis['url']     = get_permalink( $post_id );
		$analysis['signals'] = $signals;
		$analysis['external_sources'] = array_slice( $external_citations, 0, 8 );
		return $analysis;
	}

	/**
	 * Pure signal scoring, suitable for regression tests.
	 *
	 * Weights:
	 * answerability 25, evidence 20, entity clarity 15, structured data 15,
	 * freshness 10, authorship 10, textual depth 5.
	 *
	 * @param array $s Signals.
	 * @return array
	 */
	public static function analyze_signals( array $s ) {
		$words = (int) ( $s['word_count'] ?? 0 );
		$heads = (int) ( $s['heading_count'] ?? 0 );
		$questions = (int) ( $s['question_headings'] ?? 0 );
		$sources = (int) ( $s['external_citations'] ?? 0 );
		$days = isset( $s['days_since_modified'] ) ? $s['days_since_modified'] : null;

		$answerability = min( 100, ( $heads >= 3 ? 45 : ( $heads > 0 ? 25 : 0 ) ) + ( $questions >= 2 ? 55 : ( $questions > 0 ? 35 : 0 ) ) );
		$evidence = min( 100, ( ! empty( $s['has_first_party_proof'] ) ? 55 : 0 ) + ( $sources >= 2 ? 45 : ( $sources > 0 ? 25 : 0 ) ) );
		$entity = ! empty( $s['has_clear_title'] ) ? 55 : 0;
		if ( $heads >= 2 ) {
			$entity += 25;
		}
		if ( ! empty( $s['has_schema'] ) ) {
			$entity += 20;
		}
		$entity = min( 100, $entity );
		$schema = ! empty( $s['has_schema'] ) ? 100 : 0;
		$authorship = ! empty( $s['has_author'] ) ? 100 : 0;
		$depth = $words >= 700 ? 100 : ( $words >= 350 ? 70 : ( $words >= 150 ? 35 : 10 ) );
		if ( null === $days ) {
			$freshness = 50;
		} elseif ( $days <= 180 ) {
			$freshness = 100;
		} elseif ( $days <= 365 ) {
			$freshness = 75;
		} elseif ( $days <= 730 ) {
			$freshness = 45;
		} else {
			$freshness = 20;
		}

		$factors = array(
			'answerability' => array( 'label' => __( 'Direct answer structure', 'seo-command-center' ), 'score' => $answerability ),
			'evidence'      => array( 'label' => __( 'Evidence & source support', 'seo-command-center' ), 'score' => $evidence ),
			'entities'      => array( 'label' => __( 'Entity clarity', 'seo-command-center' ), 'score' => $entity ),
			'schema'        => array( 'label' => __( 'Structured data', 'seo-command-center' ), 'score' => $schema ),
			'freshness'     => array( 'label' => __( 'Freshness', 'seo-command-center' ), 'score' => $freshness ),
			'authorship'    => array( 'label' => __( 'Authorship / publisher identity', 'seo-command-center' ), 'score' => $authorship ),
			'text_depth'    => array( 'label' => __( 'Useful textual depth', 'seo-command-center' ), 'score' => $depth ),
		);

		$score = (int) round(
			( 0.25 * $answerability )
			+ ( 0.20 * $evidence )
			+ ( 0.15 * $entity )
			+ ( 0.15 * $schema )
			+ ( 0.10 * $freshness )
			+ ( 0.10 * $authorship )
			+ ( 0.05 * $depth )
		);

		$recs = array();
		if ( $answerability < 70 ) {
			$recs[] = __( 'Add concise answer-first sections under descriptive or question-based H2/H3 headings. Answer the question before expanding.', 'seo-command-center' );
		}
		if ( $evidence < 70 ) {
			$recs[] = __( 'Support factual claims with verifiable first-party evidence and links to authoritative primary sources where appropriate.', 'seo-command-center' );
		}
		if ( $entity < 70 ) {
			$recs[] = __( 'Clarify the page’s main service, organization, location and related entities consistently in headings and visible copy.', 'seo-command-center' );
		}
		if ( $schema < 70 ) {
			$recs[] = __( 'Add accurate page-appropriate structured data that matches the visible content. Do not add schema only for AI systems.', 'seo-command-center' );
		}
		if ( $authorship < 70 ) {
			$recs[] = __( 'Make the responsible author or organization clear and keep publisher identity consistent with site schema.', 'seo-command-center' );
		}
		if ( $freshness < 60 ) {
			$recs[] = __( 'Review dated facts and refresh the page when the underlying information has materially changed.', 'seo-command-center' );
		}

		return array(
			'score'           => $score,
			'label'           => self::score_label( $score ),
			'factors'         => $factors,
			'recommendations' => $recs,
		);
	}

	/**
	 * Crawl/index controls relevant to search and AI-answer discovery.
	 *
	 * @return array
	 */
	public static function crawlability() {
		$cached = get_transient( self::ROBOTS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = array(
			'wordpress_indexable'   => '0' !== (string) get_option( 'blog_public', '1' ),
			'robots_checked'        => false,
			'robots_url'            => home_url( '/robots.txt' ),
			'googlebot_allowed'     => null,
			'bingbot_allowed'       => null,
			'oai_searchbot_allowed' => null,
			'message'               => '',
		);

		$response = wp_remote_get(
			$result['robots_url'],
			array(
				'timeout'     => 5,
				'redirection' => 2,
				'user-agent'  => 'TideOrbit-AEO/1.0',
			)
		);
		if ( is_wp_error( $response ) ) {
			$result['message'] = __( 'robots.txt could not be checked from WordPress. Crawlability score excludes that check.', 'seo-command-center' );
			set_transient( self::ROBOTS_CACHE, $result, HOUR_IN_SECONDS );
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 400 ) {
			$body = (string) wp_remote_retrieve_body( $response );
			$result['robots_checked'] = true;
			if ( class_exists( 'SCC_Robots' ) ) {
				$result['googlebot_allowed']     = SCC_Robots::is_allowed( $body, '/', 'Googlebot' );
				$result['bingbot_allowed']       = SCC_Robots::is_allowed( $body, '/', 'bingbot' );
				$result['oai_searchbot_allowed'] = SCC_Robots::is_allowed( $body, '/', 'OAI-SearchBot' );
			}
		} else {
			$result['message'] = sprintf( __( 'robots.txt returned HTTP %d; verify crawler access manually.', 'seo-command-center' ), $code );
		}

		set_transient( self::ROBOTS_CACHE, $result, 6 * HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Current, non-myth-based AEO guidance TideOrbit encodes.
	 *
	 * @return array
	 */
	public static function guidance() {
		return array(
			array(
				'title' => __( 'Google AI features use normal Search eligibility', 'seo-command-center' ),
				'text'  => __( 'Google says AI Overviews and AI Mode do not require special AI schema or an AI text file. Crawlability, indexability, helpful original content, internal links and accurate structured data remain foundational.', 'seo-command-center' ),
			),
			array(
				'title' => __( 'ChatGPT Search needs OAI-SearchBot access', 'seo-command-center' ),
				'text'  => __( 'OpenAI says public pages can be surfaced and cited in ChatGPT Search when OAI-SearchBot is allowed. GPTBot training controls are separate from Search.', 'seo-command-center' ),
			),
			array(
				'title' => __( 'Bing can measure real AI citations', 'seo-command-center' ),
				'text'  => __( 'Bing Webmaster Tools AI Performance reports actual citations, cited URLs, grounding queries and citation-share signals. TideOrbit does not fabricate those measurements when no provider is connected.', 'seo-command-center' ),
			),
			array(
				'title' => __( 'Citable content is clear, specific and supportable', 'seo-command-center' ),
				'text'  => __( 'Use concise answers, descriptive headings, explicit entities, primary-source evidence, original examples/data, accurate authorship and content that is easy to retrieve as text.', 'seo-command-center' ),
			),
		);
	}

	protected static function site_recommendations( array $crawl, array $factors, array $entities, array $pages ) {
		$out = array();
		if ( empty( $crawl['wordpress_indexable'] ) ) {
			$out[] = self::rec( 'site-indexing', 100, __( 'Enable public search indexing', 'seo-command-center' ), __( 'WordPress is configured to discourage search engines, which blocks the foundation for SEO and AI-search discovery.', 'seo-command-center' ), __( 'Restore normal indexability before working on any AEO enhancements.', 'seo-command-center' ) );
		}
		if ( isset( $crawl['oai_searchbot_allowed'] ) && false === $crawl['oai_searchbot_allowed'] ) {
			$out[] = self::rec( 'oai-searchbot', 96, __( 'Allow OAI-SearchBot for ChatGPT Search', 'seo-command-center' ), __( 'Your robots.txt blocks OpenAI’s search crawler from the site root.', 'seo-command-center' ), __( 'Allow OAI-SearchBot on public pages you want eligible for ChatGPT Search citations. This is separate from GPTBot training controls.', 'seo-command-center' ) );
		}
		if ( isset( $crawl['googlebot_allowed'] ) && false === $crawl['googlebot_allowed'] ) {
			$out[] = self::rec( 'googlebot', 100, __( 'Restore Googlebot crawl access', 'seo-command-center' ), __( 'Googlebot appears blocked at the site root.', 'seo-command-center' ), __( 'Google AI features depend on normal Google Search crawl/index eligibility.', 'seo-command-center' ) );
		}
		if ( isset( $crawl['bingbot_allowed'] ) && false === $crawl['bingbot_allowed'] ) {
			$out[] = self::rec( 'bingbot', 92, __( 'Restore Bingbot crawl access', 'seo-command-center' ), __( 'Bingbot appears blocked at the site root.', 'seo-command-center' ), __( 'Restore Bing discovery before evaluating Copilot/Bing AI citation performance.', 'seo-command-center' ) );
		}

		$checks = array(
			'answerability' => array( 88, __( 'Build answer-ready sections on weak pages', 'seo-command-center' ), __( 'Too few pages use clear question/descriptive headings with concise answers.', 'seo-command-center' ), __( 'Improve extractability without creating thin FAQ-only pages.', 'seo-command-center' ) ),
			'evidence'      => array( 84, __( 'Strengthen evidence and primary-source support', 'seo-command-center' ), __( 'Pages lack enough verifiable first-party proof or authoritative source references.', 'seo-command-center' ), __( 'Make factual claims easier to trust, verify and cite.', 'seo-command-center' ) ),
			'schema'        => array( 78, __( 'Improve accurate structured-data coverage', 'seo-command-center' ), __( 'Structured data coverage is weak or inconsistent.', 'seo-command-center' ), __( 'Clarify page and entity meaning while keeping markup aligned with visible content.', 'seo-command-center' ) ),
			'authorship'    => array( 72, __( 'Clarify authorship and publisher identity', 'seo-command-center' ), __( 'Publisher/author identity is not consistently available.', 'seo-command-center' ), __( 'Make responsibility and provenance clearer to users and retrieval systems.', 'seo-command-center' ) ),
			'freshness'     => array( 60, __( 'Refresh stale factual pages', 'seo-command-center' ), __( 'A meaningful share of content has not been reviewed recently.', 'seo-command-center' ), __( 'Reduce the chance that answer engines retrieve outdated facts.', 'seo-command-center' ) ),
		);
		foreach ( $checks as $key => $cfg ) {
			$score = (int) ( $factors[ $key ]['score'] ?? 0 );
			if ( $score < 70 ) {
				$out[] = self::rec( 'factor-' . $key, $cfg[0], $cfg[1], $cfg[2], $cfg[3] );
			}
		}

		if ( ! empty( $entities['available'] ) && (int) ( $entities['coverage'] ?? 0 ) < 75 ) {
			$out[] = self::rec( 'entity-coverage', 76, __( 'Close entity-support gaps', 'seo-command-center' ), __( 'Important services or locations in your entity graph are not clearly supported by site content.', 'seo-command-center' ), __( 'Give each real business entity a clear, non-duplicative home in the architecture.', 'seo-command-center' ) );
		}

		foreach ( array_slice( $pages, 0, 4 ) as $page ) {
			if ( (int) ( $page['score'] ?? 0 ) >= 70 ) {
				continue;
			}
			$out[] = array(
				'key'      => 'page-' . (int) $page['post_id'],
				'priority' => 74,
				'title'    => sprintf( __( 'Improve AI citation readiness: %s', 'seo-command-center' ), (string) $page['title'] ),
				'url'      => (string) $page['url'],
				'reason'   => implode( ' ', array_slice( (array) $page['recommendations'], 0, 2 ) ),
				'outcome'  => __( 'Make this existing page easier to retrieve, understand, verify and cite.', 'seo-command-center' ),
			);
		}

		usort( $out, function ( $a, $b ) { return (int) $b['priority'] <=> (int) $a['priority']; } );
		return array_slice( $out, 0, 14 );
	}

	protected static function rec( $key, $priority, $title, $reason, $outcome ) {
		return array(
			'key'      => $key,
			'priority' => (int) $priority,
			'title'    => $title,
			'url'      => '',
			'reason'   => $reason,
			'outcome'  => $outcome,
		);
	}

	protected static function page_html( $post ) {
		$html = (string) $post->post_content;
		if ( class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_elementor_post( $post->ID ) ) {
			$data = SCC_Elementor::get_data( $post->ID );
			if ( is_array( $data ) ) {
				$html .= "\n" . self::collect_elementor_html( $data );
			}
		}
		return $html;
	}

	protected static function collect_elementor_html( array $elements ) {
		$out = '';
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			if ( 'heading' === (string) ( $el['widgetType'] ?? '' ) && ! empty( $settings['title'] ) ) {
				$tag = strtolower( (string) ( $settings['header_size'] ?? 'h2' ) );
				if ( ! in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
					$tag = 'h2';
				}
				$out .= '<' . $tag . '>' . esc_html( (string) $settings['title'] ) . '</' . $tag . '>';
			}
			if ( ! empty( $settings['editor'] ) && is_string( $settings['editor'] ) ) {
				$out .= (string) $settings['editor'];
			}
			if ( ! empty( $settings['link']['url'] ) ) {
				$out .= '<a href="' . esc_url( (string) $settings['link']['url'] ) . '">link</a>';
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$out .= self::collect_elementor_html( $el['elements'] );
			}
		}
		return $out;
	}

	protected static function external_links( $html ) {
		$out = array();
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( preg_match_all( '/<a\s[^>]*href=("|\')(.*?)\1[^>]*>/is', (string) $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$url = esc_url_raw( html_entity_decode( (string) $match[2] ) );
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( $host && $host !== $home_host ) {
					$out[] = $url;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	protected static function factor_labels() {
		return array(
			'answerability' => __( 'Direct answer structure', 'seo-command-center' ),
			'evidence'      => __( 'Evidence & sources', 'seo-command-center' ),
			'entities'      => __( 'Entity clarity', 'seo-command-center' ),
			'schema'        => __( 'Structured data', 'seo-command-center' ),
			'freshness'     => __( 'Freshness', 'seo-command-center' ),
			'authorship'    => __( 'Authorship / publisher identity', 'seo-command-center' ),
			'text_depth'    => __( 'Useful textual depth', 'seo-command-center' ),
		);
	}

	protected static function score_label( $score ) {
		$score = (int) $score;
		if ( $score >= 85 ) {
			return __( 'Strong', 'seo-command-center' );
		}
		if ( $score >= 70 ) {
			return __( 'Good foundation', 'seo-command-center' );
		}
		if ( $score >= 50 ) {
			return __( 'Needs work', 'seo-command-center' );
		}
		return __( 'Weak', 'seo-command-center' );
	}
}
