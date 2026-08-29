<?php
/**
 * Uninstall handler.
 *
 * Only removes data if the user explicitly opted in via
 * settings ("remove_data_on_uninstall"). Default keeps everything.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$scc_settings = get_option( 'scc_settings' );
$remove       = is_array( $scc_settings ) && ! empty( $scc_settings['remove_data_on_uninstall'] );

if ( ! $remove ) {
	return;
}

// Load the DB helper to reuse the table whitelist.
require_once plugin_dir_path( __FILE__ ) . 'includes/database/class-scc-db.php';

if ( class_exists( 'SCC_DB' ) ) {
	SCC_DB::drop();
}

delete_option( 'scc_settings' );
delete_option( 'scc_credentials' );
delete_option( 'scc_db_version' );
