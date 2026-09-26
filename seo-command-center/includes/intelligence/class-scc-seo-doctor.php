<?php
/**
 * SEO Doctor.
 *
 * One diagnosis for the whole site. The Doctor does not invent new analysis —
 * it runs/reads every TideOrbit engine (technical audit, PageSpeed & Core Web
 * Vitals, Search Console opportunities, AI-search readiness, site architecture)
 * and merges them into:
 *
 *   - one health score (only from sources that were actually measured),
 *   - a grade per area (Indexing, On-page, Content, Links, Speed, Schema, AI, Growth),
 *   - one ranked "what's wrong" list, worst first — each item saying what is
 *     wrong, why it matters, which pages, how to fix it, and (where it is safe)
 *     a one-click fix that only runs when the user clicks it.
 *
 * Sources that have not run are reported as "not measured" and excluded from
 * the score; nothing is estimated to fill the gap.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-wide SEO diagnosis aggregator.
 */
class SCC_SEO_Doctor {

	const REPORT_OPTION = 'scc_seo_doctor_report';

	/** Areas, in display order. */
	const GROUPS = array(
		'indexing' => 'Indexing & crawling',
		'onpage'   => 'On-page & metadata',
		'content'  => 'Content',
		'links'    => 'Links & site structure',
		'speed'    => 'Speed & Core Web Vitals',
		'schema'   => 'Schema & social',
		'ai'       => 'AI search readiness',
		'growth'   => 'Traffic & growth',
	);

	/** Technical-audit category → Doctor area. */
	const TECH_GROUP = array(
		'indexability'       => 'indexing',
		'crawlability'       => 'indexing',
		'canonicalization'   => 'indexing',
		'international'      => 'indexing',
		'metadata'           => 'onpage',
		'onpage_structure'   => 'onpage',
		'media'              => 'onpage',
		'content'            => 'content',
		'architecture'       => 'links',
		'mobile_performance' => 'speed',
		'core_web_vitals'    => 'speed',
		'structured_data'    => 'schema',
		'social'             => 'schema',
	);

	/** Relative weight of each technical category inside its area (mirrors the audit). */
	const TECH_WEIGHTS = array(
		'indexability'       => 20,
		'crawlability'       => 15,
		'canonicalization'   => 12,
		'architecture'       => 15,
		'metadata'           => 10,
		'onpage_structure'   => 8,
		'structured_data'    => 7,
		'mobile_performance' => 8,
		'content'            => 6,
		'media'              => 3,
		'social'             => 2,
		'international'      => 2,
	);

	/** Issue id → one-click fix type (only fixes that are safe and reversible). */
	const FIXES = array(
		'missing_meta_description'    => 'meta_description',
		'duplicate_meta_descriptions' => 'meta_description',
		'long_meta_description'       => 'meta_description',
		'missing_schema'              => 'schema',
		'missing_social_tags'         => 'social_tags',
		'orphan_page'                 => 'internal_links',
		'unreachable_from_home'       => 'internal_links',
	);

	/** Weight of each measured source in the overall health score. */
	const SCORE_WEIGHTS = array(
		'technical'    => 45,
		'speed'        => 20,
		'ai'           => 15,
		'architecture' => 20,
	);

