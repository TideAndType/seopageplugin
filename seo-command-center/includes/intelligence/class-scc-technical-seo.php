<?php
/**
 * Technical SEO Brain.
 *
 * Deterministic, evidence-first technical auditing for the current WordPress
 * site. This is a diagnostic system, not a Google ranking predictor.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-wide technical SEO auditor.
 */
class SCC_Technical_SEO {

	const REPORT_OPTION    = 'scc_technical_seo_report';
	const DEFAULT_LIMIT    = 150;
	const MAX_LIMIT        = 500;
	const LINK_CHECK_LIMIT = 60;

	/**
	 * Latest stored report.
	 *
	 * @return array|null
	 */
	public static function report() {
		$report = get_option( self::REPORT_OPTION, null );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * Run a live technical audit.
	 *
	 * @param array $args Audit args.
	 * @return array
	 */
	public function run( array $args = array() ) {
		$limit = isset( $args['limit'] ) ? SCC_Security::sanitize_int( $args['limit'], 1, self::MAX_LIMIT ) : self::DEFAULT_LIMIT;

		$pages = $this->crawl_pages( $limit );
		$site  = $this->inspect_site();
		$site['link_checks'] = $this->check_internal_targets( $pages );

		$report = self::evaluate( $pages, $site );
		$report['generated_at'] = current_time( 'mysql' );
		$report['scope'] = array(
			'page_limit'          => $limit,
			'pages_crawled'       => count( $pages ),
			'internal_links_tested'=> count( $site['link_checks'] ),
		);

		update_option( self::REPORT_OPTION, $report, false );
		SCC_Logger::info(
			'technical-seo',
			'Technical SEO audit completed.',
			array(
				'score'  => $report['score'],
				'pages'  => count( $pages ),
				'issues' => count( $report['issues'] ),
			)
		);

		return $report;
	}

	/**
	 * Crawl published WordPress URLs with rendered HTML.
	 *
	 * @param int $limit Max URLs.
	 * @return array
	 */
	protected function crawl_pages( $limit ) {
		$urls = array();
		$ids  = array();
		$home = home_url( '/' );
		$home_key = SCC_URL::normalize_for_crawl( $home );
		$urls[ $home_key ] = $home;
		$ids[ $home_key ]  = (int) get_option( 'page_on_front', 0 );

		$query = new WP_Query(
			array(
				'post_type'      => SCC_Analyzer::analyzable_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, $limit - 1 ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( (array) $query->posts as $post_id ) {
			$url = get_permalink( $post_id );
			if ( ! $url ) {
				continue;
			}
			$key = SCC_URL::normalize_for_crawl( $url );
			if ( '' !== $key ) {
				$urls[ $key ] = $url;
				if ( empty( $ids[ $key ] ) ) {
					$ids[ $key ] = (int) $post_id;
				}
			}
			if ( count( $urls ) >= $limit ) {
				break;
			}
		}

		$crawler = new SCC_Crawler();
		$pages   = array();
		foreach ( $urls as $url_key => $url ) {
			$context = self::page_context( (int) ( $ids[ $url_key ] ?? 0 ), $url_key === $home_key );
			$started = microtime( true );
			$data    = $crawler->fetch( $url, false, 12 );
			$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $data ) ) {
				$error_data = $data->get_error_data();
				$pages[] = array(
					'url'         => $url,
					'crawl_url'   => $url,
					'final_url'   => $url,
					'status'      => is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 0,
					'fetch_error' => $data->get_error_message(),
					'response_ms' => $elapsed,
					'internal_link_urls' => array(),
					'hreflang'    => array(),
				) + $context;
				continue;
			}

			$data['response_ms'] = $elapsed;
			$pages[] = $data + $context;
		}
		return $pages;
	}

	/**
	 * WordPress-side context for a crawled URL: the post behind it, its type, the
	 * keyword it targets and the schema type it should carry. Only facts the site
	 * actually holds are returned — an unknown keyword stays empty so the keyword
	 * check is skipped rather than guessed.
	 *
	 * @param int  $post_id Post id (0 when the URL is not a post).
	 * @param bool $is_home Whether this is the homepage.
	 * @return array
	 */
	protected static function page_context( $post_id, $is_home ) {
		$context = array(
			'post_id'         => (int) $post_id,
			'post_type'       => '',
			'is_home'         => (bool) $is_home,
			'target_keyword'  => '',
			'expected_schema' => array(),
		);
		if ( $is_home ) {
			$context['expected_schema'] = array( 'Organization' );
		}
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return $context;
		}
		$context['post_type']      = (string) get_post_type( $post_id );
		$context['target_keyword'] = self::target_keyword( $post_id );
		if ( ! $is_home && class_exists( 'SCC_Schema_Engine' ) ) {
			$rec = SCC_Schema_Engine::recommend( $post_id );
			// Generic context nodes (WebPage, breadcrumbs) and optional FAQ markup
			// are not required; only the page's primary type is.
			$context['expected_schema'] = array_values( array_diff( (array) ( $rec['recommended'] ?? array() ), array( 'WebPage', 'BreadcrumbList', 'FAQPage' ) ) );
		}
		return $context;
	}

