<?php
/**
 * Local citation discovery and NAP consistency scanner.
 *
 * Uses DataForSEO SERP data when configured; otherwise falls back to the
 * public DuckDuckGo HTML endpoint. The free fallback is deliberately
 * conservative: only targeted checks can become "not_found"; directories not
 * explicitly verified remain "unverified" instead of being called missing.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_Citation_Scanner {

	const CACHE_TTL = DAY_IN_SECONDS;
	const PUBLIC_RATE_LIMIT = 3;
	const PUBLIC_RATE_WINDOW = HOUR_IN_SECONDS;

	public static function directories() {
		$rows = array(
			array( 'name' => 'Google Business Profile', 'domain' => 'google.com', 'weight' => 10, 'targeted' => true, 'category' => 'maps' ),
			array( 'name' => 'Yelp', 'domain' => 'yelp.com', 'weight' => 9, 'targeted' => true, 'category' => 'directory' ),
			array( 'name' => 'Facebook', 'domain' => 'facebook.com', 'weight' => 8, 'targeted' => true, 'category' => 'social' ),
			array( 'name' => 'Bing Places', 'domain' => 'bing.com', 'weight' => 8, 'targeted' => true, 'category' => 'maps' ),
			array( 'name' => 'Apple Maps', 'domain' => 'maps.apple.com', 'weight' => 8, 'targeted' => true, 'category' => 'maps' ),
			array( 'name' => 'Better Business Bureau', 'domain' => 'bbb.org', 'weight' => 8, 'targeted' => true, 'category' => 'trust' ),
			array( 'name' => 'Yellow Pages', 'domain' => 'yellowpages.com', 'weight' => 7, 'targeted' => true, 'category' => 'directory' ),
			array( 'name' => 'MapQuest', 'domain' => 'mapquest.com', 'weight' => 6, 'targeted' => true, 'category' => 'maps' ),
			array( 'name' => 'Foursquare', 'domain' => 'foursquare.com', 'weight' => 6, 'targeted' => true, 'category' => 'directory' ),
			array( 'name' => 'Chamber of Commerce', 'domain' => 'chamberofcommerce.com', 'weight' => 6, 'targeted' => true, 'category' => 'directory' ),
			array( 'name' => 'Nextdoor', 'domain' => 'nextdoor.com', 'weight' => 6, 'targeted' => true, 'category' => 'local' ),
			array( 'name' => 'Manta', 'domain' => 'manta.com', 'weight' => 5, 'targeted' => true, 'category' => 'directory' ),
			array( 'name' => 'LinkedIn', 'domain' => 'linkedin.com', 'weight' => 5, 'targeted' => false, 'category' => 'social' ),
			array( 'name' => 'Instagram', 'domain' => 'instagram.com', 'weight' => 4, 'targeted' => false, 'category' => 'social' ),
			array( 'name' => 'Alignable', 'domain' => 'alignable.com', 'weight' => 4, 'targeted' => false, 'category' => 'local' ),
			array( 'name' => 'MerchantCircle', 'domain' => 'merchantcircle.com', 'weight' => 4, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'Hotfrog', 'domain' => 'hotfrog.com', 'weight' => 4, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'EZlocal', 'domain' => 'ezlocal.com', 'weight' => 4, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'Local.com', 'domain' => 'local.com', 'weight' => 4, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'Superpages', 'domain' => 'superpages.com', 'weight' => 4, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'DexKnows', 'domain' => 'dexknows.com', 'weight' => 4, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'Citysearch', 'domain' => 'citysearch.com', 'weight' => 3, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'Brownbook', 'domain' => 'brownbook.net', 'weight' => 3, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'Cylex', 'domain' => 'cylex.us.com', 'weight' => 3, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'ShowMeLocal', 'domain' => 'showmelocal.com', 'weight' => 3, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'YellowBot', 'domain' => 'yellowbot.com', 'weight' => 3, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'iBegin', 'domain' => 'ibegin.com', 'weight' => 3, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'n49', 'domain' => 'n49.com', 'weight' => 3, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'FindOpen', 'domain' => 'find-open.com', 'weight' => 3, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'My Local Services', 'domain' => 'mylocalservices.com', 'weight' => 3, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'USCity.net', 'domain' => 'uscity.net', 'weight' => 2, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'CitySquares', 'domain' => 'citysquares.com', 'weight' => 3, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'LocalStack', 'domain' => 'localstack.com', 'weight' => 2, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'Fyple', 'domain' => 'fyple.com', 'weight' => 2, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'Opendi', 'domain' => 'opendi.us', 'weight' => 2, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'Tuugo', 'domain' => 'tuugo.us', 'weight' => 2, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => '2FindLocal', 'domain' => '2findlocal.com', 'weight' => 2, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'WhereOrg', 'domain' => 'whereorg.com', 'weight' => 2, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'Bizapedia', 'domain' => 'bizapedia.com', 'weight' => 3, 'targeted' => false, 'category' => 'business-data' ),
			array( 'name' => 'Dun & Bradstreet', 'domain' => 'dnb.com', 'weight' => 5, 'targeted' => false, 'category' => 'business-data' ),
			array( 'name' => 'Crunchbase', 'domain' => 'crunchbase.com', 'weight' => 4, 'targeted' => false, 'category' => 'business-data' ),
			array( 'name' => 'Angi', 'domain' => 'angi.com', 'weight' => 5, 'targeted' => false, 'category' => 'vertical' ),
			array( 'name' => 'Thumbtack', 'domain' => 'thumbtack.com', 'weight' => 4, 'targeted' => false, 'category' => 'vertical' ),
			array( 'name' => 'Houzz', 'domain' => 'houzz.com', 'weight' => 4, 'targeted' => false, 'category' => 'vertical' ),
			array( 'name' => 'HomeAdvisor', 'domain' => 'homeadvisor.com', 'weight' => 4, 'targeted' => false, 'category' => 'vertical' ),
			array( 'name' => 'Porch', 'domain' => 'porch.com', 'weight' => 3, 'targeted' => false, 'category' => 'vertical' ),
			array( 'name' => 'Expertise.com', 'domain' => 'expertise.com', 'weight' => 3, 'targeted' => false, 'category' => 'vertical' ),
			array( 'name' => 'Clutch', 'domain' => 'clutch.co', 'weight' => 5, 'targeted' => false, 'category' => 'agency' ),
			array( 'name' => 'UpCity', 'domain' => 'upcity.com', 'weight' => 4, 'targeted' => false, 'category' => 'agency' ),
			array( 'name' => 'DesignRush', 'domain' => 'designrush.com', 'weight' => 4, 'targeted' => false, 'category' => 'agency' ),
			array( 'name' => 'Agency Spotter', 'domain' => 'agencyspotter.com', 'weight' => 3, 'targeted' => false, 'category' => 'agency' ),
			array( 'name' => 'The Manifest', 'domain' => 'themanifest.com', 'weight' => 3, 'targeted' => false, 'category' => 'agency' ),
			array( 'name' => 'GoodFirms', 'domain' => 'goodfirms.co', 'weight' => 3, 'targeted' => false, 'category' => 'agency' ),
			array( 'name' => 'Trustpilot', 'domain' => 'trustpilot.com', 'weight' => 4, 'targeted' => false, 'category' => 'reviews' ),
			array( 'name' => 'Sitejabber', 'domain' => 'sitejabber.com', 'weight' => 3, 'targeted' => false, 'category' => 'reviews' ),
			array( 'name' => 'Glassdoor', 'domain' => 'glassdoor.com', 'weight' => 2, 'targeted' => false, 'category' => 'business-data' ),
			array( 'name' => 'Indeed', 'domain' => 'indeed.com', 'weight' => 2, 'targeted' => false, 'category' => 'business-data' ),
			array( 'name' => 'Patch', 'domain' => 'patch.com', 'weight' => 2, 'targeted' => false, 'category' => 'local' ),
			array( 'name' => 'Loc8NearMe', 'domain' => 'loc8nearme.com', 'weight' => 2, 'targeted' => false, 'category' => 'directory' ),
			array( 'name' => 'BusinessYab', 'domain' => 'businessyab.com', 'weight' => 2, 'targeted' => false, 'category' => 'directory' ),
		);
		return apply_filters( 'scc_citation_directories', $rows );
	}

	public static function normalize_input( array $input ) {
		return array(
			'business_name' => sanitize_text_field( $input['business_name'] ?? '' ),
			'address'       => sanitize_text_field( $input['address'] ?? '' ),
			'city'          => sanitize_text_field( $input['city'] ?? '' ),
			'state'         => strtoupper( sanitize_text_field( $input['state'] ?? '' ) ),
			'phone'         => sanitize_text_field( $input['phone'] ?? '' ),
			'website'       => esc_url_raw( $input['website'] ?? '' ),
		);
	}

	public static function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( strlen( $digits ) > 10 ) {
			$digits = substr( $digits, -10 );
		}
		return $digits;
	}

	public static function normalize_name( $name ) {
		$name = strtolower( remove_accents( (string) $name ) );
		return preg_replace( '/[^a-z0-9]+/', '', $name );
	}

	public static function normalize_address( $address ) {
		$address = strtolower( remove_accents( (string) $address ) );
		$map = array(
			' street' => ' st', ' avenue' => ' ave', ' boulevard' => ' blvd',
			' road' => ' rd', ' drive' => ' dr', ' lane' => ' ln', ' court' => ' ct',
			' highway' => ' hwy', ' north' => ' n', ' south' => ' s', ' east' => ' e', ' west' => ' w',
			' suite' => ' ste',
		);
		$address = strtr( $address, $map );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $address ) );
	}

	public static function normalize_domain( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) { return ''; }
		if ( ! preg_match( '#^https?://#i', $url ) ) { $url = 'https://' . $url; }
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return strtolower( preg_replace( '/^www\./', '', $host ) );
	}

	public static function scan( array $input, $force = false ) {
		$input = self::normalize_input( $input );
		if ( '' === $input['business_name'] || '' === $input['city'] ) {
			return new WP_Error( 'scc_citation_input', __( 'Business name and city are required.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		$key = 'scc_cite_' . md5( wp_json_encode( $input ) );
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$directories = self::directories();
		$results = array();
		$provider = self::provider_label();
		$broad_query = '"' . $input['business_name'] . '" "' . $input['city'] . '"';
		$broad = self::search( $broad_query, 50 );
		if ( is_wp_error( $broad ) ) { $broad = array(); }

		foreach ( $directories as $dir ) {
			$found = self::find_domain_result( $broad, $dir['domain'] );
			$checked = false;
			$search_error = false;

			if ( empty( $found ) && ! empty( $dir['targeted'] ) ) {
				$checked = true;
				$q = 'site:' . $dir['domain'] . ' "' . $input['business_name'] . '" "' . $input['city'] . '"';
				$targeted = self::search( $q, 8 );
				if ( is_wp_error( $targeted ) ) {
					$search_error = true;
				} else {
					$found = self::find_domain_result( $targeted, $dir['domain'] );
				}
			} elseif ( ! empty( $found ) ) {
				$checked = true;
			}

			$status = 'unverified';
			$checks = array( 'name' => null, 'city' => null, 'phone' => null, 'address' => null );
			$confidence = 0;
			$url = '';
			$title = '';
			$snippet = '';

			if ( ! empty( $found ) ) {
				$url = esc_url_raw( $found['url'] ?? '' );
				$title = sanitize_text_field( $found['title'] ?? '' );
				$snippet = sanitize_text_field( $found['snippet'] ?? '' );
				$assessment = self::assess_result( $input, $title . ' ' . $snippet );
				$checks = $assessment['checks'];
				$confidence = $assessment['confidence'];
				$status = $assessment['inconsistent'] ? 'inconsistent' : 'found';
			} elseif ( $checked && ! $search_error ) {
				$status = 'not_found';
			}

			$results[] = array(
				'name'       => $dir['name'],
				'domain'     => $dir['domain'],
				'category'   => $dir['category'],
				'weight'     => (int) $dir['weight'],
				'targeted'   => (bool) $dir['targeted'],
				'status'     => $status,
				'confidence' => $confidence,
				'checks'     => $checks,
				'url'        => $url,
				'title'      => $title,
				'snippet'    => $snippet,
			);
		}

		$summary = self::summarize( $results );
		$out = array(
			'input'       => $input,
			'score'       => $summary['score'],
			'summary'     => $summary,
			'results'     => $results,
			'provider'    => $provider,
			'generated_at'=> current_time( 'mysql', true ),
			'cached'      => false,
			'methodology' => __( 'Targeted sources are explicitly searched. Secondary sources are only marked found when discovered; otherwise they remain unverified rather than being called missing.', 'seo-command-center' ),
		);

		set_transient( $key, $out, self::CACHE_TTL );
		do_action( 'scc_citation_scan_completed', $out, $input );
		return $out;
	}

	public static function public_rate_limit_ok() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$key = 'scc_cite_rl_' . md5( $ip . '|' . $ua );
		$count = (int) get_transient( $key );
		if ( $count >= self::PUBLIC_RATE_LIMIT ) { return false; }
		set_transient( $key, $count + 1, self::PUBLIC_RATE_WINDOW );
		return true;
	}

	protected static function provider_label() {
		return class_exists( 'SCC_DataForSEO' ) && SCC_DataForSEO::is_connected() ? 'DataForSEO' : 'DuckDuckGo (free fallback)';
	}

	protected static function search( $query, $limit ) {
		if ( class_exists( 'SCC_DataForSEO' ) && SCC_DataForSEO::is_connected() && method_exists( 'SCC_DataForSEO', 'serp_search' ) ) {
			return SCC_DataForSEO::serp_search( $query, 'United States', 'en', $limit );
		}
		return self::duckduckgo_search( $query, $limit );
	}

	protected static function duckduckgo_search( $query, $limit = 10 ) {
		$url = 'https://html.duckduckgo.com/html/?q=' . rawurlencode( $query );
		$response = wp_remote_get( $url, array(
			'timeout' => 8,
			'redirection' => 3,
			'user-agent' => 'Mozilla/5.0 (compatible; TideOrbitCitationScanner/1.0; +' . home_url( '/' ) . ')',
		) );
		if ( is_wp_error( $response ) ) { return $response; }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'scc_citation_search_http', sprintf( 'Search HTTP %d', $code ) );
		}
		$html = (string) wp_remote_retrieve_body( $response );
		if ( '' === $html ) { return array(); }
		$out = array();
		if ( class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument();
			libxml_use_internal_errors( true );
			$dom->loadHTML( $html );
			libxml_clear_errors();
			$xp = new DOMXPath( $dom );
			$nodes = $xp->query( "//div[contains(@class,'result')]" );
			if ( $nodes ) {
				foreach ( $nodes as $node ) {
					$a = $xp->query( ".//a[contains(@class,'result__a')]", $node )->item(0);
					if ( ! $a ) { continue; }
					$href = self::decode_ddg_url( $a->getAttribute( 'href' ) );
					if ( ! $href ) { continue; }
					$s = $xp->query( ".//*[contains(@class,'result__snippet')]", $node )->item(0);
					$out[] = array(
						'url' => esc_url_raw( $href ),
						'title' => sanitize_text_field( $a->textContent ),
						'snippet' => $s ? sanitize_text_field( $s->textContent ) : '',
					);
					if ( count( $out ) >= $limit ) { break; }
				}
			}
		}
		return $out;
	}

	protected static function decode_ddg_url( $href ) {
		$href = html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' );
		if ( 0 === strpos( $href, '//' ) ) { $href = 'https:' . $href; }
		if ( false !== strpos( $href, 'duckduckgo.com/l/?' ) ) {
			$query = wp_parse_url( $href, PHP_URL_QUERY );
			parse_str( (string) $query, $args );
			if ( ! empty( $args['uddg'] ) ) { return rawurldecode( $args['uddg'] ); }
		}
		return preg_match( '#^https?://#i', $href ) ? $href : '';
	}

	protected static function find_domain_result( array $rows, $domain ) {
		$domain = strtolower( preg_replace( '/^www\./', '', (string) $domain ) );
		foreach ( $rows as $row ) {
			$host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $row['url'] ?? '', PHP_URL_HOST ) ) );
			if ( $host && ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) ) {
				return $row;
			}
		}
		return array();
	}

	protected static function assess_result( array $input, $text ) {
		$text_plain = strtolower( remove_accents( wp_strip_all_tags( (string) $text ) ) );
		$name_norm = self::normalize_name( $input['business_name'] );
		$text_norm = self::normalize_name( $text_plain );
		$name_match = $name_norm && false !== strpos( $text_norm, $name_norm );
		$city_match = '' !== $input['city'] ? false !== strpos( $text_plain, strtolower( remove_accents( $input['city'] ) ) ) : null;

		$phone_match = null;
		$phone = self::normalize_phone( $input['phone'] );
		$phones_found = array();
		if ( preg_match_all( '/(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/', $text, $m ) ) {
			foreach ( $m[0] as $p ) { $phones_found[] = self::normalize_phone( $p ); }
		}
		if ( $phone && $phones_found ) { $phone_match = in_array( $phone, $phones_found, true ); }

		$address_match = null;
		$address = self::normalize_address( $input['address'] );
		if ( $address ) {
			$address_text = self::normalize_address( $text_plain );
			$tokens = array_values( array_filter( explode( ' ', $address ) ) );
			$hits = 0;
			foreach ( array_slice( $tokens, 0, 6 ) as $token ) {
				if ( strlen( $token ) > 1 && false !== strpos( ' ' . $address_text . ' ', ' ' . $token . ' ' ) ) { $hits++; }
			}
			if ( count( $tokens ) >= 3 ) { $address_match = $hits >= min( 3, count( $tokens ) ); }
		}

		$inconsistent = ( false === $phone_match );
		$confidence = 30;
		if ( $name_match ) { $confidence += 35; }
		if ( true === $city_match ) { $confidence += 20; }
		if ( true === $phone_match ) { $confidence += 15; }
		if ( false === $phone_match ) { $confidence -= 15; }
		$confidence = max( 0, min( 100, $confidence ) );

		return array(
			'inconsistent' => $inconsistent,
			'confidence' => $confidence,
			'checks' => array( 'name' => $name_match, 'city' => $city_match, 'phone' => $phone_match, 'address' => $address_match ),
		);
	}

	protected static function summarize( array $rows ) {
		$counts = array( 'found' => 0, 'inconsistent' => 0, 'not_found' => 0, 'unverified' => 0 );
		$verified_weight = 0;
		$earned_weight = 0;
		foreach ( $rows as $row ) {
			$status = isset( $counts[ $row['status'] ] ) ? $row['status'] : 'unverified';
			$counts[ $status ]++;
			if ( 'unverified' === $status ) { continue; }
			$w = max( 1, (int) $row['weight'] );
			$verified_weight += $w;
			if ( 'found' === $status ) { $earned_weight += $w; }
			elseif ( 'inconsistent' === $status ) { $earned_weight += (int) round( $w * 0.45 ); }
		}
		$score = $verified_weight > 0 ? (int) round( 100 * $earned_weight / $verified_weight ) : 0;
		return array_merge( $counts, array(
			'checked' => $counts['found'] + $counts['inconsistent'] + $counts['not_found'],
			'total' => count( $rows ),
			'score' => $score,
		) );
	}

	public static function shortcode() {
		$endpoint = esc_url_raw( rest_url( 'seo-command-center/v1/citation-scan/public' ) );
		$id = 'scc-citation-public-' . wp_rand( 1000, 99999 );
		ob_start();
		?>
		<div class="scc-citation-public" id="<?php echo esc_attr( $id ); ?>">
			<form class="scc-citation-public__form">
				<p><label><?php esc_html_e( 'Business name', 'seo-command-center' ); ?><br><input name="business_name" required></label></p>
				<p><label><?php esc_html_e( 'City', 'seo-command-center' ); ?><br><input name="city" required></label></p>
				<p><label><?php esc_html_e( 'State', 'seo-command-center' ); ?><br><input name="state" maxlength="2"></label></p>
				<p><label><?php esc_html_e( 'Address', 'seo-command-center' ); ?><br><input name="address"></label></p>
				<p><label><?php esc_html_e( 'Phone', 'seo-command-center' ); ?><br><input name="phone" type="tel"></label></p>
				<p><label><?php esc_html_e( 'Website', 'seo-command-center' ); ?><br><input name="website" type="url"></label></p>
				<p style="position:absolute;left:-9999px" aria-hidden="true"><label>Company URL<input name="company_url" tabindex="-1" autocomplete="off"></label></p>
				<button type="submit"><?php esc_html_e( 'Run citation scan', 'seo-command-center' ); ?></button>
			</form>
			<div class="scc-citation-public__status" aria-live="polite"></div>
			<div class="scc-citation-public__results"></div>
		</div>
		<script>
		(function(){
			var root=document.getElementById(<?php echo wp_json_encode( $id ); ?>); if(!root)return;
			var form=root.querySelector('form'), status=root.querySelector('.scc-citation-public__status'), out=root.querySelector('.scc-citation-public__results');
			form.addEventListener('submit',function(e){e.preventDefault(); status.textContent='Scanning citations…'; out.innerHTML='';
				var data={}; new FormData(form).forEach(function(v,k){data[k]=v;});
				fetch(<?php echo wp_json_encode( $endpoint ); ?>,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})
				.then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error((j&&j.message)||'Scan failed');return j;});})
				.then(function(j){var d=j.data||j; status.textContent='Citation score: '+d.score+'/100 — '+d.summary.found+' found, '+d.summary.inconsistent+' inconsistent, '+d.summary.not_found+' not found, '+d.summary.unverified+' unverified.';
					var h='<div class="scc-citation-public__grid">'; (d.results||[]).forEach(function(x){if(x.status==='unverified')return; h+='<div><strong>'+esc(x.name)+'</strong> — '+esc(x.status.replace('_',' '))+(x.url?' · <a target="_blank" rel="noopener" href="'+escAttr(x.url)+'">View</a>':'')+'</div>';}); h+='</div><p><small>'+esc(d.methodology||'')+'</small></p>'; out.innerHTML=h;})
				.catch(function(err){status.textContent=err.message||'Scan failed.';});
			});
			function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
			function escAttr(s){return esc(s);}
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}