	/**
	 * Latest stored diagnosis.
	 *
	 * @return array|null
	 */
	public static function report() {
		$report = get_option( self::REPORT_OPTION, null );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * Gather every available source and store a fresh diagnosis. The heavy
	 * crawls (technical audit, PageSpeed) are run separately — this step only
	 * reads their latest results plus the fast, cached engines.
	 *
	 * @param bool $refresh_opportunities Recompute Search Console opportunities.
	 * @return array
	 */
	public static function run( $refresh_opportunities = false ) {
		$sources = array(
			'technical'     => class_exists( 'SCC_Technical_SEO' ) ? SCC_Technical_SEO::report() : null,
			'speed'         => class_exists( 'SCC_PageSpeed' ) ? SCC_PageSpeed::report() : null,
			'architecture'  => class_exists( 'SCC_Architecture_Brain' ) ? SCC_Architecture_Brain::report() : null,
			'opportunities' => null,
			'gsc_connected' => class_exists( 'SCC_GSC' ) && SCC_GSC::is_connected(),
			'aeo'           => null,
		);

		if ( class_exists( 'SCC_Opportunity_Engine' ) ) {
			try {
				$sources['opportunities'] = SCC_Opportunity_Engine::all( (bool) $refresh_opportunities );
			} catch ( \Throwable $e ) {
				SCC_Logger::error( 'seo-doctor', 'Opportunity engine failed: ' . $e->getMessage() );
			}
		}
		if ( class_exists( 'SCC_AEO_Expert' ) ) {
			try {
				$sources['aeo'] = SCC_AEO_Expert::site_report( 40 );
			} catch ( \Throwable $e ) {
				SCC_Logger::error( 'seo-doctor', 'AEO report failed: ' . $e->getMessage() );
			}
		}

		$report = self::diagnose( $sources );
		$report['generated_at'] = current_time( 'mysql' );
		update_option( self::REPORT_OPTION, $report, false );

		SCC_Logger::info( 'seo-doctor', 'SEO Doctor diagnosis completed.', array( 'score' => $report['score'], 'issues' => count( $report['issues'] ) ) );
		return $report;
	}

	/**
	 * Merge engine outputs into one ranked diagnosis. Pure — no I/O.
	 *
	 * @param array $sources {technical, speed, architecture, opportunities, gsc_connected, aeo}.
	 * @return array
	 */
	public static function diagnose( array $sources ) {
		$technical    = is_array( $sources['technical'] ?? null ) ? $sources['technical'] : null;
		$speed        = is_array( $sources['speed'] ?? null ) ? $sources['speed'] : null;
		$architecture = is_array( $sources['architecture'] ?? null ) ? $sources['architecture'] : null;
		$aeo          = is_array( $sources['aeo'] ?? null ) ? $sources['aeo'] : null;
		$opps         = is_array( $sources['opportunities'] ?? null ) ? $sources['opportunities'] : null;
		$gsc          = ! empty( $sources['gsc_connected'] );

		$issues = array();

		// 1) Technical + on-page checks.
		foreach ( (array) ( $technical['issues'] ?? array() ) as $issue ) {
			$issues[] = self::from_check( $issue, 'technical' );
		}

		// 2) Real page speed / Core Web Vitals.
		$speed_measured = $speed && (int) ( $speed['measured'] ?? 0 ) > 0;
		if ( $speed_measured ) {
			foreach ( (array) ( $speed['issues'] ?? array() ) as $issue ) {
				$issues[] = self::from_check( $issue, 'pagespeed' );
			}
		}

		// 3) Site architecture (only what the technical audit does not already cover).
		if ( $architecture && isset( $architecture['health']['stats'] ) ) {
			$stats = (array) $architecture['health']['stats'];
			if ( (int) ( $stats['merge_candidates'] ?? 0 ) > 0 ) {
				$issues[] = self::make( 'arch:merge', 'links', 'architecture', 'medium', 'Pages compete for the same topic', (int) $stats['merge_candidates'] . ' group(s) of pages target overlapping intent.', 'When several pages chase the same search, they split relevance and often none ranks well.', 'Review the consolidation plan: merge the weaker pages into the strongest one and redirect them.', (int) $stats['merge_candidates'], array(), '', 'architecture' );
			}
			if ( (int) ( $stats['empty_hubs'] ?? 0 ) > 0 ) {
				$issues[] = self::make( 'arch:empty_hubs', 'content', 'architecture', 'low', 'Service hubs have no supporting content', (int) $stats['empty_hubs'] . ' hub page(s) have no sub-pages or articles beneath them.', 'A hub with nothing supporting it gives search engines little evidence of expertise in that service.', 'Add a few focused sub-pages or articles under each empty hub and link them together.', (int) $stats['empty_hubs'], array(), '', 'architecture' );
			}
			if ( (int) ( $stats['weak_coverage'] ?? 0 ) > 0 ) {
				$issues[] = self::make( 'arch:weak_coverage', 'content', 'architecture', 'low', 'Topics with weak coverage', (int) $stats['weak_coverage'] . ' planned topic(s) are only thinly covered.', 'Thin topical coverage makes it harder to be seen as an authority on the subject.', 'Expand the existing pages for these topics before creating new ones.', (int) $stats['weak_coverage'], array(), '', 'architecture' );
			}
		}

		// 4) AI search readiness.
		foreach ( (array) ( $aeo['recommendations'] ?? array() ) as $rec ) {
			$priority = (int) ( $rec['priority'] ?? 0 );
			$severity = $priority >= 95 ? 'critical' : ( $priority >= 85 ? 'high' : ( $priority >= 72 ? 'medium' : 'low' ) );
			$examples = ! empty( $rec['url'] ) ? array( array( 'url' => (string) $rec['url'], 'evidence' => '', 'post_id' => 0 ) ) : array();
			$issues[] = self::make( 'aeo:' . sanitize_key( (string) ( $rec['key'] ?? md5( (string) ( $rec['title'] ?? '' ) ) ) ), 'ai', 'aeo', $severity, (string) ( $rec['title'] ?? '' ), (string) ( $rec['reason'] ?? '' ), (string) ( $rec['outcome'] ?? '' ), '', $examples ? 1 : 0, $examples, '', 'aeo' );
		}

		// 5) Search Console traffic signals (decay, striking distance, cannibalization…).
		foreach ( (array) $opps as $opp ) {
			$priority = (string) ( $opp['priority'] ?? 'medium' );
			$severity = 'critical' === $priority ? 'critical' : ( 'high' === $priority ? 'high' : ( 'low' === $priority ? 'low' : 'medium' ) );
			$target   = (array) ( $opp['target'] ?? array() );
			$examples = array();
			if ( ! empty( $target['url'] ) || ! empty( $target['post_id'] ) ) {
				$examples[] = array( 'url' => (string) ( $target['url'] ?? '' ), 'evidence' => '', 'post_id' => (int) ( $target['post_id'] ?? 0 ) );
			}
			$item = self::make( 'opp:' . (string) ( $opp['id'] ?? md5( wp_json_encode( $opp ) ) ), 'growth', 'opportunities', $severity, (string) ( $opp['title'] ?? '' ), (string) ( $opp['reason'] ?? '' ), '', (string) ( $opp['recommended_action'] ?? '' ), $examples ? 1 : 0, $examples, '', 'action-queue' );
			$item['opportunity'] = $opp; // Kept so "Add to queue" can promote the original.
			$item['rank']        = min( 100, (int) ( $opp['score'] ?? 0 ) );
			$issues[] = $item;
		}

		// Rank: severity first, then how many pages are affected / opportunity score.
		$sev_rank = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
		usort(
			$issues,
			function ( $a, $b ) use ( $sev_rank ) {
				$s = ( $sev_rank[ $b['severity'] ] ?? 0 ) <=> ( $sev_rank[ $a['severity'] ] ?? 0 );
				return 0 !== $s ? $s : ( $b['rank'] <=> $a['rank'] );
			}
		);

		// Which areas were actually measured.
		$measured = array(
			'indexing' => (bool) $technical,
			'onpage'   => (bool) $technical,
			'content'  => (bool) $technical || (bool) $architecture,
			'links'    => (bool) $technical || (bool) $architecture,
			'speed'    => (bool) $technical || $speed_measured,
			'schema'   => (bool) $technical,
			'ai'       => (bool) $aeo,
			'growth'   => $gsc && is_array( $opps ),
		);

		// Area scores come from the same engine scores as the overall health, so
		// an area grade can never contradict the headline number. The technical
		// engine already scales each category by the share of pages affected.
		$components = array_fill_keys( array_keys( self::GROUPS ), array() );
		foreach ( (array) ( $technical['categories'] ?? array() ) as $cat ) {
			$area = self::TECH_GROUP[ (string) ( $cat['id'] ?? '' ) ] ?? '';
			if ( '' !== $area && isset( $cat['score'] ) ) {
				$components[ $area ][] = array( (int) $cat['score'], self::TECH_WEIGHTS[ $cat['id'] ] ?? 5 );
			}
		}
		if ( $speed_measured && null !== ( $speed['score'] ?? null ) ) {
			$components['speed'][] = array( (int) $speed['score'], 24 );
		}
		if ( $architecture && isset( $architecture['health']['score'] ) ) {
			$components['links'][] = array( (int) $architecture['health']['score'], 15 );
		}
		if ( $aeo && isset( $aeo['score'] ) ) {
			$components['ai'][] = array( (int) $aeo['score'], 1 );
		}

		// Traffic has no engine score; judge it by its issues.
		$penalty = array( 'critical' => 30, 'high' => 18, 'medium' => 9, 'low' => 4 );
		$groups  = array();
		foreach ( self::GROUPS as $gid => $label ) {
			$count = 0;
			$pen   = 0;
			foreach ( $issues as $issue ) {
				if ( $issue['group'] === $gid ) {
					$count++;
					$pen += $penalty[ $issue['severity'] ] ?? 4;
				}
			}
			$worst_in_area = self::worst( $issues, $gid );
			if ( 'growth' === $gid ) {
				$components['growth'][] = array( max( 0, 100 - min( 100, $pen ) ), 1 );
			}
			$score = null;
			if ( $measured[ $gid ] && $components[ $gid ] ) {
				$sum = 0;
				$wt  = 0;
				foreach ( $components[ $gid ] as $part ) {
					$sum += $part[0] * $part[1];
					$wt  += $part[1];
				}
				$score = self::cap( (int) round( $sum / max( 1, $wt ) ), $worst_in_area );
			}
			$groups[] = array(
				'id'       => $gid,
				'label'    => $label,
				'measured' => $measured[ $gid ],
				'score'    => $score,
				'grade'    => null === $score ? null : self::grade( $score ),
				'issues'   => $count,
			);
		}

		// Overall health: weighted blend of the engines that actually ran.
		$parts = array();
		if ( $technical && isset( $technical['score'] ) ) {
			$parts['technical'] = (int) $technical['score'];
		}
		if ( $speed_measured && null !== ( $speed['score'] ?? null ) ) {
			$parts['speed'] = (int) $speed['score'];
		}
		if ( $aeo && isset( $aeo['score'] ) ) {
			$parts['ai'] = (int) $aeo['score'];
		}
		if ( $architecture && isset( $architecture['health']['score'] ) ) {
			$parts['architecture'] = (int) $architecture['health']['score'];
		}
		$score = null;
		if ( $parts ) {
			$sum = 0;
			$wt  = 0;
			foreach ( $parts as $key => $value ) {
				$sum += $value * self::SCORE_WEIGHTS[ $key ];
				$wt  += self::SCORE_WEIGHTS[ $key ];
			}
			$score = self::cap( (int) round( $sum / $wt ), self::worst( $issues ) );
		}

		$counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );
		$fixable = 0;
		foreach ( $issues as $issue ) {
			if ( isset( $counts[ $issue['severity'] ] ) ) {
				$counts[ $issue['severity'] ]++;
			}
			if ( '' !== $issue['fix_type'] ) {
				$fixable++;
			}
		}

		return array(
			'score'      => $score,
			'grade'      => null === $score ? null : self::grade( $score ),
			'label'      => null === $score ? __( 'Not measured yet', 'seo-command-center' ) : self::label( $score ),
			'parts'      => $parts,
			'groups'     => $groups,
			'issues'     => $issues,
			'counts'     => $counts,
			'fixable'    => $fixable,
			'sources'    => array(
				'technical'     => array( 'available' => (bool) $technical, 'at' => (string) ( $technical['generated_at'] ?? '' ), 'pages' => (int) ( $technical['pages'] ?? 0 ) ),
				'speed'         => array( 'available' => $speed_measured, 'at' => (string) ( $speed['generated_at'] ?? '' ), 'urls' => (int) ( $speed['measured'] ?? 0 ) ),
				'architecture'  => array( 'available' => (bool) $architecture, 'at' => (string) ( $architecture['generated_at'] ?? '' ) ),
				'ai'            => array( 'available' => (bool) $aeo, 'pages' => (int) ( $aeo['pages_analyzed'] ?? 0 ) ),
				'growth'        => array( 'available' => $gsc && is_array( $opps ), 'gsc_connected' => $gsc ),
			),
			'disclaimer' => __( 'The SEO Doctor combines TideOrbit’s diagnostics into one view. The health score is not a Google ranking score; areas that have not been measured are shown as such and do not affect it.', 'seo-command-center' ),
		);
	}

