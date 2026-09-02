<?php
/**
 * Meta Editor — bulk-edit meta titles and descriptions from the admin.
 *
 * @package SEO_Command_Center
 * @var array $data View data ($data['data'] = initial list_pages result).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$init      = isset( $data['data'] ) ? (array) $data['data'] : array();
$seo_plugin = (string) ( $init['seo_plugin'] ?? '' );
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Meta Editor', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Edit the meta title and description for every page in one place — no need to open each page. Changes are saved to your active SEO plugin and can be reverted from history.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-context-bar">
		<span class="scc-chip"><?php esc_html_e( 'Writing to:', 'seo-command-center' ); ?> <strong><?php echo esc_html( $seo_plugin ? $seo_plugin : __( 'SEO Command Center', 'seo-command-center' ) ); ?></strong></span>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Pages', 'seo-command-center' ); ?></h2>
			<div>
				<button class="button" id="scc-meta-suggest-all"><?php esc_html_e( '✨ Suggest for all visible', 'seo-command-center' ); ?></button>
				<input type="search" id="scc-meta-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search pages…', 'seo-command-center' ); ?>">
			</div>
		</div>
		<span class="scc-inline-status" id="scc-meta-msg"></span>
		<div id="scc-meta-list" data-paged="1"></div>
		<div class="scc-meta-pager">
			<button class="button" id="scc-meta-prev" disabled><?php esc_html_e( '← Previous', 'seo-command-center' ); ?></button>
			<span id="scc-meta-pageinfo" class="scc-note"></span>
			<button class="button" id="scc-meta-next" disabled><?php esc_html_e( 'Next →', 'seo-command-center' ); ?></button>
		</div>
	</div>
</div>
