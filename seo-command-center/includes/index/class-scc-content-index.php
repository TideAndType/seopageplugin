<?php
/**
 * Searchable content index.
 *
 * A cached, per-post representation of the site's content (tokens, headings,
 * existing anchors, outbound links, primary keyword, intent) so link and
 * relevance analysis never has to crawl the whole site on every request. Built
 * incrementally on save_post and refreshable in the background.
 *
 * Relevance scoring is contextual, not exact-keyword: it compares full-content
 * term-frequency vectors (body + headings), weighted by title/keyword overlap,
 * intent compatibility, and URL proximity.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content index.
 */
class SCC_Content_Index {

	const STOP_WORDS = array(
		'the', 'a', 'an', 'and', 'or', 'for', 'of', 'in', 'to', 'with', 'on', 'at', 'by', 'is', 'are',
		'be', 'this', 'that', 'these', 'those', 'it', 'its', 'as', 'we', 'our', 'you', 'your', 'they',
		'their', 'from', 'about', 'into', 'more', 'can', 'will', 'has', 'have', 'was', 'were', 'not',
		'but', 'if', 'so', 'than', 'then', 'them', 'how', 'what', 'why', 'when', 'where', 'which', 'who',
		'all', 'any', 'some', 'each', 'also', 'get', 'out', 'up', 'do', 'does', 'help', 'best', 'top',
	);

	const MAX_TOKENS = 150;

	/**
	 * Extract readable plain text from a post, including Elementor content.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function get_plain_text( $post ) {
		$content = (string) $post->post_content;

		if ( class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_elementor_post( $post->ID ) ) {
			$data = SCC_Elementor::get_data( $post->ID );
			if ( is_array( $data ) ) {
				$content .= ' ' . self::collect_elementor_text( $data );
			}
		}

		return trim( wp_strip_all_tags( $content ) );
	}

	/**
	 * Recursively collect text from Elementor elements.
	 *
	 * @param array $elements Elements.
	 * @return string
	 */
	protected static function collect_elementor_text( array $elements ) {
		$buffer = '';
		foreach ( $elements as $el ) {
			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
				foreach ( $el['settings'] as $key => $value ) {
					if ( is_string( $value ) && in_array( $key, array( 'title', 'editor', 'text', 'description', 'heading_title', 'title_text', 'description_text' ), true ) ) {
						$buffer .= ' ' . wp_strip_all_tags( $value );
					}
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$buffer .= ' ' . self::collect_elementor_text( $el['elements'] );
			}
		}
		return $buffer;
	}

	/**
	 * Tokenize text into a term-frequency map (significant terms only).
	 *
	 * @param string $text Text.
	 * @return array<string,int>
	 */
	public static function tokenize( $text ) {
		$text  = strtolower( wp_strip_all_tags( (string) $text ) );
		$text  = preg_replace( '/[^a-z0-9 ]+/', ' ', $text );
		$words = array_filter( explode( ' ', $text ) );
		$freq  = array();
		foreach ( $words as $w ) {
			if ( strlen( $w ) < 3 || in_array( $w, self::STOP_WORDS, true ) ) {
				continue;
			}
			$freq[ $w ] = isset( $freq[ $w ] ) ? $freq[ $w ] + 1 : 1;
		}
		arsort( $freq );
		return array_slice( $freq, 0, self::MAX_TOKENS, true );
	}

	/**
	 * Extract heading strings from post HTML.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	protected static function extract_headings( $post ) {
		$headings = array();
		if ( '' === trim( (string) $post->post_content ) ) {
			return $headings;
		}
		if ( preg_match_all( '/<h[1-4][^>]*>(.*?)<\/h[1-4]>/is', $post->post_content, $m ) ) {
			foreach ( $m[1] as $h ) {
				$h = trim( wp_strip_all_tags( $h ) );
				if ( '' !== $h ) {
					$headings[] = $h;
				}
			}
		}
		return array_slice( $headings, 0, 40 );
	}

	/**
	 * Extract existing outbound internal anchors and target ids from a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array {anchors:string[], outbound:int[]}
	 */
	protected static function extract_links( $post ) {
		$anchors  = array();
		$outbound = array();
		if ( '' === trim( (string) $post->post_content ) ) {
			return array( 'anchors' => $anchors, 'outbound' => $outbound );
		}
		if ( preg_match_all( '/<a\s[^>]*href=("|\')(.*?)\1[^>]*>(.*?)<\/a>/is', $post->post_content, $m, PREG_SET_ORDER ) ) {
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			foreach ( $m as $match ) {
				$href   = $match[2];
				$anchor = trim( wp_strip_all_tags( $match[3] ) );
				if ( 0 === strpos( $href, '#' ) ) {
					continue;
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $host || $host === $home_host || 0 === strpos( $href, '/' ) ) {
					if ( '' !== $anchor ) {
						$anchors[] = $anchor;
					}
					$pid = url_to_postid( 0 === strpos( $href, '/' ) ? home_url( $href ) : $href );
					if ( $pid ) {
						$outbound[] = (int) $pid;
					}
				}
			}
		}
		return array( 'anchors' => $anchors, 'outbound' => array_values( array_unique( $outbound ) ) );
	}