	/**
	 * Normalise a technical / PageSpeed check into a Doctor issue.
	 *
	 * @param array  $issue  Check issue.
	 * @param string $source Source key.
	 * @return array
	 */
	protected static function from_check( array $issue, $source ) {
		$id       = (string) ( $issue['id'] ?? '' );
		$group    = self::TECH_GROUP[ (string) ( $issue['category'] ?? '' ) ] ?? 'indexing';
		$examples = array();
		foreach ( array_slice( (array) ( $issue['examples'] ?? array() ), 0, 8 ) as $ex ) {
			$examples[] = array(
				'url'      => (string) ( $ex['url'] ?? '' ),
				'evidence' => (string) ( $ex['evidence'] ?? '' ),
				'post_id'  => (int) ( $ex['post_id'] ?? 0 ),
			);
		}
		$screen = 'seo-audit';
		if ( 'metadata' === ( $issue['category'] ?? '' ) ) {
			$screen = 'meta-editor';
		} elseif ( 'architecture' === ( $issue['category'] ?? '' ) ) {
			$screen = 'internal-links';
		}
		return self::make(
			( 'pagespeed' === $source ? 'psi:' : 'tech:' ) . $id,
			$group,
			$source,
			(string) ( $issue['severity'] ?? 'low' ),
			(string) ( $issue['title'] ?? $id ),
			'',
			(string) ( $issue['why_it_matters'] ?? '' ),
			(string) ( $issue['fix'] ?? '' ),
			(int) ( $issue['affected_count'] ?? count( $examples ) ),
			$examples,
			self::FIXES[ $id ] ?? '',
			$screen,
			'site' === ( $issue['scope'] ?? 'page' ) ? 'site' : 'page'
		);
	}

