<?php
/**
 * Legacy internal-link API compatibility layer.
 *
 * Both /internal-links/* and /links/* must now use SCC_Link_Engine as their
 * recommendation writer. Keep the old response shape and database reader for
 * clients using the original endpoints, without running a second algorithm.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Link_Recommender {
    /**
     * Run the canonical linking engine via the older endpoint.
     *
     * @param int $limit Maximum indexed pages.
     * @return array {created:int, recommendations:array}
     */
    public function generate( $limit = 300 ) {
        global $wpdb;
        $limit = SCC_Security::sanitize_int( $limit, 1, 500 );
        if ( 0 === SCC_Content_Index::count() ) {
            SCC_Content_Index::reindex_all( $limit );
        }
        $table = SCC_DB::table( 'internal_links' );
        $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'recommended'" ); // phpcs:ignore WordPress.DB
        ( new SCC_Link_Engine() )->scan_site( $limit );
        $after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'recommended'" ); // phpcs:ignore WordPress.DB
        $rows = self::list_recommendations( 'recommended', 200 );
        $summary = array();
        foreach ( $rows as $row ) {
            $summary[] = array(
                'source' => (string) ( $row['source_title'] ?? '' ),
                'target' => (string) ( $row['target_title'] ?? '' ),
                'anchor' => (string) ( $row['anchor'] ?? '' ),
            );
        }
        return array( 'created' => max( 0, $after - $before ), 'recommendations' => $summary );
    }

    /**
     * Legacy response reader. Leave its shape unchanged for existing clients.
     */
	public static function list_recommendations( $status = 'recommended', $limit = 200 ) {
		global $wpdb;
		$table = SCC_DB::table( 'internal_links' );
		$limit = SCC_Security::sanitize_int( $limit, 1, 1000 );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['source_title'] = get_the_title( (int) $row['source_post_id'] );
			$row['target_title'] = get_the_title( (int) $row['target_post_id'] );
			$row['target_url']   = get_permalink( (int) $row['target_post_id'] );
		}
		return $rows;
	}
}
