<?php
/**
 * Centralized URL security + resolution helpers.
 *
 * One place for every outbound-URL decision so the crawler, the AI providers,
 * and the integrations all behave consistently:
 *
 *   - {@see is_safe_outbound_url()} — SSRF guard applied IMMEDIATELY BEFORE an
 *     outbound request (not only when a setting is saved). Loopback is allowed
 *     (LM Studio runs locally), private/reserved/link-local/multicast targets,
 *     cloud metadata endpoints, credentialed URLs and non-HTTP schemes are not.
 *   - {@see resolve()} — RFC 3986 relative-reference resolution for the crawler.
 *   - {@see normalize_for_crawl()} — canonical crawl identity (drops the
 *     fragment and tracking-only query parameters, lowercases scheme/host,
 *     removes default ports) so the same page is not crawled many times.
 *
 * Pure and dependency-light: everything here is unit-testable without WordPress.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL helper.
 */
class SCC_URL {

	/**
	 * Query parameters that only track a visit and never change page content.
	 * Stripped for crawl identity so tracking links do not create infinite
	 * duplicate crawl variants.
	 *
	 * @var string[]
	 */
	const TRACKING_PARAMS = array(
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
		'utm_source_platform', 'utm_creative_format', 'utm_marketing_tactic',
		'fbclid', 'gclid', 'gclsrc', 'dclid', 'gbraid', 'wbraid', 'msclkid', 'yclid',
		'mc_cid', 'mc_eid', '_hsenc', '_hsmi', 'igshid', 'vero_id', 'oly_enc_id', 'oly_anon_id',
		'_ga', '_gl', 'pk_campaign', 'pk_kwd', 'piwik_campaign', 'piwik_kwd', 'ref', 'ref_src',
	);

