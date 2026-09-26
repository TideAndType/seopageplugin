<?php
/**
 * Architecture expansion drafts.
 *
 * Generates evidence-constrained section copy for an existing page, stores it
 * for review, and applies it only after an explicit user action. Elementor
 * writes use TideOrbit's verified snapshot/rollback path.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_Architecture_Expansion {

	const META_KEY = '_scc_architecture_expansion_drafts';
	const NATIVE_BACKUP_KEY = '_scc_architecture_content_backup';

	/** @var SCC_AI_Manager */
	protected $ai;

	public function __construct( SCC_AI_Manager $ai ) {
		$this->ai = $ai;
	}

	/**
	 * Generate a reviewable missing-section draft from known evidence only.
	 *
	 * @param array $node Enriched architecture node.
	 * @return array|WP_Error
	 */
	public function generate( array $node ) {
		$post_id = (int) ( $node['post_id'] ?? 0 );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'scc_arch_no_post', __( 'This recommendation is not attached to an editable existing page.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		$missing = array_values( array_filter( array_map( 'strval', (array) ( $node['coverage']['missing'] ?? array() ) ) ) );
		if ( empty( $missing ) ) {
			$missing[] = (string) ( $node['title'] ?? $node['primary_keyword'] ?? '' );
		}

		$brand = class_exists( 'SCC_Brand_Brain' ) ? SCC_Brand_Brain::profile() : array();
		$current = wp_strip_all_tags( (string) $post->post_content );
		if ( function_exists( 'mb_substr' ) ) {
			$current = mb_substr( $current, 0, 7000 );
		} else {
			$current = substr( $current, 0, 7000 );
		}

		$context = array(
			'page' => array(
				'title' => get_the_title( $post_id ),
				'url'   => get_permalink( $post_id ),
				'existing_content_excerpt' => $current,
			),
			'topic' => array(
				'title'           => (string) ( $node['title'] ?? '' ),
				'primary_keyword' => (string) ( $node['primary_keyword'] ?? '' ),
				'intent'          => (string) ( $node['intent'] ?? '' ),
				'missing_coverage'=> array_slice( $missing, 0, 8 ),
			),
			'known_brand_facts' => $brand,
		);

		$system = 'You are TideOrbit Architecture Expansion. Write ONE useful section to add to the supplied existing webpage. '
			. 'Use ONLY facts present in the supplied page excerpt and known_brand_facts. Never invent testimonials, credentials, '
			. 'statistics, locations, guarantees, clients, certifications, prices, service capabilities or outcomes. '
			. 'Do not repeat the page introduction. Address the missing coverage directly and naturally. '
			. 'Return JSON only with keys heading and html. html may use only short paragraphs, strong/emphasis, and ul/ol/li. '
			. 'Do not include an H1 and do not include a CTA unless the supplied brand facts explicitly contain that CTA.';

		$response = $this->ai->complete(
			array(
				'system'      => $system,
				'messages'    => array( array( 'role' => 'user', 'content' => wp_json_encode( $context ) ) ),
				'json'        => true,
				'max_tokens'  => SCC_AI_Manager::token_budget( 1800 ),
				'temperature' => 0.25,
			),
			'content-brief'
		);

		if ( $response->is_error() ) {
			return $response->error;
		}
		$data = $response->json();
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'scc_arch_bad_draft', __( 'The AI response could not be parsed into a section draft.', 'seo-command-center' ), array( 'status' => 502 ) );
		}

		$heading = sanitize_text_field( (string) ( $data['heading'] ?? $node['title'] ?? '' ) );
		$html    = self::sanitize_section_html( (string) ( $data['html'] ?? '' ) );
		if ( '' === $heading || '' === trim( wp_strip_all_tags( $html ) ) ) {
			return new WP_Error( 'scc_arch_empty_draft', __( 'The generated section was empty. Try again.', 'seo-command-center' ), array( 'status' => 502 ) );
		}

		$draft_id = substr( sha1( (string) ( $node['node_id'] ?? '' ) . '|' . microtime( true ) ), 0, 16 );
		$draft = array(
			'id'           => $draft_id,
			'node_id'      => (string) ( $node['node_id'] ?? '' ),
			'post_id'      => $post_id,
			'topic'        => (string) ( $node['title'] ?? '' ),
			'heading'      => $heading,
			'html'         => $html,
			'missing'      => array_slice( $missing, 0, 8 ),
			'generated_at' => current_time( 'mysql' ),
			'provider'     => (string) ( $response->provider ?? '' ),
			'model'        => (string) ( $response->model ?? '' ),
			'applied'      => false,
		);

		$drafts = self::drafts( $post_id );
		$drafts[ $draft_id ] = $draft;
		if ( count( $drafts ) > 10 ) {
			$drafts = array_slice( $drafts, -10, null, true );
		}
		update_post_meta( $post_id, self::META_KEY, $drafts );

		return $draft;
	}

	/**
	 * Apply an approved draft to Elementor or normal WordPress content.
	 *
	 * @param int    $post_id Post id.
	 * @param string $draft_id Draft id.
	 * @return array|WP_Error
	 */
	public static function apply( $post_id, $draft_id ) {
		$post_id  = (int) $post_id;
		$draft_id = sanitize_text_field( (string) $draft_id );
		$drafts   = self::drafts( $post_id );
		if ( empty( $drafts[ $draft_id ] ) ) {
			return new WP_Error( 'scc_arch_missing_draft', __( 'That section draft no longer exists.', 'seo-command-center' ), array( 'status' => 404 ) );
		}
		$draft = $drafts[ $draft_id ];
		if ( ! empty( $draft['applied'] ) ) {
			return new WP_Error( 'scc_arch_draft_applied', __( 'This section draft has already been applied.', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		$is_elementor = class_exists( 'SCC_Elementor' )
			&& SCC_Elementor::is_active()
			&& SCC_Elementor::is_elementor_post( $post_id );

		if ( $is_elementor ) {
			if ( ! class_exists( 'SCC_Block_Elementor_Renderer' ) ) {
				return new WP_Error( 'scc_arch_no_renderer', __( 'The Elementor renderer is unavailable.', 'seo-command-center' ), array( 'status' => 500 ) );
			}
			$applied = SCC_Block_Elementor_Renderer::append_content_section( $post_id, $draft['heading'], $draft['html'] );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			$mode = 'elementor';
		} else {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'scc_arch_no_post', __( 'Target page not found.', 'seo-command-center' ), array( 'status' => 404 ) );
			}
			$backup = array(
				'created_at'   => current_time( 'mysql' ),
				'post_content' => (string) $post->post_content,
			);
			update_post_meta( $post_id, self::NATIVE_BACKUP_KEY, $backup );
			if ( function_exists( 'wp_save_post_revision' ) ) {
				wp_save_post_revision( $post_id );
			}
			$section = "\n<!-- TideOrbit architecture expansion -->\n<section class=\"scc-architecture-expansion\"><h2>"
				. esc_html( $draft['heading'] ) . '</h2>' . $draft['html'] . '</section>';
			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => (string) $post->post_content . $section,
				),
				true
			);
			if ( is_wp_error( $updated ) || ! $updated ) {
				return is_wp_error( $updated ) ? $updated : new WP_Error( 'scc_arch_apply_failed', __( 'Could not update the page.', 'seo-command-center' ) );
			}
			$mode = 'wordpress';
		}

		$drafts[ $draft_id ]['applied']    = true;
		$drafts[ $draft_id ]['applied_at'] = current_time( 'mysql' );
		update_post_meta( $post_id, self::META_KEY, $drafts );

		if ( class_exists( 'SCC_Content_Index' ) ) {
			SCC_Content_Index::index_post( $post_id );
		}
		if ( class_exists( 'SCC_Site_Knowledge' ) ) {
			SCC_Site_Knowledge::invalidate();
		}

		return array(
			'ok'      => true,
			'mode'    => $mode,
			'post_id' => $post_id,
			'message' => __( 'The reviewed section was added to the page. A TideOrbit recovery point was saved first.', 'seo-command-center' ),
		);
	}

	/**
	 * Roll back the last Architecture expansion.
	 *
	 * @param int $post_id Post id.
	 * @return array|WP_Error
	 */
	public static function rollback( $post_id ) {
		$post_id = (int) $post_id;
		$is_elementor = class_exists( 'SCC_Elementor' )
			&& SCC_Elementor::is_active()
			&& SCC_Elementor::is_elementor_post( $post_id );

		if ( $is_elementor && class_exists( 'SCC_Block_Elementor_Renderer' ) ) {
			$r = SCC_Block_Elementor_Renderer::restore_last_backup( $post_id );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		} else {
			$backup = get_post_meta( $post_id, self::NATIVE_BACKUP_KEY, true );
			if ( ! is_array( $backup ) || ! array_key_exists( 'post_content', $backup ) ) {
				return new WP_Error( 'scc_arch_no_backup', __( 'No Architecture expansion backup is available for this page.', 'seo-command-center' ), array( 'status' => 404 ) );
			}
			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => (string) $backup['post_content'],
				),
				true
			);
			if ( is_wp_error( $updated ) || ! $updated ) {
				return is_wp_error( $updated ) ? $updated : new WP_Error( 'scc_arch_rollback_failed', __( 'Could not restore the previous page content.', 'seo-command-center' ) );
			}
		}

		if ( class_exists( 'SCC_Content_Index' ) ) {
			SCC_Content_Index::index_post( $post_id );
		}
		if ( class_exists( 'SCC_Site_Knowledge' ) ) {
			SCC_Site_Knowledge::invalidate();
		}

		return array( 'ok' => true, 'post_id' => $post_id, 'message' => __( 'The previous page version was restored from TideOrbit’s last expansion backup.', 'seo-command-center' ) );
	}

	public static function drafts( $post_id ) {
		$value = get_post_meta( (int) $post_id, self::META_KEY, true );
		return is_array( $value ) ? $value : array();
	}

	protected static function sanitize_section_html( $html ) {
		$allowed = array(
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'a'      => array( 'href' => true, 'title' => true ),
			'br'     => array(),
		);
		return trim( wp_kses( (string) $html, $allowed ) );
	}
}
