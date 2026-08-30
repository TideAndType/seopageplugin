<?php
/**
 * Automatic schema engine: detection, recommendation, generation with dynamic
 * WordPress + business data, conflict detection, and validation warnings.
 *
 * Principles: only generate schema that accurately represents the page; never
 * invent business information; warn (don't fabricate) when optional data is
 * missing; and avoid duplicating structured data another plugin/theme emits.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema engine.
 */
class SCC_Schema_Engine {

	/**
	 * Business/organization settings (never invented; user-provided).
	 *
	 * @return array
	 */
	public static function business() {
		$defaults = array(
			'organization_name' => get_bloginfo( 'name' ),
			'logo'              => '',
			'phone'             => '',
			'street'            => '',
			'city'              => '',
			'region'            => '',
			'postal_code'       => '',
			'country'           => '',
			'social_profiles'   => array(),
			'service_areas'     => array(),
			'default_author'    => '',
			'schema_enabled'    => true,
		);
		$stored = get_option( 'scc_schema_settings', array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	/**
	 * Persist business/schema settings (sanitized).
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function save_business( array $input ) {
		$list = function ( $v ) {
			if ( ! is_array( $v ) ) {
				$v = preg_split( '/[\r\n,]+/', (string) $v );
			}
			return array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', (array) $v ) ) ) );
		};
		$data = array(
			'organization_name' => SCC_Security::sanitize_text( $input['organization_name'] ?? '' ),
			'logo'              => esc_url_raw( $input['logo'] ?? '' ),
			'phone'             => SCC_Security::sanitize_text( $input['phone'] ?? '' ),
			'street'            => SCC_Security::sanitize_text( $input['street'] ?? '' ),
			'city'              => SCC_Security::sanitize_text( $input['city'] ?? '' ),
			'region'            => SCC_Security::sanitize_text( $input['region'] ?? '' ),
			'postal_code'       => SCC_Security::sanitize_text( $input['postal_code'] ?? '' ),
			'country'           => SCC_Security::sanitize_text( $input['country'] ?? '' ),
			'social_profiles'   => array_values( array_filter( array_map( 'esc_url_raw', $list( $input['social_profiles'] ?? array() ) ) ) ),
			'service_areas'     => $list( $input['service_areas'] ?? array() ),
			'default_author'    => SCC_Security::sanitize_text( $input['default_author'] ?? '' ),
			'schema_enabled'    => SCC_Security::sanitize_bool( $input['schema_enabled'] ?? true ),
		);
		update_option( 'scc_schema_settings', $data );
		return $data;
	}

	/**
	 * Recommend schema types for a post (accurate to its content).
	 *
	 * @param int $post_id Post id.
	 * @return array {page_type, recommended:[], not_recommended:[]}
	 */
	public static function recommend( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'page_type' => '', 'recommended' => array(), 'not_recommended' => array() );
		}

		// Derive a page type from content plan (if generated) or heuristics.
		$page_type = self::page_type_of( $post );
		$content   = SCC_Content_Index::get_plain_text( $post );
		$has_faq   = (bool) preg_match( '/frequently asked|<h[23][^>]*>\s*(what|how|why|when|can|do|is|are)\b/i', $post->post_content );

		$recommended = array( 'WebPage', 'BreadcrumbList' );
		switch ( $page_type ) {
			case 'article':
				$recommended[] = 'BlogPosting';
				break;
			case 'service':
			case 'pillar':
				$recommended[] = 'Service';
				break;
			case 'location':
				$recommended[] = 'LocalBusiness';
				break;
		}
		if ( $has_faq ) {
			$recommended[] = 'FAQPage';
		}
		$recommended = array_values( array_unique( $recommended ) );

		$not_recommended = array_values( array_diff( SCC_Schema::ALLOWED, $recommended ) );

		return array(
			'page_type'       => $page_type,
			'recommended'     => $recommended,
			'not_recommended' => $not_recommended,
			'has_faq'         => $has_faq,
			'word_count'      => str_word_count( $content ),
		);
	}

