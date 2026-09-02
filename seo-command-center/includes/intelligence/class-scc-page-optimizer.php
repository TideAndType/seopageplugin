<?php
/**
 * Page Optimizer — a per-page SEO scorecard + prioritized fixes.
 *
 * "Optimize this page": for one post it orchestrates the EXISTING per-page
 * systems (SEO report, the latest analysis item, the link graph, content decay,
 * intent drift, and GSC performance) into a component scorecard and a ranked list
 * of concrete fixes. Nothing new is analyzed here — it is a read/compose layer.
 *
 * `compose()` (weighted, renormalized, unknown components excluded) and
 * `build_recommendations()` are pure and unit-tested; `scorecard()` gathers the
 * live signals. Components with no data are marked `known:false` and excluded
 * from the score rather than guessed — matching the plugin's no-fabrication rule.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page optimizer.
 */
class SCC_Page_Optimizer {

	/**
	 * Component weights (relative). Filterable so the model is configurable.
	 *
	 * @return array<string,array{label:string,weight:int}>
	 */
	public static function weights() {
		$w = array(
			'content'          => array( 'label' => __( 'Content', 'seo-command-center' ), 'weight' => 20 ),
			'technical'        => array( 'label' => __( 'Technical', 'seo-command-center' ), 'weight' => 15 ),
			'metadata'         => array( 'label' => __( 'Metadata', 'seo-command-center' ), 'weight' => 15 ),
			'internal_linking' => array( 'label' => __( 'Internal linking', 'seo-command-center' ), 'weight' => 15 ),
			'schema'           => array( 'label' => __( 'Schema', 'seo-command-center' ), 'weight' => 15 ),
			'intent'           => array( 'label' => __( 'Intent', 'seo-command-center' ), 'weight' => 10 ),
			'gsc'              => array( 'label' => __( 'GSC opportunity', 'seo-command-center' ), 'weight' => 10 ),
		);
		/** @var array $w */
		$w = apply_filters( 'scc_page_optimizer_weights', $w );
		return $w;
	}

	/**
	 * Compose an overall score from component percentages. Unknown components are
	 * excluded and the remaining weights renormalized, so a missing signal never
	 * unfairly tanks (or inflates) the score.
	 *
	 * @param array $components key => {known:bool, pct:int}.
	 * @return array {score:int, components:array}
	 */
	public static function compose( array $components ) {
		$weights = self::weights();

		$total_weight = 0;
		$acc          = 0;
		$out          = array();

		foreach ( $weights as $key => $meta ) {
			$c      = isset( $components[ $key ] ) ? $components[ $key ] : array();
			$known  = ! empty( $c['known'] );
			$pct    = max( 0, min( 100, (int) ( $c['pct'] ?? 0 ) ) );
			$weight = (int) $meta['weight'];

			if ( $known ) {
				$total_weight += $weight;
				$acc          += $weight * $pct;
			}
			$out[] = array(
				'key'    => $key,
				'label'  => $meta['label'],
				'weight' => $weight,
				'known'  => $known,
				'pct'    => $known ? $pct : 0,
				'note'   => isset( $c['note'] ) ? (string) $c['note'] : '',
			);
		}

		$score = $total_weight > 0 ? (int) round( $acc / $total_weight ) : 0;
		return array( 'score' => $score, 'components' => $out );
	}

