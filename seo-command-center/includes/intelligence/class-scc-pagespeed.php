<?php
/**
 * PageSpeed & Core Web Vitals.
 *
 * Measures real page speed with Google's free PageSpeed Insights API. Field data
 * (the Chrome UX Report — what real visitors experienced over the last 28 days)
 * is preferred; when a URL has too little traffic for field data, the Lighthouse
 * lab test is used and clearly labelled as such. When a URL cannot be measured
 * the result says so — no number is ever estimated or invented.
 *
 * An API key is optional (it only raises Google's shared rate limit).
 *
 * @see https://developers.google.com/speed/docs/insights/v5/get-started
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PageSpeed Insights client + Core Web Vitals diagnostics.
 */
class SCC_PageSpeed {

	const REPORT_OPTION = 'scc_pagespeed_report';
	const ENDPOINT      = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const DEFAULT_URLS  = 3;
	const MAX_URLS      = 5;

	/**
	 * Google's published Core Web Vitals thresholds: [good upper bound, poor lower bound].
	 */
	const THRESHOLDS = array(
		'lcp_ms' => array( 2500, 4000 ),
		'cls'    => array( 0.1, 0.25 ),
		'inp_ms' => array( 200, 500 ),
		'tbt_ms' => array( 200, 600 ), // Lab-only stand-in for responsiveness.
	);

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
	 * Measure the homepage plus the most important pages, store and return the report.
	 *
	 * @param int    $count    Number of URLs to test (1–5).
	 * @param string $strategy mobile|desktop.
	 * @return array
	 */
	public function run( $count = self::DEFAULT_URLS, $strategy = 'mobile' ) {
		$count    = max( 1, min( self::MAX_URLS, (int) $count ) );
		$strategy = 'desktop' === $strategy ? 'desktop' : 'mobile';

		$results = array();
		foreach ( self::pick_urls( $count ) as $target ) {
			$results[] = $this->measure( $target['url'], $strategy ) + array( 'post_id' => (int) $target['post_id'] );
		}

		$report = self::build_report( $results, $strategy );
		$report['generated_at'] = current_time( 'mysql' );
		update_option( self::REPORT_OPTION, $report, false );

		if ( class_exists( 'SCC_Logger' ) ) {
			SCC_Logger::info( 'pagespeed', 'PageSpeed check completed.', array( 'urls' => count( $results ), 'score' => $report['score'] ) );
		}
		return $report;
	}

