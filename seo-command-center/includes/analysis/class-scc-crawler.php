<?php
/**
 * HTTP crawler for rendered-page inspection.
 *
 * Best-effort: fetches a URL and parses the rendered HTML (title, canonical,
 * meta description, JSON-LD schema, headings). Respects robots.txt for external
 * URLs and never bypasses authentication, paywalls, or access controls.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crawler.
 */
class SCC_Crawler {

	const TIMEOUT      = 20;
	const MAX_BYTES    = 2000000; // 2 MB cap.
	const MAX_REDIRECTS = 5;
	const USER_AGENT   = 'SEO-Command-Center/1.0 (+WordPress)';
	const PRODUCT_TOKEN = 'SEO-Command-Center'; // robots.txt user-agent token.

	// Content types we will parse. Anything else (PDF, image, video, zip,
	// executable, arbitrary binary) is refused so the crawler never downloads or
	// tries to parse a non-HTML resource.
	const PARSEABLE_TYPES = array( 'text/html', 'application/xhtml+xml', 'text/plain', 'application/xml', 'text/xml' );

	/**
	 * Fetch and parse a single URL.
	 *
	 * @param string $url             URL to fetch.
	 * @param bool   $respect_robots  Whether to honor robots.txt (true for external).
	 * @return array|WP_Error Parsed data (incl. crawl_url/final_url/canonical) or error.
	 */
	public function fetch( $url, $respect_robots = true, $timeout = null ) {
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return new WP_Error( 'scc_bad_url', __( 'Invalid URL.', 'seo-command-center' ) );
		}

		// A caller may cap the per-request wait (e.g. bulk competitor crawling,
		// where a single slow host must not eat the whole request budget). Falls
		// back to the default TIMEOUT when not given or out of range.
		$timeout = ( null === $timeout ) ? self::TIMEOUT : (int) $timeout;
		if ( $timeout < 1 || $timeout > self::TIMEOUT ) {
			$timeout = self::TIMEOUT;
		}

		// SSRF guard, immediately before the request: refuse private/reserved/
		// link-local/metadata targets even if a supplied URL points at one.
		$safe = SCC_URL::is_safe_outbound_url( $url, false );
		if ( is_wp_error( $safe ) ) {
			SCC_Logger::error( 'crawler', 'Blocked outbound URL: ' . $safe->get_error_message(), array( 'url' => $url ) );
			return $safe;
		}

