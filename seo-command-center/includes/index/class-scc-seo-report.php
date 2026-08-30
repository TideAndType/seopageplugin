<?php
/**
 * Unified SEO Optimization Report for a single page.
 *
 * Aggregates the three subsystems (internal links, metadata, schema) into one
 * readiness view. The score is explicitly an INTERNAL optimization score, not a
 * ranking guarantee.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO report.
 */
class SCC_SEO_Report {

	/**
	 * Build the report for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function build( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'post_id' => $post_id, 'score' => 0, 'items' => array() );
		}

		// Ensure indexed so counts are meaningful.
		SCC_Content_Index::index_post( $post_id );

		// Internal links (outbound recommendations for this page).
		$outbound = SCC_Link_Engine::recommendations( array( 'direction' => 'outbound', 'limit' => 500 ) );
		$outbound = array_filter( $outbound, function ( $r ) use ( $post_id ) {
			return (int) $r['source_post_id'] === (int) $post_id && 'recommended' === $r['status'];
		} );
		$inbound = SCC_Link_Engine::recommendations( array( 'direction' => 'inbound', 'limit' => 500 ) );
		$inbound = array_filter( $inbound, function ( $r ) use ( $post_id ) {
			return (int) $r['target_post_id'] === (int) $post_id && 'recommended' === $r['status'];
		} );

		// Metadata.
		$meta       = SCC_Metadata::current( $post_id );
		$has_title  = '' !== trim( $meta['title'] );
		$has_desc   = '' !== trim( $meta['description'] );

		// Schema.
		$stored_schema = get_post_meta( $post_id, '_scc_schema', true );
		$schema_nodes  = $stored_schema ? json_decode( $stored_schema, true ) : array();
		$schema_valid  = false;
		if ( is_array( $schema_nodes ) && ! empty( $schema_nodes ) ) {
			$schema_valid = true;
			foreach ( $schema_nodes as $node ) {
				if ( ! is_array( $node ) || is_wp_error( SCC_Schema::validate( $node ) ) ) {
					$schema_valid = false;
					break;
				}
			}
		}
		$breadcrumbs = false;
		foreach ( (array) $schema_nodes as $node ) {
			if ( isset( $node['@type'] ) && 'BreadcrumbList' === $node['@type'] ) {
				$breadcrumbs = true;
			}
		}

		// Scoring (internal optimization guide).
		$points = 0;
		$max    = 0;

		$max += 25;
		$points += min( 25, count( $outbound ) * 5 ); // internal links available/added.

		$max += 20;
		$points += min( 20, count( $inbound ) * 5 ); // inbound opportunities.

		$max += 15;
		$points += $has_title ? 15 : 0;

		$max += 15;
		$points += $has_desc ? 15 : 0;

		$max += 20;
		$points += $schema_valid ? 20 : 0;

		$max += 5;
		$points += $breadcrumbs ? 5 : 0;

		$score = $max > 0 ? (int) round( 100 * $points / $max ) : 0;

		return array(
			'post_id' => (int) $post_id,
			'title'   => get_the_title( $post ),
			'score'   => $score,
			'items'   => array(
				array( 'label' => __( 'Internal links', 'seo-command-center' ), 'value' => count( $outbound ), 'note' => __( 'outbound opportunities', 'seo-command-center' ) ),
				array( 'label' => __( 'Inbound links', 'seo-command-center' ), 'value' => count( $inbound ), 'note' => __( 'pages that could link here', 'seo-command-center' ) ),
				array( 'label' => __( 'Meta title', 'seo-command-center' ), 'value' => $has_title ? __( 'set', 'seo-command-center' ) : __( 'missing', 'seo-command-center' ), 'ok' => $has_title ),
				array( 'label' => __( 'Meta description', 'seo-command-center' ), 'value' => $has_desc ? __( 'set', 'seo-command-center' ) : __( 'missing', 'seo-command-center' ), 'ok' => $has_desc ),
				array( 'label' => __( 'Schema', 'seo-command-center' ), 'value' => $schema_valid ? __( 'valid', 'seo-command-center' ) : __( 'none', 'seo-command-center' ), 'ok' => $schema_valid ),
				array( 'label' => __( 'Breadcrumbs', 'seo-command-center' ), 'value' => $breadcrumbs ? __( 'detected', 'seo-command-center' ) : __( 'no', 'seo-command-center' ), 'ok' => $breadcrumbs ),
			),
			'disclaimer' => __( 'Internal optimization score — not a guarantee of Google rankings.', 'seo-command-center' ),
		);
	}
}