	/**
	 * The URLs worth measuring: the homepage first, then recently updated key pages.
	 *
	 * @param int $count Max URLs.
	 * @return array[] {url, post_id}
	 */
	protected static function pick_urls( $count ) {
		$urls = array(
			array(
				'url'     => home_url( '/' ),
				'post_id' => (int) get_option( 'page_on_front', 0 ),
			),
		);
		if ( $count <= 1 ) {
			return $urls;
		}
		$query = new WP_Query(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => $count + 2,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => array( 'type' => 'ASC', 'modified' => 'DESC' ), // Pages (services) before posts.
				'post__not_in'   => array_filter( array( (int) get_option( 'page_on_front', 0 ) ) ),
			)
		);
		foreach ( (array) $query->posts as $post_id ) {
			$link = get_permalink( $post_id );
			if ( $link ) {
				$urls[] = array( 'url' => $link, 'post_id' => (int) $post_id );
			}
			if ( count( $urls ) >= $count ) {
				break;
			}
		}
		return $urls;
	}

	/**
	 * Run PageSpeed Insights for one URL.
	 *
	 * @param string $url      URL.
	 * @param string $strategy mobile|desktop.
	 * @return array Result (see parse_result()) plus url/ok/error.
	 */
	protected function measure( $url, $strategy ) {
		$args = array(
			'url'      => $url,
			'strategy' => $strategy,
			'category' => 'performance',
		);
		$creds = get_option( 'scc_credentials', array() );
		if ( is_array( $creds ) && ! empty( $creds['pagespeed_key'] ) ) {
			$args['key'] = (string) $creds['pagespeed_key'];
		}
		$request = add_query_arg( array_map( 'rawurlencode', $args ), self::ENDPOINT );

		$response = wp_remote_get(
			$request,
			array(
				'timeout'   => 70, // A Lighthouse run commonly takes 15–40s.
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::unmeasured( $url, sprintf( __( 'PageSpeed Insights could not be reached (%s).', 'seo-command-center' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 429 === $code ) {
			return self::unmeasured( $url, __( 'Google’s shared PageSpeed quota is exhausted. Add a free PageSpeed API key under Connections, or try again later.', 'seo-command-center' ) );
		}
		if ( 200 !== $code || ! is_array( $json ) ) {
			$message = is_array( $json ) && ! empty( $json['error']['message'] ) ? (string) $json['error']['message'] : sprintf( 'HTTP %d', $code );
			return self::unmeasured( $url, sprintf( __( 'PageSpeed Insights could not test this URL: %s', 'seo-command-center' ), $message ) );
		}

		return array( 'url' => $url, 'ok' => true, 'error' => '' ) + self::parse_result( $json );
	}

	/**
	 * A result that honestly records "not measured".
	 *
	 * @param string $url   URL.
	 * @param string $error Why.
	 * @return array
	 */
	protected static function unmeasured( $url, $error ) {
		return array(
			'url'         => (string) $url,
			'ok'          => false,
			'error'       => (string) $error,
			'performance' => null,
			'field'       => null,
			'lab'         => null,
		);
	}

	/**
	 * Extract the numbers that matter from a PageSpeed Insights v5 response.
	 *
	 * @param array $json Decoded API response.
	 * @return array {performance:int|null, field:array|null, lab:array|null}
	 */
	public static function parse_result( array $json ) {
		$out = array(
			'performance' => null,
			'field'       => null,
			'lab'         => null,
		);

		$lh = isset( $json['lighthouseResult'] ) && is_array( $json['lighthouseResult'] ) ? $json['lighthouseResult'] : array();
		if ( isset( $lh['categories']['performance']['score'] ) && is_numeric( $lh['categories']['performance']['score'] ) ) {
			$out['performance'] = (int) round( (float) $lh['categories']['performance']['score'] * 100 );
		}
		$audit = function ( $id ) use ( $lh ) {
			$value = $lh['audits'][ $id ]['numericValue'] ?? null;
			return is_numeric( $value ) ? (float) $value : null;
		};
		$lab = array(
			'lcp_ms' => $audit( 'largest-contentful-paint' ),
			'cls'    => $audit( 'cumulative-layout-shift' ),
			'tbt_ms' => $audit( 'total-blocking-time' ),
			'fcp_ms' => $audit( 'first-contentful-paint' ),
		);
		if ( array_filter( $lab, 'is_numeric' ) ) {
			$out['lab'] = array(
				'lcp_ms' => null === $lab['lcp_ms'] ? null : (int) round( $lab['lcp_ms'] ),
				'cls'    => null === $lab['cls'] ? null : round( $lab['cls'], 3 ),
				'tbt_ms' => null === $lab['tbt_ms'] ? null : (int) round( $lab['tbt_ms'] ),
				'fcp_ms' => null === $lab['fcp_ms'] ? null : (int) round( $lab['fcp_ms'] ),
			);
		}

		// Field data: the URL's own CrUX record (never the origin-wide fallback,
		// which would describe other pages).
		$metrics = isset( $json['loadingExperience']['metrics'] ) && is_array( $json['loadingExperience']['metrics'] ) ? $json['loadingExperience']['metrics'] : array();
		$scope   = (string) ( $json['loadingExperience']['id'] ?? '' );
		$is_origin_fallback = ! empty( $json['loadingExperience']['origin_fallback'] );
		if ( $metrics && ! $is_origin_fallback ) {
			$p = function ( $key ) use ( $metrics ) {
				$value = $metrics[ $key ]['percentile'] ?? null;
				return is_numeric( $value ) ? (float) $value : null;
			};
			$cls = $p( 'CUMULATIVE_LAYOUT_SHIFT_SCORE' );
			$field = array(
				'lcp_ms'   => null === $p( 'LARGEST_CONTENTFUL_PAINT_MS' ) ? null : (int) $p( 'LARGEST_CONTENTFUL_PAINT_MS' ),
				// CrUX reports CLS ×100 as an integer percentile.
				'cls'      => null === $cls ? null : round( $cls / 100, 3 ),
				'inp_ms'   => null === $p( 'INTERACTION_TO_NEXT_PAINT' ) ? null : (int) $p( 'INTERACTION_TO_NEXT_PAINT' ),
				'category' => (string) ( $json['loadingExperience']['overall_category'] ?? '' ),
				'scope'    => $scope,
			);
			if ( null !== $field['lcp_ms'] || null !== $field['cls'] || null !== $field['inp_ms'] ) {
				$out['field'] = $field;
			}
		}

		return $out;
	}

	/**
	 * Rate a metric against Google's thresholds.
	 *
	 * @param string    $metric Metric key.
	 * @param float|int $value  Value.
	 * @return string good|needs_improvement|poor
	 */
	public static function rate( $metric, $value ) {
		if ( ! isset( self::THRESHOLDS[ $metric ] ) || ! is_numeric( $value ) ) {
			return 'good';
		}
		list( $good, $poor ) = self::THRESHOLDS[ $metric ];
		if ( $value > $poor ) {
			return 'poor';
		}
		return $value > $good ? 'needs_improvement' : 'good';
	}

	/**
	 * Turn measured results into diagnostic issues + a summary report.
	 *
	 * @param array  $results  Per-URL results.
	 * @param string $strategy mobile|desktop.
	 * @return array {score:int|null, strategy, results, issues, measured, disclaimer}
	 */
	public static function build_report( array $results, $strategy = 'mobile' ) {
		$issues = array();
		$add = function ( $id, $severity, $title, $url, $post_id, $evidence, $why, $fix ) use ( &$issues ) {
			if ( ! isset( $issues[ $id ] ) ) {
				$issues[ $id ] = array(
					'id'             => $id,
					'category'       => 'core_web_vitals',
					'severity'       => $severity,
					'title'          => $title,
					'affected_count' => 0,
					'scope'          => 'page',
					'why_it_matters' => $why,
					'fix'            => $fix,
					'examples'       => array(),
				);
			}
			// Keep the worst severity seen across URLs.
			if ( 'high' === $severity ) {
				$issues[ $id ]['severity'] = 'high';
			}
			$issues[ $id ]['affected_count']++;
			$issues[ $id ]['examples'][] = array( 'url' => (string) $url, 'evidence' => (string) $evidence, 'post_id' => (int) $post_id );
		};

		$fix_text = array(
			'lcp' => 'Serve the main above-the-fold image or heading faster: compress and properly size the hero image (WebP/AVIF), preload it, avoid lazy-loading it, enable page caching and reduce render-blocking CSS/JS.',
			'cls' => 'Reserve space for images, embeds, ads and banners (width/height or aspect-ratio), and avoid inserting content above existing content after load. Use font-display: swap with fallback metrics.',
			'inp' => 'Reduce heavy JavaScript: remove unused plugins/scripts, defer non-critical JS, and break up long tasks from sliders, chat widgets and trackers.',
		);

		$scores   = array();
		$measured = 0;
		foreach ( $results as $r ) {
			if ( empty( $r['ok'] ) ) {
				continue;
			}
			$measured++;
			$url  = (string) ( $r['url'] ?? '' );
			$post = (int) ( $r['post_id'] ?? 0 );
			if ( null !== ( $r['performance'] ?? null ) ) {
				$scores[] = (int) $r['performance'];
			}

			// Prefer real-user field data; fall back to lab, and say which.
			$field = is_array( $r['field'] ?? null ) ? $r['field'] : null;
			$lab   = is_array( $r['lab'] ?? null ) ? $r['lab'] : null;
			$src   = $field ? 'real Chrome users, last 28 days' : 'lab test';
			$data  = $field ? $field : ( $lab ? $lab : array() );

			$lcp = $data['lcp_ms'] ?? null;
			if ( null !== $lcp && 'good' !== self::rate( 'lcp_ms', $lcp ) ) {
				$poor = 'poor' === self::rate( 'lcp_ms', $lcp );
				$add( 'slow_lcp', $poor ? 'high' : 'medium', 'Main content loads slowly (LCP)', $url, $post, 'LCP ' . round( $lcp / 1000, 1 ) . 's (' . $src . '; good is ≤ 2.5s)', 'Largest Contentful Paint is a Core Web Vital: how long visitors wait to see the main content. Slow LCP hurts rankings and conversions.', $fix_text['lcp'] );
			}
			$cls = $data['cls'] ?? null;
			if ( null !== $cls && 'good' !== self::rate( 'cls', $cls ) ) {
				$poor = 'poor' === self::rate( 'cls', $cls );
				$add( 'layout_shift', $poor ? 'high' : 'medium', 'Page layout jumps while loading (CLS)', $url, $post, 'CLS ' . $cls . ' (' . $src . '; good is ≤ 0.1)', 'Cumulative Layout Shift is a Core Web Vital: content that moves while loading causes mis-clicks and frustration.', $fix_text['cls'] );
			}
			if ( $field && null !== ( $field['inp_ms'] ?? null ) && 'good' !== self::rate( 'inp_ms', $field['inp_ms'] ) ) {
				$poor = 'poor' === self::rate( 'inp_ms', $field['inp_ms'] );
				$add( 'slow_interaction', $poor ? 'high' : 'medium', 'Page responds slowly to taps and clicks (INP)', $url, $post, 'INP ' . (int) $field['inp_ms'] . 'ms (' . $src . '; good is ≤ 200ms)', 'Interaction to Next Paint is a Core Web Vital: how quickly the page reacts when visitors tap or click.', $fix_text['inp'] );
			} elseif ( ! $field && $lab && null !== ( $lab['tbt_ms'] ?? null ) && 'good' !== self::rate( 'tbt_ms', $lab['tbt_ms'] ) ) {
				$poor = 'poor' === self::rate( 'tbt_ms', $lab['tbt_ms'] );
				$add( 'slow_interaction', $poor ? 'high' : 'medium', 'Heavy JavaScript blocks the page (lab)', $url, $post, 'Total Blocking Time ' . (int) $lab['tbt_ms'] . 'ms (lab test; good is ≤ 200ms)', 'Long JavaScript tasks make the page slow to respond to taps and clicks.', $fix_text['inp'] );
			}
			if ( null !== ( $r['performance'] ?? null ) && (int) $r['performance'] < 50 ) {
				$add( 'low_performance_score', 'medium', 'Low PageSpeed performance score', $url, $post, 'Performance ' . (int) $r['performance'] . '/100 (Lighthouse ' . ( 'desktop' === $strategy ? 'desktop' : 'mobile' ) . ' lab test)', 'A low Lighthouse score points to heavy pages that load slowly for visitors on phones.', 'Start with the largest wins: image compression and sizing, page caching, and removing unused scripts and plugins.' );
			}
		}

		$rank = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
		$issues = array_values( $issues );
		usort(
			$issues,
			function ( $a, $b ) use ( $rank ) {
				return ( $rank[ $b['severity'] ] ?? 0 ) <=> ( $rank[ $a['severity'] ] ?? 0 );
			}
		);

		return array(
			'score'      => $scores ? (int) round( array_sum( $scores ) / count( $scores ) ) : null,
			'strategy'   => $strategy,
			'measured'   => $measured,
			'results'    => array_values( $results ),
			'issues'     => $issues,
			'disclaimer' => __( 'Speed data comes from Google PageSpeed Insights. Field data reflects real Chrome visitors; lab data is a single simulated test and is labelled as such.', 'seo-command-center' ),
		);
	}
}
