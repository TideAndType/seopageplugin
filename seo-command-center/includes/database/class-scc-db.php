<?php
/**
 * Database layer: table names, schema, and prepared accessors.
 *
 * All custom-table access goes through this class, which always uses
 * $wpdb->prepare() for anything with a variable.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database helper.
 */
class SCC_DB {

	/**
	 * Fully-qualified table name.
	 *
	 * @param string $name Short table key (e.g. 'analyses').
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'scc_' . $name;
	}

	/**
	 * Create or upgrade all custom tables.
	 *
	 * Uses dbDelta which is safe to call repeatedly.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$analyses        = self::table( 'analyses' );
		$items           = self::table( 'analysis_items' );
		$strategies      = self::table( 'keyword_strategies' );
		$plan            = self::table( 'content_plan' );
		$links           = self::table( 'internal_links' );
		$templates       = self::table( 'template_mappings' );
		$jobs            = self::table( 'jobs' );
		$usage           = self::table( 'api_usage' );
		$logs            = self::table( 'logs' );
		$content_index   = self::table( 'content_index' );
		$change_history  = self::table( 'change_history' );
		$meta_history    = self::table( 'meta_history' );
		$scc_templates   = self::table( 'templates' );

		$sql = array();

		$sql[] = "CREATE TABLE {$analyses} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			status VARCHAR(20) NOT NULL DEFAULT 'complete',
			type VARCHAR(40) NOT NULL DEFAULT 'site',
			summary LONGTEXT NULL,
			totals LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$items} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			analysis_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			url TEXT NULL,
			post_type VARCHAR(40) NULL,
			title TEXT NULL,
			h1 TEXT NULL,
			meta_title TEXT NULL,
			meta_description TEXT NULL,
			word_count INT NOT NULL DEFAULT 0,
			internal_links INT NOT NULL DEFAULT 0,
			external_links INT NOT NULL DEFAULT 0,
			images INT NOT NULL DEFAULT 0,
			images_missing_alt INT NOT NULL DEFAULT 0,
			has_schema TINYINT(1) NOT NULL DEFAULT 0,
			is_elementor TINYINT(1) NOT NULL DEFAULT 0,
			flags LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY analysis_id (analysis_id),
			KEY post_id (post_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$strategies} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			name VARCHAR(200) NULL,
			inputs LONGTEXT NULL,
			topical_map LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$plan} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			title TEXT NULL,
			url TEXT NULL,
			primary_keyword VARCHAR(200) NULL,
			secondary LONGTEXT NULL,
			intent VARCHAR(40) NULL,
			page_type VARCHAR(40) NULL,
			word_count INT NOT NULL DEFAULT 0,
			parent VARCHAR(200) NULL,
			links_to LONGTEXT NULL,
			links_from LONGTEXT NULL,
			cta TEXT NULL,
			schema_type VARCHAR(60) NULL,
			priority VARCHAR(20) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'recommended',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$links} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			anchor TEXT NULL,
			context TEXT NULL,
			direction VARCHAR(20) NOT NULL DEFAULT 'existing',
			confidence INT NOT NULL DEFAULT 0,
			reason TEXT NULL,
			sentence TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'recommended',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY source_post_id (source_post_id),
			KEY target_post_id (target_post_id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$templates} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			template_name VARCHAR(200) NULL,
			content_type VARCHAR(40) NULL,
			placeholders LONGTEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$jobs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(60) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			payload LONGTEXT NULL,
			cursor VARCHAR(200) NULL,
			attempts INT NOT NULL DEFAULT 0,
			max_attempts INT NOT NULL DEFAULT 3,
			scheduled_at DATETIME NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$usage} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			provider VARCHAR(40) NULL,
			model VARCHAR(80) NULL,
			operation VARCHAR(80) NULL,
			input_tokens INT NOT NULL DEFAULT 0,
			output_tokens INT NOT NULL DEFAULT 0,
			cost DECIMAL(10,5) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'ok',
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$content_index} (
			post_id BIGINT UNSIGNED NOT NULL,
			url TEXT NULL,
			post_type VARCHAR(40) NULL,
			title TEXT NULL,
			primary_keyword VARCHAR(200) NULL,
			intent VARCHAR(40) NULL,
			tokens LONGTEXT NULL,
			headings LONGTEXT NULL,
			anchors LONGTEXT NULL,
			outbound LONGTEXT NULL,
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (post_id),
			KEY post_type (post_type)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$change_history} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			change_type VARCHAR(30) NOT NULL DEFAULT '',
			previous_value LONGTEXT NULL,
			new_value LONGTEXT NULL,
			reason TEXT NULL,
			confidence INT NOT NULL DEFAULT 0,
			trigger_source VARCHAR(40) NULL,
			reverted TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY change_type (change_type)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$meta_history} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			field VARCHAR(20) NOT NULL DEFAULT 'title',
			previous_value TEXT NULL,
			new_value TEXT NULL,
			variants LONGTEXT NULL,
			reason TEXT NULL,
			perf_before LONGTEXT NULL,
			perf_after LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY post_id (post_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$scc_templates} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			family VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(200) NULL,
			description TEXT NULL,
			content_type VARCHAR(40) NULL,
			template_type VARCHAR(40) NULL,
			structure LONGTEXT NULL,
			renderer VARCHAR(40) NOT NULL DEFAULT 'gutenberg',
			elementor_source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			version INT NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			modified_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY family (family),
			KEY content_type (content_type),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$logs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			source VARCHAR(60) NULL,
			message TEXT NULL,
			context LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$actions = self::table( 'seo_actions' );
		$sql[] = "CREATE TABLE {$actions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			opportunity_id VARCHAR(40) NOT NULL DEFAULT '',
			type VARCHAR(60) NOT NULL DEFAULT 'review',
			title TEXT NULL,
			target LONGTEXT NULL,
			score INT NOT NULL DEFAULT 0,
			confidence INT NOT NULL DEFAULT 0,
			priority VARCHAR(10) NOT NULL DEFAULT 'medium',
			reason TEXT NULL,
			expected_impact VARCHAR(10) NULL,
			effort VARCHAR(10) NULL,
			risk VARCHAR(10) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			source VARCHAR(40) NULL,
			payload LONGTEXT NULL,
			result LONGTEXT NULL,
			snoozed_until DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY opportunity_id (opportunity_id),
			KEY status (status),
			KEY score (score)
		) {$charset_collate};";

		$snapshots = self::table( 'seo_snapshots' );
		$sql[] = "CREATE TABLE {$snapshots} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			captured_on DATE NOT NULL DEFAULT '0000-00-00',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			health_score INT NOT NULL DEFAULT 0,
			clicks INT NOT NULL DEFAULT 0,
			impressions BIGINT NOT NULL DEFAULT 0,
			avg_position FLOAT NOT NULL DEFAULT 0,
			opportunities_open INT NOT NULL DEFAULT 0,
			actions_completed INT NOT NULL DEFAULT 0,
			components LONGTEXT NULL,
			meta LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY captured_on (captured_on)
		) {$charset_collate};";

		$experiments = self::table( 'seo_experiments' );
		$sql[] = "CREATE TABLE {$experiments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			url TEXT NULL,
			title TEXT NULL,
			change_type VARCHAR(60) NOT NULL DEFAULT '',
			note TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			baseline LONGTEXT NULL,
			result LONGTEXT NULL,
			measure_days INT NOT NULL DEFAULT 28,
			start_date DATE NOT NULL DEFAULT '0000-00-00',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Drop all custom tables (used only on uninstall when opted in).
	 */
	public static function drop() {
		global $wpdb;
		$tables = array(
			'analyses',
			'analysis_items',
			'keyword_strategies',
			'content_plan',
			'internal_links',
			'template_mappings',
			'jobs',
			'api_usage',
			'logs',
			'content_index',
			'change_history',
			'meta_history',
			'templates',
			'seo_actions',
			'seo_snapshots',
			'seo_experiments',
		);
		foreach ( $tables as $t ) {
			$name = self::table( $t );
			// Table name cannot be parameterized; it is built from a fixed whitelist above.
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Insert a row.
	 *
	 * @param string $table   Short table key.
	 * @param array  $data    Column => value.
	 * @param array  $formats Optional printf-style formats.
	 * @return int|false Insert id or false.
	 */
	public static function insert( $table, array $data, array $formats = array() ) {
		global $wpdb;
		$ok = $wpdb->insert( self::table( $table ), $data, $formats ? $formats : null ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update rows.
	 *
	 * @param string $table Short table key.
	 * @param array  $data  Column => value.
	 * @param array  $where Column => value.
	 * @return int|false Rows affected or false.
	 */
	public static function update( $table, array $data, array $where ) {
		global $wpdb;
		return $wpdb->update( self::table( $table ), $data, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Get a single row by id as an associative array.
	 *
	 * @param string $table Short table key.
	 * @param int    $id    Row id.
	 * @return array|null
	 */
	public static function get( $table, $id ) {
		global $wpdb;
		$name = self::table( $table );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$name} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
