<?php
/**
 * Site-aware pre-publish SEO preflight.
 *
 * Detects content repetition, duplicated FAQs, local-template bleed,
 * target/title mismatch and measured cannibalization before TideOrbit publishes.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Preflight {

	public static function evaluate( $post_id ) {
		$post_id = (int) $post_id;
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'scc_preflight_missing', __( 'The page no longer exists.', 'seo-command-center' ) );
		}

		$text = class_exists( 'SCC_Content_Index' )
			? SCC_Content_Index::get_plain_text( $post )
			: wp_strip_all_tags( (string) $post->post_content );
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		$issues = array();

		$duplicates = self::duplicate_passages( $text );
		if ( count( $duplicates ) >= 2 ) {
			$issues[] = self::issue( 'high', 'duplicate_passages', 'Repeated copy detected', count( $duplicates ) . ' long sentence(s) repeat verbatim.', true );
		} elseif ( 1 === count( $duplicates ) ) {
			$issues[] = self::issue( 'medium', 'duplicate_passage', 'Possible repeated copy', 'A long sentence repeats verbatim. Review the surrounding paragraph before publishing.', false );
		}

		$questions = self::duplicate_questions( $text );
		if ( ! empty( $questions ) ) {
			$issues[] = self::issue( 'high', 'duplicate_faqs', 'Duplicate FAQ questions', implode( '; ', array_slice( $questions, 0, 3 ) ), true );
		}

		$words = str_word_count( $text );
		if ( $words < 150 ) {
			$issues[] = self::issue( 'medium', 'thin_copy', 'Very little visible copy', $words . ' words were found in the page content.', false );
		}

		$target_city = self::target_city( $post_id, $post );
		if ( $target_city ) {
			$business = class_exists( 'SCC_Schema_Engine' ) ? SCC_Schema_Engine::business() : array();
			$areas = array_values( array_unique( array_filter( array_map( 'trim', (array) ( $business['service_areas'] ?? array() ) ) ) ) );
			$target_count = self::phrase_count( $text, $target_city );
			foreach ( $areas as $area ) {
				if ( 0 === strcasecmp( $area, $target_city ) ) { continue; }
				$count = self::phrase_count( $text, $area );
				if ( $count >= 3 && $count >= max( 1, $target_count ) ) {
					$issues[] = self::issue(
						'high',
						'location_bleed',
						'Likely wrong-city template copy',
						sprintf( '%s is the target, but %s appears %d times versus %d target-city mentions.', $target_city, $area, $count, $target_count ),
						true
					);
				} elseif ( $count >= 2 ) {
					$issues[] = self::issue( 'medium', 'location_review', 'Review other-city mentions', sprintf( '%s appears %d times on a %s page.', $area, $count, $target_city ), false );
				}
			}
		}

		$keyword = self::primary_keyword( $post_id );
		$title = get_the_title( $post );
		if ( $keyword && $title ) {
			$overlap = self::token_overlap( $keyword, $title );
			if ( $overlap < 0.34 ) {
				$issues[] = self::issue( 'medium', 'title_intent_mismatch', 'Title may not match the target intent', sprintf( 'Target: "%s" · title: "%s"', $keyword, $title ), false );
			}
		}

		if ( class_exists( 'SCC_GSC_Cannibalization' ) && 'publish' !== (string) $post->post_status ) {
			$url = get_permalink( $post_id );
			if ( $url ) {
				$groups = SCC_GSC_Cannibalization::for_url( $url );
				if ( ! empty( $groups ) ) {
					$worst = $groups[0];
					$issues[] = self::issue(
						'high' === ( $worst['risk'] ?? '' ) ? 'high' : 'medium',
						'gsc_cannibalization',
						'Search Console shows competing URLs',
						sprintf( 'Query "%s" is split across %d URLs.', (string) $worst['query'], count( (array) $worst['pages'] ) ),
						false
					);
				}
			}
		}

		$penalty = array( 'high' => 22, 'medium' => 10, 'low' => 4 );
		$score = 100;
		$can_publish = true;
		foreach ( $issues as $issue ) {
			$score -= $penalty[ $issue['severity'] ] ?? 0;
			if ( ! empty( $issue['blocking'] ) ) { $can_publish = false; }
		}
		$score = max( 0, $score );
		return array(
			'post_id' => $post_id,
			'score' => $score,
			'status' => $can_publish ? ( empty( $issues ) ? 'pass' : 'warn' ) : 'hold',
			'can_publish' => $can_publish,
			'issues' => $issues,
			'target_city' => $target_city,
			'primary_keyword' => $keyword,
			'checked_at' => current_time( 'mysql' ),
		);
	}

	public static function duplicate_passages( $text ) {
		$sentences = preg_split( '/(?<=[.!?])\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$seen = array();
		$dupes = array();
		foreach ( (array) $sentences as $sentence ) {
			if ( str_word_count( $sentence ) < 12 ) { continue; }
			$key = self::normalize_phrase( $sentence );
			if ( strlen( $key ) < 70 ) { continue; }
			if ( isset( $seen[ $key ] ) ) {
				$dupes[ $key ] = trim( $sentence );
			} else {
				$seen[ $key ] = true;
			}
		}
		return array_values( $dupes );
	}

	public static function duplicate_questions( $text ) {
		preg_match_all( '/[^.!?\n]{8,160}\?/u', (string) $text, $m );
		$seen = array();
		$dupes = array();
		foreach ( (array) ( $m[0] ?? array() ) as $q ) {
			$key = self::normalize_phrase( $q );
			if ( strlen( $key ) < 8 ) { continue; }
			if ( isset( $seen[ $key ] ) ) { $dupes[ $key ] = trim( $q ); }
			else { $seen[ $key ] = true; }
		}
		return array_values( $dupes );
	}

	public static function token_overlap( $a, $b ) {
		$ta = self::tokens( $a );
		$tb = self::tokens( $b );
		if ( empty( $ta ) ) { return 1.0; }
		return count( array_intersect( $ta, $tb ) ) / count( $ta );
	}

	protected static function target_city( $post_id, $post ) {
		$bags = array();
		if ( class_exists( 'SCC_Page_Brain' ) ) {
			$brain = SCC_Page_Brain::for_post( $post_id );
			if ( is_array( $brain ) ) { $bags[] = $brain; }
		}
		$brief = get_post_meta( $post_id, '_scc_brief', true );
		if ( is_string( $brief ) ) { $brief = json_decode( $brief, true ); }
		if ( is_array( $brief ) ) { $bags[] = $brief; }
		foreach ( $bags as $bag ) {
			$city = self::find_key( $bag, array( 'city', 'target_city', 'target_location', 'location' ) );
			if ( $city ) { return sanitize_text_field( $city ); }
		}

		$business = class_exists( 'SCC_Schema_Engine' ) ? SCC_Schema_Engine::business() : array();
		foreach ( (array) ( $business['service_areas'] ?? array() ) as $area ) {
			if ( false !== stripos( (string) $post->post_title, (string) $area ) ) { return (string) $area; }
		}
		return '';
	}

	protected static function primary_keyword( $post_id ) {
		global $wpdb;
		if ( ! class_exists( 'SCC_DB' ) ) { return ''; }
		$table = SCC_DB::table( 'content_plan' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT primary_keyword FROM {$table} WHERE post_id = %d LIMIT 1", (int) $post_id ) );
	}

	protected static function find_key( array $bag, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $bag[ $key ] ) && is_scalar( $bag[ $key ] ) && '' !== trim( (string) $bag[ $key ] ) ) {
				return (string) $bag[ $key ];
			}
		}
		foreach ( $bag as $value ) {
			if ( is_array( $value ) ) {
				$found = self::find_key( $value, $keys );
				if ( $found ) { return $found; }
			}
		}
		return '';
	}

	protected static function phrase_count( $text, $phrase ) {
		if ( '' === trim( (string) $phrase ) ) { return 0; }
		return substr_count( strtolower( (string) $text ), strtolower( (string) $phrase ) );
	}

	protected static function tokens( $text ) {
		$text = self::normalize_phrase( $text );
		$out = array();
		foreach ( preg_split( '/\s+/', $text ) as $token ) {
			if ( strlen( $token ) >= 3 && ! in_array( $token, array( 'the','and','for','with','your','our','from','this','that' ), true ) ) {
				$out[ $token ] = true;
			}
		}
		return array_keys( $out );
	}

	protected static function normalize_phrase( $text ) {
		$text = strtolower( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/[^a-z0-9\s]+/i', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	protected static function issue( $severity, $code, $title, $evidence, $blocking ) {
		return array(
			'severity' => $severity,
			'code' => $code,
			'title' => $title,
			'evidence' => $evidence,
			'blocking' => (bool) $blocking,
		);
	}
}