	/**
	 * (Re)index a single post.
	 *
	 * @param int|WP_Post $post Post or id.
	 * @return bool
	 */
	public static function index_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		// Only index public, non-trashed content types.
		$types = SCC_Analyzer::analyzable_post_types();
		if ( ! in_array( $post->post_type, $types, true ) || 'trash' === $post->post_status ) {
			self::remove( $post->ID );
			return false;
		}

		$text     = self::get_plain_text( $post );
		$tokens   = self::tokenize( $post->post_title . ' ' . $text );
		$headings = self::extract_headings( $post );
		$links    = self::extract_links( $post );

		// Primary keyword + intent from the content plan, if present.
		$plan = self::plan_meta( $post->ID );

		global $wpdb;
		$table = SCC_DB::table( 'content_index' );
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'post_id'         => (int) $post->ID,
				'url'             => get_permalink( $post ),
				'post_type'       => $post->post_type,
				'title'           => get_the_title( $post ),
				'primary_keyword' => $plan['primary_keyword'],
				'intent'          => $plan['intent'],
				'tokens'          => wp_json_encode( $tokens ),
				'headings'        => wp_json_encode( $headings ),
				'anchors'         => wp_json_encode( $links['anchors'] ),
				'outbound'        => wp_json_encode( $links['outbound'] ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return true;
	}

