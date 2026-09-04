<?php
/**
 * Content Plan view.
 *
 * @package SEO_Command_Center
 * @var array $data View data (entries, statuses).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$entries  = isset( $data['entries'] ) ? $data['entries'] : array();
$statuses = isset( $data['statuses'] ) ? $data['statuses'] : array();
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Content Plan', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Your recommended pages and articles. Click “Brief” to see an outline, or “Generate draft” to create it — every page is saved as a WordPress draft for your review. Nothing is published automatically.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card">
		<?php if ( empty( $entries ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No content plan entries yet. Build a keyword strategy and send its architecture here from the Site Architecture page.', 'seo-command-center' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-architecture' ) ); ?>"><?php esc_html_e( 'Go to Site Architecture', 'seo-command-center' ); ?></a>
		<?php else : ?>
			<table class="widefat striped scc-table" id="scc-plan-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'URL', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Primary keyword', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Intent', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Words', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Priority', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Status', 'seo-command-center' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $e ) : ?>
						<tr data-id="<?php echo esc_attr( $e['id'] ); ?>">
							<td><?php echo esc_html( $e['title'] ); ?></td>
							<td><code><?php echo esc_html( $e['url'] ); ?></code></td>
							<td><?php echo esc_html( $e['primary_keyword'] ); ?></td>
							<td><?php echo esc_html( $e['page_type'] ); ?></td>
							<td><?php echo esc_html( $e['intent'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $e['word_count'] ) ); ?></td>
							<td><?php echo esc_html( $e['priority'] ); ?></td>
							<td>
								<select class="scc-plan-status">
									<?php foreach ( $statuses as $s ) : ?>
										<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $e['status'], $s ); ?>><?php echo esc_html( str_replace( '_', ' ', $s ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td class="scc-plan-actions">
								<button class="button button-small scc-brief-btn"><?php esc_html_e( 'Brief', 'seo-command-center' ); ?></button>
								<button class="button button-small button-primary scc-generate-btn"><?php esc_html_e( 'Generate draft', 'seo-command-center' ); ?></button>
								<button class="button-link scc-plan-delete" aria-label="<?php esc_attr_e( 'Delete', 'seo-command-center' ); ?>">&times;</button>
							</td>
						</tr>
						<tr class="scc-brief-row" hidden>
							<td colspan="9"><div class="scc-brief-panel"></div></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<span class="scc-inline-status" id="scc-plan-status-msg"></span>
		<?php endif; ?>
	</div>
</div>
