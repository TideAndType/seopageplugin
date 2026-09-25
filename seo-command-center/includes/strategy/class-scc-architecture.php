<?php
/**
 * Site architecture engine.
 *
 * Turns a topical map into an intent-aware Pillar -> Service/Location ->
 * Supporting Content tree. Existing-site coverage is resolved by URL AND topic
 * identity so a different slug never creates a duplicate-page recommendation.
 *
 * Commercial subtopics normally belong as sections on their parent service page;
 * informational subtopics can become supporting articles. This keeps the site
 * architecture useful instead of turning every keyword variation into a URL.
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
	 * @return array Tree: list of pillar nodes with children/sections/articles.
	 */
	public function build( array $map ) {
		$existing = $this->existing_pages();
		$clusters = isset( $map['clusters'] ) ? (array) $map['clusters'] : array();

		// Group by service.
		$services = array();
		foreach ( $clusters as $c ) {
			$service = ! empty( $c['service'] ) ? $c['service'] : __( 'General', 'seo-command-center' );
			if ( ! isset( $services[ $service ] ) ) {
				$services[ $service ] = array(
					'children' => array(),
					'sections' => array(),
					'articles' => array(),
					'seed'     => null,
				);
			}

			$node = array(
				'title'           => $this->node_title( $c ),
				'primary_keyword' => (string) ( $c['primary_keyword'] ?? '' ),
				'intent'          => (string) ( $c['intent'] ?? 'commercial' ),
				'url'             => (string) ( $c['recommended_url'] ?? '' ),
				'page_type'       => (string) ( $c['page_type'] ?? 'service' ),
				'related'         => (array) ( $c['related'] ?? array() ),
				'rationale'       => (string) ( $c['rationale'] ?? '' ),
				'status'          => (string) ( $c['status'] ?? 'new' ),
				'page_candidate'  => true,
			);
			$node = $this->resolve_existing( $node, $existing );

			if ( 'article' === $node['page_type'] ) {
				$services[ $service ]['articles'][] = $node;
			} elseif ( 'location' === $node['page_type'] || ! empty( $c['location'] ) ) {
				$services[ $service ]['children'][] = $node;
			} else {
				// First service/pillar is the hub. Additional same-service
				// commercial variants should usually be coverage on that hub,
				// not another page.
				if ( null === $services[ $service ]['seed'] ) {
					$services[ $service ]['seed'] = $node;
				} elseif ( ! $node['exists'] && $this->is_service_section_intent( $node['intent'] ) ) {
					$services[ $service ]['sections'][] = $this->as_service_section( $node, $services[ $service ]['seed'] );
				} else {
					$services[ $service ]['children'][] = $node;
				}
			}

			// Subtopics are intent-aware. Informational questions/articles can
			// earn URLs; same-service commercial variants default to sections.
			foreach ( (array) ( $c['subtopics'] ?? array() ) as $sub ) {
				if ( empty( $sub['title'] ) ) {
					continue;
				}
				$subnode = array(
					'title'           => (string) $sub['title'],
					'primary_keyword' => (string) ( $sub['primary_keyword'] ?? $sub['title'] ),
					'intent'          => (string) ( $sub['intent'] ?? 'informational' ),
					'url'             => (string) ( $sub['recommended_url'] ?? '' ),
					'page_type'       => 'article',
					'related'         => (array) ( $sub['content_nodes'] ?? array() ),
					'rationale'       => '',
					'status'          => (string) ( $sub['status'] ?? 'new' ),
					'page_candidate'  => true,
				);
				$subnode = $this->resolve_existing( $subnode, $existing );

				if ( $subnode['exists'] ) {
					// Existing commercial pages are respected as existing pages;
					// we simply stop recommending a duplicate.
					if ( $this->is_service_section_intent( $subnode['intent'] ) ) {
						$subnode['page_type'] = 'service';
						$services[ $service ]['children'][] = $subnode;
					} elseif ( 'local' === $subnode['intent'] ) {
						$subnode['page_type'] = 'location';
						$services[ $service ]['children'][] = $subnode;
					} else {
						$services[ $service ]['articles'][] = $subnode;
					}
					continue;
				}

				if ( $this->is_service_section_intent( $subnode['intent'] ) ) {
					$parent = null !== $services[ $service ]['seed'] ? $services[ $service ]['seed'] : $node;
					$services[ $service ]['sections'][] = $this->as_service_section( $subnode, $parent );
				} elseif ( 'local' === $subnode['intent'] ) {
					$subnode['page_type'] = 'location';
					$services[ $service ]['children'][] = $subnode;
				} else {
					$subnode['page_type'] = 'article';
					$services[ $service ]['articles'][] = $subnode;
				}
			}
		}

		// Assemble and de-duplicate the tree. Semantic reconciliation can cause a
		// model's fake "new" URL and a real existing URL to resolve to the same
		// page; only show that page once.
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
					'status'          => 'new',
					'action'          => 'create_page',
					'page_candidate'  => true,
				);
				$seed = $this->resolve_existing( $seed, $existing );
			}

			$seen = array();
			$seed_key = $this->node_identity( $seed );
			if ( '' !== $seed_key ) {
				$seen[ $seed_key ] = true;
			}

			$seed['service']  = $service_name;
			$seed['children'] = $this->dedupe_nodes( $group['children'], $seen );
			$seed['sections'] = $this->dedupe_nodes( $group['sections'], $seen );
			$seed['articles'] = $this->dedupe_nodes( $group['articles'], $seen );
			$tree[] = $seed;
		}

		return array(
			'pillars'  => $tree,
			'existing' => count( $existing ),
			'notes'    => isset( $map['notes'] ) ? $map['notes'] : '',
		);
	}

	/**
	 * Resolve a node against real site pages by exact URL, then semantic topic.
	 *
	 * @param array $node     Architecture node.
	 * @param array $existing Existing pages.
	 * @return array
	 */
	protected function resolve_existing( array $node, array $existing ) {
		$original_url = (string) ( $node['url'] ?? '' );
		$path         = $this->normalize_path( $original_url );

		$match = null;
		foreach ( $existing as $page ) {
			if ( $path && $path === $this->normalize_path( $page['path'] ?? '' ) ) {
				$match = array(
					'path'       => (string) $page['path'],
					'title'      => (string) $page['title'],
					'match_type' => 'path',
					'score'      => 1.0,
				);
				break;
			}
		}
		if ( ! $match && class_exists( 'SCC_Keyword_Strategy' ) ) {
			$match = SCC_Keyword_Strategy::match_existing_topic(
				array(
					'title'           => $node['title'] ?? '',
					'primary_keyword' => $node['primary_keyword'] ?? '',
					'recommended_url' => $original_url,
				),
				$existing
			);
		}

		if ( $match ) {
			$node['exists']                 = true;
			$node['url']                    = $match['path'];
			$node['matched_existing_title'] = $match['title'];
			$node['coverage_match']         = $match['match_type'];
			$node['status']                 = 'path' === $match['match_type'] ? 'existing' : 'covered';
			$node['action']                 = 'path' === $match['match_type'] ? 'existing' : 'use_existing';
			$node['original_url']            = $original_url;
			$node['page_candidate']          = false;
			if ( 'path' !== $match['match_type'] ) {
				$node['rationale'] = sprintf(
					/* translators: %s: existing page title */
					__( 'This topic is already covered by the existing page “%s”; do not create another URL for the same intent.', 'seo-command-center' ),
					$match['title']
				);
			}
			return $node;
		}

		$node['exists'] = false;
		$node['status'] = 'new';
		$node['action'] = 'create_page';
		return $node;
	}

	/**
	 * Commercial variants usually belong on the service page itself.
	 *
	 * @param string $intent Search intent.
	 * @return bool
	 */
	protected function is_service_section_intent( $intent ) {
		return in_array( strtolower( (string) $intent ), array( 'commercial', 'transactional', 'navigational' ), true );
	}

	/**
	 * Convert a commercial subtopic to an on-page section recommendation.
	 *
	 * @param array $node   Topic node.
	 * @param array $parent Parent service node.
	 * @return array
	 */
	protected function as_service_section( array $node, array $parent ) {
		$target = (string) ( $parent['url'] ?? '' );
		$node['page_type']      = 'section';
		$node['status']         = 'section';
		$node['action']         = 'expand_existing';
		$node['page_candidate'] = false;
		$node['parent_url']     = $target;
		$node['url']            = $target;
		$node['exists']         = ! empty( $parent['exists'] );
		$node['rationale']      = __( 'This is the same commercial service intent. Cover it as a section on the parent service page instead of creating another page.', 'seo-command-center' );
		return $node;
	}

	/**
	 * Remove duplicate nodes after existing-topic resolution.
	 *
	 * @param array $nodes Nodes.
	 * @param array $seen  Seen identities, updated by reference.
	 * @return array
	 */
	protected function dedupe_nodes( array $nodes, array &$seen ) {
		$out = array();
		foreach ( $nodes as $node ) {
			$key = $this->node_identity( $node );
			if ( '' !== $key && isset( $seen[ $key ] ) ) {
				continue;
			}
			if ( '' !== $key ) {
				$seen[ $key ] = true;
			}
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * Stable identity for de-duplication.
	 *
	 * @param array $node Node.
	 * @return string
	 */
	protected function node_identity( array $node ) {
		$url = $this->normalize_path( $node['url'] ?? '' );
		if ( '' !== $url ) {
			return 'url:' . $url;
		}
		$title = class_exists( 'SCC_Keyword_Strategy' )
			? SCC_Keyword_Strategy::normalize_topic_phrase( $node['title'] ?? '' )
			: strtolower( trim( (string) ( $node['title'] ?? '' ) ) );
		return '' !== $title ? 'topic:' . $title : '';
	}

	/**
	 * A human title for a cluster node.
	 *
	 * @param array $c Cluster.
	 * @return string
	 */
	protected function node_title( array $c ) {
		if ( ! empty( $c['location'] ) ) {
			return trim( (string) ( $c['service'] ?? '' ) . ' — ' . (string) $c['location'] );
		}
		return ! empty( $c['service'] ) ? (string) $c['service'] : (string) ( $c['primary_keyword'] ?? '' );
	}

	/**
	 * Real existing site pages with title + normalized path. Prefer the latest
	 * analysis, then supplement it from the live WordPress/sitemap inventory so
	 * Architecture does not depend on a fresh analysis run.
	 *
	 * @return array<int,array{title:string,path:string}>
	 */
	protected function existing_pages() {
		$pages = array();
		$seen  = array();

		$latest = SCC_Analyzer::latest();
		if ( $latest && ! empty( $latest['items'] ) ) {
			foreach ( $latest['items'] as $item ) {
				$path = wp_parse_url( (string) ( $item['url'] ?? '' ), PHP_URL_PATH );
				$path = '/' . trim( (string) $path, '/' ) . '/';
				if ( '//' === $path ) {
					$path = '/';
				}
				if ( '' === trim( $path ) || isset( $seen[ $path ] ) ) {
					continue;
				}
				$seen[ $path ] = true;
				$pages[] = array(
					'title' => (string) ( $item['title'] ?? '' ),
					'path'  => $path,
				);
			}
		}

		if ( class_exists( 'SCC_Keyword_Strategy' ) ) {
			foreach ( (array) SCC_Keyword_Strategy::existing_site_pages( 300 ) as $page ) {
				$path = (string) ( $page['path'] ?? '' );
				if ( '' === $path || isset( $seen[ $path ] ) ) {
					continue;
				}
				$seen[ $path ] = true;
				$pages[] = array(
					'title' => (string) ( $page['title'] ?? '' ),
					'path'  => $path,
				);
			}
		}

		return $pages;
	}

	/**
	 * Normalize a path for comparison (trim slashes, lowercase).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected function normalize_path( $path ) {
		$path = wp_parse_url( (string) $path, PHP_URL_PATH );
		return strtolower( trim( (string) $path, '/' ) );
	}
}
