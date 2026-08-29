<?php
/**
 * Generate Content view.
 *
 * @package SEO_Command_Center
 * @var array $data View data (entries, auto_publish).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$entries      = isset( $data['entries'] ) ? $data['entries'] : array();
$auto_publish = ! empty( $data['auto_publish'] );
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Generate Content', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Review a content brief, then generate a draft. Everything is saved as a WordPress draft for your review.', 'seo-command-center' ); ?></p>
	</div>

	<?php if ( $auto_publish ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Automatic publishing is ON. Generated content will be published immediately. Turn it off in Settings to keep drafts for review.', 'seo-command-center' ); ?></p></div>
	<?php else : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Generated content is saved as a draft. You review and publish it yourself.', 'seo-command-center' ); ?></p></div>
	<?php endif; ?>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Content ready to generate', 'seo-command-center' ); ?></h2>
		<?php if ( empty( $entries ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'Nothing to generate yet. Approve entries in your Content Plan first.', 'seo-command-center' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-content-plan' ) ); ?>"><?php esc_html_e( 'Go to Content Plan', 'seo-command-center' ); ?></a>
		<?php else : ?>
			<table class="widefat striped scc-table" id="scc-generate-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Primary keyword', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Status', 'seo-command-center' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $e ) : ?>
						<tr data-id="<?php echo esc_attr( $e['id'] ); ?>">
							<td><?php echo esc_html( $e['title'] ); ?></td>
							<td><?php echo esc_html( $e['page_type'] ); ?></td>
							<td><?php echo esc_html( $e['primary_keyword'] ); ?></td>
							<td class="scc-gen-status"><?php echo esc_html( str_replace( '_', ' ', $e['status'] ) ); ?></td>
							<td>
								<button class="button scc-brief-btn"><?php esc_html_e( 'Preview brief', 'seo-command-center' ); ?></button>
								<button class="button button-primary scc-generate-btn"><?php esc_html_e( 'Generate draft', 'seo-command-center' ); ?></button>
							</td>
						</tr>
						<tr class="scc-brief-row" hidden>
							<td colspan="5"><div class="scc-brief-panel"></div></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<span class="scc-inline-status" id="scc-generate-msg"></span>
		<?php endif; ?>
	</div>
</div>