	/**
	 * Remove a post from the index.
	 *
	 * @param int $post_id Post id.
	 */
	public static function remove( $post_id ) {
		global $wpdb;
		$wpdb->delete( SCC_DB::table( 'content_index' ), array( 'post_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Look up content-plan keyword/intent for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array {primary_keyword, intent}
	 */
	protected static function plan_meta( $post_id ) {
		global $wpdb;
		$table = SCC_DB::table( 'content_plan' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT primary_keyword, intent FROM {$table} WHERE post_id = %d LIMIT 1", (int) $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'primary_keyword' => $row ? (string) $row['primary_keyword'] : '',
			'intent'          => $row ? (string) $row['intent'] : '',
		);
	}

	/**
	 * Rebuild the full index (bounded).
	 *
	 * @param int $limit Max posts.
	 * @return int Number indexed.
	 */
	public static function reindex_all( $limit = 1000 ) {
		$limit = SCC_Security::sanitize_int( $limit, 1, 10000 );
		$query = new WP_Query(
			array(
				'post_type'      => SCC_Analyzer::analyzable_post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		$count = 0;
		foreach ( $query->posts as $pid ) {
			if ( self::index_post( $pid ) ) {
				$count++;
			}
		}
		SCC_Logger::info( 'content-index', 'Reindex complete', array( 'indexed' => $count ) );
		return $count;
	}

	/**
	 * Decode a stored row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	protected static function decode( array $row ) {
		$row['tokens']   = json_decode( (string) $row['tokens'], true ) ?: array();
		$row['headings'] = json_decode( (string) $row['headings'], true ) ?: array();
		$row['anchors']  = json_decode( (string) $row['anchors'], true ) ?: array();
		$row['outbound'] = json_decode( (string) $row['outbound'], true ) ?: array();
		return $row;
	}

	/**
	 * Get one indexed row.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function get( $post_id ) {
		global $wpdb;
		$table = SCC_DB::table( 'content_index' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", (int) $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * All indexed rows (bounded).
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function all( $limit = 2000 ) {
		global $wpdb;
		$table = SCC_DB::table( 'content_index' );
		$limit = SCC_Security::sanitize_int( $limit, 1, 10000 );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows ? array_map( array( __CLASS__, 'decode' ), $rows ) : array();
	}

	/**
	 * Count indexed rows.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = SCC_DB::table( 'content_index' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Contextual relevance score (0-100) between two indexed rows.
	 *
	 * Combines cosine similarity of term-frequency vectors (contextual overlap),
	 * title-token overlap, shared primary keyword, intent compatibility, and URL
	 * path proximity — deliberately more than exact-keyword matching.
	 *
	 * @param array $a Source index row (decoded).
	 * @param array $b Target index row (decoded).
	 * @return float
	 */
	public static function relevance( array $a, array $b ) {
		$cosine = self::cosine( $a['tokens'] ?? array(), $b['tokens'] ?? array() ); // 0..1

		// Title token overlap (Jaccard) — strong topical signal.
		$ta = self::title_tokens( $a['title'] ?? '' );
		$tb = self::title_tokens( $b['title'] ?? '' );
		$title_overlap = self::jaccard( $ta, $tb ); // 0..1

		// Shared primary keyword tokens.
		$kw_bonus = 0;
		$kwb = strtolower( (string) ( $b['primary_keyword'] ?? '' ) );
		if ( $kwb && ! empty( $a['tokens'] ) ) {
			foreach ( array_filter( explode( ' ', preg_replace( '/[^a-z0-9 ]+/', ' ', $kwb ) ) ) as $w ) {
				if ( strlen( $w ) >= 3 && isset( $a['tokens'][ $w ] ) ) {
					$kw_bonus = 0.15;
					break;
				}
			}
		}

		// Intent compatibility (informational should link to commercial, etc.).
		$intent_bonus = self::intent_bonus( $a['intent'] ?? '', $b['intent'] ?? '' );

		// URL path proximity (shared first path segment).
		$url_bonus = self::url_proximity( $a['url'] ?? '', $b['url'] ?? '' );

		$score = ( 0.55 * $cosine ) + ( 0.25 * $title_overlap ) + $kw_bonus + $intent_bonus + $url_bonus;
		return round( min( 1, $score ) * 100, 1 );
	}

	/**
	 * Cosine similarity of two term-frequency maps.
	 *
	 * @param array $a Map A.
	 * @param array $b Map B.
	 * @return float 0..1
	 */
	protected static function cosine( array $a, array $b ) {
		if ( empty( $a ) || empty( $b ) ) {
			return 0.0;
		}
		$dot = 0.0;
		foreach ( $a as $term => $wa ) {
			if ( isset( $b[ $term ] ) ) {
				$dot += $wa * $b[ $term ];
			}
		}
		$na = sqrt( array_sum( array_map( function ( $v ) { return $v * $v; }, $a ) ) );
		$nb = sqrt( array_sum( array_map( function ( $v ) { return $v * $v; }, $b ) ) );
		if ( $na <= 0 || $nb <= 0 ) {
			return 0.0;
		}
		return $dot / ( $na * $nb );
	}

	/**
	 * Title tokens.
	 *
	 * @param string $title Title.
	 * @return string[]
	 */
	protected static function title_tokens( $title ) {
		return array_keys( self::tokenize( $title ) );
	}

	/**
	 * Jaccard of two token lists.
	 *
	 * @param array $a A.
	 * @param array $b B.
	 * @return float
	 */
	protected static function jaccard( array $a, array $b ) {
		if ( empty( $a ) || empty( $b ) ) {
			return 0.0;
		}
		$i = count( array_intersect( $a, $b ) );
		$u = count( array_unique( array_merge( $a, $b ) ) );
		return $u > 0 ? $i / $u : 0.0;
	}

	/**
	 * Intent-compatibility bonus.
	 *
	 * @param string $a Source intent.
	 * @param string $b Target intent.
	 * @return float
	 */
	protected static function intent_bonus( $a, $b ) {
		$a = strtolower( $a );
		$b = strtolower( $b );
		if ( '' === $a || '' === $b ) {
			return 0.0;
		}
		// Informational content linking to commercial/local pages is ideal.
		if ( 'informational' === $a && in_array( $b, array( 'commercial', 'transactional', 'local' ), true ) ) {
			return 0.1;
		}
		return ( $a === $b ) ? 0.03 : 0.0;
	}

	/**
	 * URL path proximity bonus.
	 *
	 * @param string $a URL A.
	 * @param string $b URL B.
	 * @return float
	 */
	protected static function url_proximity( $a, $b ) {
		$sa = self::first_segment( $a );
		$sb = self::first_segment( $b );
		return ( $sa && $sa === $sb ) ? 0.05 : 0.0;
	}

	/**
	 * First path segment of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected static function first_segment( $url ) {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return '';
		}
		$parts = explode( '/', $path );
		return $parts[0];
	}
}
