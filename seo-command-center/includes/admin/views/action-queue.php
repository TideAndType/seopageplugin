<?php
/**
 * Action Queue view — the operational hub of the intelligence layer.
 *
 * Top: every computed opportunity (ranked, explainable) with "Add to queue".
 * Below: the persistent action queue with its lifecycle (approve / dismiss /
 * snooze / execute) and "Fix Everything Safe" for the deterministic subset.
 *
 * @package SEO_Command_Center
 * @var array $data View data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$opps    = isset( $data['opportunities'] ) ? (array) $data['opportunities'] : array();
$actions = isset( $data['actions'] ) ? (array) $data['actions'] : array();
$safe_n  = (int) ( $data['safe_pending_count'] ?? 0 );

$dc_label = array(
	'verified'    => __( 'Verified data', 'seo-command-center' ),
	'partial'     => __( 'Partial data', 'seo-command-center' ),
	'estimated'   => __( 'Estimated', 'seo-command-center' ),
	'unavailable' => __( 'Data unavailable', 'seo-command-center' ),
);
$status_label = array(
	'new' => __( 'New', 'seo-command-center' ), 'reviewing' => __( 'Reviewing', 'seo-command-center' ),
	'approved' => __( 'Approved', 'seo-command-center' ), 'in_progress' => __( 'In progress', 'seo-command-center' ),
	'completed' => __( 'Completed', 'seo-command-center' ), 'dismissed' => __( 'Dismissed', 'seo-command-center' ),
	'snoozed' => __( 'Snoozed', 'seo-command-center' ), 'failed' => __( 'Failed', 'seo-command-center' ),
);
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<span class="scc-phase-badge">✦ <?php esc_html_e( 'SEO Intelligence', 'seo-command-center' ); ?></span>
		<h1><?php esc_html_e( 'Action Queue', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'The highest-impact SEO actions for this site, scored and explained from your real data. Add the ones you want to the queue, then approve, snooze, or run the safe ones. Nothing changes your site until you act.', 'seo-command-center' ); ?></p>
	</div>

	<!-- Opportunities -->
	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Opportunities', 'seo-command-center' ); ?></h2>
			<button class="button" id="scc-opps-refresh"><?php esc_html_e( 'Refresh', 'seo-command-center' ); ?></button>
		</div>
		<span class="scc-inline-status" id="scc-opps-msg"></span>
		<?php if ( empty( $opps ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No opportunities yet. Run a site analysis and connect Google Search Console for the sharpest recommendations, then Refresh.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<div class="scc-opps" id="scc-opps-list">
				<?php foreach ( $opps as $op ) : ?>
					<div class="scc-opp" data-opp-id="<?php echo esc_attr( $op['id'] ); ?>">
						<div class="scc-opp__score"><span class="scc-opp__num"><?php echo esc_html( (int) $op['score'] ); ?></span><span class="scc-opp__den">/100</span></div>
						<div class="scc-opp__body">
							<div class="scc-opp__title"><strong><?php echo esc_html( $op['title'] ); ?></strong>
								<span class="scc-flag scc-flag--prio-<?php echo esc_attr( $op['priority'] ); ?>"><?php echo esc_html( ucfirst( $op['priority'] ) ); ?></span>
								<span class="scc-flag"><?php echo esc_html( $dc_label[ $op['data_confidence'] ] ?? $op['data_confidence'] ); ?></span>
							</div>
							<p class="scc-opp__why"><?php echo esc_html( $op['reason'] ); ?></p>
							<div class="scc-opp__factors">
								<?php foreach ( (array) $op['factors'] as $f ) : ?>
									<span class="scc-opp__factor">+<?php echo esc_html( (int) $f['points'] ); ?> <?php echo esc_html( $f['label'] ); ?></span>
								<?php endforeach; ?>
							</div>
							<div class="scc-opp__meta">
								<span><?php esc_html_e( 'Impact:', 'seo-command-center' ); ?> <strong><?php echo esc_html( ucfirst( (string) $op['expected_impact'] ) ); ?></strong></span>
								<span><?php esc_html_e( 'Effort:', 'seo-command-center' ); ?> <strong><?php echo esc_html( (string) $op['effort'] ); ?></strong></span>
								<span><?php esc_html_e( 'Risk:', 'seo-command-center' ); ?> <strong><?php echo esc_html( ucfirst( (string) $op['risk'] ) ); ?></strong></span>
								<span><?php esc_html_e( 'Confidence:', 'seo-command-center' ); ?> <strong><?php echo esc_html( (int) $op['confidence'] ); ?>%</strong></span>
							</div>
						</div>
						<div class="scc-opp__actions">
							<button class="button button-primary button-small scc-opp-approve"><?php esc_html_e( 'Add to queue', 'seo-command-center' ); ?></button>
							<button class="button button-small scc-opp-dismiss"><?php esc_html_e( 'Dismiss', 'seo-command-center' ); ?></button>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- Persistent queue -->
	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Queue', 'seo-command-center' ); ?></h2>
			<button class="button button-primary" id="scc-fix-safe" <?php echo $safe_n > 0 ? '' : 'disabled'; ?>>
				<?php echo esc_html( sprintf( /* translators: %d count */ __( 'Fix Everything Safe (%d)', 'seo-command-center' ), $safe_n ) ); ?>
			</button>
		</div>
		<span class="scc-inline-status" id="scc-queue-msg"></span>
		<p class="scc-note"><?php esc_html_e( '“Fix Everything Safe” only runs deterministic, reversible actions (internal links). It never edits content, publishes, deletes, or redirects.', 'seo-command-center' ); ?></p>
		<?php if ( empty( $actions ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'The queue is empty. Add opportunities above to start.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<table class="widefat striped scc-table" id="scc-queue-table">
				<thead><tr>
					<th><?php esc_html_e( 'Score', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Action', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Status', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Do', 'seo-command-center' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $actions as $a ) : ?>
						<tr data-id="<?php echo esc_attr( $a['id'] ); ?>" data-safe="<?php echo ! empty( $a['safe'] ) ? '1' : '0'; ?>">
							<td><strong><?php echo esc_html( (int) $a['score'] ); ?></strong></td>
							<td>
								<strong><?php echo esc_html( $a['title'] ); ?></strong>
								<div class="scc-note"><?php echo esc_html( $a['reason'] ); ?></div>
							</td>
							<td><span class="scc-flag"><?php echo esc_html( str_replace( '_', ' ', (string) $a['type'] ) ); ?></span><?php echo ! empty( $a['safe'] ) ? ' <span class="scc-badge scc-badge--ok">' . esc_html__( 'safe', 'seo-command-center' ) . '</span>' : ''; ?></td>
							<td class="scc-q-status"><span class="scc-flag scc-flag--prio-<?php echo esc_attr( $a['priority'] ); ?>"><?php echo esc_html( $status_label[ $a['status'] ] ?? $a['status'] ); ?></span></td>
							<td>
								<?php if ( in_array( $a['status'], array( 'new', 'reviewing' ), true ) ) : ?>
									<button class="button button-small scc-q-approve"><?php esc_html_e( 'Approve', 'seo-command-center' ); ?></button>
								<?php endif; ?>
								<?php if ( ! empty( $a['safe'] ) && in_array( $a['status'], array( 'new', 'approved' ), true ) ) : ?>
									<button class="button button-small button-primary scc-q-execute"><?php esc_html_e( 'Run', 'seo-command-center' ); ?></button>
								<?php endif; ?>
								<?php if ( ! in_array( $a['status'], array( 'completed', 'dismissed' ), true ) ) : ?>
									<button class="button button-small scc-q-snooze"><?php esc_html_e( 'Snooze', 'seo-command-center' ); ?></button>
									<button class="button button-small button-link-delete scc-q-dismiss"><?php esc_html_e( 'Dismiss', 'seo-command-center' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
