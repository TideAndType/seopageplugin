<?php
/**
 * Keyword / topical strategy builder.
 *
 * Turns structured business inputs into a structured topical map via the AI
 * layer (not a flat keyword list). Persists to scc_keyword_strategies.
 *
 * The output is explicitly AI strategic opinion — it is NOT measured search
 * volume or ranking data. When DataForSEO / GSC are connected (Phase 6) those
 * real metrics augment this map; until then nothing here is presented as a
 * measured number.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keyword strategy service.
 */
class SCC_Keyword_Strategy {

	/** @var SCC_AI_Manager */
	protected $ai;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager $ai AI manager.
	 */
	public function __construct( SCC_AI_Manager $ai ) {
		$this->ai = $ai;
	}

	/**
	 * Sanitize the raw business inputs.
	 *
	 * @param array $raw Raw input.
	 * @return array
	 */
	public static function sanitize_inputs( array $raw ) {
		$list = function ( $value ) {
			if ( is_array( $value ) ) {
				$items = $value;
			} else {
				$items = preg_split( '/[\r\n,]+/', (string) $value );
			}
			$items = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', (array) $items ) ) );
			return array_values( array_slice( $items, 0, 100 ) );
		};

		$map_type = strtolower( (string) ( $raw['map_type'] ?? 'seo' ) );
		$map_type = in_array( $map_type, array( 'seo', 'question', 'keyword' ), true ) ? $map_type : 'seo';
		$depth    = strtolower( (string) ( $raw['depth'] ?? 'standard' ) );
		$depth    = in_array( $depth, array( 'compact', 'standard', 'deep' ), true ) ? $depth : 'standard';

		return array(
			'business_name' => SCC_Security::sanitize_text( $raw['business_name'] ?? '' ),
			'description'   => SCC_Security::sanitize_textarea( $raw['description'] ?? '' ),
			'services'      => $list( $raw['services'] ?? '' ),
			'products'      => $list( $raw['products'] ?? '' ),
			'locations'     => $list( $raw['locations'] ?? '' ),
			'audience'      => SCC_Security::sanitize_textarea( $raw['audience'] ?? '' ),
			'competitors'   => $list( $raw['competitors'] ?? '' ),
			'seed_keywords' => $list( $raw['seed_keywords'] ?? '' ),
			'existing_pages'=> array_slice( $list( $raw['existing_pages'] ?? '' ), 0, 200 ),
			'website'       => esc_url_raw( $raw['website'] ?? home_url() ),
			// Generation controls (map type / language / depth).
			'map_type'      => $map_type,
			'language'      => SCC_Security::sanitize_text( $raw['language'] ?? '' ),
			'depth'         => $depth,
		);
	}

	/**
	 * Infer strategy inputs from the existing site (site identity + published
	 * page/post titles), so a user can build a plan with one click. The AI then
	 * derives services, locations, and clusters from what the site actually has.
	 *
	 * @return array Raw inputs (unsanitized; generate() sanitizes).
	 */
	public static function infer_inputs_from_site() {
		$titles = array();

		// Prefer the content index (fast, already built); fall back to WP_Query.
		if ( class_exists( 'SCC_Content_Index' ) && SCC_Content_Index::count() > 0 ) {
			foreach ( SCC_Content_Index::all( 500 ) as $row ) {
				if ( ! empty( $row['title'] ) ) {
					$titles[] = $row['title'];
				}
			}
		} else {
			$q = new WP_Query( array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			) );
			foreach ( $q->posts as $pid ) {
				$titles[] = get_the_title( $pid );
			}
		}
		$titles = array_values( array_unique( array_filter( array_map( 'trim', $titles ) ) ) );

