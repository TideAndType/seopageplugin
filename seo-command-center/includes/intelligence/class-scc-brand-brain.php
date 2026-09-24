<?php
/**
 * Brand Brain.
 *
 * Central, factual brand profile used by planning and evidence checks. The
 * profile is seeded from existing Schema settings and can be extended through
 * the scc_brand_profile option or the scc_brand_profile filter. Empty values
 * stay empty: this class never invents claims, testimonials or credentials.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Brand_Brain {

	const OPTION = 'scc_brand_profile';

	public static function profile() {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$business = class_exists( 'SCC_Schema_Engine' ) ? SCC_Schema_Engine::business() : array();
		$business = is_array( $business ) ? $business : array();

		$profile = array(
			'business_name'         => (string) ( $stored['business_name'] ?? ( $business['organization_name'] ?? get_bloginfo( 'name' ) ) ),
			'voice'                 => (string) ( $stored['voice'] ?? '' ),
			'primary_cta'           => (string) ( $stored['primary_cta'] ?? '' ),
			'secondary_cta'         => (string) ( $stored['secondary_cta'] ?? '' ),
			'services'              => self::list_value( $stored['services'] ?? array() ),
			'locations'             => self::list_value( $stored['locations'] ?? ( $business['service_areas'] ?? array() ) ),
			'unique_selling_points' => self::list_value( $stored['unique_selling_points'] ?? array() ),
			'proof_points'          => self::list_value( $stored['proof_points'] ?? array() ),
			'testimonials'          => self::testimonials( $stored['testimonials'] ?? array() ),
			'credentials'           => self::list_value( $stored['credentials'] ?? array() ),
			'forbidden_claims'      => self::list_value( $stored['forbidden_claims'] ?? array() ),
		);

		return apply_filters( 'scc_brand_profile', $profile );
	}

	public static function update( array $raw ) {
		$clean = array(
			'business_name'         => SCC_Security::sanitize_text( $raw['business_name'] ?? '' ),
			'voice'                 => SCC_Security::sanitize_textarea( $raw['voice'] ?? '' ),
			'primary_cta'           => SCC_Security::sanitize_text( $raw['primary_cta'] ?? '' ),
			'secondary_cta'         => SCC_Security::sanitize_text( $raw['secondary_cta'] ?? '' ),
			'services'              => self::list_value( $raw['services'] ?? array() ),
			'locations'             => self::list_value( $raw['locations'] ?? array() ),
			'unique_selling_points' => self::list_value( $raw['unique_selling_points'] ?? array() ),
			'proof_points'          => self::list_value( $raw['proof_points'] ?? array() ),
			'testimonials'          => self::testimonials( $raw['testimonials'] ?? array() ),
			'credentials'           => self::list_value( $raw['credentials'] ?? array() ),
			'forbidden_claims'      => self::list_value( $raw['forbidden_claims'] ?? array() ),
		);
		update_option( self::OPTION, $clean, false );
		return self::profile();
	}

	public static function evidence_catalog() {
		$p = self::profile();
		return array(
			'proof_points' => $p['proof_points'],
			'testimonials' => $p['testimonials'],
			'credentials'  => $p['credentials'],
			'usps'         => $p['unique_selling_points'],
		);
	}

	protected static function list_value( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		$out = array();
		foreach ( (array) $value as $item ) {
			$item = SCC_Security::sanitize_text( $item );
			if ( '' !== $item ) { $out[] = $item; }
		}
		return array_values( array_unique( $out ) );
	}

	protected static function testimonials( $value ) {
		$out = array();
		if ( is_string( $value ) ) {
			$lines = preg_split( '/[\r\n]+/', $value );
			$value = array();
			foreach ( (array) $lines as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line ) { continue; }
				$parts = array_map( 'trim', explode( '|', $line, 2 ) );
				$value[] = array(
					'quote'       => (string) ( $parts[0] ?? '' ),
					'attribution' => (string) ( $parts[1] ?? '' ),
				);
			}
		}
		foreach ( (array) $value as $item ) {
			if ( is_string( $item ) ) {
				$item = array( 'quote' => $item, 'attribution' => '' );
			}
			if ( ! is_array( $item ) ) { continue; }
			$quote = SCC_Security::sanitize_textarea( $item['quote'] ?? '' );
			if ( '' === $quote ) { continue; }
			$out[] = array(
				'quote'       => $quote,
				'attribution' => SCC_Security::sanitize_text( $item['attribution'] ?? '' ),
			);
		}
		return $out;
	}
}