	/**
	 * Build one normalised issue.
	 *
	 * @return array
	 */
	protected static function make( $id, $group, $source, $severity, $title, $what, $why, $fix, $affected, array $examples, $fix_type, $screen, $scope = 'page' ) {
		$sev_base = array( 'critical' => 100, 'high' => 70, 'medium' => 40, 'low' => 15 );
		$severity = isset( $sev_base[ $severity ] ) ? $severity : 'low';
		$affected = max( 0, (int) $affected );
		return array(
			'id'             => (string) $id,
			'group'          => isset( self::GROUPS[ $group ] ) ? $group : 'indexing',
			'source'         => (string) $source,
			'severity'       => $severity,
			'title'          => (string) $title,
			'what'           => (string) $what,
			'why'            => (string) $why,
			'fix'            => (string) $fix,
			'affected_count' => $affected,
			'scope'          => $scope,
			'examples'       => $examples,
			'fix_type'       => (string) $fix_type,
			'screen'         => (string) $screen,
			'rank'           => min( 100, $affected * 2 ),
		);
	}

	/**
	 * Worst severity among issues (optionally in one area).
	 *
	 * @param array  $issues Issues.
	 * @param string $group  Area id ('' = all).
	 * @return string '' when none.
	 */
	protected static function worst( array $issues, $group = '' ) {
		$rank = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
		$best = '';
		foreach ( $issues as $issue ) {
			if ( '' !== $group && $issue['group'] !== $group ) {
				continue;
			}
			if ( ( $rank[ $issue['severity'] ] ?? 0 ) > ( $rank[ $best ] ?? 0 ) ) {
				$best = $issue['severity'];
			}
		}
		return $best;
	}

