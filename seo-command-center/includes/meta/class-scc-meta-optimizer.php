<?php
/**
 * AI meta title & description optimizer.
 *
 * Generates classified metadata variants (each with a stated reason), optionally
 * informed by real Search Console performance, and applies a chosen variant
 * non-destructively with full history + a configurable cooldown. It never claims
 * a change caused an improvement without comparable before/after data.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta optimizer.
 */
class SCC_Meta_Optimizer {

	/** @var SCC_AI_Manager */
	protected $ai;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager $ai AI manager.
	 */
	public function __construct( SCC_AI_Manager $ai ) {
		$this->ai = $ai;
	}

	/**
	 * Generate metadata variants for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array|WP_Error {current, variants, performance}
	 */
	public function generate_variants( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'scc_no_post', __( 'Post not found.', 'seo-command-center' ), array( 'status' => 404 ) );
		}

		$current = SCC_Metadata::current( $post_id );
		$plan    = SCC_Content_Index::get( $post_id );
		$perf    = $this->performance( $post_id );

		$excerpt = wp_trim_words( SCC_Content_Index::get_plain_text( $post ), 180 );

		$system = 'You are an SEO strategist optimizing a page title tag and meta description. '
			. 'Generate DISTINCT, non-spammy variants, each with a clear rationale. Titles must match search intent, '
			. 'read naturally, include important terms without stuffing, avoid unnecessary punctuation, and earn the click. '
			. 'Descriptions must accurately summarize the page, give a real reason to click, and avoid false claims. '
			. 'Treat length as guidance (title ~50-60 chars, description ~140-160), not a guarantee. '
			. 'Classify each variant as one of: keyword, ctr, benefit, local, commercial, brand. '
			. 'Return JSON: {"variants":[{"type":str,"title":str,"description":str,"reason":str}]}';

		$context = array(
			'page_title'      => get_the_title( $post ),
			'current_title'   => $current['title'],
			'current_desc'    => $current['description'],
			'primary_keyword' => $plan['primary_keyword'] ?? '',
			'intent'          => $plan['intent'] ?? '',
			'url'             => get_permalink( $post ),
			'content_excerpt' => $excerpt,
			'search_console'  => $perf, // real data or null.
			'site_name'       => get_bloginfo( 'name' ),
		);

		$response = $this->ai->complete(
			array(
				'system'      => $system,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => "Page context (JSON):\n" . wp_json_encode( $context ) . "\n\nGenerate the metadata variants JSON now.",
					),
				),
				'json'        => true,
				'max_tokens'  => 1800,
				'temperature' => 0.6,
			),
			'meta-optimization'
		);

		if ( $response->is_error() ) {
			return $response->error;
		}
		$data = $response->json();
		if ( ! is_array( $data ) || empty( $data['variants'] ) ) {
			return new WP_Error( 'scc_bad_ai_output', __( 'Could not parse metadata variants. Try again.', 'seo-command-center' ), array( 'status' => 502 ) );
		}

		$valid_types = array( 'keyword', 'ctr', 'benefit', 'local', 'commercial', 'brand' );
		$variants    = array();
		foreach ( (array) $data['variants'] as $v ) {
			$type = strtolower( (string) ( $v['type'] ?? '' ) );
			$variants[] = array(
				'type'        => in_array( $type, $valid_types, true ) ? $type : 'ctr',
				'title'       => SCC_Security::sanitize_text( $v['title'] ?? '' ),
				'description' => SCC_Security::sanitize_textarea( $v['description'] ?? '' ),
				'reason'      => SCC_Security::sanitize_textarea( $v['reason'] ?? '' ),
			);
		}

		return array(
			'current'     => $current,
			'variants'    => $variants,
			'performance' => $perf,
			'cooldown'    => array(
				'title'       => SCC_Meta_History::in_cooldown( $post_id, 'title' ),
				'description' => SCC_Meta_History::in_cooldown( $post_id, 'description' ),
			),
		);
	}

	/**
	 * Apply a chosen title/description to a post.
	 *
	 * @param int    $post_id  Post id.
	 * @param array  $meta     {title, description}.
	 * @param string $reason   Reason.
	 * @param bool   $force    Bypass the cooldown (manual trigger).
	 * @return array|WP_Error
	 */
	public function apply( $post_id, array $meta, $reason = '', $force = false ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot edit this post.', 'seo-command-center' ), array( 'status' => 403 ) );
		}

		$title = isset( $meta['title'] ) ? SCC_Security::sanitize_text( $meta['title'] ) : '';
		$desc  = isset( $meta['description'] ) ? SCC_Security::sanitize_textarea( $meta['description'] ) : '';
		if ( '' === $title && '' === $desc ) {
			return new WP_Error( 'scc_empty', __( 'Nothing to apply.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		$current = SCC_Metadata::current( $post_id );
		$perf    = $this->performance( $post_id );

		// Cooldown guard (unless forced by an explicit manual action).
		if ( ! $force ) {
			if ( '' !== $title && SCC_Meta_History::in_cooldown( $post_id, 'title' ) ) {
				return new WP_Error( 'scc_cooldown', __( 'This title was changed recently. Use the manual override to change it again within the cooldown.', 'seo-command-center' ), array( 'status' => 409 ) );
			}
			if ( '' !== $desc && SCC_Meta_History::in_cooldown( $post_id, 'description' ) ) {
				return new WP_Error( 'scc_cooldown', __( 'This description was changed recently. Use the manual override to change it again within the cooldown.', 'seo-command-center' ), array( 'status' => 409 ) );
			}
		}

		// Apply (overwrite = true: this is an explicit, user-approved change).
		SCC_Metadata::apply(
			$post_id,
			array( 'meta_title' => $title ? $title : $current['title'], 'meta_description' => $desc ? $desc : $current['description'] ),
			true
		);

		// History + revert records per changed field.
		if ( '' !== $title && $title !== $current['title'] ) {
			SCC_Meta_History::record( array( 'post_id' => $post_id, 'field' => 'title', 'previous_value' => $current['title'], 'new_value' => $title, 'reason' => $reason, 'perf_before' => $perf ) );
			SCC_Change_History::record( array( 'post_id' => $post_id, 'change_type' => 'meta_title', 'previous_value' => $current['title'], 'new_value' => $title, 'reason' => $reason, 'trigger_source' => $force ? 'manual' : 'assisted' ) );
		}
		if ( '' !== $desc && $desc !== $current['description'] ) {
			SCC_Meta_History::record( array( 'post_id' => $post_id, 'field' => 'description', 'previous_value' => $current['description'], 'new_value' => $desc, 'reason' => $reason, 'perf_before' => $perf ) );
			SCC_Change_History::record( array( 'post_id' => $post_id, 'change_type' => 'meta_description', 'previous_value' => $current['description'], 'new_value' => $desc, 'reason' => $reason, 'trigger_source' => $force ? 'manual' : 'assisted' ) );
		}

		return array( 'applied' => true, 'current' => SCC_Metadata::current( $post_id ) );
	}

	/**
	 * Search Console performance for a post's URL, or null if unavailable.
	 *
	 * @param int $post_id Post id.
	 * @return array|null {impressions, clicks, ctr, position}
	 */
	public function performance( $post_id ) {
		if ( ! class_exists( 'SCC_GSC' ) || ! SCC_GSC::is_connected() ) {
			return null;
		}
		$url  = get_permalink( $post_id );
		$rows = SCC_GSC::query( '', array( 'page' ), 90, 5000 );
		if ( is_wp_error( $rows ) ) {
			return null;
		}
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['keys'][0] ) && untrailingslashit( $row['keys'][0] ) === untrailingslashit( $url ) ) {
				return array(
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
					'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				);
			}
		}
		return null;
	}

	/**
	 * Pages with a metadata opportunity from Search Console (position 4-20,
	 * meaningful impressions, low CTR).
	 *
	 * @return array|WP_Error
	 */
	public function opportunities() {
		if ( ! class_exists( 'SCC_GSC' ) || ! SCC_GSC::is_connected() ) {
			return array();
		}
		$rows = SCC_GSC::query( '', array( 'page' ), 90, 2000 );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$ops = array();
		foreach ( (array) $rows as $row ) {
			$impressions = (int) ( $row['impressions'] ?? 0 );
			$position    = (float) ( $row['position'] ?? 0 );
			$ctr         = (float) ( $row['ctr'] ?? 0 );
			if ( $impressions < 100 || $position < 4 || $position > 20 ) {
				continue;
			}
			$url = $row['keys'][0] ?? '';
			$ops[] = array(
				'url'         => $url,
				'post_id'     => (int) url_to_postid( $url ),
				'impressions' => $impressions,
				'ctr'         => round( $ctr * 100, 2 ),
				'position'    => round( $position, 1 ),
				'recommendation' => __( 'Ranks on page 1-2 with low CTR — test a stronger intent/benefit title.', 'seo-command-center' ),
			);
		}
		usort( $ops, function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );
		return array_slice( $ops, 0, 100 );
	}
}