	/**
	 * The keyword a post targets: TideOrbit's content plan first, then the focus
	 * keyword from Yoast or Rank Math. Empty when none is set.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function target_keyword( $post_id ) {
		$keyword = '';
		if ( class_exists( 'SCC_Content_Index' ) ) {
			$row     = SCC_Content_Index::get( $post_id );
			$keyword = is_array( $row ) ? (string) ( $row['primary_keyword'] ?? '' ) : '';
		}
		if ( '' === trim( $keyword ) ) {
			$keyword = (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		}
		if ( '' === trim( $keyword ) ) {
			$rank_math = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
			$keyword   = trim( (string) strtok( $rank_math, ',' ) );
		}
		return trim( $keyword );
	}

	/**
	 * Site-level robots/sitemap/indexability checks.
	 *
	 * @return array
	 */
	protected function inspect_site() {
		$home = home_url( '/' );
		$site = array(
			'home_url'                => $home,
			'https'                   => 'https' === strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) ),
			'blog_public'             => (int) get_option( 'blog_public', 1 ),
			'robots_status'           => 0,
			'robots_blocks_home'      => false,
			'robots_declares_sitemap' => false,
			'sitemap_ok'              => false,
			'sitemap_url'             => '',
			'sitemap_urls'            => array(),
		);

		$robots_url = home_url( '/robots.txt' );
		$response   = wp_safe_remote_get(
			$robots_url,
			array(
				'timeout'             => 8,
				'redirection'         => 2,
				'sslverify'           => true,
				'user-agent'          => SCC_Crawler::USER_AGENT,
				'limit_response_size' => 524288,
			)
		);
		$robots_body = '';
		if ( ! is_wp_error( $response ) ) {
			$site['robots_status'] = (int) wp_remote_retrieve_response_code( $response );
			$robots_body = (string) wp_remote_retrieve_body( $response );
			if ( 200 === $site['robots_status'] && '' !== trim( $robots_body ) ) {
				$site['robots_blocks_home'] = ! SCC_Robots::is_allowed( $robots_body, '/', 'Googlebot' );
				if ( preg_match( '/^\s*Sitemap:\s*(\S+)/im', $robots_body, $m ) ) {
					$site['robots_declares_sitemap'] = true;
					$site['sitemap_url'] = esc_url_raw( trim( $m[1] ) );
				}
			}
		}

		$sitemap = $this->discover_sitemap( $site['sitemap_url'] );
		if ( $sitemap['ok'] ) {
			$site['sitemap_ok']   = true;
			$site['sitemap_url']  = $sitemap['url'];
			$site['sitemap_urls'] = $sitemap['urls'];
		}

		return $site;
	}

	/**
	 * Find and read a WordPress/SEO-plugin XML sitemap (bounded).
	 *
	 * @param string $hint Sitemap URL from robots.txt.
	 * @return array
	 */
	protected function discover_sitemap( $hint = '' ) {
		$candidates = array_filter(
			array_unique(
				array(
					$hint,
					home_url( '/wp-sitemap.xml' ),
					home_url( '/sitemap_index.xml' ),
					home_url( '/sitemap.xml' ),
				)
			)
		);

		foreach ( $candidates as $candidate ) {
			$response = wp_safe_remote_get(
				$candidate,
				array(
					'timeout'             => 10,
					'redirection'         => 3,
					'sslverify'           => true,
					'user-agent'          => SCC_Crawler::USER_AGENT,
					'limit_response_size' => 2000000,
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$body = (string) wp_remote_retrieve_body( $response );
			if ( false === stripos( $body, '<urlset' ) && false === stripos( $body, '<sitemapindex' ) ) {
				continue;
			}

			$urls = array();
			$locs = $this->xml_locs( $body );
			$child_count = 0;
			foreach ( $locs as $loc ) {
				if ( preg_match( '/\.xml(?:\?|$)/i', $loc ) && $child_count < 12 ) {
					$child_count++;
					$child = wp_safe_remote_get(
						$loc,
						array(
							'timeout'             => 8,
							'redirection'         => 2,
							'sslverify'           => true,
							'user-agent'          => SCC_Crawler::USER_AGENT,
							'limit_response_size' => 2000000,
						)
					);
					if ( ! is_wp_error( $child ) && 200 === (int) wp_remote_retrieve_response_code( $child ) ) {
						foreach ( $this->xml_locs( wp_remote_retrieve_body( $child ) ) as $child_url ) {
							if ( ! preg_match( '/\.xml(?:\?|$)/i', $child_url ) ) {
								$urls[] = SCC_URL::normalize_for_crawl( $child_url );
							}
							if ( count( $urls ) >= 5000 ) {
								break 2;
							}
						}
					}
				} elseif ( ! preg_match( '/\.xml(?:\?|$)/i', $loc ) ) {
					$urls[] = SCC_URL::normalize_for_crawl( $loc );
				}
				if ( count( $urls ) >= 5000 ) {
					break;
				}
			}

			return array(
				'ok'   => true,
				'url'  => $candidate,
				'urls' => array_values( array_unique( array_filter( $urls ) ) ),
			);
		}

		return array( 'ok' => false, 'url' => '', 'urls' => array() );
	}

	/**
	 * Extract <loc> values without requiring an XML extension.
	 *
	 * @param string $xml XML text.
	 * @return array
	 */
	protected function xml_locs( $xml ) {
		$out = array();
		if ( preg_match_all( '#<loc[^>]*>(.*?)</loc>#is', (string) $xml, $matches ) ) {
			foreach ( $matches[1] as $loc ) {
				$loc = html_entity_decode( trim( wp_strip_all_tags( $loc ) ), ENT_QUOTES, 'UTF-8' );
				if ( wp_http_validate_url( $loc ) ) {
					$out[] = $loc;
				}
			}
		}
		return $out;
	}

	/**
	 * Test a bounded sample of unique internal links without following redirects.
	 *
	 * @param array $pages Crawled pages.
	 * @return array
	 */
	protected function check_internal_targets( array $pages ) {
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$targets   = array();
		foreach ( $pages as $page ) {
			foreach ( (array) ( $page['internal_link_urls'] ?? array() ) as $url ) {
				if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $home_host ) {
					continue;
				}
				$key = SCC_URL::normalize_for_crawl( $url );
				if ( '' !== $key ) {
					$targets[ $key ] = $url;
				}
				if ( count( $targets ) >= self::LINK_CHECK_LIMIT ) {
					break 2;
				}
			}
		}

		$checks = array();
		foreach ( $targets as $url ) {
			$safe = SCC_URL::is_safe_outbound_url( $url, false );
			if ( is_wp_error( $safe ) ) {
				continue;
			}
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 5,
					'redirection'         => 0,
					'sslverify'           => true,
					'user-agent'          => SCC_Crawler::USER_AGENT,
					'limit_response_size' => 1024,
				)
			);
			if ( is_wp_error( $response ) ) {
				$checks[] = array( 'url' => $url, 'status' => 0, 'error' => $response->get_error_message() );
				continue;
			}
			$checks[] = array(
				'url'      => $url,
				'status'   => (int) wp_remote_retrieve_response_code( $response ),
				'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
			);
		}
		return $checks;
	}

	/**
	 * Pure evaluator used by both production and the dependency-light test suite.
	 *
	 * @param array $pages Page snapshots.
	 * @param array $site  Site snapshot.
	 * @return array
	 */
	public static function evaluate( array $pages, array $site = array() ) {
		$issues = array();
		$count  = max( 1, count( $pages ) );
		$home   = SCC_URL::normalize_for_crawl( (string) ( $site['home_url'] ?? home_url( '/' ) ) );

		// URL → post id, so every issue example can point at the page to fix.
		$post_by_key = array();
		foreach ( $pages as $page ) {
			$page_key = SCC_URL::normalize_for_crawl( (string) ( $page['crawl_url'] ?? $page['url'] ?? '' ) );
			if ( '' !== $page_key && ! empty( $page['post_id'] ) ) {
				$post_by_key[ $page_key ] = (int) $page['post_id'];
			}
		}

		$add = function ( $id, $category, $severity, $title, $url, $evidence, $why, $fix, $scope = 'page' ) use ( &$issues, $post_by_key ) {
			if ( ! isset( $issues[ $id ] ) ) {
				$issues[ $id ] = array(
					'id'             => $id,
					'category'       => $category,
					'severity'       => $severity,
					'title'          => $title,
					'affected_count' => 0,
					'scope'          => $scope,
					'why_it_matters' => $why,
					'fix'            => $fix,
					'examples'       => array(),
				);
			}
			$issues[ $id ]['affected_count']++;
			if ( count( $issues[ $id ]['examples'] ) < 8 ) {
				$issues[ $id ]['examples'][] = array(
					'url'      => (string) $url,
					'evidence' => (string) $evidence,
					'post_id'  => (int) ( $post_by_key[ SCC_URL::normalize_for_crawl( (string) $url ) ] ?? 0 ),
				);
			}
		};

		// Site-level indexability/crawlability.
		if ( isset( $site['blog_public'] ) && 0 === (int) $site['blog_public'] ) {
			$add( 'site_discourages_search', 'indexability', 'critical', 'WordPress is discouraging search engines', home_url( '/' ), 'Settings → Reading has search engine visibility disabled.', 'This can prevent the entire site from being indexed.', 'Enable search engine visibility when the site is ready for public indexing.', 'site' );
		}
		if ( isset( $site['https'] ) && ! $site['https'] ) {
			$add( 'site_not_https', 'crawlability', 'high', 'Site is not using HTTPS', home_url( '/' ), 'The canonical WordPress home URL is HTTP.', 'HTTPS is the expected production transport and avoids security/canonicalization problems.', 'Serve the site over HTTPS and 301 redirect every HTTP URL to its HTTPS equivalent.', 'site' );
		}
		if ( ! empty( $site['robots_blocks_home'] ) ) {
			$add( 'robots_blocks_site', 'indexability', 'critical', 'robots.txt blocks the site root', home_url( '/robots.txt' ), 'Googlebot is disallowed from crawling /.', 'Google cannot reliably crawl blocked pages.', 'Remove the broad Disallow rule unless the block is intentional.', 'site' );
		}
		if ( isset( $site['sitemap_ok'] ) && ! $site['sitemap_ok'] ) {
			$add( 'sitemap_missing', 'crawlability', 'medium', 'No readable XML sitemap found', home_url( '/' ), 'TideOrbit checked the WordPress and common SEO-plugin sitemap locations.', 'A sitemap helps search engines discover canonical URLs and changes efficiently.', 'Enable the WordPress core sitemap or your SEO plugin sitemap and reference it in robots.txt.', 'site' );
		}
		if ( ! empty( $site['sitemap_ok'] ) && empty( $site['robots_declares_sitemap'] ) ) {
			$add( 'robots_missing_sitemap', 'crawlability', 'low', 'robots.txt does not declare the sitemap', home_url( '/robots.txt' ), 'A sitemap exists but no Sitemap: directive was found.', 'This is optional, but declaring the sitemap gives crawlers another discovery path.', 'Add a Sitemap: line pointing to the canonical XML sitemap.', 'site' );
		}

		$url_map = array();
		$incoming = array();
		$adjacency = array();
		$title_map = array();
		$desc_map = array();
		$hreflang_map = array();
		$sitemap_set = array_fill_keys( (array) ( $site['sitemap_urls'] ?? array() ), true );

		foreach ( $pages as $page ) {
			$url = (string) ( $page['crawl_url'] ?? $page['url'] ?? '' );
			$key = SCC_URL::normalize_for_crawl( $url );
			if ( '' === $key ) {
				continue;
			}
			$url_map[ $key ] = $page;
			$incoming[ $key ] = 0;
			$adjacency[ $key ] = array();

			if ( ! empty( $page['fetch_error'] ) ) {
				$status = (int) ( $page['status'] ?? 0 );
				$severity = $status >= 500 ? 'critical' : 'high';
				$add( 'published_url_unreachable', 'crawlability', $severity, 'Published URL could not be crawled', $url, (string) $page['fetch_error'], 'Published pages that return errors waste crawl activity and may drop from search results.', 'Restore a 200 response, or intentionally redirect/remove the URL with the correct status.' );
				continue;
			}

			$status = (int) ( $page['status'] ?? 200 );
			if ( $status >= 400 ) {
				$add( 'published_http_error', 'crawlability', $status >= 500 ? 'critical' : 'high', 'Published page returns an HTTP error', $url, 'HTTP ' . $status, 'Search engines cannot index a normal page that consistently returns an error.', 'Fix the server/application error or redirect the URL to the correct live replacement.' );
			}
			if ( ! empty( $page['redirected'] ) ) {
				$add( 'published_url_redirects', 'crawlability', 'medium', 'Published WordPress permalink redirects', $url, 'Final URL: ' . (string) ( $page['final_url'] ?? '' ), 'Internal canonical URLs should normally resolve directly without unnecessary hops.', 'Update the permalink/internal references to the final canonical URL and keep one clean redirect where migration requires it.' );
			}
			if ( ! empty( $page['noindex'] ) ) {
				$add( 'published_noindex', 'indexability', 'high', 'Published page is marked noindex', $url, (string) ( $page['robots_meta'] ?? $page['x_robots_tag'] ?? 'noindex' ), 'A noindex directive tells search engines not to keep this page in search results.', 'Confirm this is intentional. If the page should rank, remove the noindex directive at its actual source.' );
			}
			if ( ! empty( $page['nofollow'] ) ) {
				$add( 'page_nofollow', 'crawlability', 'medium', 'Page-level nofollow directive detected', $url, (string) ( $page['robots_meta'] ?? $page['x_robots_tag'] ?? 'nofollow' ), 'Page-level nofollow can reduce normal discovery and link-signal flow from this page.', 'Remove page-level nofollow unless there is a specific reason to suppress following all links.' );
			}

			$title = trim( (string) ( $page['title'] ?? '' ) );
			if ( '' === $title ) {
				$add( 'missing_title', 'metadata', 'high', 'Missing HTML title', $url, 'No non-empty <title> was found.', 'The title is a primary search-result and relevance signal.', 'Add one unique, descriptive HTML title for the page.' );
			} else {
				$normalized = strtolower( preg_replace( '/\s+/', ' ', $title ) );
				$title_map[ $normalized ][] = $url;
				$len = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
				if ( $len > 65 ) {
					$add( 'long_title', 'metadata', 'low', 'Very long HTML title', $url, $len . ' characters', 'Long titles may be truncated or rewritten in search results.', 'Tighten the title around the page’s actual topic and value without keyword stuffing.' );
				}
			}
			if ( (int) ( $page['title_count'] ?? 1 ) > 1 ) {
				$add( 'multiple_titles', 'metadata', 'high', 'Multiple HTML title elements', $url, (int) $page['title_count'] . ' <title> elements found', 'Conflicting titles create ambiguous document metadata.', 'Ensure the theme/SEO plugin outputs exactly one HTML title element.' );
			}

			$desc = trim( (string) ( $page['meta_description'] ?? '' ) );
			if ( '' === $desc ) {
				$add( 'missing_meta_description', 'metadata', 'low', 'Missing meta description', $url, 'No meta description was found.', 'Google may generate its own snippet, but a useful description gives you more control over SERP messaging.', 'Write a concise description that accurately summarizes this page.' );
			} else {
				$desc_map[ strtolower( preg_replace( '/\s+/', ' ', $desc ) ) ][] = $url;
				$len = function_exists( 'mb_strlen' ) ? mb_strlen( $desc ) : strlen( $desc );
				if ( $len > 170 ) {
					$add( 'long_meta_description', 'metadata', 'low', 'Very long meta description', $url, $len . ' characters', 'Long descriptions are commonly truncated and can dilute the useful part of the snippet.', 'Move the most useful page-specific message earlier and shorten unnecessary copy.' );
				}
			}
			if ( (int) ( $page['meta_description_count'] ?? ( '' !== $desc ? 1 : 0 ) ) > 1 ) {
				$add( 'multiple_meta_descriptions', 'metadata', 'medium', 'Multiple meta descriptions', $url, (int) $page['meta_description_count'] . ' description tags found', 'Conflicting descriptions are ambiguous and often signal plugin/theme duplication.', 'Make one system responsible for the meta description output.' );
			}

			$canonical_count = (int) ( $page['canonical_count'] ?? ( empty( $page['canonical'] ) ? 0 : 1 ) );
			$canonical = SCC_URL::normalize_for_crawl( (string) ( $page['canonical_resolved'] ?? $page['canonical'] ?? '' ) );
			if ( 0 === $canonical_count ) {
				$add( 'missing_canonical', 'canonicalization', 'medium', 'Missing canonical URL', $url, 'No rel=canonical tag was found.', 'A self-referencing canonical helps consolidate URL variants and makes the preferred URL explicit.', 'Output one absolute self-referencing canonical unless this page intentionally canonicalizes elsewhere.' );
			} elseif ( $canonical_count > 1 ) {
				$add( 'multiple_canonicals', 'canonicalization', 'high', 'Multiple canonical tags', $url, $canonical_count . ' canonical tags found', 'Multiple canonicals create conflicting consolidation signals.', 'Make exactly one system output the canonical tag.' );
			} elseif ( '' !== $canonical && $canonical !== $key ) {
				$add( 'nonself_canonical', 'canonicalization', 'high', 'Canonical points to a different URL', $url, 'Canonical: ' . $canonical, 'A different canonical can cause this URL to be treated as a duplicate and excluded from search.', 'Verify the cross-canonical is intentional; otherwise canonicalize to the final preferred version of this page.' );
			}

			$h1s = (array) ( $page['h1'] ?? array() );
			if ( 0 === count( $h1s ) ) {
				$add( 'missing_h1', 'onpage_structure', 'low', 'No H1 found', $url, 'Rendered page contains no H1.', 'A clear main heading improves page semantics and accessibility.', 'Add one descriptive main heading that matches the page’s primary purpose.' );
			} elseif ( count( $h1s ) > 1 ) {
				$add( 'multiple_h1', 'onpage_structure', 'low', 'Multiple H1 headings', $url, count( $h1s ) . ' H1 elements found', 'Multiple H1s are valid HTML, but often indicate unclear hierarchy in page-builder layouts.', 'Review the heading hierarchy and keep the primary page heading obvious.' );
			}

			// Heading hierarchy: a jump of more than one level (H2 → H4) usually
			// means headings are chosen for their look, not the page's structure.
			$outline = array_map( 'intval', (array) ( $page['heading_outline'] ?? array() ) );
			$prev_level = 0;
			foreach ( $outline as $level ) {
				if ( $prev_level > 0 && $level > $prev_level + 1 ) {
					$add( 'heading_level_skip', 'onpage_structure', 'low', 'Heading levels are skipped', $url, 'H' . $prev_level . ' is followed directly by H' . $level, 'Skipped levels blur the outline that search engines and screen readers use to understand how the page is organised.', 'Use heading levels in order (H2 for sections, H3 for sub-points) and style them with CSS instead of picking a level for its size.' );
					break;
				}
				$prev_level = $level;
			}

			// Thin content. Only judged for real posts/pages with a measured word
			// count; the homepage and utility pages legitimately carry little copy.
			$words = (int) ( $page['word_count'] ?? 0 );
			if ( empty( $page['is_home'] ) && ! empty( $page['post_id'] ) && $words > 0 ) {
				$thin_limit = 'post' === ( $page['post_type'] ?? '' ) ? 300 : 200;
				if ( $words < $thin_limit ) {
					$add( 'thin_content', 'content', $words < 100 ? 'medium' : 'low', 'Thin content', $url, $words . ' words of visible body copy', 'Pages with very little unique copy rarely show enough depth to rank for anything competitive.', 'Expand the page with genuinely useful detail for its audience — or merge it into a stronger related page if it has no distinct purpose.' );
				}
			}

			// Target keyword placement (only when the site states a target keyword).
			$keyword = trim( (string) ( $page['target_keyword'] ?? '' ) );
			if ( '' !== $keyword ) {
				if ( '' !== $title && ! self::keyword_in( $keyword, $title ) ) {
					$add( 'keyword_missing_title', 'onpage_structure', 'medium', 'Target keyword missing from the title', $url, 'Target: “' . $keyword . '” · Title: “' . $title . '”', 'The title is one of the strongest signals of what a page is about; leaving the target phrase out makes the page harder to match to that search.', 'Work the target phrase (or a natural close variant) into the title, ideally near the start.' );
				}
				if ( 1 === count( $h1s ) && ! self::keyword_in( $keyword, (string) $h1s[0] ) ) {
					$add( 'keyword_missing_h1', 'onpage_structure', 'low', 'Target keyword missing from the H1', $url, 'Target: “' . $keyword . '” · H1: “' . (string) $h1s[0] . '”', 'The main heading tells visitors and search engines what the page covers.', 'Reflect the target topic in the H1 in natural language.' );
				}
			}

			// Structured data expected for this page type.
			$expected = (array) ( $page['expected_schema'] ?? array() );
			if ( ! empty( $expected ) && ! self::schema_satisfies( (array) ( $page['schema_types'] ?? array() ), $expected ) ) {
				$found = (array) ( $page['schema_types'] ?? array() );
				$add( 'missing_schema', 'structured_data', 'medium', 'Missing structured data for this page type', $url, 'Expected: ' . implode( ' or ', $expected ) . ' · Found: ' . ( $found ? implode( ', ', array_slice( $found, 0, 6 ) ) : 'none' ), 'Structured data tells search engines exactly what the page represents and makes it eligible for richer results.', 'Add accurate ' . implode( '/', $expected ) . ' markup that matches what is visible on the page.' );
			}

			// Social sharing previews. Only judged when the crawler recorded tag data.
			if ( array_key_exists( 'og', $page ) ) {
				$og = (array) $page['og'];
				$missing_og = array();
				foreach ( array( 'og:title', 'og:description', 'og:image' ) as $tag ) {
					if ( '' === trim( (string) ( $og[ $tag ] ?? '' ) ) ) {
						$missing_og[] = $tag;
					}
				}
				if ( $missing_og ) {
					$add( 'missing_social_tags', 'social', 'low', 'Social sharing tags missing', $url, 'Missing: ' . implode( ', ', $missing_og ), 'Without Open Graph tags, links shared on Facebook, LinkedIn, Slack and similar apps show a poor or random preview.', 'Output og:title, og:description and og:image (and a twitter:card) for every public page.' );
				}
			}

			if ( empty( $page['viewport'] ) ) {
				$add( 'missing_viewport', 'mobile_performance', 'medium', 'Viewport meta tag missing', $url, 'No viewport meta tag found.', 'A missing viewport can break mobile rendering and mobile-first usability.', 'Add a standard responsive viewport meta tag in the theme head.' );
			}
			if ( ! empty( $page['mixed_content_count'] ) ) {
				$add( 'mixed_content', 'mobile_performance', 'high', 'HTTPS page loads HTTP resources', $url, (int) $page['mixed_content_count'] . ' insecure resource references', 'Browsers can block mixed content, causing incomplete rendering and poor user experience.', 'Update resources to HTTPS and remove hard-coded HTTP asset URLs.' );
			}
			if ( ! empty( $page['schema_invalid'] ) ) {
				$add( 'invalid_jsonld', 'structured_data', 'high', 'Malformed JSON-LD structured data', $url, (int) $page['schema_invalid'] . ' malformed JSON-LD block(s)', 'Malformed structured data cannot be interpreted reliably by search engines.', 'Fix or remove the invalid JSON-LD block and validate the final rendered markup.' );
			}
			if ( ! empty( $page['images_missing_alt'] ) ) {
				$add( 'images_missing_alt', 'media', 'medium', 'Images missing alt text', $url, (int) $page['images_missing_alt'] . ' image(s)', 'Useful alt text supports accessibility and image understanding when the image conveys content.', 'Add concise descriptive alt text to meaningful images; leave decorative images intentionally empty.' );
			}
			if ( ! empty( $page['images_missing_dimensions'] ) ) {
				$add( 'images_missing_dimensions', 'mobile_performance', 'low', 'Images missing width/height attributes', $url, (int) $page['images_missing_dimensions'] . ' image(s)', 'Missing intrinsic dimensions can contribute to layout shifts while images load.', 'Provide intrinsic dimensions or an equivalent aspect-ratio reservation for images.' );
			}
			$response_ms = (int) ( $page['response_ms'] ?? 0 );
			if ( $response_ms >= 4000 ) {
				$add( 'very_slow_response', 'mobile_performance', 'high', 'Very slow server response during audit', $url, $response_ms . ' ms', 'Slow origin responses can delay rendering and hurt crawl efficiency and user experience.', 'Profile hosting, PHP/database work, uncached requests and third-party dependencies. This is a server timing diagnostic, not a Core Web Vitals measurement.' );
			} elseif ( $response_ms >= 2000 ) {
				$add( 'slow_response', 'mobile_performance', 'medium', 'Slow server response during audit', $url, $response_ms . ' ms', 'Slow responses delay delivery even before browser rendering begins.', 'Review caching, backend work and hosting response time. Confirm with a dedicated performance tool before making major changes.' );
			}

			if ( ! empty( $site['sitemap_ok'] ) ) {
				if ( ! isset( $sitemap_set[ $key ] ) ) {
					$add( 'published_not_in_sitemap', 'crawlability', 'medium', 'Published page is missing from the XML sitemap', $url, 'URL not found in the discovered sitemap set.', 'Important canonical pages are easier to discover and monitor when they are included in the sitemap.', 'Confirm the page is indexable and eligible, then include it in the canonical sitemap.' );
				}
				if ( ! empty( $page['noindex'] ) && isset( $sitemap_set[ $key ] ) ) {
					$add( 'noindex_in_sitemap', 'indexability', 'high', 'Noindex URL is included in the sitemap', $url, 'Sitemap includes a page that declares noindex.', 'A sitemap should normally list URLs you actually want indexed.', 'Either remove noindex or remove the URL from the sitemap, depending on the intended search behavior.' );
				}
				if ( ! empty( $page['redirected'] ) && isset( $sitemap_set[ $key ] ) ) {
					$add( 'redirect_in_sitemap', 'crawlability', 'medium', 'Redirecting URL is included in the sitemap', $url, 'Sitemap URL redirects to ' . (string) ( $page['final_url'] ?? '' ), 'Sitemaps should list final canonical 200 URLs rather than redirect sources.', 'Replace the redirecting sitemap entry with its final canonical URL.' );
				}
			}

			$hreflang_map[ $key ] = (array) ( $page['hreflang'] ?? array() );
			if ( ! empty( $hreflang_map[ $key ] ) ) {
				$self_ref = false;
				foreach ( $hreflang_map[ $key ] as $alt ) {
					if ( SCC_URL::normalize_for_crawl( (string) ( $alt['url'] ?? '' ) ) === $key ) {
						$self_ref = true;
						break;
					}
				}
				if ( ! $self_ref ) {
					$add( 'hreflang_missing_self', 'international', 'medium', 'hreflang cluster has no self-reference', $url, 'Alternate language links exist, but none point back to this URL.', 'Self-referencing hreflang helps make the language/region cluster explicit.', 'Add a hreflang entry for this page’s own language/region URL.' );
				}
			}

			foreach ( (array) ( $page['internal_link_urls'] ?? array() ) as $target ) {
				$target_key = SCC_URL::normalize_for_crawl( $target );
				if ( '' !== $target_key ) {
					$adjacency[ $key ][ $target_key ] = true;
				}
			}
		}

		// Duplicate metadata across crawled canonical pages.
		foreach ( $title_map as $value => $urls ) {
			if ( '' !== $value && count( $urls ) > 1 ) {
				foreach ( $urls as $url ) {
					$add( 'duplicate_titles', 'metadata', 'high', 'Duplicate HTML titles', $url, 'Same title used on ' . count( $urls ) . ' crawled URLs', 'Duplicate titles make it harder to distinguish pages and often correlate with overlapping intent.', 'Write a unique title that accurately reflects what is distinct about each page.' );
				}
			}
		}
		foreach ( $desc_map as $value => $urls ) {
			if ( '' !== $value && count( $urls ) > 1 ) {
				foreach ( $urls as $url ) {
					$add( 'duplicate_meta_descriptions', 'metadata', 'low', 'Duplicate meta descriptions', $url, 'Same description used on ' . count( $urls ) . ' crawled URLs', 'Repeated snippets reduce page differentiation in search results.', 'Use a page-specific description where the page deserves a controlled snippet.' );
				}
			}
		}

		// Incoming-link graph and click depth from the homepage.
		foreach ( $adjacency as $source => $targets ) {
			foreach ( array_keys( $targets ) as $target ) {
				if ( isset( $incoming[ $target ] ) && $target !== $source ) {
					$incoming[ $target ]++;
				}
			}
		}
		$depth = array();
		if ( isset( $url_map[ $home ] ) ) {
			$depth[ $home ] = 0;
			$queue = array( $home );
			while ( $queue ) {
				$source = array_shift( $queue );
				foreach ( array_keys( $adjacency[ $source ] ?? array() ) as $target ) {
					if ( ! isset( $url_map[ $target ] ) || isset( $depth[ $target ] ) ) {
						continue;
					}
					$depth[ $target ] = $depth[ $source ] + 1;
					$queue[] = $target;
				}
			}
		}
		foreach ( $url_map as $key => $page ) {
			$url = (string) ( $page['crawl_url'] ?? $page['url'] ?? $key );
			if ( $key === $home ) {
				continue;
			}
			if ( 0 === (int) ( $incoming[ $key ] ?? 0 ) ) {
				$add( 'orphan_page', 'architecture', 'high', 'Page has no discovered internal links pointing to it', $url, '0 incoming links in the audited page graph', 'Orphaned pages are harder for users and crawlers to discover and receive no internal link context.', 'Link to the page naturally from a relevant hub, service, category or supporting article.' );
			}
			if ( isset( $depth[ $key ] ) && $depth[ $key ] > 3 ) {
				$add( 'deep_click_depth', 'architecture', $depth[ $key ] > 5 ? 'high' : 'medium', 'Important page is deep in the internal-link graph', $url, 'Minimum discovered click depth: ' . $depth[ $key ], 'Very deep pages can be harder to discover and signal weaker architectural importance.', 'Add relevant links from higher-level hubs or nearby authoritative pages.' );
			} elseif ( ! isset( $depth[ $key ] ) ) {
				$add( 'unreachable_from_home', 'architecture', 'high', 'Page was not reachable from the homepage link graph', $url, 'No crawl path from the audited homepage to this URL.', 'Pages disconnected from the normal navigation/link graph are difficult to discover organically.', 'Create a logical internal-link path from a crawlable hub or navigation structure.' );
			}
		}

		// hreflang reciprocity for alternates that are also in this audit set.
		foreach ( $hreflang_map as $source => $alternates ) {
			foreach ( $alternates as $alt ) {
				$target = SCC_URL::normalize_for_crawl( (string) ( $alt['url'] ?? '' ) );
				if ( '' === $target || $target === $source || ! isset( $hreflang_map[ $target ] ) ) {
					continue;
				}
				$reciprocal = false;
				foreach ( $hreflang_map[ $target ] as $back ) {
					if ( SCC_URL::normalize_for_crawl( (string) ( $back['url'] ?? '' ) ) === $source ) {
						$reciprocal = true;
						break;
					}
				}
				if ( ! $reciprocal ) {
					$source_url = (string) ( $url_map[ $source ]['crawl_url'] ?? $source );
					$add( 'hreflang_not_reciprocal', 'international', 'high', 'hreflang alternate does not link back', $source_url, 'Alternate: ' . (string) ( $alt['url'] ?? '' ), 'hreflang annotations are expected to be reciprocal so engines can trust the cluster.', 'Add a matching return hreflang annotation on the alternate page.' );
				}
			}
		}

		// Real HTTP checks for a bounded sample of linked internal targets.
		foreach ( (array) ( $site['link_checks'] ?? array() ) as $check ) {
			$status = (int) ( $check['status'] ?? 0 );
			$url = (string) ( $check['url'] ?? '' );
			if ( 404 === $status || 410 === $status ) {
				$add( 'broken_internal_link', 'architecture', 'high', 'Broken internal link detected', $url, 'HTTP ' . $status, 'Broken internal links waste crawl paths and frustrate users.', 'Update or remove links to this URL, or restore/redirect the missing destination.', 'site' );
			} elseif ( $status >= 500 ) {
				$add( 'internal_link_server_error', 'architecture', 'high', 'Internal link points to a server error', $url, 'HTTP ' . $status, 'Internal links should lead to healthy, usable destinations.', 'Fix the destination server error before continuing to link to it.', 'site' );
			} elseif ( $status >= 300 && $status < 400 ) {
				$add( 'internal_link_redirect', 'architecture', 'medium', 'Internal links pass through redirects', $url, 'HTTP ' . $status . ( ! empty( $check['location'] ) ? ' → ' . $check['location'] : '' ), 'Internal redirect hops add latency and waste crawl budget at scale.', 'Update internal links to point directly at the final canonical URL.', 'site' );
			} elseif ( 0 === $status && ! empty( $check['error'] ) ) {
				$add( 'internal_link_unverifiable', 'crawlability', 'low', 'Some internal links could not be verified', $url, (string) $check['error'], 'An audit transport error does not prove the URL is broken, but it needs a manual check.', 'Open the URL directly and confirm it returns the intended response.', 'site' );
			}
		}

		$issues = array_values( $issues );
		$rank = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
		usort(
			$issues,
			function ( $a, $b ) use ( $rank ) {
				$severity = ( $rank[ $b['severity'] ] ?? 0 ) <=> ( $rank[ $a['severity'] ] ?? 0 );
				if ( 0 !== $severity ) {
					return $severity;
				}
				return $b['affected_count'] <=> $a['affected_count'];
			}
		);

		$category_defs = array(
			'indexability'       => array( 'label' => 'Indexability', 'weight' => 20 ),
			'crawlability'       => array( 'label' => 'Crawlability', 'weight' => 15 ),
			'canonicalization'   => array( 'label' => 'Canonicals', 'weight' => 12 ),
			'architecture'       => array( 'label' => 'Architecture', 'weight' => 15 ),
			'metadata'           => array( 'label' => 'Metadata', 'weight' => 10 ),
			'onpage_structure'   => array( 'label' => 'HTML structure', 'weight' => 8 ),
			'structured_data'    => array( 'label' => 'Structured data', 'weight' => 7 ),
			'mobile_performance' => array( 'label' => 'Mobile & performance', 'weight' => 8 ),
			'content'            => array( 'label' => 'Content depth', 'weight' => 6 ),
			'media'              => array( 'label' => 'Images', 'weight' => 3 ),
			'social'             => array( 'label' => 'Social sharing', 'weight' => 2 ),
			'international'      => array( 'label' => 'International', 'weight' => 2 ),
		);
		$base_penalty = array( 'critical' => 55, 'high' => 30, 'medium' => 14, 'low' => 6 );
		$categories = array();
		$weighted = 0.0;
		$weights = 0;

		foreach ( $category_defs as $category => $def ) {
			$penalty = 0.0;
			$category_issues = 0;
			foreach ( $issues as $issue ) {
				if ( $issue['category'] !== $category ) {
					continue;
				}
				$category_issues++;
				$ratio = 'site' === $issue['scope'] ? 1.0 : min( 1.0, max( 0.15, $issue['affected_count'] / $count ) );
				$penalty += ( $base_penalty[ $issue['severity'] ] ?? 5 ) * $ratio;
			}
			$category_score = max( 0, (int) round( 100 - min( 100, $penalty ) ) );
			$categories[] = array(
				'id'     => $category,
				'label'  => $def['label'],
				'score'  => $category_score,
				'issues' => $category_issues,
			);
			$weighted += $category_score * $def['weight'];
			$weights += $def['weight'];
		}

		$counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );
		foreach ( $issues as $issue ) {
			if ( isset( $counts[ $issue['severity'] ] ) ) {
				$counts[ $issue['severity'] ]++;
			}
		}

		return array(
			'score'      => $weights ? (int) round( $weighted / $weights ) : 100,
			'pages'      => count( $pages ),
			'issues'     => $issues,
			'counts'     => $counts,
			'categories' => $categories,
			'disclaimer' => 'Technical SEO Health is a TideOrbit diagnostic based on the audited URLs and observable technical signals. It is not a Google ranking score or a Core Web Vitals field-data score.',
		);
	}

	/**
	 * Whether a keyword is present in a piece of text. The exact phrase counts,
	 * and so does every meaningful word of it appearing (in any order, singular
	 * or plural), which is how people naturally phrase titles.
	 *
	 * @param string $keyword Target keyword.
	 * @param string $text    Title/heading text.
	 * @return bool
	 */
	public static function keyword_in( $keyword, $text ) {
		$norm = function ( $value ) {
			$value = strtolower( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );
			$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );
			return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
		};
		$keyword = $norm( $keyword );
		$text    = $norm( $text );
		if ( '' === $keyword ) {
			return true;
		}
		if ( '' === $text ) {
			return false;
		}
		if ( false !== strpos( ' ' . $text . ' ', ' ' . $keyword . ' ' ) ) {
			return true;
		}
		$stop  = array( 'a', 'an', 'and', 'the', 'of', 'for', 'in', 'on', 'to', 'with', 'at', 'by', 'or', 'near', 'me', 'my', 'your', 'best' );
		$words = array_flip( explode( ' ', $text ) );
		$checked = 0;
		foreach ( explode( ' ', $keyword ) as $token ) {
			if ( strlen( $token ) < 2 || in_array( $token, $stop, true ) ) {
				continue;
			}
			$checked++;
			$stem = preg_replace( '/(es|s)$/', '', $token );
			if ( ! isset( $words[ $token ] ) && ! isset( $words[ $stem ] ) && ! isset( $words[ $stem . 's' ] ) && ! isset( $words[ $stem . 'es' ] ) ) {
				return false;
			}
		}
		return $checked > 0;
	}

	/**
	 * Whether the schema types on a page satisfy any expected type, counting
	 * standard subtypes (a Dentist is a LocalBusiness, a NewsArticle an Article).
	 *
	 * @param array $found    Types present on the page.
	 * @param array $expected Acceptable types.
	 * @return bool
	 */
	public static function schema_satisfies( array $found, array $expected ) {
		$family = array(
			'Organization'  => array( 'Organization', 'Corporation', 'LocalBusiness', 'ProfessionalService', 'WebSite', 'NGO', 'EducationalOrganization', 'MedicalOrganization', 'Person' ),
			'BlogPosting'   => array( 'BlogPosting', 'Article', 'NewsArticle', 'TechArticle', 'Report', 'ScholarlyArticle' ),
			'Article'       => array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report', 'ScholarlyArticle' ),
			'Service'       => array( 'Service', 'Product', 'Offer', 'LocalBusiness', 'ProfessionalService' ),
			'LocalBusiness' => array( 'LocalBusiness', 'ProfessionalService', 'Store', 'Restaurant', 'Dentist', 'Physician', 'MedicalClinic', 'LegalService', 'Attorney', 'HomeAndConstructionBusiness', 'AutomotiveBusiness', 'FinancialService', 'HealthAndBeautyBusiness', 'RealEstateAgent', 'Plumber', 'Electrician', 'HVACBusiness', 'RoofingContractor', 'GeneralContractor' ),
		);
		$found = array_map( 'strval', $found );
		foreach ( $expected as $type ) {
			$accepted = $family[ $type ] ?? array( $type );
			if ( array_intersect( $accepted, $found ) ) {
				return true;
			}
		}
		return false;
	}
}