		if ( $respect_robots && ! $this->allowed_by_robots( $url ) ) {
			return new WP_Error( 'scc_robots', __( 'Blocked by robots.txt.', 'seo-command-center' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => self::MAX_REDIRECTS, // WP caps the chain, preventing loops.
				'sslverify'   => true,
				'user-agent'  => self::USER_AGENT,
				'limit_response_size' => self::MAX_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			SCC_Logger::error( 'crawler', 'Fetch failed: ' . $response->get_error_message(), array( 'url' => $url ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( (int) $code >= 400 ) {
			return new WP_Error( 'scc_http', sprintf( 'HTTP %d', $code ), array( 'status' => $code ) );
		}

		// Content-type filtering: never parse a non-HTML/binary resource.
		$ctype = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' !== $ctype && ! $this->is_parseable_type( $ctype ) ) {
			return new WP_Error(
				'scc_content_type',
				/* translators: %s: content type */
				sprintf( __( 'Skipped: %s is not an HTML page.', 'seo-command-center' ), $ctype ),
				array( 'content_type' => $ctype )
			);
		}

		// Distinguish the URL we asked for from the one we ended up at.
		$final_url = $this->final_url( $response, $url );
		$final_safe = SCC_URL::is_safe_outbound_url( $final_url, false );
		if ( is_wp_error( $final_safe ) ) {
			SCC_Logger::error( 'crawler', 'Blocked redirected URL: ' . $final_safe->get_error_message(), array( 'url' => $final_url ) );
			return $final_safe;
		}

		$html = wp_remote_retrieve_body( $response );
		$data = $this->parse( $html, $final_url );

		// Record crawl diagnostics: the crawl URL (requested), the final URL (after
		// redirects) and the declared canonical are three DIFFERENT things.
		$data['crawl_url']   = $url;
		$data['final_url']   = $final_url;
		$data['redirected']  = ( SCC_URL::normalize_for_crawl( $url ) !== SCC_URL::normalize_for_crawl( $final_url ) );
		$data['status']      = (int) $code;
		$data['content_type']= $ctype;
		$data['x_robots_tag']= strtolower( trim( (string) wp_remote_retrieve_header( $response, 'x-robots-tag' ) ) );
		if ( false !== strpos( $data['x_robots_tag'], 'noindex' ) ) {
			$data['noindex'] = true;
		}
		if ( false !== strpos( $data['x_robots_tag'], 'nofollow' ) ) {
			$data['nofollow'] = true;
		}
		return $data;
	}

	/**
	 * Best-effort extraction of the final URL after redirects from a WP HTTP
	 * response, falling back to the requested URL.
	 *
	 * @param array  $response wp_remote_get response.
	 * @param string $requested Requested URL.
	 * @return string
	 */
	protected function final_url( $response, $requested ) {
		if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
			$obj = $response['http_response']->get_response_object();
			if ( $obj && ! empty( $obj->url ) ) {
				return (string) $obj->url;
			}
		}
		return $requested;
	}

	/**
	 * Whether a Content-Type header names a resource we should parse.
	 *
	 * @param string $ctype Lowercased content-type header value.
	 * @return bool
	 */
	protected function is_parseable_type( $ctype ) {
		foreach ( self::PARSEABLE_TYPES as $type ) {
			if ( false !== strpos( $ctype, $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse an HTML string into a structured summary.
	 *
	 * @param string $html HTML.
	 * @param string $url  Source URL.
	 * @return array
	 */
	public function parse( $html, $url = '' ) {
		$data = array(
			'url'                       => $url,
			'title'                     => '',
			'title_count'               => 0,
			'meta_description'          => '',
			'meta_description_count'    => 0,
			'canonical'                 => '',
			'canonical_count'           => 0,
			'canonical_resolved'        => '', // Absolute canonical (canonical may be relative or point elsewhere).
			'robots_meta'               => '',
			'noindex'                   => false,
			'nofollow'                  => false,
			'hreflang'                  => array(),
			'html_lang'                 => '',
			'viewport'                  => '',
			'h1'                        => array(),
			'h2'                        => array(),
			'h3'                        => array(),
			'heading_outline'           => array(), // Ordered heading levels (1–6) in document order.
			'og'                        => array(), // Open Graph / Twitter card tags found (lowercased property => content).
			'text_excerpt'              => '',
			'word_count'                => 0,
			'schema_types'              => array(),
			'schema_blocks'             => 0,
			'schema_invalid'            => 0,
			'images'                    => 0,
			'images_missing_alt'        => 0,
			'images_empty_alt'          => 0,
			'images_missing_dimensions' => 0,
			'images_not_lazy'           => 0,
			'mixed_content_count'       => 0,
			'internal_links'            => 0,
			'external_links'            => 0,
			'internal_link_urls'        => array(),
		);

		if ( '' === trim( (string) $html ) ) {
			return $data;
		}

		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $dom );

		// Title.
		$title_nodes = $xpath->query( '//title' );
		$data['title_count'] = $title_nodes ? (int) $title_nodes->length : 0;
		if ( $title_nodes && $title_nodes->length ) {
			$data['title'] = trim( $title_nodes->item( 0 )->textContent );
		}

		// Meta description, robots directives, canonical, language and viewport.
		$desc_nodes = $xpath->query( '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]' );
		$data['meta_description_count'] = $desc_nodes ? (int) $desc_nodes->length : 0;
		if ( $desc_nodes && $desc_nodes->length ) {
			$data['meta_description'] = trim( $desc_nodes->item( 0 )->getAttribute( 'content' ) );
		}

		$robot_directives = array();
		foreach ( $xpath->query( '//meta[@name]' ) as $node ) {
			$name = strtolower( trim( $node->getAttribute( 'name' ) ) );
			if ( in_array( $name, array( 'robots', 'googlebot' ), true ) ) {
				$value = strtolower( trim( $node->getAttribute( 'content' ) ) );
				if ( '' !== $value ) {
					$robot_directives[] = $value;
				}
			}
		}
		$data['robots_meta'] = implode( ', ', array_unique( $robot_directives ) );
		$data['noindex'] = false !== strpos( $data['robots_meta'], 'noindex' );
		$data['nofollow'] = false !== strpos( $data['robots_meta'], 'nofollow' );

		$canonical_nodes = $xpath->query( '//link[contains(concat(" ", normalize-space(@rel), " "), " canonical ")]' );
		$data['canonical_count'] = $canonical_nodes ? (int) $canonical_nodes->length : 0;
		if ( $canonical_nodes && $canonical_nodes->length ) {
			$data['canonical'] = trim( $canonical_nodes->item( 0 )->getAttribute( 'href' ) );
			// Resolve to an absolute URL: a canonical can be relative or point at a
			// different page entirely, so keep it distinct from the crawl/final URL.
			if ( '' !== $data['canonical'] && '' !== (string) $url && class_exists( 'SCC_URL' ) ) {
				$data['canonical_resolved'] = SCC_URL::resolve( $url, $data['canonical'] );
			} else {
				$data['canonical_resolved'] = $data['canonical'];
			}
		}

		$html_nodes = $xpath->query( '//html' );
		if ( $html_nodes && $html_nodes->length ) {
			$data['html_lang'] = strtolower( trim( $html_nodes->item( 0 )->getAttribute( 'lang' ) ) );
		}
		$viewport_nodes = $xpath->query( '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="viewport"]' );
		if ( $viewport_nodes && $viewport_nodes->length ) {
			$data['viewport'] = trim( $viewport_nodes->item( 0 )->getAttribute( 'content' ) );
		}

		foreach ( $xpath->query( '//link[contains(concat(" ", normalize-space(@rel), " "), " alternate ")][@hreflang][@href]' ) as $node ) {
			$href = trim( $node->getAttribute( 'href' ) );
			$lang = strtolower( trim( $node->getAttribute( 'hreflang' ) ) );
			if ( '' === $href || '' === $lang ) {
				continue;
			}
			$data['hreflang'][] = array(
				'lang' => $lang,
				'url'  => class_exists( 'SCC_URL' ) ? SCC_URL::resolve( $url, $href ) : $href,
			);
		}

		// Headings.
		foreach ( $xpath->query( '//h1' ) as $node ) {
			$data['h1'][] = trim( $node->textContent );
		}
		foreach ( $xpath->query( '//h2' ) as $node ) {
			$data['h2'][] = trim( $node->textContent );
		}
		foreach ( $xpath->query( '//h3' ) as $node ) {
			$data['h3'][] = trim( $node->textContent );
		}
		// Full heading outline in document order (an XPath union returns nodes in
		// document order), used to spot skipped levels such as H2 → H4.
		foreach ( $xpath->query( '//h1 | //h2 | //h3 | //h4 | //h5 | //h6' ) as $node ) {
			if ( '' !== trim( $node->textContent ) ) {
				$data['heading_outline'][] = (int) substr( strtolower( $node->nodeName ), 1 );
			}
		}

		// Open Graph + Twitter card tags (social sharing previews).
		foreach ( $xpath->query( '//meta[@property or @name]' ) as $node ) {
			$key = strtolower( trim( $node->hasAttribute( 'property' ) ? $node->getAttribute( 'property' ) : $node->getAttribute( 'name' ) ) );
			if ( ( 0 === strpos( $key, 'og:' ) || 0 === strpos( $key, 'twitter:' ) ) && ! isset( $data['og'][ $key ] ) ) {
				$data['og'][ $key ] = trim( (string) $node->getAttribute( 'content' ) );
			}
		}

		// JSON-LD schema types (BEFORE stripping scripts below, so we keep them).
		// Each block is parsed once, identical blocks are de-duplicated, and a
		// single malformed block never aborts the crawl (json_decode -> null,
		// which extract_schema_types safely ignores).
		$seen_blocks = array();
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $node ) {
			$data['schema_blocks']++;
			$raw = trim( $node->textContent );
			if ( '' === $raw ) {
				$data['schema_invalid']++;
				continue;
			}
			$hash = md5( $raw );
			if ( isset( $seen_blocks[ $hash ] ) ) {
				continue; // Duplicate JSON-LD block: skip.
			}
			$seen_blocks[ $hash ] = true;
			$json = json_decode( $raw, true );
			if ( null === $json && JSON_ERROR_NONE !== json_last_error() ) {
				$data['schema_invalid']++;
				continue; // Malformed JSON-LD: ignore this block, keep crawling.
			}
			$data['schema_types'] = array_merge( $data['schema_types'], $this->extract_schema_types( $json ) );
		}
		$data['schema_types'] = array_values( array_unique( $data['schema_types'] ) );

		// Detect mixed-content references before stripping scripts/styles for the
		// visible-text excerpt. Otherwise insecure script/link resources disappear
		// from the DOM before this technical check sees them.
		if ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			foreach ( $xpath->query( '//*[@src or @href]' ) as $node ) {
				$ref = $node->hasAttribute( 'src' ) ? trim( $node->getAttribute( 'src' ) ) : trim( $node->getAttribute( 'href' ) );
				if ( 0 === stripos( $ref, 'http://' ) ) {
					$data['mixed_content_count']++;
				}
			}
		}

		// Links (internal vs external relative to host). Read before the nav/header/
		// footer strip below: menu links are real links, and without them every page
		// reached only from the menu looked orphaned in the audit's link graph.
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$scheme = $scheme ? $scheme : 'https';
		$link_seen = array();
		foreach ( $xpath->query( '//a[@href]' ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) || 0 === stripos( $href, 'javascript:' ) ) {
				continue;
			}
			$link_host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $link_host || ( $host && $link_host === $host ) ) {
				$data['internal_links']++;
				// Resolve the reference against the page URL (RFC 3986), then reduce
				// it to a stable crawl identity (fragment + tracking params dropped)
				// so the same page is not queued many times.
				$abs = ( '' !== (string) $url && class_exists( 'SCC_URL' ) )
					? SCC_URL::normalize_for_crawl( SCC_URL::resolve( $url, $href ) )
					: ( $link_host ? $href : ( $scheme . '://' . $host . '/' . ltrim( $href, '/' ) ) );
				if ( '' === $abs ) {
					continue;
				}
				$path = (string) wp_parse_url( $abs, PHP_URL_PATH );
				if ( '' !== $path && '/' !== $path && ! preg_match( '/\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|css|js|mp4|mp3|avi|mov|exe|dmg|woff2?|ttf)$/i', $path ) && ! isset( $link_seen[ $abs ] ) ) {
					$link_seen[ $abs ]            = true;
					$data['internal_link_urls'][] = $abs;
				}
			} else {
				$data['external_links']++;
			}
		}
		$data['internal_link_urls'] = array_slice( $data['internal_link_urls'], 0, 200 );

		// Visible body text excerpt (drop script/style/nav/header/footer noise), so
		// callers can compare actual page CONTENT, not just headings.
		foreach ( $xpath->query( '//script | //style | //noscript | //nav | //header | //footer | //form' ) as $strip ) {
			if ( $strip->parentNode ) {
				$strip->parentNode->removeChild( $strip );
			}
		}
		$body_nodes = $xpath->query( '//body' );
		if ( $body_nodes && $body_nodes->length ) {
			$text = preg_replace( '/\s+/', ' ', (string) $body_nodes->item( 0 )->textContent );
			$data['text_excerpt'] = trim( mb_substr( $text, 0, 4000 ) );
			$data['word_count'] = str_word_count( wp_strip_all_tags( $text ) );
		}

		// Images.
		$imgs = $xpath->query( '//img' );
		$data['images'] = $imgs ? $imgs->length : 0;
		foreach ( $imgs as $img ) {
			if ( ! $img->hasAttribute( 'alt' ) ) {
				$data['images_missing_alt']++;
			} elseif ( '' === trim( $img->getAttribute( 'alt' ) ) ) {
				// Empty alt can be correct for decorative images, so track it
				// separately and do not automatically treat it as an SEO error.
				$data['images_empty_alt']++;
			}
			if ( '' === trim( $img->getAttribute( 'width' ) ) || '' === trim( $img->getAttribute( 'height' ) ) ) {
				$data['images_missing_dimensions']++;
			}
			$loading = strtolower( trim( $img->getAttribute( 'loading' ) ) );
			if ( 'lazy' !== $loading ) {
				$data['images_not_lazy']++;
			}
		}

		return $data;
	}

	/**
	 * Extract @type values from a decoded JSON-LD structure.
	 *
	 * @param mixed $json Decoded JSON.
	 * @return array
	 */
	protected function extract_schema_types( $json ) {
		$types = array();
		if ( ! is_array( $json ) ) {
			return $types;
		}
		// A JSON-LD block may be a plain list of nodes rather than a single node
		// or an @graph wrapper.
		if ( array_values( $json ) === $json ) {
			foreach ( $json as $item ) {
				$types = array_merge( $types, $this->extract_schema_types( $item ) );
			}
			return $types;
		}
		if ( isset( $json['@type'] ) ) {
			$types = array_merge( $types, (array) $json['@type'] );
		}
		if ( isset( $json['@graph'] ) && is_array( $json['@graph'] ) ) {
			foreach ( $json['@graph'] as $item ) {
				$types = array_merge( $types, $this->extract_schema_types( $item ) );
			}
		}
		return $types;
	}

	/**
	 * Very small robots.txt allow check for a given URL and our user agent.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	protected function allowed_by_robots( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return false;
		}
		$robots_url = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . '/robots.txt';
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';

		$robots_safe = SCC_URL::is_safe_outbound_url( $robots_url, false );
		if ( is_wp_error( $robots_safe ) ) {
			return false;
		}

		$cache_key = 'scc_robots_' . md5( $parts['host'] );
		$rules = get_transient( $cache_key );
		if ( false === $rules ) {
			$resp = wp_safe_remote_get(
				$robots_url,
				array(
					'timeout'             => 10,
					'redirection'         => 3,
					'sslverify'           => true,
					'user-agent'          => self::USER_AGENT,
					'limit_response_size' => 524288,
				)
			);
			$rules = ( is_wp_error( $resp ) ) ? '' : wp_remote_retrieve_body( $resp );
			set_transient( $cache_key, $rules, HOUR_IN_SECONDS );
		}
		if ( '' === $rules ) {
			return true; // No robots.txt or unreachable: assume allowed.
		}

		// Full Allow/Disallow evaluation (wildcards, $ anchors, agent groups,
		// longest-match precedence) for our product token.
		$request_path = $path . ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );
		return SCC_Robots::is_allowed( $rules, $request_path, self::PRODUCT_TOKEN );
	}
}
