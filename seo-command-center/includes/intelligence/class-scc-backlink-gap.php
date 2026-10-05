<?php
/**
 * Backlink gap intelligence.
 *
 * Uses DataForSEO domain intersection to find domains linking to competitors
 * but not to the current site, then classifies and prioritizes realistic
 * acquisition opportunities. It never auto-submits or fabricates links.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Backlink_Gap {
	const LAST_OPTION = 'scc_backlink_gap_last';

	public static function scan( array $competitors, $limit = 100 ) {
		if ( ! class_exists( 'SCC_DataForSEO' ) || ! SCC_DataForSEO::is_connected() ) {
			return new WP_Error( 'scc_backlink_no_provider', __( 'Connect DataForSEO before running a backlink gap.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		$our = self::domain( home_url( '/' ) );
		$clean = array();
		foreach ( $competitors as $value ) {
			$d = self::domain( $value );
			if ( $d && $d !== $our ) { $clean[] = $d; }
		}
		$clean = array_slice( array_values( array_unique( $clean ) ), 0, 10 );
		if ( empty( $clean ) ) {
			return new WP_Error( 'scc_backlink_input', __( 'Add at least one competitor domain.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		$items = SCC_DataForSEO::backlink_domain_gap( $clean, $our, max( 10, min( 500, (int) $limit ) ) );
		if ( is_wp_error( $items ) ) { return $items; }

		$out = array();
		foreach ( (array) $items as $item ) {
			$domain = self::domain( $item['domain'] ?? '' );
			if ( ! $domain || $domain === $our ) { continue; }
			$type = self::classify_domain( $domain );
			$item['domain'] = $domain;
			$item['type'] = $type;
			$item['priority_score'] = self::score_item( $item, $type );
			$item['priority'] = $item['priority_score'] >= 70 ? 'high' : ( $item['priority_score'] >= 45 ? 'medium' : 'low' );
			$item['recommended_action'] = self::action_for( $domain, $type );
			$out[] = $item;
		}
		usort( $out, function ( $a, $b ) { return $b['priority_score'] <=> $a['priority_score']; } );

		$result = array(
			'available' => true,
			'our_domain' => $our,
			'competitors' => $clean,
			'opportunities' => $out,
			'high_priority' => count( array_filter( $out, function ( $i ) { return 'high' === $i['priority']; } ) ),
			'generated_at' => current_time( 'mysql' ),
			'provider' => 'DataForSEO Backlinks',
		);
		update_option( self::LAST_OPTION, $result, false );
		return $result;
	}

	public static function classify_domain( $domain ) {
		$d = strtolower( (string) $domain );
		if ( preg_match( '/\.(gov|edu)$/', $d ) ) { return 'authority'; }
		if ( preg_match( '/chamber|association|society|alliance|council|bbb\./', $d ) ) { return 'association'; }
		if ( preg_match( '/clutch|upcity|designrush|goodfirms|themanifest|agencyspotter|expertise/', $d ) ) { return 'agency_directory'; }
		if ( preg_match( '/news|journal|tribune|observer|times|herald|patch\./', $d ) ) { return 'news'; }
		if ( preg_match( '/podcast|radio|fm\b/', $d ) ) { return 'media'; }
		if ( preg_match( '/directory|yellow|manta|hotfrog|local|citysearch|merchantcircle/', $d ) ) { return 'directory'; }
		if ( preg_match( '/blog|magazine|resource/', $d ) ) { return 'editorial'; }
		return 'website';
	}

	public static function score_item( array $item, $type = '' ) {
		$rank = max( 0, min( 100, (int) ( $item['rank'] ?? 0 ) ) );
		$hits = max( 0, (int) ( $item['competitor_hits'] ?? 0 ) );
		$backlinks = max( 0, (int) ( $item['backlinks'] ?? 0 ) );
		$score = min( 55, $rank * 0.55 );
		$score += min( 25, $hits * 8 );
		$score += min( 10, log( max( 1, $backlinks + 1 ), 2 ) * 2 );
		$bonus = array(
			'authority' => 15, 'association' => 12, 'news' => 10,
			'media' => 8, 'agency_directory' => 8, 'editorial' => 8,
			'directory' => 3, 'website' => 0,
		);
		$score += $bonus[ $type ] ?? 0;
		return (int) round( min( 100, $score ) );
	}

	public static function last() {
		$last = get_option( self::LAST_OPTION, null );
		return is_array( $last ) ? $last : null;
	}

	protected static function action_for( $domain, $type ) {
		switch ( $type ) {
			case 'authority': return 'Review eligibility for an authoritative resource, partnership, research citation or community contribution.';
			case 'association': return 'Check membership, sponsorship, directory and community-partner opportunities.';
			case 'agency_directory': return 'Review the agency profile requirements and build a complete, evidence-backed listing if it fits.';
			case 'news': return 'Look for a genuinely newsworthy local story, data study, expert quote or community angle.';
			case 'media': return 'Pitch a useful expert interview, local-business topic or case-study discussion.';
			case 'editorial': return 'Find the specific resource/article that cites competitors and pitch a stronger relevant contribution.';
			case 'directory': return 'Verify quality and relevance before creating or correcting a listing.';
			default: return 'Inspect how competitors earned the link and pursue only a relevant editorial, partner or resource opportunity.';
		}
	}

	protected static function domain( $value ) {
		$value = trim( strtolower( (string) $value ) );
		if ( '' === $value ) { return ''; }
		if ( ! preg_match( '#^https?://#', $value ) ) { $value = 'https://' . $value; }
		$host = (string) wp_parse_url( $value, PHP_URL_HOST );
		return preg_replace( '/^www\./', '', strtolower( $host ) );
	}
}
