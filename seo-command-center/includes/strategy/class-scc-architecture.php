<?php
/**
 * Site architecture engine.
 *
 * Turns a topical map into a Pillar -> Service -> Location -> Supporting tree,
 * marks which recommended URLs already exist on the site (from the latest
 * analysis), and proposes parents + internal links. Deterministic (no extra AI
 * call) so it is fast, cheap, and testable.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Architecture builder.
 */
class SCC_Architecture {

	/**
	 * Build an architecture tree from a topical map.
	 *
	 * @param array $map Normalized topical map (clusters, entities, notes).
	 * @return array Tree: list of pillar nodes with children.
	 */
	public function build( array $map ) {
		$existing = $this->existing_paths();
		$clusters = isset( $map['clusters'] ) ? (array) $map['clusters'] : array();

		// Group by service.
		$services = array();
		foreach ( $clusters as $c ) {
			$service = $c['service'] ? $c['service'] : __( 'General', 'seo-command-center' );
			if ( ! isset( $services[ $service ] ) ) {
				$services[ $service ] = array(
					'children' => array(),
					'articles' => array(),
					'seed'     => null,
				);
			}
			$node = array(
				'title'           => $this->node_title( $c ),
				'primary_keyword' => $c['primary_keyword'],
				'intent'          => $c['intent'],
				'url'             => $c['recommended_url'],
				'page_type'       => $c['page_type'],
				'related'         => $c['related'],
				'rationale'       => $c['rationale'],
				'exists'          => $this->path_exists( $c['recommended_url'], $existing ),
			);
			if ( 'article' === $c['page_type'] ) {
				$services[ $service ]['articles'][] = $node;
			} elseif ( 'location' === $c['page_type'] || ! empty( $c['location'] ) ) {
				$services[ $service ]['children'][] = $node;
			} else {
				// service or pillar: use as the service seed if not set.
				if ( null === $services[ $service ]['seed'] ) {
					$services[ $service ]['seed'] = $node;
				} else {
					$services[ $service ]['children'][] = $node;
				}
			}
		}

		// Assemble tree.
		$tree = array();
		foreach ( $services as $service_name => $group ) {
			$seed = $group['seed'];
			if ( null === $seed ) {
				$seed = array(
					'title'           => $service_name,
					'primary_keyword' => $service_name,
					'intent'          => 'commercial',
					'url'             => '/' . sanitize_title( $service_name ) . '/',
					'page_type'       => 'service',
					'related'         => array(),
					'rationale'       => '',
					'exists'          => false,
				);
			}
			$seed['service']  = $service_name;
			$seed['children'] = $group['children'];
			$seed['articles'] = $group['articles'];
			$tree[] = $seed;
		}

		return array(
			'pillars'  => $tree,
			'existing' => count( $existing ),
			'notes'    => isset( $map['notes'] ) ? $map['notes'] : '',
		);
	}

	/**
	 * A human title for a cluster node.
	 *
	 * @param array $c Cluster.
	 * @return string
	 */
	protected function node_title( array $c ) {
		if ( ! empty( $c['location'] ) ) {
			return trim( $c['service'] . ' — ' . $c['location'] );
		}
		return $c['service'] ? $c['service'] : $c['primary_keyword'];
	}

	/**
	 * Map of existing site paths from the latest analysis.
	 *
	 * @return array<string,bool> Normalized path => true.
	 */
	protected function existing_paths() {
		$latest = SCC_Analyzer::latest();
		$paths  = array();
		if ( ! $latest || empty( $latest['items'] ) ) {
			return $paths;
		}
		foreach ( $latest['items'] as $item ) {
			$path = wp_parse_url( $item['url'], PHP_URL_PATH );
			if ( $path ) {
				$paths[ $this->normalize_path( $path ) ] = true;
			}
		}
		return $paths;
	}

	/**
	 * Whether a recommended path already exists.
	 *
	 * @param string $path     Path.
	 * @param array  $existing Existing paths.
	 * @return bool
	 */
	protected function path_exists( $path, array $existing ) {
		return isset( $existing[ $this->normalize_path( $path ) ] );
	}

	/**
	 * Normalize a path for comparison (trim slashes, lowercase).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected function normalize_path( $path ) {
		return strtolower( trim( (string) $path, '/' ) );
	}
}
