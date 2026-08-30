<?php
/**
 * Template store (scc_templates): CRUD, versioning, cloning.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template store.
 */
class SCC_Template_Store {

	/**
	 * Sanitize an incoming template payload.
	 *
	 * @param array $raw Raw.
	 * @return array
	 */
	protected static function sanitize( array $raw ) {
		$type = sanitize_key( $raw['content_type'] ?? 'custom' );
		if ( ! in_array( $type, SCC_Template::TYPES, true ) ) {
			$type = 'custom';
		}
		$renderer = sanitize_key( $raw['renderer'] ?? '' );

		// Structure: accept array or JSON string; default to the type default.
		$structure = isset( $raw['structure'] ) ? $raw['structure'] : null;
		if ( is_string( $structure ) ) {
			$structure = json_decode( $structure, true );
		}
		if ( ! is_array( $structure ) || empty( $structure['sections'] ) ) {
			$structure = SCC_Template::default_structure( $type );
		}
		$structure = self::sanitize_structure( $structure );

		return array(
			'name'                => SCC_Security::sanitize_text( $raw['name'] ?? __( 'Untitled template', 'seo-command-center' ) ),
			'description'         => SCC_Security::sanitize_textarea( $raw['description'] ?? '' ),
			'content_type'        => $type,
			'template_type'       => $type,
			'structure'           => wp_json_encode( $structure ),
			'renderer'            => $renderer,
			'elementor_source_id' => SCC_Security::sanitize_int( $raw['elementor_source_id'] ?? 0, 0, PHP_INT_MAX ),
			'status'              => in_array( ( $raw['status'] ?? 'active' ), array( 'active', 'draft', 'archived' ), true ) ? $raw['status'] : 'active',
		);
	}

	/**
	 * Sanitize a structure tree.
	 *
	 * @param array $structure Structure.
	 * @return array
	 */
	protected static function sanitize_structure( array $structure ) {
		$sections = array();
		foreach ( (array) ( $structure['sections'] ?? array() ) as $section ) {
			$fields = array();
			foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
				$fields[] = array(
					'key'      => sanitize_key( $field['key'] ?? '' ),
					'label'    => SCC_Security::sanitize_text( $field['label'] ?? '' ),
					'type'     => in_array( ( $field['type'] ?? 'richtext' ), array( 'text', 'richtext', 'list', 'faq', 'image' ), true ) ? $field['type'] : 'richtext',
					'required' => SCC_Security::sanitize_bool( $field['required'] ?? false ),
					'variable' => preg_replace( '/[^A-Z0-9_]/', '', strtoupper( (string) ( $field['variable'] ?? '' ) ) ),
					'default'  => SCC_Security::sanitize_textarea( $field['default'] ?? '' ),
				);
			}
			$sections[] = array(
				'key'    => sanitize_key( $section['key'] ?? '' ),
				'label'  => SCC_Security::sanitize_text( $section['label'] ?? '' ),
				'fields' => $fields,
			);
		}
		return array( 'sections' => $sections );
	}

	/**
	 * Create a brand-new template (new family, version 1).
	 *
	 * @param array $raw Raw payload.
	 * @return int|false Template id.
	 */
	public static function create( array $raw ) {
		$data           = self::sanitize( $raw );
		$data['family'] = self::new_family( $data['name'] );
		$data['version'] = 1;
		$data['created_at']  = current_time( 'mysql' );
		$data['modified_at'] = current_time( 'mysql' );
		return SCC_DB::insert( 'templates', $data );
	}

	/**
	 * Update a template as a NEW VERSION (old versions archived).
	 * Existing generated pages are unaffected (they are plain WP content).
	 *
	 * @param int   $id  Existing template id (any version of the family).
	 * @param array $raw Raw payload.
	 * @return int|false New version id.
	 */
	public static function update_as_new_version( $id, array $raw ) {
		$existing = self::get_row( $id );
		if ( ! $existing ) {
			return false;
		}
		$family = $existing['family'];
		$next   = self::latest_version( $family ) + 1;

		// Archive current active version(s) of this family.
		global $wpdb;
		$wpdb->update( SCC_DB::table( 'templates' ), array( 'status' => 'archived' ), array( 'family' => $family, 'status' => 'active' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$data            = self::sanitize( $raw );
		$data['family']  = $family;
		$data['version'] = $next;
		$data['status']  = 'active';
		$data['created_at']  = $existing['created_at'];
		$data['modified_at'] = current_time( 'mysql' );
		return SCC_DB::insert( 'templates', $data );
	}

	/**
	 * Clone a template family into a new family.
	 *
	 * @param int    $id       Source template id.
	 * @param string $new_name New name.
	 * @return int|false New template id.
	 */
	public static function clone_template( $id, $new_name = '' ) {
		$row = self::get_row( $id );
		if ( ! $row ) {
			return false;
		}
		$raw = $row;
		$raw['name'] = $new_name ? $new_name : ( $row['name'] . ' — ' . __( 'Copy', 'seo-command-center' ) );
		return self::create( $raw ); // create() assigns a fresh family + version 1.
	}

	/**
	 * Delete a single template row.
	 *
	 * @param int $id Template id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( SCC_DB::table( 'templates' ), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Get a raw row by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get_row( $id ) {
		return SCC_DB::get( 'templates', $id );
	}

	/**
	 * Get a decoded SCC_Template by id.
	 *
	 * @param int $id Id.
	 * @return SCC_Template|null
	 */
	public static function get( $id ) {
		$row = self::get_row( $id );
		return $row ? new SCC_Template( self::decode( $row ) ) : null;
	}

	/**
	 * Active template for a family.
	 *
	 * @param string $family Family.
	 * @return SCC_Template|null
	 */
	public static function active_for_family( $family ) {
		global $wpdb;
		$table = SCC_DB::table( 'templates' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE family = %s AND status = 'active' ORDER BY version DESC LIMIT 1", $family ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? new SCC_Template( self::decode( $row ) ) : null;
	}

	/**
	 * Active template mapped to a content type (most recent).
	 *
	 * @param string $content_type Content type.
	 * @return SCC_Template|null
	 */
	public static function active_for_content_type( $content_type ) {
		global $wpdb;
		$table = SCC_DB::table( 'templates' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE content_type = %s AND status = 'active' ORDER BY id DESC LIMIT 1", $content_type ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? new SCC_Template( self::decode( $row ) ) : null;
	}

	/**
	 * List active templates (latest version per family).
	 *
	 * @return array
	 */
	public static function all_active() {
		global $wpdb;
		$table = SCC_DB::table( 'templates' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows ? array_map( array( __CLASS__, 'decode' ), $rows ) : array();
	}

	/**
	 * Version history for a family.
	 *
	 * @param string $family Family.
	 * @return array
	 */
	public static function versions( $family ) {
		global $wpdb;
		$table = SCC_DB::table( 'templates' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE family = %s ORDER BY version DESC", $family ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows ? array_map( array( __CLASS__, 'decode' ), $rows ) : array();
	}

	/**
	 * Latest version number for a family.
	 *
	 * @param string $family Family.
	 * @return int
	 */
	protected static function latest_version( $family ) {
		global $wpdb;
		$table = SCC_DB::table( 'templates' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$table} WHERE family = %s", $family ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Generate a unique family slug.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	protected static function new_family( $name ) {
		$base = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'template';
		}
		return $base . '-' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 6 );
	}

	/**
	 * Decode JSON columns on a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function decode( array $row ) {
		$row['structure'] = json_decode( (string) $row['structure'], true ) ?: array( 'sections' => array() );
		return $row;
	}
}