	/**
	 * Keep a grade honest about its worst open problem: nothing critical can
	 * pass, and nothing with a high-severity issue can earn an A or B.
	 *
	 * @param int    $score Score.
	 * @param string $worst Worst open severity.
	 * @return int
	 */
	public static function cap( $score, $worst ) {
		$ceiling = array( 'critical' => 49, 'high' => 79, 'medium' => 92 );
		return isset( $ceiling[ $worst ] ) ? min( (int) $score, $ceiling[ $worst ] ) : (int) $score;
	}

	/**
	 * Letter grade for a 0–100 score.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	public static function grade( $score ) {
		$score = (int) $score;
		if ( $score >= 90 ) {
			return 'A';
		}
		if ( $score >= 80 ) {
			return 'B';
		}
		if ( $score >= 70 ) {
			return 'C';
		}
		if ( $score >= 60 ) {
			return 'D';
		}
		return 'F';
	}

	/**
	 * Plain-English label for a score.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	public static function label( $score ) {
		$score = (int) $score;
		if ( $score >= 90 ) {
			return __( 'Excellent', 'seo-command-center' );
		}
		if ( $score >= 75 ) {
			return __( 'Good, with some fixes to make', 'seo-command-center' );
		}
		if ( $score >= 55 ) {
			return __( 'Needs work', 'seo-command-center' );
		}
		return __( 'Serious problems', 'seo-command-center' );
	}

	/**
	 * After a one-click fix, drop the fixed page from its issue (or the whole
	 * issue for a site-level fix) so the list reflects reality until the next
	 * check-up re-verifies it. Pure transform + store.
	 *
	 * @param string $issue_id Issue id.
	 * @param int    $post_id  Fixed page (0 = the whole issue).
	 * @return array|null Updated report.
	 */
	public static function mark_fixed( $issue_id, $post_id = 0 ) {
		$report = self::report();
		if ( ! $report ) {
			return null;
		}
		$report = self::without_fixed( $report, $issue_id, $post_id );
		update_option( self::REPORT_OPTION, $report, false );
		return $report;
	}