	/**
	 * Generate schema nodes for a post (validated) with warnings.
	 *
	 * @param int   $post_id Post id.
	 * @param array $types   Explicit types to generate (default: recommended).
	 * @return array {nodes:[], warnings:[], invalid:[]}
	 */
	public static function generate( $post_id, array $types = array() ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'nodes' => array(), 'warnings' => array(), 'invalid' => array() );
		}

		$rec      = self::recommend( $post_id );
		$types    = $types ? array_values( array_intersect( $types, SCC_Schema::ALLOWED ) ) : $rec['recommended'];
		$business = self::business();
		$warnings = array();
		$nodes    = array();
		$invalid  = array();

		$url         = get_permalink( $post );
		$title       = get_the_title( $post );
		$description = SCC_SEO_Meta::get_description( $post_id );
		if ( '' === $description ) {
			$description = wp_trim_words( SCC_Content_Index::get_plain_text( $post ), 30 );
		}
		$image = get_the_post_thumbnail_url( $post_id, 'full' );

		foreach ( $types as $type ) {
			$node = null;
			switch ( $type ) {
				case 'BlogPosting':
				case 'Article':
				case 'NewsArticle':
					$node = SCC_Schema::build( $type, array(
						'name'             => $title,
						'description'      => $description,
						'url'              => $url,
						'author'           => $business['default_author'] ? $business['default_author'] : $business['organization_name'],
						'author_is_person' => (bool) $business['default_author'],
						'image'            => $image ? $image : '',
						'date'             => get_the_date( 'c', $post ),
						'modified'         => get_the_modified_date( 'c', $post ),
					) );
					break;

				case 'Service':
					$node = SCC_Schema::build( 'Service', array(
						'name'        => $title,
						'description' => $description,
						'provider'    => $business['organization_name'],
						'area'        => implode( ', ', (array) $business['service_areas'] ),
					) );
					if ( empty( $business['service_areas'] ) ) {
						$warnings[] = __( 'Service areas not configured — areaServed omitted.', 'seo-command-center' );
					}
					break;

				case 'LocalBusiness':
					$node = self::build_local_business( $title, $description, $url, $business, $warnings );
					break;

				case 'Organization':
					$node = SCC_Schema::build( 'Organization', array(
						'name' => $business['organization_name'],
						'url'  => home_url( '/' ),
					) );
					if ( $node && ! is_wp_error( $node ) ) {
						if ( $business['logo'] ) {
							$node['logo'] = $business['logo'];
						} else {
							$warnings[] = __( 'Logo not configured — Organization logo omitted.', 'seo-command-center' );
						}
						if ( ! empty( $business['social_profiles'] ) ) {
							$node['sameAs'] = $business['social_profiles'];
						}
					}
					break;

				case 'FAQPage':
					$faqs = self::extract_faqs( $post );
					if ( empty( $faqs ) ) {
						$warnings[] = __( 'No clear FAQ Q&A pairs found — FAQPage skipped.', 'seo-command-center' );
						continue 2;
					}
					$node = SCC_Schema::build( 'FAQPage', array( 'faqs' => $faqs ) );
					break;

				case 'BreadcrumbList':
					$node = SCC_Schema::build( 'BreadcrumbList', array( 'crumbs' => self::breadcrumbs( $post ) ) );
					break;

				case 'WebPage':
					$node = SCC_Schema::build( 'WebPage', array( 'name' => $title, 'description' => $description, 'url' => $url ) );
					break;

				case 'Person':
					$node = SCC_Schema::build( 'Person', array(
						'name'   => $business['default_author'] ? $business['default_author'] : get_the_author_meta( 'display_name', $post->post_author ),
						'sameAs' => $business['social_profiles'],
					) );
					break;
			}

			if ( is_wp_error( $node ) ) {
				$invalid[] = $type . ': ' . $node->get_error_message();
			} elseif ( is_array( $node ) ) {
				$nodes[] = $node;
			}
		}

		return array( 'nodes' => $nodes, 'warnings' => $warnings, 'invalid' => $invalid );
	}

	/**
	 * Build LocalBusiness with address, phone (only if provided).
	 *
	 * @param string $name        Name.
	 * @param string $description  Description.
	 * @param string $url          URL.
	 * @param array  $business     Business settings.
	 * @param array  $warnings     Warnings (by ref).
	 * @return array|WP_Error
	 */
	protected static function build_local_business( $name, $description, $url, array $business, array &$warnings ) {
		$node = SCC_Schema::build( 'LocalBusiness', array(
			'name'        => $business['organization_name'] ? $business['organization_name'] : $name,
			'description' => $description,
			'url'         => $url,
			'area'        => implode( ', ', (array) $business['service_areas'] ),
		) );
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		if ( $business['phone'] ) {
			$node['telephone'] = $business['phone'];
		} else {
			$warnings[] = __( 'Telephone not configured.', 'seo-command-center' );
		}
		$address = array_filter( array(
			'streetAddress'   => $business['street'],
			'addressLocality' => $business['city'],
			'addressRegion'   => $business['region'],
			'postalCode'      => $business['postal_code'],
			'addressCountry'  => $business['country'],
		) );
		if ( ! empty( $address ) ) {
			$node['address'] = array_merge( array( '@type' => 'PostalAddress' ), $address );
		} else {
			$warnings[] = __( 'Address not configured.', 'seo-command-center' );
		}
		if ( ! empty( $business['social_profiles'] ) ) {
			$node['sameAs'] = $business['social_profiles'];
		}
		return $node;
	}

	/**
	 * Detect schema conflicts: what the active SEO plugin and the rendered page
	 * already output, so we don't duplicate entities.
	 *
	 * @param int $post_id Post id.
	 * @return array {plugin, plugin_types:[], page_types:[], conflicts:[]}
	 */
	public static function detect_conflicts( $post_id, array $intended_types = array() ) {
		$plugin       = SCC_SEO_Meta::detect();
		$plugin_types = array();
		if ( in_array( $plugin, array( SCC_SEO_Meta::PLUGIN_YOAST, SCC_SEO_Meta::PLUGIN_RANKMATH ), true ) ) {
			$plugin_types = array( 'WebPage', 'BreadcrumbList', 'Organization', 'Article' );
		} elseif ( SCC_SEO_Meta::PLUGIN_AIOSEO === $plugin ) {
			$plugin_types = array( 'WebPage', 'BreadcrumbList', 'Organization' );
		}

		// Rendered-page JSON-LD (best-effort; internal fetch allowed).
		$page_types = array();
		$permalink  = get_permalink( $post_id );
		if ( $permalink && class_exists( 'SCC_Crawler' ) ) {
			$crawler = new SCC_Crawler();
			$parsed  = $crawler->fetch( $permalink, false );
			if ( ! is_wp_error( $parsed ) && ! empty( $parsed['schema_types'] ) ) {
				$page_types = $parsed['schema_types'];
			}
		}

		$existing  = array_values( array_unique( array_merge( $plugin_types, $page_types ) ) );
		$conflicts = $intended_types ? array_values( array_intersect( $intended_types, $existing ) ) : array();

		return array(
			'plugin'       => SCC_SEO_Meta::label( $plugin ),
			'plugin_types' => $plugin_types,
			'page_types'   => $page_types,
			'conflicts'    => $conflicts,
		);
	}

	/**
	 * Save schema nodes to a post (with change history).
	 *
	 * @param int   $post_id Post id.
	 * @param array $nodes   Validated nodes.
	 * @return true|WP_Error
	 */
	public static function save( $post_id, array $nodes ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot edit this post.', 'seo-command-center' ), array( 'status' => 403 ) );
		}
		// Validate every node before saving.
		foreach ( $nodes as $node ) {
			$valid = SCC_Schema::validate( $node );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		$previous = get_post_meta( $post_id, '_scc_schema', true );
		update_post_meta( $post_id, '_scc_schema', wp_json_encode( array_values( $nodes ) ) );

		SCC_Change_History::record( array(
			'post_id'        => $post_id,
			'change_type'    => 'schema',
			'previous_value' => $previous ? json_decode( $previous, true ) : '',
			'new_value'      => $nodes,
			'reason'         => __( 'Schema generated/updated', 'seo-command-center' ),
			'trigger_source' => 'manual',
		) );
		return true;
	}

	/**
	 * Disable schema for a post.
	 *
	 * @param int $post_id Post id.
	 * @return true|WP_Error
	 */
	public static function disable( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot edit this post.', 'seo-command-center' ), array( 'status' => 403 ) );
		}
		$previous = get_post_meta( $post_id, '_scc_schema', true );
		if ( $previous ) {
			SCC_Change_History::record( array(
				'post_id'        => $post_id,
				'change_type'    => 'schema',
				'previous_value' => json_decode( $previous, true ),
				'new_value'      => '',
				'reason'         => __( 'Schema disabled', 'seo-command-center' ),
				'trigger_source' => 'manual',
			) );
		}
		delete_post_meta( $post_id, '_scc_schema' );
		return true;
	}

	/**
	 * Derive a page type for schema decisions.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected static function page_type_of( $post ) {
		global $wpdb;
		$table = SCC_DB::table( 'content_plan' );
		$type  = $wpdb->get_var( $wpdb->prepare( "SELECT page_type FROM {$table} WHERE post_id = %d LIMIT 1", (int) $post->ID ) ); // phpcs:ignore WordPress.DB
		if ( $type ) {
			return $type;
		}
		return ( 'post' === $post->post_type ) ? 'article' : 'service';
	}

	/**
	 * Extract FAQ Q&A pairs from post content (h3 question + following paragraph).
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	protected static function extract_faqs( $post ) {
		$faqs = array();
		if ( preg_match_all( '/<h[34][^>]*>(.*?)<\/h[34]>\s*<p[^>]*>(.*?)<\/p>/is', $post->post_content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$q = trim( wp_strip_all_tags( $match[1] ) );
				$a = trim( wp_strip_all_tags( $match[2] ) );
				if ( '' !== $q && '' !== $a && false !== strpos( $q, '?' ) ) {
					$faqs[] = array( 'question' => $q, 'answer' => $a );
				}
			}
		}
		return $faqs;
	}

	/**
	 * Build breadcrumb crumbs from ancestors.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	protected static function breadcrumbs( $post ) {
		$crumbs = array( array( 'name' => __( 'Home', 'seo-command-center' ), 'url' => home_url( '/' ) ) );
		foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
			$crumbs[] = array( 'name' => get_the_title( $ancestor_id ), 'url' => get_permalink( $ancestor_id ) );
		}
		$crumbs[] = array( 'name' => get_the_title( $post ), 'url' => get_permalink( $post ) );
		return $crumbs;
	}
}