	/**
	 * Build a prioritized recommendation list from a set of per-page flags.
	 * Pure — the same flags always produce the same ordered recommendations.
	 *
	 * @param array $flags {has_title, has_desc, schema_valid, thin, word_count, is_orphan, outbound_opps, decay, drift, missing_h1, images_missing_alt}.
	 * @return array
	 */
	public static function build_recommendations( array $flags ) {
		$recs = array();
		$add  = function ( $key, $sev, $label, $fix, $action ) use ( &$recs ) {
			$recs[] = array( 'key' => $key, 'severity' => $sev, 'label' => $label, 'fix' => $fix, 'action' => $action );
		};

		if ( ! empty( $flags['decay'] ) ) {
			$add( 'decay', 'high', __( 'This page is losing Search Console traffic', 'seo-command-center' ), __( 'Refresh the content and internal links.', 'seo-command-center' ), 'refresh_content' );
		}
		if ( ! empty( $flags['drift'] ) ) {
			$add( 'intent', 'high', __( 'Search intent for this page is shifting', 'seo-command-center' ), __( 'Realign the page to the new dominant intent.', 'seo-command-center' ), 'realign_intent' );
		}
		if ( empty( $flags['schema_valid'] ) ) {
			$add( 'schema', 'medium', __( 'No valid schema on this page', 'seo-command-center' ), __( 'Generate and save recommended schema.', 'seo-command-center' ), 'schema' );
		}
		if ( ! empty( $flags['thin'] ) ) {
			$add( 'content', 'high', sprintf( /* translators: %d words */ __( 'Thin content (%d words)', 'seo-command-center' ), (int) ( $flags['word_count'] ?? 0 ) ), __( 'Expand with sections, examples and FAQs.', 'seo-command-center' ), 'expand_content' );
		}
		if ( ! empty( $flags['is_orphan'] ) ) {
			$add( 'internal_linking', 'high', __( 'Orphan page — no internal links point here', 'seo-command-center' ), __( 'Add internal links from related pages.', 'seo-command-center' ), 'add_internal_links' );
		} elseif ( (int) ( $flags['outbound_opps'] ?? 0 ) > 0 ) {
			$add( 'internal_linking', 'medium', sprintf( /* translators: %d */ __( '%d internal-link opportunities available', 'seo-command-center' ), (int) $flags['outbound_opps'] ), __( 'Insert the recommended internal links.', 'seo-command-center' ), 'add_internal_links' );
		}
		if ( empty( $flags['has_title'] ) ) {
			$add( 'metadata', 'medium', __( 'Missing meta title', 'seo-command-center' ), __( 'Generate and apply an optimized title.', 'seo-command-center' ), 'improve_meta' );
		}
		if ( empty( $flags['has_desc'] ) ) {
			$add( 'metadata', 'low', __( 'Missing meta description', 'seo-command-center' ), __( 'Generate and apply a description.', 'seo-command-center' ), 'improve_meta' );
		}
		if ( ! empty( $flags['missing_h1'] ) ) {
			$add( 'technical', 'medium', __( 'No H1 heading', 'seo-command-center' ), __( 'Add a single, descriptive H1.', 'seo-command-center' ), 'edit' );
		}
		if ( (int) ( $flags['images_missing_alt'] ?? 0 ) > 0 ) {
			$add( 'technical', 'low', sprintf( /* translators: %d */ __( '%d images missing alt text', 'seo-command-center' ), (int) $flags['images_missing_alt'] ), __( 'Add descriptive alt text.', 'seo-command-center' ), 'edit' );
		}

		$rank = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
		usort( $recs, function ( $a, $b ) use ( $rank ) {
			return ( $rank[ $a['severity'] ] ?? 3 ) <=> ( $rank[ $b['severity'] ] ?? 3 );
		} );
		return $recs;
	}