	/**
	 * Remove a fixed page (or a whole issue) from a report. Pure.
	 *
	 * @param array  $report   Report.
	 * @param string $issue_id Issue id.
	 * @param int    $post_id  Page (0 = whole issue).
	 * @return array
	 */
	public static function without_fixed( array $report, $issue_id, $post_id = 0 ) {
		$post_id = (int) $post_id;
		$kept    = array();
		foreach ( (array) ( $report['issues'] ?? array() ) as $issue ) {
			if ( (string) $issue['id'] !== (string) $issue_id ) {
				$kept[] = $issue;
				continue;
			}
			if ( $post_id <= 0 ) {
				continue; // Whole issue resolved.
			}
			$before = count( (array) $issue['examples'] );
			$issue['examples'] = array_values(
				array_filter(
					(array) $issue['examples'],
					function ( $ex ) use ( $post_id ) {
						return (int) ( $ex['post_id'] ?? 0 ) !== $post_id;
					}
				)
			);
			$removed = $before - count( $issue['examples'] );
			$issue['affected_count'] = max( 0, (int) $issue['affected_count'] - max( 1, $removed ) );
			if ( $issue['affected_count'] > 0 ) {
				$kept[] = $issue;
			}
		}
		$report['issues'] = $kept;
		$report['fixed']  = (int) ( $report['fixed'] ?? 0 ) + 1;
		return $report;
	}

	/**
	 * The Action Queue entry for a Doctor issue. Traffic opportunities keep their
	 * original shape; everything else becomes a review item — never a type the
	 * queue's Autopilot is allowed to run unattended.
	 *
	 * @param array $issue Doctor issue.
	 * @return array Opportunity-shaped array for SCC_Action_Queue::promote().
	 */
	public static function queue_item( array $issue ) {
		if ( ! empty( $issue['opportunity'] ) && is_array( $issue['opportunity'] ) ) {
			return $issue['opportunity'];
		}
		$first = (array) ( $issue['examples'][0] ?? array() );
		$map   = array( 'critical' => 'high', 'high' => 'high', 'medium' => 'medium', 'low' => 'low' );
		return array(
			'id'                 => 'doctor-' . sanitize_key( str_replace( ':', '-', (string) $issue['id'] ) ),
			'action_type'        => 'doctor_review',
			'title'              => (string) $issue['title'] . ( (int) $issue['affected_count'] > 1 ? sprintf( ' (%d pages)', (int) $issue['affected_count'] ) : '' ),
			'target'             => array( 'post_id' => (int) ( $first['post_id'] ?? 0 ), 'url' => (string) ( $first['url'] ?? '' ), 'urls' => array_values( array_filter( array_map( function ( $ex ) { return (string) ( $ex['url'] ?? '' ); }, (array) $issue['examples'] ) ) ) ),
			'priority'           => $map[ $issue['severity'] ] ?? 'medium',
			'score'              => array( 'critical' => 95, 'high' => 80, 'medium' => 55, 'low' => 30 )[ $issue['severity'] ] ?? 50,
			'confidence'         => 90,
			'reason'             => trim( (string) $issue['what'] . ' ' . (string) $issue['why'] ),
			'recommended_action' => (string) $issue['fix'],
			'expected_impact'    => in_array( $issue['severity'], array( 'critical', 'high' ), true ) ? 'high' : 'medium',
			'risk'               => 'low',
			'source'             => 'seo_doctor',
		);
	}

	/**
	 * Find one issue in the stored diagnosis.
	 *
	 * @param string $issue_id Issue id.
	 * @return array|null
	 */
	public static function find_issue( $issue_id ) {
		$report = self::report();
		foreach ( (array) ( $report['issues'] ?? array() ) as $issue ) {
			if ( (string) $issue['id'] === (string) $issue_id ) {
				return $issue;
			}
		}
		return null;
	}
}
