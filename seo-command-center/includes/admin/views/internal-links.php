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
			<h2><?php esc_html_e( 'Internal Link Autopilot', 'seo-command-center' ); ?></h2>
			<div>
				<button class="button" id="scc-links-scan"><?php esc_html_e( 'Scan site for opportunities', 'seo-command-center' ); ?></button>
				<button class="button button-primary" id="scc-links-apply-high"><?php esc_html_e( 'Apply high-confidence links', 'seo-command-center' ); ?></button>
			</div>
		</div>
		<span class="scc-inline-status" id="scc-links-msg"></span>
		<p class="scc-note">
			<?php
			echo esc_html( sprintf(
				/* translators: 1: on/off, 2: indexed count, 3: threshold */
				__( 'Autopilot is %1$s · %2$d pages indexed · high-confidence threshold %3$d%%. Relevance is prioritized over link quantity; nothing is inserted below the medium threshold.', 'seo-command-center' ),
				! empty( $data['autopilot'] ) ? __( 'ON', 'seo-command-center' ) : __( 'OFF', 'seo-command-center' ),
				(int) ( $data['indexed'] ?? 0 ),
				(int) ( $data['high'] ?? 80 )
			) );
			?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-settings' ) ); ?>"><?php esc_html_e( 'Autopilot settings', 'seo-command-center' ); ?></a>
		</p>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Link recommendations', 'seo-command-center' ); ?></h2>
		<?php if ( empty( $recs ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No pending recommendations. Run a site scan to find genuinely relevant internal links.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<table class="widefat striped scc-table" id="scc-links-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'From', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Anchor', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'To', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Conf.', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'seo-command-center' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recs as $r ) : ?>
						<tr data-id="<?php echo esc_attr( $r['id'] ); ?>">
							<td><?php echo esc_html( $r['source_title'] ); ?></td>
							<td>“<?php echo esc_html( $r['anchor'] ); ?>”</td>
							<td><a href="<?php echo esc_url( $r['target_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r['target_title'] ); ?></a></td>
							<td><strong><?php echo esc_html( (int) $r['confidence'] ); ?>%</strong></td>
							<td class="scc-note"><?php echo esc_html( $r['reason'] ); ?></td>
							<td><button class="button scc-apply-link"><?php esc_html_e( 'Insert', 'seo-command-center' ); ?></button></td>
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

	<div class="scc-card">
		<h2><?php esc_html_e( 'Change history', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'Every automatic change (links, metadata, schema) is logged and can be reverted.', 'seo-command-center' ); ?></p>
		<?php $history = isset( $data['history'] ) ? $data['history'] : array(); ?>
		<?php if ( empty( $history ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No changes recorded yet.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<table class="widefat striped scc-table" id="scc-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Page', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Source', 'seo-command-center' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history as $h ) : ?>
						<tr data-id="<?php echo esc_attr( $h['id'] ); ?>">
							<td><?php echo esc_html( $h['created_at'] ); ?></td>
							<td><?php echo esc_html( $h['post_title'] ); ?></td>
							<td><?php echo esc_html( str_replace( '_', ' ', $h['change_type'] ) ); ?></td>
							<td><?php echo esc_html( $h['trigger_source'] ); ?></td>
							<td>
								<?php if ( (int) $h['reverted'] === 1 ) : ?>
									<span class="scc-badge"><?php esc_html_e( 'Reverted', 'seo-command-center' ); ?></span>
								<?php else : ?>
									<button class="button button-small scc-revert"><?php esc_html_e( 'Revert', 'seo-command-center' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<span class="scc-inline-status" id="scc-history-msg"></span>
		<?php endif; ?>
	</div>
</div>