		return array(
			'business_name'  => get_bloginfo( 'name' ),
			'description'    => get_bloginfo( 'description' ),
			'website'        => home_url(),
			'existing_pages' => $titles,
		);
	}

	/**
	 * Real search-demand signals from Google Search Console (when connected):
	 * the site's top queries by impressions, plus "quick win" queries where the
	 * site already ranks on page 1-2 (positions 4-20) — strong candidates for
	 * dedicated pages. These are MEASURED numbers, so they may be shown as-is.
	 *
	 * @return array {connected:bool, top_queries:array, quick_wins:array}
	 */
	public static function gsc_signals() {
		$out = array( 'connected' => false, 'top_queries' => array(), 'quick_wins' => array(), 'untapped' => array() );
		if ( ! class_exists( 'SCC_GSC' ) || ! SCC_GSC::is_connected() ) {
			return $out;
		}
		$rows = SCC_GSC::query( '', array( 'query' ), 90, 500 );
		if ( is_wp_error( $rows ) || empty( $rows ) ) {
			return $out;
		}
		$out['connected'] = true;

		$queries = array();
		foreach ( $rows as $row ) {
			$q = SCC_Security::sanitize_text( $row['keys'][0] ?? '' );
			if ( '' === $q ) {
				continue;
			}
			$queries[] = array(
				'query'       => $q,
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
		}

		// Top queries by impressions (the demand the site already sees).
		usort( $queries, function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
		$out['top_queries'] = array_slice( $queries, 0, 40 );

		// Quick wins: real impressions and ranking just off the top.
		$wins = array_filter( $queries, function ( $q ) {
			return $q['impressions'] >= 20 && $q['position'] >= 4 && $q['position'] <= 20;
		} );
		$out['quick_wins'] = array_slice( array_values( $wins ), 0, 30 );

		// Untapped demand: lots of impressions but almost no clicks (poor CTR or
		// ranking beyond page 2). These are real searches the site is seen for but
		// doesn't win — strong candidates for a new or upgraded page.
		$untapped = array_filter( $queries, function ( $q ) {
			$ctr = $q['impressions'] > 0 ? $q['clicks'] / $q['impressions'] : 0;
			return $q['impressions'] >= 50 && ( $q['position'] > 20 || $ctr < 0.01 );
		} );
		usort( $untapped, function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
		$out['untapped'] = array_slice( array_values( $untapped ), 0, 25 );

		return $out;
	}

	/**
	 * The site's real published pages/posts as {title, path} pairs, so the
	 * topical map can be anchored to the actual URLs the site already has.
	 *
	 * @param int $limit Max pages.
	 * @return array<int,array{title:string,path:string}>
	 */
	public static function existing_site_pages( $limit = 200 ) {
		$pages = array();
		$q     = new WP_Query( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'no_found_rows'  => true,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );
		$seen = array();
		foreach ( $q->posts as $pid ) {
			$title = trim( (string) get_the_title( $pid ) );
			$path  = wp_parse_url( (string) get_permalink( $pid ), PHP_URL_PATH );
			$path  = self::normalize_site_path( (string) $path );
			if ( '' === $title || '' === $path || isset( $seen[ $path ] ) ) {
				continue;
			}
			$seen[ $path ]  = true;
			$pages[]        = array( 'title' => $title, 'path' => $path );
		}

		// Also merge any URLs the site's sitemap exposes but WP_Query missed
		// (custom post types, front-page builders, headless routes, etc.), so
		// the topical map mirrors the REAL live site, not just standard posts.
		foreach ( self::sitemap_pages( $limit ) as $sp ) {
			if ( count( $pages ) >= (int) $limit ) {
				break;
			}
			if ( isset( $seen[ $sp['path'] ] ) ) {
				continue;
			}
			$seen[ $sp['path'] ] = true;
			$pages[]             = $sp;
		}
		return $pages;
	}

	/**
	 * Real URLs from the site's XML sitemap, as {title, path} pairs.
	 *
	 * Tries the WordPress core sitemap and the common /sitemap.xml / sitemap_index
	 * locations, follows one level of sitemap index, and derives a readable title
	 * from each slug. Everything is best-effort and same-host only — a missing or
	 * unreachable sitemap simply yields an empty list.
	 *
	 * @param int $limit Max URLs to return.
	 * @return array<int,array{title:string,path:string}>
	 */
	public static function sitemap_pages( $limit = 200 ) {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $home_host ) {
			return array();
		}

		$candidates = array(
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
		);

		$locs = array();
		foreach ( $candidates as $url ) {
			$found = self::fetch_sitemap_locs( $url );
			if ( ! empty( $found ) ) {
				$locs = $found;
				break;
			}
		}
		if ( empty( $locs ) ) {
			return array();
		}

		// If this looks like a sitemap index (entries are themselves .xml), pull
		// one level of child sitemaps and collect their page URLs instead.
		$child_xml = array_filter( $locs, function ( $u ) {
			return (bool) preg_match( '/\.xml(\?|$)/i', $u );
		} );
		if ( count( $child_xml ) === count( $locs ) ) {
			$pages_locs = array();
			foreach ( array_slice( array_values( $child_xml ), 0, 20 ) as $child ) {
				$pages_locs = array_merge( $pages_locs, self::fetch_sitemap_locs( $child ) );
				if ( count( $pages_locs ) >= (int) $limit * 2 ) {
					break;
				}
			}
			$locs = $pages_locs;
		}

		$out  = array();
		$seen = array();
		foreach ( $locs as $loc ) {
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
			$host = wp_parse_url( $loc, PHP_URL_HOST );
			if ( $host && $host !== $home_host ) {
				continue;
			}
			if ( preg_match( '/\.xml(\?|$)/i', $loc ) ) {
				continue;
			}
			$path = self::normalize_site_path( (string) wp_parse_url( $loc, PHP_URL_PATH ) );
			if ( '' === $path || '/' === $path || isset( $seen[ $path ] ) ) {
				continue;
			}
			$seen[ $path ] = true;
			$out[]         = array( 'title' => self::title_from_path( $path ), 'path' => $path );
		}
		return $out;
	}

	/**
	 * Fetch a sitemap URL and return its <loc> values.
	 *
	 * @param string $url Sitemap URL.
	 * @return array<int,string>
	 */
	protected static function fetch_sitemap_locs( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 12, 'redirection' => 3, 'user-agent' => 'SEO-Command-Center/' . ( defined( 'SCC_VERSION' ) ? SCC_VERSION : '1' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || false === stripos( $body, '<loc' ) ) {
			return array();
		}
		if ( ! preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/is', $body, $m ) ) {
			return array();
		}
		return array_map( function ( $u ) {
			return esc_url_raw( trim( html_entity_decode( $u, ENT_QUOTES ) ) );
		}, $m[1] );
	}

	/**
	 * Derive a human title from a URL path (last non-empty slug, title-cased).
	 *
	 * @param string $path Normalized path.
	 * @return string
	 */
	protected static function title_from_path( $path ) {
		$segments = array_values( array_filter( explode( '/', (string) $path ) ) );
		$slug     = end( $segments );
		if ( ! $slug ) {
			return '';
		}
		$words = str_replace( array( '-', '_' ), ' ', $slug );
		return ucwords( trim( $words ) );
	}

	/**
	 * Normalize a URL path to a clean, comparable "/slug/…/" form.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	protected static function normalize_site_path( $path ) {
		$segments = array_filter( explode( '/', (string) $path ) );
		$clean    = array_filter( array_map( 'sanitize_title', $segments ) );
		return '/' . implode( '/', $clean ) . ( $clean ? '/' : '' );
	}

	/**
	 * Build the AI prompt for the topical map.
	 *
	 * @param array $inputs Sanitized inputs.
	 * @return array {system, messages, json}
	 */
	protected function build_prompt( array $inputs ) {
		$map_type = $inputs['map_type'] ?? 'seo';
		$depth    = $inputs['depth'] ?? 'standard';
		$language = trim( (string) ( $inputs['language'] ?? '' ) );

		// Depth presets: how many pillars / subtopics / content nodes to aim for,
		// and a token budget sized to match.
		// Token budgets are kept modest so a synchronous generation finishes within
		// a typical hosting request window (some hosts cap requests at ~60-120s).
		// The tolerant parser salvages any truncated tail, so a smaller budget
		// degrades gracefully rather than failing.
		$presets = array(
			'compact'  => array( 'pillars' => '4-5', 'subs' => '2-3', 'nodes' => '2-3', 'tokens' => 1800 ),
			'standard' => array( 'pillars' => '5-7', 'subs' => '3-4', 'nodes' => '2-3', 'tokens' => 2600 ),
			'deep'     => array( 'pillars' => '7-10', 'subs' => '4-6', 'nodes' => '3-4', 'tokens' => 3600 ),
		);
		$p = isset( $presets[ $depth ] ) ? $presets[ $depth ] : $presets['standard'];

		// Map-type flavor.
		$type_intro = 'Build a STRUCTURED TOPICAL MAP for a business as a set of PILLAR topics.';
		if ( 'question' === $map_type ) {
			$type_intro = 'Build a QUESTION / FAQ TOPICAL MAP: pillars are themes, and subtopics + content nodes are the real questions searchers ask (who/what/why/how/best/vs/cost), grouped by theme.';
		} elseif ( 'keyword' === $map_type ) {
			$type_intro = 'Build a KEYWORD-CLUSTER MAP: pillars are head terms, subtopics are tightly-related keyword clusters, and content nodes are long-tail variations grouped by search intent.';
		}

		$system = 'You are a senior SEO strategist. ' . $type_intro . ' '
			. 'Use the classic pillar/cluster model with THREE levels: PILLAR -> SUBTOPIC -> CONTENT NODES. '
			. 'A pillar is a broad hub page; its subtopics are specific supporting articles/pages that link up '
			. 'to it; each subtopic has a few CONTENT NODES (specific questions/points that page must cover). '
			. 'Aim for roughly ' . $p['pillars'] . ' pillars, ' . $p['subs'] . ' subtopics per pillar, and '
			. $p['nodes'] . ' content nodes per subtopic. '
			. 'For each PILLAR provide: the topic name (service), one primary keyword, 3-6 genuinely distinct '
			. 'supporting terms, the dominant search intent (informational, commercial, transactional, '
			. 'navigational, or local), a clean recommended URL slug path, an example SEO meta title under ~60 '
			. 'characters, a strategic priority ("high", "medium", or "low"), 2-4 related internal topics, and '
			. 'the SUBTOPICS. For each SUBTOPIC provide: title, primary_keyword, intent, a recommended URL slug '
			. 'path (usually nested under the pillar), an example meta title, and content_nodes (an array of '
			. 'short specific questions/points to cover). '
			. 'Only propose location+service pages where they would carry genuinely unique local value — '
			. 'never propose near-duplicate doorway pages. Do not invent search volume or difficulty numbers; '
			. 'priority is your strategic judgement, not measured data. '
			. ( '' !== $language ? ( 'Write ALL topics, keywords, titles and questions in ' . $language . '. ' ) : '' )
			. 'MIRROR THE REAL SITE. An "existing_site_pages" list of {title, url} is provided. '
			. 'For EVERY existing page, output one pillar (or subtopic) that REUSES its exact url path verbatim '
			. 'and sets "status":"existing" — do not invent a new slug for a page that already exists. Then ADD '
			. 'pillars/subtopics with "status":"new" for genuine gaps. Infer the business\'s real services, '
			. 'products and locations from the existing page titles. Never propose a near-duplicate of a page '
			. 'that already exists. '
			. 'USE REAL SEARCH DEMAND. If "gsc_top_queries" (real Google Search Console queries with impressions, '
			. 'clicks and average position) and "gsc_quick_wins" (queries already ranking on page 1-2 without a '
			. 'dedicated page) are provided, ground your keywords and NEW suggestions in them: add new '
			. 'pillars/subtopics targeting the highest-impression queries the site has no page for, and mark '
			. 'quick-win topics "high" priority. If "gsc_untapped" (queries with real impressions but almost no '
			. 'clicks — demand the site is seen for but does not win) is provided, propose NEW pages that directly '
			. 'answer those searches. Prefer the real query phrasing for primary keywords where natural. '
			. 'Return JSON with this exact shape: '
			. '{"clusters":[{"service":str,"location":str|null,"primary_keyword":str,"supporting_terms":[str],'
			. '"intent":str,"recommended_url":str,"meta_title":str,"priority":"high|medium|low","related":[str],'
			. '"page_type":"pillar|service|location|article","status":"existing|new","rationale":str,'
			. '"subtopics":[{"title":str,"primary_keyword":str,"intent":str,"recommended_url":str,"meta_title":str,'
			. '"content_nodes":[str]}]}],"entities":[str],"notes":str}';

		$existing = self::existing_site_pages();
		$inputs['existing_site_pages'] = $existing;

		// Real Search Console demand, if the caller supplied it (see generate()).
		if ( ! empty( $inputs['gsc_top_queries'] ) ) {
			$inputs['gsc_top_queries'] = array_slice( (array) $inputs['gsc_top_queries'], 0, 40 );
		}
		if ( ! empty( $inputs['gsc_quick_wins'] ) ) {
			$inputs['gsc_quick_wins'] = array_slice( (array) $inputs['gsc_quick_wins'], 0, 30 );
		}
		if ( ! empty( $inputs['gsc_untapped'] ) ) {
			$inputs['gsc_untapped'] = array_slice( (array) $inputs['gsc_untapped'], 0, 25 );
		}

		$payload  = wp_json_encode( $inputs );

		return array(
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => "Business inputs (JSON):\n" . $payload . "\n\nProduce the topical map JSON now. Include every existing page (reusing its exact url) plus recommended new pages.",
				),
			),
			'json'       => true,
			// Sized to the chosen depth. If a model truncates the tail, the
			// tolerant JSON parser closes and salvages what came through.
			'max_tokens' => (int) $p['tokens'],
			'temperature'=> 0.4,
		);
	}

	/**
	 * Generate a topical map and persist it.
	 *
	 * @param array $raw Raw business inputs.
	 * @return array|WP_Error {strategy_id, inputs, map}
	 */
	public function generate( array $raw ) {
		$inputs = self::sanitize_inputs( $raw );

		if ( '' === $inputs['business_name'] && empty( $inputs['services'] ) && empty( $inputs['seed_keywords'] ) && empty( $inputs['existing_pages'] ) ) {
			return new WP_Error( 'scc_missing_inputs', __( 'Enter at least a business name, a service, or a seed keyword — or use “Build from my site”.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		// Pull real Search Console demand (when connected) to ground suggestions.
		$gsc = self::gsc_signals();
		if ( ! empty( $gsc['top_queries'] ) ) {
			$inputs['gsc_top_queries'] = $gsc['top_queries'];
		}
		if ( ! empty( $gsc['quick_wins'] ) ) {
			$inputs['gsc_quick_wins'] = $gsc['quick_wins'];
		}
		if ( ! empty( $gsc['untapped'] ) ) {
			$inputs['gsc_untapped'] = $gsc['untapped'];
		}

		$response = $this->ai->complete( $this->build_prompt( $inputs ), 'keyword-strategy' );
		if ( $response->is_error() ) {
			return $response->error;
		}

		$parsed     = $response->json();
		$ai_usable  = is_array( $parsed ) && ! empty( $parsed['clusters'] );
		$map        = $ai_usable ? $this->normalize_map( $parsed ) : array( 'clusters' => array(), 'entities' => array(), 'notes' => '' );

		if ( ! $ai_usable ) {
			// The model didn't return a usable cluster list (common with very
			// small local models). Rather than failing, still build the map from
			// the site's real pages so the user always gets their architecture.
			SCC_Logger::error( 'keyword-strategy', 'AI returned no usable clusters; falling back to site-mirror map' );
			$map['notes'] = __( 'The AI model did not return topic suggestions this time, so this map shows your existing pages only. Try again, or use a larger model for richer recommendations.', 'seo-command-center' );
		}

		$map = $this->reconcile_with_site( $map );

		// If there were neither AI suggestions nor existing pages, there is
		// genuinely nothing to show.
		if ( empty( $map['clusters'] ) ) {
			return new WP_Error( 'scc_bad_ai_output', __( 'The AI response could not be parsed and no existing pages were found to map. Try again, or add a business name/service above.', 'seo-command-center' ), array( 'status' => 502 ) );
		}

		// Record which provider/model actually produced this map, so it's clear
		// in the UI what generated it (and easy to confirm LM Studio was used).
		$map['generated_by']    = isset( $response->provider ) ? (string) $response->provider : '';
		$map['generated_model'] = isset( $response->model ) ? (string) $response->model : '';

		// Attach the real Search Console quick-win opportunities (measured data)
		// so the map can surface them alongside the AI suggestions.
		$map['gsc_connected']  = ! empty( $gsc['connected'] );
		$map['gsc_quick_wins'] = isset( $gsc['quick_wins'] ) ? $gsc['quick_wins'] : array();

		$strategy_id = SCC_DB::insert(
			'keyword_strategies',
			array(
				'created_at'  => current_time( 'mysql' ),
				'name'        => $inputs['business_name'] ? $inputs['business_name'] : __( 'Keyword strategy', 'seo-command-center' ),
				'inputs'      => wp_json_encode( $inputs ),
				'topical_map' => wp_json_encode( $map ),
				'status'      => 'draft',
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		SCC_Logger::info( 'keyword-strategy', 'Topical map generated', array( 'strategy_id' => $strategy_id, 'clusters' => count( $map['clusters'] ) ) );

		return array(
			'strategy_id' => $strategy_id,
			'inputs'      => $inputs,
			'map'         => $map,
		);
	}

	/**
	 * Normalize and sanitize the AI map structure defensively.
	 *
	 * @param array $map Raw decoded map.
	 * @return array
	 */
	protected function normalize_map( array $map ) {
		$valid_intent = array( 'informational', 'commercial', 'transactional', 'navigational', 'local' );
		$valid_type   = array( 'pillar', 'service', 'location', 'article' );

		$valid_priority = array( 'high', 'medium', 'low' );

		$clusters = array();
		foreach ( (array) ( $map['clusters'] ?? array() ) as $c ) {
			$intent   = strtolower( (string) ( $c['intent'] ?? 'informational' ) );
			$type     = strtolower( (string) ( $c['page_type'] ?? 'service' ) );
			$priority = strtolower( (string) ( $c['priority'] ?? '' ) );
			$priority = in_array( $priority, $valid_priority, true ) ? $priority : 'medium';

			// Subtopics (supporting articles under the pillar).
			$subtopics = array();
			foreach ( (array) ( $c['subtopics'] ?? array() ) as $s ) {
				$sintent = strtolower( (string) ( $s['intent'] ?? 'informational' ) );
				$title   = SCC_Security::sanitize_text( $s['title'] ?? ( $s['primary_keyword'] ?? '' ) );
				if ( '' === $title ) {
					continue;
				}
				$nodes = array_values( array_filter( array_map( array( 'SCC_Security', 'sanitize_text' ), (array) ( $s['content_nodes'] ?? array() ) ) ) );
				$subtopics[] = array(
					'title'           => $title,
					'primary_keyword' => SCC_Security::sanitize_text( $s['primary_keyword'] ?? $title ),
					'intent'          => in_array( $sintent, $valid_intent, true ) ? $sintent : 'informational',
					'recommended_url' => $this->clean_slug_path( $s['recommended_url'] ?? '' ),
					'meta_title'      => SCC_Security::sanitize_text( $s['meta_title'] ?? '' ),
					'content_nodes'   => array_slice( $nodes, 0, 12 ),
					'status'          => 'new',
				);
			}

			$cluster = array(
				'service'          => SCC_Security::sanitize_text( $c['service'] ?? '' ),
				'location'         => empty( $c['location'] ) ? '' : SCC_Security::sanitize_text( $c['location'] ),
				'primary_keyword'  => SCC_Security::sanitize_text( $c['primary_keyword'] ?? '' ),
				'supporting_terms' => array_values( array_filter( array_map( array( 'SCC_Security', 'sanitize_text' ), (array) ( $c['supporting_terms'] ?? array() ) ) ) ),
				'intent'           => in_array( $intent, $valid_intent, true ) ? $intent : 'informational',
				'recommended_url'  => $this->clean_slug_path( $c['recommended_url'] ?? '' ),
				'meta_title'       => SCC_Security::sanitize_text( $c['meta_title'] ?? '' ),
				'priority'         => $priority,
				'related'          => array_values( array_filter( array_map( array( 'SCC_Security', 'sanitize_text' ), (array) ( $c['related'] ?? array() ) ) ) ),
				'page_type'        => in_array( $type, $valid_type, true ) ? $type : 'service',
				'status'           => ( isset( $c['status'] ) && 'existing' === strtolower( (string) $c['status'] ) ) ? 'existing' : 'new',
				'rationale'        => SCC_Security::sanitize_textarea( $c['rationale'] ?? '' ),
				'subtopics'        => $subtopics,
			);
			$cluster['authority_score'] = $this->authority_score( $cluster );
			$clusters[] = $cluster;
		}

		return array(
			'clusters' => $clusters,
			'entities' => array_values( array_filter( array_map( array( 'SCC_Security', 'sanitize_text' ), (array) ( $map['entities'] ?? array() ) ) ) ),
			'notes'    => SCC_Security::sanitize_textarea( $map['notes'] ?? '' ),
		);
	}

	/**
	 * Reconcile the AI map against the site's real pages so the topical map
	 * always mirrors the actual architecture: every existing page is present
	 * (anchored to its real URL and flagged "existing"), everything else is a
	 * "new" gap recommendation. Deterministic — does not trust the model to get
	 * the existing set right.
	 *
	 * @param array $map Normalized map.
	 * @return array
	 */
	protected function reconcile_with_site( array $map ) {
		$pages = static::existing_site_pages();
		if ( empty( $pages ) ) {
			return $map; // Nothing to anchor to (e.g. brand-new site).
		}

		// Index existing pages by normalized path.
		$by_path = array();
		foreach ( $pages as $p ) {
			$by_path[ $p['path'] ] = $p['title'];
		}

		$clusters = isset( $map['clusters'] ) ? $map['clusters'] : array();
		$seen     = array();

		// Flag clusters (and their subtopics) whose URL matches a real page.
		foreach ( $clusters as &$c ) {
			$path = self::normalize_site_path( $c['recommended_url'] );
			if ( isset( $by_path[ $path ] ) ) {
				$c['status']          = 'existing';
				$c['recommended_url'] = $path; // Use the real, exact path.
				$seen[ $path ]        = true;
			} elseif ( 'existing' === $c['status'] ) {
				// Model claimed "existing" but the URL doesn't match a real page —
				// it's really a recommendation.
				$c['status'] = 'new';
			}
			if ( ! empty( $c['subtopics'] ) && is_array( $c['subtopics'] ) ) {
				foreach ( $c['subtopics'] as &$s ) {
					$spath = self::normalize_site_path( $s['recommended_url'] ?? '' );
					if ( '' !== $spath && isset( $by_path[ $spath ] ) ) {
						$s['status']          = 'existing';
						$s['recommended_url'] = $spath;
						$seen[ $spath ]       = true;
					} else {
						$s['status'] = 'new';
					}
				}
				unset( $s );
			}
		}
		unset( $c );

		// Inject any real page the model left out, anchored to its true URL.
		foreach ( $by_path as $path => $title ) {
			if ( isset( $seen[ $path ] ) ) {
				continue;
			}
			$injected = array(
				'service'          => $title,
				'location'         => '',
				'primary_keyword'  => $title,
				'supporting_terms' => array(),
				'intent'           => 'commercial',
				'recommended_url'  => $path,
				'meta_title'       => '',
				'priority'         => 'medium',
				'related'          => array(),
				'page_type'        => $this->guess_page_type( $path ),
				'status'           => 'existing',
				'rationale'        => __( 'Existing page on your site.', 'seo-command-center' ),
				'subtopics'        => array(),
			);
			$injected['authority_score'] = $this->authority_score( $injected );
			$clusters[] = $injected;
		}

		// Existing pages first, then recommended gaps.
		usort( $clusters, function ( $a, $b ) {
			$ax = ( 'existing' === $a['status'] ) ? 0 : 1;
			$bx = ( 'existing' === $b['status'] ) ? 0 : 1;
			return $ax <=> $bx;
		} );

		$map['clusters'] = $clusters;

		// Counts span pillars AND subtopics (topicalmap-style topic totals).
		$existing = 0;
		$total    = 0;
		foreach ( $clusters as $c ) {
			$total++;
			if ( 'existing' === $c['status'] ) {
				$existing++;
			}
			foreach ( (array) ( $c['subtopics'] ?? array() ) as $s ) {
				$total++;
				if ( isset( $s['status'] ) && 'existing' === $s['status'] ) {
					$existing++;
				}
			}
		}
		$map['existing_count'] = $existing;
		$map['new_count']      = $total - $existing;
		$map['pillar_count']   = count( $clusters );
		return $map;
	}

	/**
	 * A strategic (NOT measured) topical-authority score 0-100 for a pillar,
	 * derived from priority, breadth of supporting terms, and subtopic depth.
	 * Presented as strategic opinion, never as search-volume data.
	 *
	 * @param array $cluster Cluster.
	 * @return int
	 */
	protected function authority_score( array $cluster ) {
		$base = array( 'high' => 80, 'medium' => 60, 'low' => 40 );
		$score = isset( $base[ $cluster['priority'] ?? 'medium' ] ) ? $base[ $cluster['priority'] ] : 60;
		$score += min( 10, count( (array) ( $cluster['supporting_terms'] ?? array() ) ) * 2 );
		$score += min( 10, count( (array) ( $cluster['subtopics'] ?? array() ) ) * 2 );
		return (int) max( 1, min( 100, $score ) );
	}

	/**
	 * Guess a page type from a URL path depth (home = pillar, deep = article).
	 *
	 * @param string $path Normalized path.
	 * @return string
	 */
	protected function guess_page_type( $path ) {
		$depth = count( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		if ( 0 === $depth ) {
			return 'pillar'; // Home page.
		}
		if ( $depth >= 2 ) {
			return 'article';
		}
		return 'service';
	}

	/**
	 * Sanitize a recommended URL into a clean relative slug path.
	 *
	 * @param string $url Raw URL or path.
	 * @return string
	 */
	protected function clean_slug_path( $url ) {
		$url  = (string) $url;
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			$path = $url;
		}
		$segments = array_filter( explode( '/', $path ) );
		$clean    = array_map( 'sanitize_title', $segments );
		$clean    = array_filter( $clean );
		return '/' . implode( '/', $clean ) . ( $clean ? '/' : '' );
	}

	/**
	 * Fetch the latest strategy row (decoded).
	 *
	 * @return array|null
	 */
	public static function latest() {
		global $wpdb;
		$table = SCC_DB::table( 'keyword_strategies' );
		$row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$row['inputs_data'] = json_decode( (string) $row['inputs'], true );
		$row['map_data']    = json_decode( (string) $row['topical_map'], true );
		return $row;
	}

	/**
	 * Fetch a strategy by id (decoded).
	 *
	 * @param int $id Strategy id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = SCC_DB::get( 'keyword_strategies', $id );
		if ( ! $row ) {
			return null;
		}
		$row['inputs_data'] = json_decode( (string) $row['inputs'], true );
		$row['map_data']    = json_decode( (string) $row['topical_map'], true );
		return $row;
	}
}
