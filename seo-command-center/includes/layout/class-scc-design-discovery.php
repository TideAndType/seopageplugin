<?php
/**
 * 21st.dev Design Discovery scanner.
 *
 * Runs ONLY on administrator request. Reads component metadata from the public
 * 21st.dev community catalog; never executes React/JS, auto-installs packages,
 * or imports copyrighted source into a WordPress page. The user's separately
 * granted marketplace scanning permission controls use of this feature.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Design_Discovery {
	const OPTION = 'scc_21st_component_catalog';
	const LIMIT = 600;
	const PER_RUN = 6;

	public static function categories() {
		return array(
			'hero' => 'Heroes',
			'features' => 'Features',
			'grid' => 'Grids & Bento',
			'card' => 'Cards',
			'call-to-action' => 'Calls to Action',
			'pricing-section' => 'Pricing',
			'faq' => 'FAQs',
			'testimonial' => 'Testimonials',
			'button' => 'Buttons',
			'navigation-menu' => 'Navigation',
		);
	}

	public static function catalog() {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/** Return the source metadata and never return a component's executable code. */
	public static function listing( $category = '', $search = '' ) {
		$category = sanitize_key( $category );
		$search = strtolower( trim( (string) $search ) );
		$items = array_values( array_filter( self::catalog(), function ( $row ) use ( $category, $search ) {
			if ( $category && $category !== ( $row['category'] ?? '' ) ) { return false; }
			if ( ! $search ) { return true; }
			return false !== strpos( strtolower( (string) ( $row['title'] ?? '' ) . ' ' . ( $row['description'] ?? '' ) . ' ' . implode( ' ', (array) ( $row['tags'] ?? array() ) ) ), $search );
		} ) );
		usort( $items, function ( $a, $b ) {
			return (int) ( $b['scanned'] ?? 0 ) - (int) ( $a['scanned'] ?? 0 );
		} );
		return array_slice( $items, 0, 150 );
	}

	/**
	 * Strictly allow only marketplace page URLs on 21st.dev.
	 * Redirects/third-party source links are NEVER followed by the scanner.
	 */
	public static function canonical_url( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Next.js may emit @ as %40 in URLs; normalize only this pathname prefix.
		$url = str_replace( array( '/%40', '/%2540' ), '/@', $url );
		if ( 0 === strpos( $url, '/@' ) || 0 === strpos( $url, '/community/components/' ) ) {
			$url = 'https://21st.dev' . $url;
		} elseif ( 0 === strpos( $url, 'https://21st.dev/' ) ) {
			// Already absolute.
		} else { return ''; }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || '21st.dev' !== ( $parts['host'] ?? '' ) ) {
			return '';
		}
		$path = (string) ( $parts['path'] ?? '' );
		// /s/ is the marketplace category namespace, never a component author.
		if ( 0 === strpos( $path, '/community/components/s/' ) ) { return ''; }
		if ( ! preg_match( '#^/(?:@[a-zA-Z0-9_.-]+/components/[a-zA-Z0-9_./-]+|community/components/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_./-]+)$#', $path ) ) {
			return '';
		}
		return 'https://21st.dev' . rtrim( $path, '/' );
	}

	/** Extract canonical component page URLs from a rendered category HTML document. */
	public static function discover_links( $html ) {
		$links = array();
		if ( preg_match_all( '~<a\\b[^>]*href\\s*=\\s*["\\\']([^"\\\']+)["\\\']~i', (string) $html, $m ) ) {
			foreach ( $m[1] as $href ) {
				$canonical = self::canonical_url( $href );
				if ( $canonical ) { $links[ $canonical ] = true; }
			}
		}
		return array_keys( $links );
	}

	/** Read a component page without executing scripts or extracting TSX. */
	public static function parse_detail( $html, $url, $category ) {
		$url = self::canonical_url( $url );
		if ( ! $url ) { return array(); }
		$html = (string) $html;
		$title = '';
		$description = '';
		$license = '';
		$deps = array();
		$tags = array();
		$author = '';
		$preview = '';

		if ( class_exists( 'DOMDocument' ) ) {
			$doc = new DOMDocument();
			$prior = libxml_use_internal_errors( true );
			$doc->loadHTML( '<?xml encoding="UTF-8"?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
			libxml_clear_errors();
			libxml_use_internal_errors( $prior );
			$xp = new DOMXPath( $doc );
			$h1 = $xp->query( '//h1' );
			if ( $h1 && $h1->length ) { $title = trim( $h1->item( 0 )->textContent ); }
			$meta = $xp->query( '//meta[@name="description"]/@content' );
			if ( $meta && $meta->length ) { $description = trim( $meta->item( 0 )->nodeValue ); }
			$headings = $xp->query( '//h2|//h3' );
			if ( $headings ) {
				foreach ( $headings as $h ) {
					$heading = strtolower( trim( $h->textContent ) );
					if ( ! in_array( $heading, array( 'license', 'dependencies', 'tags' ), true ) ) { continue; }
					$next = $h->nextSibling;
					while ( $next && ( ! ( $next instanceof DOMElement ) || '' === trim( $next->textContent ) ) ) {
						$next = $next->nextSibling;
					}
					$values = array();
					if ( $next instanceof DOMElement ) {
						foreach ( $next->getElementsByTagName( 'a' ) as $link ) {
							$text = trim( $link->textContent );
							if ( $text ) { $values[] = substr( $text, 0, 90 ); }
						}
					}
					if ( 'license' === $heading ) {
						$license = $next ? substr( trim( $next->textContent ), 0, 130 ) : '';
					} elseif ( 'dependencies' === $heading ) {
						$deps = array_slice( array_unique( $values ), 0, 20 );
					} elseif ( 'tags' === $heading ) {
						$tags = array_slice( array_unique( $values ), 0, 20 );
					}
				}
			}
			$images = $xp->query( '//img[contains(@src,"cdn.21st.dev")]/@src' );
			if ( $images && $images->length ) {
				$src = (string) $images->item( 0 )->nodeValue;
				if ( 0 === strpos( $src, 'https://cdn.21st.dev/' ) ) { $preview = $src; }
			}
		}
		if ( ! $title && preg_match( '~<h1[^>]*>(.*?)</h1>~is', $html, $m ) ) { $title = strip_tags( $m[1] ); }
		if ( ! $description && preg_match( '~<meta[^>]+name=["\\\']description["\\\'][^>]+content=["\\\']([^"\\\']+)~i', $html, $m ) ) { $description = $m[1]; }
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#^/@([^/]+)/components/#', $path, $m ) ) { $author = $m[1]; }
		elseif ( preg_match( '#^/community/components/([^/]+)/#', $path, $m ) ) { $author = $m[1]; }
		$title = sanitize_text_field( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $title ) { return array(); }
		$desc = trim( wp_strip_all_tags( html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		$signals = strtolower( $title . ' ' . $desc . ' ' . implode( ' ', $tags ) );
		$style = array();
		foreach ( array( 'bento', 'editorial', 'split', 'collage', 'minimal', 'glass', 'gradient', 'animated', 'scroll', 'image', 'grid', 'dark', 'serif', '3d', 'shader' ) as $signal ) {
			if ( false !== strpos( $signals, $signal ) ) { $style[] = $signal; }
		}
		return array(
			'url' => $url,
			'title' => substr( $title, 0, 160 ),
			'description' => substr( $desc, 0, 500 ),
			'author' => sanitize_text_field( $author ),
			'category' => sanitize_key( $category ),
			'tags' => $tags,
			'dependencies' => $deps,
			'license' => sanitize_text_field( $license ),
			'signals' => $style,
			'preview' => $preview,
			'scanned' => time(),
			'mode' => 'reference_only',
		);
	}

	/**
	 * Incremental scanner. One selected category per admin click. Each click
	 * fetches a category page and a small batch of new component detail pages.
	 */
	public static function scan( $category ) {
		$category = sanitize_key( $category );
		if ( ! isset( self::categories()[ $category ] ) ) {
			return new WP_Error( 'scc_21st_category', 'Choose a valid component category.' );
		}
		$url = 'https://21st.dev/community/components/s/' . $category;
		$response = self::fetch( $url );
		if ( is_wp_error( $response ) ) { return $response; }
		$links = self::discover_links( $response );
		if ( ! $links ) {
			return new WP_Error( 'scc_21st_empty', 'No component links were found. The marketplace may require a browser renderer or may have changed its HTML.' );
		}
		$catalog = self::catalog();
		$stored = 0;
		$failed = 0;
		$started = microtime( true );
		foreach ( $links as $link ) {
			if ( $stored >= self::PER_RUN || microtime( true ) - $started > 42 ) { break; }
			$key = md5( $link );
			if ( isset( $catalog[ $key ] ) ) { continue; }
			$detail = self::fetch( $link );
			if ( is_wp_error( $detail ) ) { $failed++; continue; }
			$record = self::parse_detail( $detail, $link, $category );
			if ( ! $record ) { $failed++; continue; }
			$catalog[ $key ] = $record;
			$stored++;
		}
		if ( count( $catalog ) > self::LIMIT ) {
			uasort( $catalog, function ( $a, $b ) {
				return (int) ( $b['scanned'] ?? 0 ) - (int) ( $a['scanned'] ?? 0 );
			} );
			$catalog = array_slice( $catalog, 0, self::LIMIT, true );
		}
		update_option( self::OPTION, $catalog, false );
		return array(
			'added' => $stored, 'failed' => $failed, 'found_on_page' => count( $links ),
			'total' => count( $catalog ), 'category' => $category,
			'items' => self::listing( $category ),
		);
	}

	protected static function fetch( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$category_url = preg_match( '#^https://21st\\.dev/community/components/s/[a-z-]+$#', $url );
		if ( '21st.dev' !== $host || ( ! $category_url && ! self::canonical_url( $url ) ) ) {
			return new WP_Error( 'scc_21st_url', 'Invalid 21st.dev marketplace URL.' );
		}
		// Handle a single 21st.dev legacy URL redirect manually, after verifying
		// its destination is still a canonical page on the same exact host.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$result = wp_remote_get( $url, array(
				'timeout' => 18,
				'redirection' => 0,
				'limit_response_size' => 1500000,
				'headers' => array( 'Accept' => 'text/html' ),
				'user-agent' => 'TideOrbit-DesignDiscovery/1.0 (+WordPress; administrator-initiated)',
			) );
			if ( is_wp_error( $result ) ) { return $result; }
			$code = (int) wp_remote_retrieve_response_code( $result );
			if ( ! in_array( $code, array( 301, 302, 307, 308 ), true ) ) { break; }
			$location = (string) wp_remote_retrieve_header( $result, 'location' );
			$next = self::canonical_url( $location );
			if ( ! $next ) { return new WP_Error( 'scc_21st_redirect', 'The marketplace redirected to an unapproved URL.' ); }
			$url = $next;
		}

		if ( 200 !== $code ) {
			return new WP_Error( 'scc_21st_http', sprintf( '21st.dev returned HTTP %d. Check your hosting outbound access or retry.', $code ) );
		}
		$body = (string) wp_remote_retrieve_body( $result );
		if ( '' === $body ) { return new WP_Error( 'scc_21st_empty_html', '21st.dev returned an empty page.' ); }
		return $body;
	}

	/** Small grounded set sent to LM Studio as design reference material. */
	public static function inspirations( $category = '', $limit = 6 ) {
		$items = self::listing( $category );
		$limit = max( 1, min( 12, (int) $limit ) );
		if ( '' === $category ) {
			// Diversify the art-direction context across hero, feature, grid,
			// pricing, CTA and supporting components; don't send eight similar
			// cards merely because that category was scanned most recently.
			$groups = array();
			foreach ( $items as $item ) {
				$groups[ $item['category'] ][] = $item;
			}
			$balanced = array();
			while ( count( $balanced ) < $limit && ! empty( $groups ) ) {
				foreach ( array_keys( $groups ) as $key ) {
					if ( count( $balanced ) >= $limit ) { break; }
					$next = array_shift( $groups[ $key ] );
					if ( $next ) { $balanced[] = $next; }
					if ( empty( $groups[ $key ] ) ) { unset( $groups[ $key ] ); }
				}
			}
			$items = $balanced;
		} else { $items = array_slice( $items, 0, $limit ); }
		return array_map( function ( $c ) {
			return array(
				'name' => $c['title'],
				'category' => $c['category'],
				'summary' => $c['description'],
				'patterns' => $c['signals'],
				'source_url' => $c['url'],
				'license' => $c['license'],
			);
		}, $items );
	}
}
