<?php
/**
 * Entity Authority Graph.
 *
 * Derives the site's key entities and their relationships from data the plugin
 * already holds — the business/schema settings (organization, locations) and the
 * latest topical map (services / topics) — then checks which entities have a
 * supporting page and which are missing or weakly supported. It never invents
 * business facts; entities come only from configured data and the real map/pages.
 *
 * `analyze()` is pure and unit-tested; `build()` gathers the live inputs.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entity graph.
 */
class SCC_Entity_Graph {

	/**
	 * Build the entity graph from live data.
	 *
	 * @return array {available:bool, nodes:array, edges:array, gaps:array}
	 */
	public static function build() {
		$business = class_exists( 'SCC_Schema_Engine' ) ? SCC_Schema_Engine::business() : array();

		$services  = array();
		$locations = array();
		if ( class_exists( 'SCC_Keyword_Strategy' ) ) {
			$strategy = SCC_Keyword_Strategy::latest();
			$map      = ( $strategy && ! empty( $strategy['map_data'] ) ) ? $strategy['map_data'] : array();
			foreach ( (array) ( $map['clusters'] ?? array() ) as $cluster ) {
				$svc = trim( (string) ( $cluster['service'] ?? '' ) );
				if ( '' !== $svc ) {
					$services[] = $svc;
				}
				$loc = trim( (string) ( $cluster['location'] ?? '' ) );
				if ( '' !== $loc ) {
					$locations[] = $loc;
				}
			}
		}
		// Configured service areas are authoritative locations.
		foreach ( (array) ( $business['service_areas'] ?? array() ) as $area ) {
			$area = trim( (string) $area );
			if ( '' !== $area ) {
				$locations[] = $area;
			}
		}

		$pages = class_exists( 'SCC_Keyword_Strategy' ) ? SCC_Keyword_Strategy::existing_site_pages( 300 ) : array();

		return self::analyze(
			array(
				'organization' => (string) ( $business['organization_name'] ?? '' ),
				'services'     => array_values( array_unique( $services ) ),
				'locations'    => array_values( array_unique( $locations ) ),
				'pages'        => $pages,
			)
		);
	}

	/**
	 * Pure graph construction + gap analysis.
	 *
	 * @param array $input {organization, services[], locations[], pages[{title,path}]}.
	 * @return array
	 */
	public static function analyze( array $input ) {
		$org       = trim( (string) ( $input['organization'] ?? '' ) );
		$services  = array_values( array_filter( array_map( 'trim', (array) ( $input['services'] ?? array() ) ) ) );
		$locations = array_values( array_filter( array_map( 'trim', (array) ( $input['locations'] ?? array() ) ) ) );
		$pages     = (array) ( $input['pages'] ?? array() );

		if ( '' === $org && empty( $services ) && empty( $locations ) ) {
			return array( 'available' => false, 'nodes' => array(), 'edges' => array(), 'gaps' => array() );
		}

		// Haystack of page titles + paths for support checks.
		$haystack = array();
		foreach ( $pages as $p ) {
			$haystack[] = strtolower( (string) ( $p['title'] ?? '' ) . ' ' . (string) ( $p['path'] ?? '' ) );
		}
		$haystack = implode( ' | ', $haystack );

		$supported = function ( $label ) use ( $haystack ) {
			$l = strtolower( trim( (string) $label ) );
			if ( '' === $l ) {
				return false;
			}
			// Supported if the whole phrase, or all of its significant words, appear.
			if ( false !== strpos( $haystack, $l ) ) {
				return true;
			}
			$words = array_filter( explode( ' ', preg_replace( '/[^a-z0-9 ]+/', ' ', $l ) ), function ( $w ) {
				return strlen( $w ) >= 4;
			} );
			if ( empty( $words ) ) {
				return false;
			}
			foreach ( $words as $w ) {
				if ( false === strpos( $haystack, $w ) ) {
					return false;
				}
			}
			return true;
		};

		$nodes = array();
		$edges = array();
		$gaps  = array();

		if ( '' !== $org ) {
			$nodes[] = array( 'id' => 'org', 'type' => 'Organization', 'label' => $org, 'supported' => true );
		}

		foreach ( $services as $i => $svc ) {
			$id  = 'svc_' . $i;
			$sup = $supported( $svc );
			$nodes[] = array( 'id' => $id, 'type' => 'Service', 'label' => $svc, 'supported' => $sup );
			if ( '' !== $org ) {
				$edges[] = array( 'from' => 'org', 'to' => $id, 'rel' => 'provides' );
			}
			if ( ! $sup ) {
				$gaps[] = array( 'entity' => $svc, 'type' => 'Service', 'reason' => __( 'No page clearly supports this service entity.', 'seo-command-center' ), 'recommendation' => __( 'Create or strengthen a dedicated service page.', 'seo-command-center' ) );
			}
		}

		foreach ( $locations as $i => $loc ) {
			$id  = 'loc_' . $i;
			$sup = $supported( $loc );
			$nodes[] = array( 'id' => $id, 'type' => 'Location', 'label' => $loc, 'supported' => $sup );
			if ( '' !== $org ) {
				$edges[] = array( 'from' => 'org', 'to' => $id, 'rel' => 'serves' );
			}
			if ( ! $sup ) {
				$gaps[] = array( 'entity' => $loc, 'type' => 'Location', 'reason' => __( 'No page clearly supports this location entity.', 'seo-command-center' ), 'recommendation' => __( 'Add a location page or local signals if you genuinely serve here.', 'seo-command-center' ) );
			}
		}

		$total     = max( 1, count( $nodes ) );
		$supported_n = 0;
		foreach ( $nodes as $n ) {
			if ( ! empty( $n['supported'] ) ) {
				$supported_n++;
			}
		}
		$coverage = (int) round( 100 * $supported_n / $total );

		return array(
			'available' => true,
			'coverage'  => $coverage,
			'nodes'     => $nodes,
			'edges'     => $edges,
			'gaps'      => $gaps,
		);
	}
}
