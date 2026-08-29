<?php
/**
 * Site Analysis view: per-URL table.
 *
 * @package SEO_Command_Center
 * @var array $data View data (latest).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$latest = isset( $data['latest'] ) ? $data['latest'] : null;
$items  = ( $latest && ! empty( $latest['items'] ) ) ? $latest['items'] : array();
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Site Analysis', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Every page and post, with its on-page SEO signals. Read-only — nothing here is changed automatically.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Analyzed content', 'seo-command-center' ); ?></h2>
			<button class="button button-primary" id="scc-run-analysis"><?php esc_html_e( 'Run analysis', 'seo-command-center' ); ?></button>
		</div>
		<span class="scc-inline-status" id="scc-analysis-status"></span>

		<?php if ( empty( $items ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No analysis yet. Run one to populate this table.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<table class="widefat striped scc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Words', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Int. links', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Meta desc', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'H1', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Schema', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Elementor', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Flags', 'seo-command-center' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php $flags = json_decode( (string) $item['flags'], true ); $flags = is_array( $flags ) ? $flags : array(); ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['title'] ? $item['title'] : $item['url'] ); ?></a>
							</td>
							<td><?php echo esc_html( $item['post_type'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $item['word_count'] ) ); ?></td>
							<td><?php echo esc_html( (int) $item['internal_links'] ); ?></td>
							<td><?php echo '' !== trim( (string) $item['meta_description'] ) ? '✓' : '<span class="scc-bad">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo '' !== trim( (string) $item['h1'] ) ? '✓' : '<span class="scc-bad">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo (int) $item['has_schema'] ? '✓' : '—'; ?></td>
							<td><?php echo (int) $item['is_elementor'] ? '✓' : '—'; ?></td>
							<td>
								<?php foreach ( $flags as $flag ) : ?>
									<span class="scc-flag scc-flag--<?php echo esc_attr( $flag ); ?>"><?php echo esc_html( str_replace( '_', ' ', $flag ) ); ?></span>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
