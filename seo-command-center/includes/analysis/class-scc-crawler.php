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
	const USER_AGENT   = 'SEO-Command-Center/1.0 (+WordPress)';

	/**
	 * Fetch and parse a single URL.
	 *
	 * @param string $url             URL to fetch.
	 * @param bool   $respect_robots  Whether to honor robots.txt (true for external).
	 * @return array|WP_Error Parsed data or error.
	 */
	public function fetch( $url, $respect_robots = true ) {
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return new WP_Error( 'scc_bad_url', __( 'Invalid URL.', 'seo-command-center' ) );
		}

		if ( $respect_robots && ! $this->allowed_by_robots( $url ) ) {
			return new WP_Error( 'scc_robots', __( 'Blocked by robots.txt.', 'seo-command-center' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
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

		$html = wp_remote_retrieve_body( $response );
		return $this->parse( $html, $url );
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
			'url'              => $url,
			'title'           => '',
			'meta_description' => '',
			'canonical'       => '',
			'h1'              => array(),
			'h2'              => array(),
			'h3'              => array(),
			'text_excerpt'    => '',
			'schema_types'    => array(),
			'images'          => 0,
			'images_missing_alt' => 0,
			'internal_links'  => 0,
			'external_links'  => 0,
			'internal_link_urls' => array(),
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
		if ( $title_nodes && $title_nodes->length ) {
			$data['title'] = trim( $title_nodes->item( 0 )->textContent );
		}

		// Meta description + canonical.
		foreach ( $xpath->query( '//meta[@name="description"]' ) as $node ) {
			$data['meta_description'] = trim( $node->getAttribute( 'content' ) );
			break;
		}
		foreach ( $xpath->query( '//link[@rel="canonical"]' ) as $node ) {
			$data['canonical'] = trim( $node->getAttribute( 'href' ) );
			break;
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

		// JSON-LD schema types (BEFORE stripping scripts below, so we keep them).
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $node ) {
			$json = json_decode( trim( $node->textContent ), true );
			$data['schema_types'] = array_merge( $data['schema_types'], $this->extract_schema_types( $json ) );
		}
		$data['schema_types'] = array_values( array_unique( $data['schema_types'] ) );

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
		}

		// Images.
		$imgs = $xpath->query( '//img' );
		$data['images'] = $imgs ? $imgs->length : 0;
		foreach ( $imgs as $img ) {
			$alt = trim( $img->getAttribute( 'alt' ) );
			if ( '' === $alt ) {
				$data['images_missing_alt']++;
			}
		}

		// Links (internal vs external relative to host).
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
				// Absolutize relative links so callers can crawl them.
				$abs = $link_host ? $href : ( $scheme . '://' . $host . '/' . ltrim( $href, '/' ) );
				$path = (string) wp_parse_url( $abs, PHP_URL_PATH );
				if ( '' !== $path && '/' !== $path && ! preg_match( '/\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|css|js)$/i', $path ) && ! isset( $link_seen[ $path ] ) ) {
					$link_seen[ $path ]        = true;
					$data['internal_link_urls'][] = $abs;
				}
			} else {
				$data['external_links']++;
			}
		}
		$data['internal_link_urls'] = array_slice( $data['internal_link_urls'], 0, 60 );

		// JSON-LD schema types.
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $node ) {
			$json = json_decode( trim( $node->textContent ), true );
			$data['schema_types'] = array_merge( $data['schema_types'], $this->extract_schema_types( $json ) );
		}
		$data['schema_types'] = array_values( array_unique( $data['schema_types'] ) );

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

		$cache_key = 'scc_robots_' . md5( $parts['host'] );
		$rules = get_transient( $cache_key );
		if ( false === $rules ) {
			$resp = wp_remote_get( $robots_url, array( 'timeout' => 10, 'user-agent' => self::USER_AGENT ) );
			$rules = ( is_wp_error( $resp ) ) ? '' : wp_remote_retrieve_body( $resp );
			set_transient( $cache_key, $rules, HOUR_IN_SECONDS );
		}
		if ( '' === $rules ) {
			return true; // No robots.txt or unreachable: assume allowed.
		}

		// Parse disallow rules for '*' user-agent group (simple, conservative).
		$disallows = array();
		$applies   = false;
		foreach ( preg_split( '/\r?\n/', $rules ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
				$applies = ( '*' === trim( $m[1] ) );
			} elseif ( $applies && preg_match( '/^disallow:\s*(.*)$/i', $line, $m ) ) {
				$rule = trim( $m[1] );
				if ( '' !== $rule ) {
					$disallows[] = $rule;
				}
			}
		}
		foreach ( $disallows as $rule ) {
			if ( 0 === strpos( $path, $rule ) ) {
				return false;
			}
		}
		return true;
	}
}
