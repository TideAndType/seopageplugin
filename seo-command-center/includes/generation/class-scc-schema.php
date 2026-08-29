<?php
/**
 * Schema (JSON-LD) generation + validation.
 *
 * Builds appropriate structured data for a page type and validates the minimum
 * required fields before output. Skips schema types another SEO plugin already
 * emits site-wide where we can detect it.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema builder.
 */
class SCC_Schema {

	/** Allowed schema @types this plugin will emit. */
	const ALLOWED = array( 'Article', 'BlogPosting', 'FAQPage', 'LocalBusiness', 'Organization', 'Service', 'BreadcrumbList', 'WebPage' );

	/**
	 * Choose a schema type for a page type.
	 *
	 * @param string $page_type article|service|location|landing|pillar.
	 * @return string
	 */
	public static function type_for( $page_type ) {
		switch ( $page_type ) {
			case 'article':
				return 'BlogPosting';
			case 'location':
				return 'LocalBusiness';
			case 'service':
			case 'pillar':
				return 'Service';
			default:
				return 'WebPage';
		}
	}

	/**
	 * Build a JSON-LD array for a page.
	 *
	 * @param string $type Schema @type (must be in ALLOWED).
	 * @param array  $data Fields: name, description, url, faqs, author, etc.
	 * @return array|WP_Error Valid JSON-LD array or error.
	 */
	public static function build( $type, array $data ) {
		if ( ! in_array( $type, self::ALLOWED, true ) ) {
			return new WP_Error( 'scc_bad_schema', __( 'Unsupported schema type.', 'seo-command-center' ) );
		}

		$name = SCC_Security::sanitize_text( $data['name'] ?? '' );
		$url  = esc_url_raw( $data['url'] ?? '' );
		$desc = SCC_Security::sanitize_textarea( $data['description'] ?? '' );

		$node = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
		);

		switch ( $type ) {
			case 'Article':
			case 'BlogPosting':
				$node['headline']    = $name;
				$node['description'] = $desc;
				if ( $url ) {
					$node['mainEntityOfPage'] = $url;
				}
				if ( ! empty( $data['author'] ) ) {
					$node['author'] = array( '@type' => 'Organization', 'name' => SCC_Security::sanitize_text( $data['author'] ) );
				}
				if ( ! empty( $data['date'] ) ) {
					$node['datePublished'] = SCC_Security::sanitize_text( $data['date'] );
				}
				break;

			case 'Service':
				$node['name']        = $name;
				$node['description'] = $desc;
				if ( ! empty( $data['provider'] ) ) {
					$node['provider'] = array( '@type' => 'Organization', 'name' => SCC_Security::sanitize_text( $data['provider'] ) );
				}
				if ( ! empty( $data['area'] ) ) {
					$node['areaServed'] = SCC_Security::sanitize_text( $data['area'] );
				}
				break;

			case 'LocalBusiness':
				$node['name']        = $name;
				$node['description'] = $desc;
				if ( $url ) {
					$node['url'] = $url;
				}
				if ( ! empty( $data['area'] ) ) {
					$node['areaServed'] = SCC_Security::sanitize_text( $data['area'] );
				}
				break;

			case 'FAQPage':
				$node['mainEntity'] = self::faq_entities( $data['faqs'] ?? array() );
				if ( empty( $node['mainEntity'] ) ) {
					return new WP_Error( 'scc_bad_schema', __( 'FAQPage requires at least one question/answer.', 'seo-command-center' ) );
				}
				break;

			case 'BreadcrumbList':
				$node['itemListElement'] = self::breadcrumb_items( $data['crumbs'] ?? array() );
				break;

			case 'Organization':
				$node['name'] = $name;
				if ( $url ) {
					$node['url'] = $url;
				}
				break;

			default: // WebPage.
				$node['name']        = $name;
				$node['description'] = $desc;
				if ( $url ) {
					$node['url'] = $url;
				}
		}

		$validation = self::validate( $node );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		return $node;
	}

	/**
	 * Build FAQ mainEntity entries.
	 *
	 * @param array $faqs List of {question, answer}.
	 * @return array
	 */
	protected static function faq_entities( array $faqs ) {
		$entities = array();
		foreach ( $faqs as $faq ) {
			$q = SCC_Security::sanitize_text( $faq['question'] ?? '' );
			$a = SCC_Security::sanitize_textarea( $faq['answer'] ?? '' );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $a,
				),
			);
		}
		return $entities;
	}

	/**
	 * Build breadcrumb items.
	 *
	 * @param array $crumbs List of {name, url}.
	 * @return array
	 */
	protected static function breadcrumb_items( array $crumbs ) {
		$items = array();
		$pos   = 1;
		foreach ( $crumbs as $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => SCC_Security::sanitize_text( $crumb['name'] ?? '' ),
				'item'     => esc_url_raw( $crumb['url'] ?? '' ),
			);
		}
		return $items;
	}

	/**
	 * Validate a schema node has its minimum required fields.
	 *
	 * @param array $node Node.
	 * @return true|WP_Error
	 */
	public static function validate( array $node ) {
		if ( empty( $node['@context'] ) || empty( $node['@type'] ) ) {
			return new WP_Error( 'scc_bad_schema', __( 'Schema missing @context/@type.', 'seo-command-center' ) );
		}
		$type = $node['@type'];
		if ( in_array( $type, array( 'Article', 'BlogPosting' ), true ) && empty( $node['headline'] ) ) {
			return new WP_Error( 'scc_bad_schema', __( 'Article schema requires a headline.', 'seo-command-center' ) );
		}
		if ( in_array( $type, array( 'Service', 'LocalBusiness', 'Organization', 'WebPage' ), true ) && empty( $node['name'] ) ) {
			return new WP_Error( 'scc_bad_schema', __( 'Schema requires a name.', 'seo-command-center' ) );
		}
		return true;
	}

	/**
	 * Whether the active SEO plugin already emits this schema type site-wide,
	 * so we should not duplicate it.
	 *
	 * @param string $type Schema type.
	 * @return bool
	 */
	public static function already_provided( $type ) {
		$plugin = SCC_SEO_Meta::detect();
		// Yoast/Rank Math emit Article/WebPage/BreadcrumbList graphs by default.
		$auto = array( 'Article', 'BlogPosting', 'WebPage', 'BreadcrumbList', 'Organization' );
		if ( in_array( $plugin, array( SCC_SEO_Meta::PLUGIN_YOAST, SCC_SEO_Meta::PLUGIN_RANKMATH ), true ) && in_array( $type, $auto, true ) ) {
			return true;
		}
		return false;
	}
}