	/**
	 * Build the full scorecard for a post from live data.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function scorecard( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return array( 'post_id' => $post_id, 'score' => 0, 'components' => array(), 'recommendations' => array() );
		}
		$url = get_permalink( $post_id );

		// Existing per-page report (metadata + schema + link recs).
		$report   = class_exists( 'SCC_SEO_Report' ) ? SCC_SEO_Report::build( $post_id ) : array( 'items' => array() );
		$item_by  = self::report_flags( $report );

		// Latest analysis item for content/technical flags.
		$analysis = self::analysis_flags( $post_id );

		// Link graph: inbound count → orphan.
		$is_orphan = false;
		if ( class_exists( 'SCC_Link_Graph' ) ) {
			$graph = new SCC_Link_Graph();
			$built = $graph->build( 1000 );
			foreach ( (array) ( $built['orphans'] ?? array() ) as $o ) {
				if ( (int) ( $o['post_id'] ?? 0 ) === $post_id ) {
					$is_orphan = true;
					break;
				}
			}
		}

		// GSC / decay / intent-drift for this URL (all optional, guarded).
		$gsc_known = false; $gsc_pct = 0; $gsc_note = '';
		$decay = false; $drift = false;
		if ( class_exists( 'SCC_Content_Decay' ) ) {
			$d = SCC_Content_Decay::detect();
			if ( ! empty( $d['available'] ) ) {
				foreach ( (array) $d['items'] as $it ) {
					if ( (int) ( $it['post_id'] ?? 0 ) === $post_id ) { $decay = true; break; }
				}
			}
		}
		if ( class_exists( 'SCC_Intent_Drift' ) ) {
			$dr = SCC_Intent_Drift::detect();
			if ( ! empty( $dr['available'] ) ) {
				foreach ( (array) $dr['items'] as $it ) {
					if ( (int) ( $it['post_id'] ?? 0 ) === $post_id ) { $drift = true; break; }
				}
			}
		}
		if ( class_exists( 'SCC_GSC' ) && SCC_GSC::is_connected() ) {
			$perf = SCC_GSC::page_metrics( $url );
			if ( is_array( $perf ) ) {
				$gsc_known = true;
				$pos       = (float) ( $perf['position'] ?? 0 );
				// Closer to #1 = healthier; positions 4-20 flag opportunity.
				$gsc_pct   = $pos > 0 ? (int) max( 0, min( 100, round( 100 - ( $pos - 1 ) * 5 ) ) ) : 50;
				$gsc_note  = sprintf( /* translators: 1: position 2: impressions */ __( 'Avg position %1$s · %2$s impressions', 'seo-command-center' ), round( $pos, 1 ), number_format_i18n( (int) ( $perf['impressions'] ?? 0 ) ) );
			}
		}

		$flags = array(
			'has_title'          => $item_by['has_title'],
			'has_desc'           => $item_by['has_desc'],
			'schema_valid'       => $item_by['schema_valid'],
			'outbound_opps'      => $item_by['outbound_opps'],
			'thin'               => $analysis['thin'],
			'word_count'         => $analysis['word_count'],
			'missing_h1'         => $analysis['missing_h1'],
			'images_missing_alt' => $analysis['images_missing_alt'],
			'is_orphan'          => $is_orphan,
			'decay'              => $decay,
			'drift'              => $drift,
		);

		$components = array(
			'content'          => array( 'known' => $analysis['known'], 'pct' => $analysis['thin'] ? 40 : ( $analysis['word_count'] >= 1200 ? 100 : 75 ), 'note' => $analysis['known'] ? sprintf( __( '%s words', 'seo-command-center' ), number_format_i18n( $analysis['word_count'] ) ) : '' ),
			'technical'        => array( 'known' => $analysis['known'], 'pct' => 100 - ( $analysis['missing_h1'] ? 40 : 0 ) - ( $analysis['images_missing_alt'] > 0 ? 20 : 0 ) ),
			'metadata'         => array( 'known' => true, 'pct' => ( $item_by['has_title'] ? 60 : 0 ) + ( $item_by['has_desc'] ? 40 : 0 ) ),
			'internal_linking' => array( 'known' => true, 'pct' => $is_orphan ? 20 : ( $item_by['outbound_opps'] > 0 ? 70 : 100 ), 'note' => $is_orphan ? __( 'orphan', 'seo-command-center' ) : '' ),
			'schema'           => array( 'known' => true, 'pct' => $item_by['schema_valid'] ? 100 : 0 ),
			'intent'           => array( 'known' => $drift ? true : ( class_exists( 'SCC_GSC' ) && SCC_GSC::is_connected() ), 'pct' => $drift ? 40 : 100, 'note' => $drift ? __( 'intent shifting', 'seo-command-center' ) : '' ),
			'gsc'              => array( 'known' => $gsc_known, 'pct' => $gsc_pct, 'note' => $gsc_note ),
		);

		$composed = self::compose( $components );

		return array(
			'post_id'         => $post_id,
			'title'           => get_the_title( $post ),
			'url'             => $url,
			'score'           => $composed['score'],
			'components'      => $composed['components'],
			'recommendations' => self::build_recommendations( $flags ),
			'disclaimer'      => __( 'Internal optimization score from your real data — not a guarantee of Google rankings.', 'seo-command-center' ),
		);
	}

	/**
	 * Extract metadata/schema/link flags from the existing SEO report.
	 *
	 * @param array $report Report.
	 * @return array
	 */
	protected static function report_flags( array $report ) {
		$flags = array( 'has_title' => false, 'has_desc' => false, 'schema_valid' => false, 'outbound_opps' => 0 );
		foreach ( (array) ( $report['items'] ?? array() ) as $it ) {
			$label = strtolower( (string) ( $it['label'] ?? '' ) );
			if ( false !== strpos( $label, 'meta title' ) ) {
				$flags['has_title'] = ! empty( $it['ok'] );
			} elseif ( false !== strpos( $label, 'meta description' ) ) {
				$flags['has_desc'] = ! empty( $it['ok'] );
			} elseif ( 'schema' === $label ) {
				$flags['schema_valid'] = ! empty( $it['ok'] );
			} elseif ( false !== strpos( $label, 'internal links' ) ) {
				$flags['outbound_opps'] = (int) ( $it['value'] ?? 0 );
			}
		}
		return $flags;
	}

	/**
	 * Content/technical flags from the latest analysis item for this post.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	protected static function analysis_flags( $post_id ) {
		$out = array( 'known' => false, 'thin' => false, 'word_count' => 0, 'missing_h1' => false, 'images_missing_alt' => 0 );
		if ( ! class_exists( 'SCC_Analyzer' ) ) {
			return $out;
		}
		$latest = SCC_Analyzer::latest();
		foreach ( (array) ( $latest['items'] ?? array() ) as $item ) {
			if ( (int) ( $item['post_id'] ?? 0 ) !== (int) $post_id ) {
				continue;
			}
			$flags = $item['flags'] ?? array();
			if ( is_string( $flags ) ) {
				$decoded = json_decode( $flags, true );
				$flags   = is_array( $decoded ) ? $decoded : array();
			}
			$out['known']              = true;
			$out['word_count']         = (int) ( $item['word_count'] ?? 0 );
			$out['thin']               = in_array( 'thin_content', (array) $flags, true );
			$out['missing_h1']         = in_array( 'no_h1', (array) $flags, true );
			$out['images_missing_alt'] = (int) ( $item['images_missing_alt'] ?? 0 );
			break;
		}
		return $out;
	}
}