	/**
	 * Whether a URL is safe to request from the server. This is the SSRF guard:
	 * call it immediately before every outbound HTTP request.
	 *
	 * Loopback (127.0.0.0/8, ::1, and the literal host "localhost") is allowed
	 * because LM Studio and other local model servers legitimately live there.
	 * Everything else that is not a public address is rejected.
	 *
	 * @param string $url            URL to check.
	 * @param bool   $allow_loopback Whether loopback targets are acceptable (default true).
	 * @return true|WP_Error True when safe, else a WP_Error explaining why.
	 */
	public static function is_safe_outbound_url( $url, $allow_loopback = true ) {
		$url   = trim( (string) $url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'scc_url_invalid', __( 'That URL is not valid.', 'seo-command-center' ) );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'scc_url_scheme',
				/* translators: %s: scheme */
				sprintf( __( 'The scheme "%s" is not allowed. Use http or https.', 'seo-command-center' ), $scheme )
			);
		}

		// Embedded credentials (http://user:pass@host) are never accepted.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'scc_url_credentials', __( 'URLs with embedded credentials are not allowed.', 'seo-command-center' ) );
		}

		$host = strtolower( trim( (string) $parts['host'], '[]' ) );
		if ( '' === $host ) {
			return new WP_Error( 'scc_url_invalid', __( 'That URL has no host.', 'seo-command-center' ) );
		}

		// Allow a caller/site to override the decision explicitly.
		$override = apply_filters( 'scc_allow_outbound_url', null, $url, $host );
		if ( true === $override ) {
			return true;
		}
		if ( is_wp_error( $override ) ) {
			return $override;
		}

		// Resolve the host to the set of IPs it points at, then judge each one.
		// This catches a hostname that resolves to a private/metadata address
		// (a basic DNS-rebinding / SSRF-by-hostname mitigation).
		$ips = self::resolve_host_ips( $host );

		// A literal IP host is judged directly even if resolution returned nothing.
		if ( empty( $ips ) && ( filter_var( $host, FILTER_VALIDATE_IP ) ) ) {
			$ips = array( $host );
		}

		foreach ( $ips as $ip ) {
			$category = self::ip_category( $ip );
			if ( 'public' === $category ) {
				continue;
			}
			if ( 'loopback' === $category && $allow_loopback ) {
				continue;
			}
			return new WP_Error(
				'scc_url_blocked',
				/* translators: 1: host, 2: address category */
				sprintf( __( 'Refusing to connect to %1$s: it resolves to a %2$s address, which is not allowed for security reasons.', 'seo-command-center' ), $host, $category )
			);
		}

		// The literal host "localhost" (and *.localhost) is loopback by definition.
		if ( empty( $ips ) && self::is_localhost_name( $host ) ) {
			return $allow_loopback ? true : new WP_Error( 'scc_url_blocked', __( 'Loopback targets are not allowed here.', 'seo-command-center' ) );
		}

		// If a hostname could not be resolved at all and is not an IP literal, let
		// it proceed — the request itself will simply fail. We never fail-open for
		// a resolved private address (handled above).
		return true;
	}

	/**
	 * Whether a host string is the loopback name space.
	 *
	 * @param string $host Lowercased host.
	 * @return bool
	 */
	public static function is_localhost_name( $host ) {
		$host = strtolower( trim( (string) $host, '.' ) );
		return ( 'localhost' === $host || self::str_ends_with( $host, '.localhost' ) );
	}

	/**
	 * Classify an IP address for the outbound guard.
	 *
	 * @param string $ip IP address (v4 or v6).
	 * @return string One of: loopback, private, linklocal, multicast, reserved,
	 *                unspecified, public, invalid.
	 */
	public static function ip_category( $ip ) {
		$ip = trim( (string) $ip, '[]' );

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::ipv4_category( $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return self::ipv6_category( $ip );
		}
		return 'invalid';
	}

	/**
	 * Classify an IPv4 address.
	 *
	 * @param string $ip IPv4.
	 * @return string
	 */
	protected static function ipv4_category( $ip ) {
		$long = ip2long( $ip );
		if ( false === $long ) {
			return 'invalid';
		}
		$long = $long & 0xFFFFFFFF;

		$in = function ( $cidr ) use ( $long ) {
			list( $net, $bits ) = explode( '/', $cidr );
			$net  = ip2long( $net ) & 0xFFFFFFFF;
			$mask = ( 0 === (int) $bits ) ? 0 : ( ( 0xFFFFFFFF << ( 32 - (int) $bits ) ) & 0xFFFFFFFF );
			return ( $long & $mask ) === ( $net & $mask );
		};

		if ( $in( '127.0.0.0/8' ) ) {
			return 'loopback';
		}
		if ( $in( '0.0.0.0/8' ) ) {
			return 'unspecified';
		}
		if ( $in( '169.254.0.0/16' ) ) {
			return 'linklocal'; // Includes the cloud metadata endpoint 169.254.169.254.
		}
		if ( $in( '10.0.0.0/8' ) || $in( '172.16.0.0/12' ) || $in( '192.168.0.0/16' ) ) {
			return 'private';
		}
		if ( $in( '224.0.0.0/4' ) ) {
			return 'multicast';
		}
		// Carrier-grade NAT, benchmarking, documentation, and future/broadcast use.
		if ( $in( '100.64.0.0/10' ) || $in( '192.0.0.0/24' ) || $in( '192.0.2.0/24' )
			|| $in( '198.18.0.0/15' ) || $in( '198.51.100.0/24' ) || $in( '203.0.113.0/24' )
			|| $in( '240.0.0.0/4' ) || 0xFFFFFFFF === $long ) {
			return 'reserved';
		}
		return 'public';
	}

	/**
	 * Classify an IPv6 address.
	 *
	 * @param string $ip IPv6.
	 * @return string
	 */
	protected static function ipv6_category( $ip ) {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return 'invalid';
		}

		// IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible: judge as IPv4.
		$mapped_prefix = str_repeat( "\x00", 10 ) . "\xff\xff";
		if ( 0 === strncmp( $packed, $mapped_prefix, 12 ) ) {
			return self::ipv4_category( long2ip( unpack( 'N', substr( $packed, 12, 4 ) )[1] ) );
		}

		if ( "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01" === $packed ) {
			return 'loopback'; // ::1
		}
		if ( str_repeat( "\x00", 16 ) === $packed ) {
			return 'unspecified'; // ::
		}

		$first = ord( $packed[0] );
		$second = ord( $packed[1] );

		if ( 0xFF === $first ) {
			return 'multicast'; // ff00::/8
		}
		if ( 0xFE === ( $first & 0xFF ) && 0x80 === ( $second & 0xC0 ) ) {
			return 'linklocal'; // fe80::/10
		}
		if ( 0xFC === ( $first & 0xFE ) ) {
			return 'private'; // fc00::/7 unique local
		}
		// Documentation prefix 2001:db8::/32.
		if ( 0x20 === $first && 0x01 === $second && "\x0d\xb8" === substr( $packed, 2, 2 ) ) {
			return 'reserved';
		}
		return 'public';
	}

	/**
	 * Resolve a host to its IP addresses (v4 + v6). Returns an empty array when
	 * the host is already an IP literal or cannot be resolved.
	 *
	 * @param string $host Host name.
	 * @return string[] IPs.
	 */
	protected static function resolve_host_ips( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		// Allow tests/environments to inject a resolver and avoid real DNS.
		$injected = apply_filters( 'scc_resolve_host_ips', null, $host );
		if ( is_array( $injected ) ) {
			return $injected;
		}

		$ips = array();
		if ( function_exists( 'gethostbynamel' ) ) {
			$v4 = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $v4 ) ) {
				$ips = array_merge( $ips, $v4 );
			}
		}
		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( (array) $aaaa as $rec ) {
				if ( ! empty( $rec['ipv6'] ) ) {
					$ips[] = $rec['ipv6'];
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * Resolve a (possibly relative) reference against a base URL per RFC 3986 §5.
	 *
	 * Handles absolute URLs, protocol-relative (//host/path), root-relative
	 * (/path), relative (path, ./path, ../path), query-only (?x) and
	 * fragment-only (#y) references.
	 *
	 * @param string $base      Absolute base URL of the page the reference was found on.
	 * @param string $reference The href/src value to resolve.
	 * @return string Absolute URL, or '' when it cannot be resolved.
	 */
	public static function resolve( $base, $reference ) {
		$reference = trim( (string) $reference );
		$base      = trim( (string) $base );

		if ( '' === $reference ) {
			return $base;
		}

		$r = wp_parse_url( $reference );
		if ( false === $r ) {
			return '';
		}

		// Absolute reference (has its own scheme): return normalized as-is.
		if ( ! empty( $r['scheme'] ) ) {
			return self::compose(
				$r['scheme'],
				self::authority( $r ),
				self::remove_dot_segments( isset( $r['path'] ) ? $r['path'] : '' ),
				isset( $r['query'] ) ? $r['query'] : null,
				isset( $r['fragment'] ) ? $r['fragment'] : null
			);
		}

		$b = wp_parse_url( $base );
		if ( false === $b || empty( $b['scheme'] ) ) {
			return '';
		}
		$b_scheme = $b['scheme'];
		$b_auth   = self::authority( $b );
		$b_path   = isset( $b['path'] ) ? $b['path'] : '';
		$b_query  = isset( $b['query'] ) ? $b['query'] : null;

		// Protocol-relative: //host/path — keep the base scheme.
		if ( isset( $r['host'] ) && '' !== ( $r['host'] ?? '' ) && 0 === strpos( $reference, '//' ) ) {
			return self::compose(
				$b_scheme,
				self::authority( $r ),
				self::remove_dot_segments( isset( $r['path'] ) ? $r['path'] : '' ),
				isset( $r['query'] ) ? $r['query'] : null,
				isset( $r['fragment'] ) ? $r['fragment'] : null
			);
		}

		$r_path     = isset( $r['path'] ) ? $r['path'] : '';
		$r_query    = isset( $r['query'] ) ? $r['query'] : null;
		$r_fragment = isset( $r['fragment'] ) ? $r['fragment'] : null;

		if ( '' === $r_path ) {
			// Same document; only query/fragment may change.
			$path  = $b_path;
			$query = ( null !== $r_query ) ? $r_query : $b_query;
		} else {
			if ( 0 === strpos( $r_path, '/' ) ) {
				$path = self::remove_dot_segments( $r_path );
			} else {
				$path = self::remove_dot_segments( self::merge_paths( $b_auth, $b_path, $r_path ) );
			}
			$query = $r_query;
		}

		return self::compose( $b_scheme, $b_auth, $path, $query, $r_fragment );
	}

	/**
	 * Produce a stable crawl identity for an absolute URL: lowercase scheme/host,
	 * drop the default port, strip the fragment, and remove tracking-only query
	 * parameters (utm_*, fbclid, gclid, …) while preserving meaningful ones.
	 *
	 * @param string $url Absolute URL.
	 * @return string Normalized URL, or '' when not absolute/HTTP.
	 */
	public static function normalize_for_crawl( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		$auth = $host . ( $port ? ':' . $port : '' );

		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? self::strip_tracking_params( $parts['query'] ) : '';

		$out = $scheme . '://' . $auth . $path;
		if ( '' !== $query ) {
			$out .= '?' . $query;
		}
		return $out;
	}

	/**
	 * Remove tracking-only parameters from a query string, preserving order and
	 * meaningful parameters.
	 *
	 * @param string $query Raw query string (without the leading '?').
	 * @return string
	 */
	public static function strip_tracking_params( $query ) {
		$query = (string) $query;
		if ( '' === $query ) {
			return '';
		}
		$kept = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$eq   = strpos( $pair, '=' );
			$name = ( false === $eq ) ? $pair : substr( $pair, 0, $eq );
			$key  = strtolower( rawurldecode( $name ) );
			if ( in_array( $key, self::TRACKING_PARAMS, true ) ) {
				continue;
			}
			$kept[] = $pair;
		}
		return implode( '&', $kept );
	}

	// --- RFC 3986 internals ------------------------------------------------

	/**
	 * Build the authority (host[:port]) from parsed parts.
	 *
	 * @param array $p Parsed URL parts.
	 * @return string
	 */
	protected static function authority( array $p ) {
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$auth = $p['host'];
		if ( isset( $p['port'] ) && '' !== (string) $p['port'] ) {
			$auth .= ':' . (int) $p['port'];
		}
		return $auth;
	}

	/**
	 * Merge a relative path onto the base path (RFC 3986 §5.3).
	 *
	 * @param string $base_authority Base authority.
	 * @param string $base_path      Base path.
	 * @param string $ref_path       Relative reference path.
	 * @return string
	 */
	protected static function merge_paths( $base_authority, $base_path, $ref_path ) {
		if ( '' !== $base_authority && '' === $base_path ) {
			return '/' . $ref_path;
		}
		$slash = strrpos( $base_path, '/' );
		if ( false === $slash ) {
			return $ref_path;
		}
		return substr( $base_path, 0, $slash + 1 ) . $ref_path;
	}

	/**
	 * Remove "." and ".." segments from a path (RFC 3986 §5.2.4).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected static function remove_dot_segments( $path ) {
		if ( '' === $path ) {
			return '';
		}
		$input  = $path;
		$output = '';

		while ( '' !== $input ) {
			if ( 0 === strpos( $input, '../' ) ) {
				$input = substr( $input, 3 );
			} elseif ( 0 === strpos( $input, './' ) ) {
				$input = substr( $input, 2 );
			} elseif ( '/.' === $input ) {
				$input = '/';
			} elseif ( 0 === strpos( $input, '/./' ) ) {
				$input = '/' . substr( $input, 3 );
			} elseif ( '/..' === $input ) {
				$input  = '/';
				$output = self::drop_last_segment( $output );
			} elseif ( 0 === strpos( $input, '/../' ) ) {
				$input  = '/' . substr( $input, 4 );
				$output = self::drop_last_segment( $output );
			} elseif ( '.' === $input || '..' === $input ) {
				$input = '';
			} else {
				// Move the first path segment (up to but not including the next
				// '/' after any leading '/') from input to output.
				$slash = ( 0 === strpos( $input, '/' ) ) ? strpos( $input, '/', 1 ) : strpos( $input, '/' );
				if ( false === $slash ) {
					$output .= $input;
					$input   = '';
				} else {
					$output .= substr( $input, 0, $slash );
					$input   = substr( $input, $slash );
				}
			}
		}
		return $output;
	}

	/**
	 * Drop the last path segment (and its trailing slash) from an output buffer.
	 *
	 * @param string $output Output buffer.
	 * @return string
	 */
	protected static function drop_last_segment( $output ) {
		$slash = strrpos( $output, '/' );
		return ( false === $slash ) ? '' : substr( $output, 0, $slash );
	}

	/**
	 * Reassemble a URL from components.
	 *
	 * @param string      $scheme    Scheme.
	 * @param string      $authority Authority.
	 * @param string      $path      Path.
	 * @param string|null $query     Query (without '?') or null.
	 * @param string|null $fragment  Fragment (without '#') or null.
	 * @return string
	 */
	protected static function compose( $scheme, $authority, $path, $query, $fragment ) {
		$out = $scheme . '://' . $authority;
		if ( '' !== $path && 0 !== strpos( $path, '/' ) ) {
			$path = '/' . $path;
		}
		$out .= $path;
		if ( null !== $query && '' !== $query ) {
			$out .= '?' . $query;
		}
		if ( null !== $fragment && '' !== $fragment ) {
			$out .= '#' . $fragment;
		}
		return $out;
	}

	/**
	 * PHP 7.4-safe str_ends_with.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	protected static function str_ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		return 0 === $len || ( strlen( $haystack ) >= $len && 0 === substr_compare( $haystack, $needle, -$len ) );
	}
}
