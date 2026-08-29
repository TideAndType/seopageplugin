<?php
/**
 * Content plan store (scc_content_plan).
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content plan service.
 */
class SCC_Content_Plan {

	const STATUSES  = array( 'recommended', 'approved', 'generating', 'draft', 'review', 'published', 'needs_update' );
	const PRIORITIES = array( 'high', 'medium', 'low' );

	/**
	 * List plan entries, optionally filtered by status.
	 *
	 * @param string $status Optional status filter.
	 * @return array
	 */
	public static function all( $status = '' ) {
		global $wpdb;
		$table = SCC_DB::table( 'content_plan' );
		if ( $status && in_array( $status, self::STATUSES, true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY FIELD(priority,'high','medium','low'), id DESC", $status ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY FIELD(priority,'high','medium','low'), id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return $rows ? array_map( array( __CLASS__, 'decode' ), $rows ) : array();
	}

	/**
	 * Decode JSON columns on a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	protected static function decode( array $row ) {
		$row['secondary']  = json_decode( (string) $row['secondary'], true ) ?: array();
		$row['links_to']   = json_decode( (string) $row['links_to'], true ) ?: array();
		$row['links_from'] = json_decode( (string) $row['links_from'], true ) ?: array();
		return $row;
	}

	/**
	 * Fetch a single decoded entry by id.
	 *
	 * @param int $id Entry id.
	 * @return array|null
	 */
	public static function find( $id ) {
		$row = SCC_DB::get( 'content_plan', $id );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Sanitize an incoming plan entry.
	 *
	 * @param array $raw Raw entry.
	 * @return array
	 */
	public static function sanitize( array $raw ) {
		$list = function ( $value ) {
			if ( ! is_array( $value ) ) {
				$value = preg_split( '/[\r\n,]+/', (string) $value );
			}
			return array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', (array) $value ) ) ) );
		};

		$status   = strtolower( (string) ( $raw['status'] ?? 'recommended' ) );
		$priority = strtolower( (string) ( $raw['priority'] ?? 'medium' ) );

		return array(
			'title'           => SCC_Security::sanitize_text( $raw['title'] ?? '' ),
			'url'             => SCC_Security::sanitize_text( $raw['url'] ?? '' ),
			'primary_keyword' => SCC_Security::sanitize_text( $raw['primary_keyword'] ?? '' ),
			'secondary'       => wp_json_encode( $list( $raw['secondary'] ?? array() ) ),
			'intent'          => SCC_Security::sanitize_text( $raw['intent'] ?? '' ),
			'page_type'       => SCC_Security::sanitize_text( $raw['page_type'] ?? '' ),
			'word_count'      => SCC_Security::sanitize_int( $raw['word_count'] ?? 0, 0, 20000 ),
			'parent'          => SCC_Security::sanitize_text( $raw['parent'] ?? '' ),
			'links_to'        => wp_json_encode( $list( $raw['links_to'] ?? array() ) ),
			'links_from'      => wp_json_encode( $list( $raw['links_from'] ?? array() ) ),
			'cta'             => SCC_Security::sanitize_textarea( $raw['cta'] ?? '' ),
			'schema_type'     => SCC_Security::sanitize_text( $raw['schema_type'] ?? '' ),
			'priority'        => in_array( $priority, self::PRIORITIES, true ) ? $priority : 'medium',
			'status'          => in_array( $status, self::STATUSES, true ) ? $status : 'recommended',
		);
	}

	/**
	 * Create a plan entry.
	 *
	 * @param array $raw Raw entry.
	 * @return int|false
	 */
	public static function create( array $raw ) {
		$data = self::sanitize( $raw );
		$data['created_at'] = current_time( 'mysql' );
		return SCC_DB::insert( 'content_plan', $data );
	}

	/**
	 * Update a plan entry's status (safe subset update).
	 *
	 * @param int    $id     Entry id.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		$status = strtolower( (string) $status );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		return false !== SCC_DB::update( 'content_plan', array( 'status' => $status ), array( 'id' => (int) $id ) );
	}

	/**
	 * Delete a plan entry.
	 *
	 * @param int $id Entry id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( SCC_DB::table( 'content_plan' ), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Seed the plan from an architecture tree (only entries that don't exist).
	 *
	 * @param array $tree Architecture tree from SCC_Architecture::build().
	 * @return int Number of entries created.
	 */
	public static function seed_from_architecture( array $tree ) {
		$created = 0;
		$default_words = (int) SCC_Settings::get( 'default_word_count', 1200 );

		foreach ( (array) ( $tree['pillars'] ?? array() ) as $pillar ) {
			$created += self::maybe_add_node( $pillar, '', $default_words );
			foreach ( (array) ( $pillar['children'] ?? array() ) as $child ) {
				$created += self::maybe_add_node( $child, $pillar['title'], $default_words );
			}
			foreach ( (array) ( $pillar['articles'] ?? array() ) as $article ) {
				$created += self::maybe_add_node( $article, $pillar['title'], max( 800, $default_words ) );
			}
		}
		return $created;
	}

	/**
	 * Add a single architecture node to the plan if it doesn't already exist.
	 *
	 * @param array  $node   Node.
	 * @param string $parent Parent title.
	 * @param int    $words  Word count.
	 * @return int 1 if created, 0 otherwise.
	 */
	protected static function maybe_add_node( array $node, $parent, $words ) {
		if ( ! empty( $node['exists'] ) ) {
			return 0; // Don't re-recommend pages that already exist.
		}
		if ( self::url_in_plan( $node['url'] ?? '' ) ) {
			return 0;
		}
		$schema = 'article' === ( $node['page_type'] ?? '' ) ? 'BlogPosting' : 'Service';
		$id = self::create(
			array(
				'title'           => $node['title'] ?? '',
				'url'             => $node['url'] ?? '',
				'primary_keyword' => $node['primary_keyword'] ?? '',
				'secondary'       => $node['related'] ?? array(),
				'intent'          => $node['intent'] ?? '',
				'page_type'       => $node['page_type'] ?? 'service',
				'word_count'      => $words,
				'parent'          => $parent,
				'schema_type'     => $schema,
				'priority'        => 'medium',
				'status'          => 'recommended',
			)
		);
		return $id ? 1 : 0;
	}

	/**
	 * Whether a URL is already present in the plan.
	 *
	 * @param string $url URL/path.
	 * @return bool
	 */
	protected static function url_in_plan( $url ) {
		global $wpdb;
		if ( '' === $url ) {
			return false;
		}
		$table = SCC_DB::table( 'content_plan' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE url = %s", $url ) ); // phpcs:ignore WordPress.DB
		return $count > 0;
	}
}
