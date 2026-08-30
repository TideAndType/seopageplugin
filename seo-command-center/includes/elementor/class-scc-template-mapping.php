<?php
/**
 * Template -> content-type mapping store (scc_template_mappings).
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template mapping service.
 */
class SCC_Template_Mapping {

	const CONTENT_TYPES = array( 'article', 'service', 'location', 'landing', 'pillar' );

	/**
	 * All mappings.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = SCC_DB::table( 'template_mappings' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['placeholders'] = json_decode( (string) $row['placeholders'], true ) ?: array();
		}
		return $rows;
	}

	/**
	 * Get the active mapping for a content type.
	 *
	 * @param string $content_type Content type.
	 * @return array|null
	 */
	public static function for_content_type( $content_type ) {
		global $wpdb;
		$table = SCC_DB::table( 'template_mappings' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE content_type = %s AND active = 1 ORDER BY id DESC LIMIT 1", $content_type ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$row['placeholders'] = json_decode( (string) $row['placeholders'], true ) ?: array();
		return $row;
	}

	/**
	 * Create or update a mapping (one active mapping per content type).
	 *
	 * @param array $raw {template_id, template_name, content_type}.
	 * @return int|false Mapping id.
	 */
	public static function save( array $raw ) {
		$template_id  = SCC_Security::sanitize_int( $raw['template_id'] ?? 0, 0, PHP_INT_MAX );
		$content_type = sanitize_key( $raw['content_type'] ?? '' );
		if ( ! $template_id || ! in_array( $content_type, self::CONTENT_TYPES, true ) ) {
			return false;
		}

		$name = SCC_Security::sanitize_text( $raw['template_name'] ?? '' );

		// Detect placeholders present in the template for reference.
		$placeholders = array();
		$data = SCC_Elementor::get_data( $template_id );
		if ( is_array( $data ) ) {
			$placeholders = SCC_Placeholders::detect( $data );
		}

		global $wpdb;
		$table = SCC_DB::table( 'template_mappings' );

		// Deactivate other mappings for the same content type.
		$wpdb->update( $table, array( 'active' => 0 ), array( 'content_type' => $content_type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return SCC_DB::insert(
			'template_mappings',
			array(
				'template_id'   => $template_id,
				'template_name' => $name,
				'content_type'  => $content_type,
				'placeholders'  => wp_json_encode( $placeholders ),
				'active'        => 1,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Delete a mapping.
	 *
	 * @param int $id Mapping id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( SCC_DB::table( 'template_mappings' ), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
