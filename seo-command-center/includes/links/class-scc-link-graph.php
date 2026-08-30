<?php
/**
 * Internal link content graph.
 *
 * Builds a directed graph of internal links between published content by
 * parsing each post's rendered links and resolving them to post IDs. Produces
 * inbound/outbound counts and the orphan / under-linked / over-linked reports
 * the dashboard needs. Read-only.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link graph builder.
 */
class SCC_Link_Graph {

	/** Below this many inbound internal links, a page is "under-linked". */
	const UNDER_LINKED = 2;

	/** Above this many inbound internal links, a page is "over-linked". */
	const OVER_LINKED = 25;

	/**
	 * Build the graph.
	 *
	 * @param int $limit Max posts to include.
	 * @return array {nodes, orphans, under_linked, over_linked, totals}
	 */
	public function build( $limit = 500 ) {
		$limit = SCC_Security::sanitize_int( $limit, 1, 5000 );

		$post_types = SCC_Analyzer::analyzable_post_types();
		$query      = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'ignore_sticky_posts' => true,
			)
		);

		$nodes = array();
		foreach ( $query->posts as $post ) {
			$nodes[ $post->ID ] = array(
				'post_id'       => (int) $post->ID,
				'title'         => get_the_title( $post ),
				'url'           => get_permalink( $post ),
				'post_type'     => $post->post_type,
				'outbound'      => array(),
				'inbound_count' => 0,
			);
		}

		// Resolve outbound links and tally inbound.
		foreach ( $query->posts as $post ) {
			$targets = $this->outbound_targets( $post, array_keys( $nodes ) );
			$nodes[ $post->ID ]['outbound'] = $targets;
			foreach ( $targets as $target_id ) {
				if ( isset( $nodes[ $target_id ] ) ) {
					$nodes[ $target_id ]['inbound_count']++;
				}
			}
		}

		$orphans      = array();
		$under_linked = array();
		$over_linked  = array();
		foreach ( $nodes as $node ) {
			if ( 0 === $node['inbound_count'] ) {
				$orphans[] = $node;
			} elseif ( $node['inbound_count'] < self::UNDER_LINKED ) {
				$under_linked[] = $node;
			}
			if ( $node['inbound_count'] > self::OVER_LINKED ) {
				$over_linked[] = $node;
			}
		}

		return array(
			'nodes'        => $nodes,
			'orphans'      => $orphans,
			'under_linked' => $under_linked,
			'over_linked'  => $over_linked,
			'totals'       => array(
				'pages'        => count( $nodes ),
				'orphans'      => count( $orphans ),
				'under_linked' => count( $under_linked ),
				'over_linked'  => count( $over_linked ),
			),
		);
	}

	/**
	 * Resolve the internal post IDs a post links to.
	 *
	 * @param WP_Post $post     Post.
	 * @param int[]   $known_ids Known node ids (to filter to graph).
	 * @return int[] Unique target post ids.
	 */
	protected function outbound_targets( $post, array $known_ids ) {
		$content = $post->post_content;
		if ( '' === trim( (string) $content ) ) {
			return array();
		}

		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $content . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xpath = new DOMXPath( $dom );

		$targets = array();
		foreach ( $xpath->query( '//a[@href]' ) as $a ) {
			$href = $a->getAttribute( 'href' );
			$target_id = $this->resolve_internal( $href );
			if ( $target_id && $target_id !== (int) $post->ID ) {
				$targets[ $target_id ] = true;
			}
		}
		return array_values( array_intersect( array_keys( $targets ), $known_ids ) );
	}

	/**
	 * Resolve an href to an internal post id, or 0.
	 *
	 * @param string $href Href.
	 * @return int
	 */
	protected function resolve_internal( $href ) {
		if ( '' === $href || 0 === strpos( $href, '#' ) ) {
			return 0;
		}
		// Make protocol-relative / relative URLs absolute against home.
		if ( 0 === strpos( $href, '/' ) ) {
			$href = home_url( $href );
		}
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$link_host = wp_parse_url( $href, PHP_URL_HOST );
		if ( $link_host && $link_host !== $home_host ) {
			return 0; // External.
		}
		return (int) url_to_postid( $href );
	}
}
