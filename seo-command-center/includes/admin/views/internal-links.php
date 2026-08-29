<?php
/**
 * Internal Links view.
 *
 * @package SEO_Command_Center
 * @var array $data View data (totals, orphans, under_linked, over_linked, recommendations).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$totals = isset( $data['totals'] ) ? $data['totals'] : array();
$recs   = isset( $data['recommendations'] ) ? $data['recommendations'] : array();

$list_block = function ( $title, $nodes, $empty ) {
	?>
	<div class="scc-card">
		<h2><?php echo esc_html( $title ); ?></h2>
		<?php if ( empty( $nodes ) ) : ?>
			<p class="scc-ok"><?php echo esc_html( $empty ); ?></p>
		<?php else : ?>
			<ul class="scc-cannibal">
				<?php foreach ( $nodes as $n ) : ?>
					<li>
						<a href="<?php echo esc_url( $n['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $n['title'] ); ?></a>
						<span class="scc-note">(<?php echo esc_html( (int) $n['inbound_count'] ); ?> <?php esc_html_e( 'inbound', 'seo-command-center' ); ?>)</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
};
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Internal Links', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'The internal-link health of your site, and contextual link suggestions. Links are only inserted when you click Apply — and always into a phrase that already appears naturally in the content.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-grid scc-stats">
		<?php
		$stats = array(
			array( __( 'Pages in graph', 'seo-command-center' ), $totals['pages'] ?? 0 ),
			array( __( 'Orphan pages', 'seo-command-center' ), $totals['orphans'] ?? 0, 'warn' ),
			array( __( 'Under-linked', 'seo-command-center' ), $totals['under_linked'] ?? 0, 'warn' ),
			array( __( 'Over-linked', 'seo-command-center' ), $totals['over_linked'] ?? 0 ),
		);
		foreach ( $stats as $s ) :
			$cls = isset( $s[2] ) && $s[1] > 0 ? 'scc-stat scc-stat--' . esc_attr( $s[2] ) : 'scc-stat';
			?>
			<div class="<?php echo esc_attr( $cls ); ?>">
				<div class="scc-stat__num"><?php echo esc_html( number_format_i18n( (int) $s[1] ) ); ?></div>
				<div class="scc-stat__label"><?php echo esc_html( $s[0] ); ?></div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Link recommendations', 'seo-command-center' ); ?></h2>
			<button class="button button-primary" id="scc-recommend-links"><?php esc_html_e( 'Generate recommendations', 'seo-command-center' ); ?></button>
		</div>
		<span class="scc-inline-status" id="scc-links-msg"></span>

		<?php if ( empty( $recs ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No pending recommendations. Click “Generate recommendations” to find contextual internal links.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<table class="widefat striped scc-table" id="scc-links-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'From', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Anchor', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'To', 'seo-command-center' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recs as $r ) : ?>
						<tr data-id="<?php echo esc_attr( $r['id'] ); ?>">
							<td><?php echo esc_html( $r['source_title'] ); ?></td>
							<td>“<?php echo esc_html( $r['anchor'] ); ?>”</td>
							<td><a href="<?php echo esc_url( $r['target_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r['target_title'] ); ?></a></td>
							<td><button class="button scc-apply-link"><?php esc_html_e( 'Apply', 'seo-command-center' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="scc-columns">
		<?php
		$list_block( __( 'Orphan pages (no internal links in)', 'seo-command-center' ), $data['orphans'], __( 'No orphan pages — every page has at least one inbound internal link.', 'seo-command-center' ) );
		$list_block( __( 'Under-linked pages', 'seo-command-center' ), $data['under_linked'], __( 'No under-linked pages detected.', 'seo-command-center' ) );
		?>
	</div>

	<?php if ( ! empty( $data['over_linked'] ) ) : ?>
		<?php $list_block( __( 'Over-linked pages (receiving a lot of links)', 'seo-command-center' ), $data['over_linked'], '' ); ?>
	<?php endif; ?>
</div>
