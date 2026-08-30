<?php
/**
 * Publishing Queue view (batch job status, usage, and the review workflow).
 *
 * @package SEO_Command_Center
 * @var array $data View data (queue, jobs, usage, budget, auto_publish).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$queue  = isset( $data['queue'] ) ? $data['queue'] : array();
$jobs   = isset( $data['jobs'] ) ? $data['jobs'] : array();
$usage  = isset( $data['usage'] ) ? $data['usage'] : array();
$budget = isset( $data['budget'] ) ? (float) $data['budget'] : 0;
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Publishing Queue', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Review generated drafts and publish when you are ready. Batch generation runs in the background so it never ties up your site.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-grid scc-stats">
		<div class="scc-stat"><div class="scc-stat__num"><?php echo esc_html( (int) ( $jobs['queued'] ?? 0 ) ); ?></div><div class="scc-stat__label"><?php esc_html_e( 'Jobs queued', 'seo-command-center' ); ?></div></div>
		<div class="scc-stat"><div class="scc-stat__num"><?php echo esc_html( (int) ( $jobs['completed'] ?? 0 ) ); ?></div><div class="scc-stat__label"><?php esc_html_e( 'Completed', 'seo-command-center' ); ?></div></div>
		<div class="scc-stat <?php echo ( ! empty( $jobs['failed'] ) ) ? 'scc-stat--warn' : ''; ?>"><div class="scc-stat__num"><?php echo esc_html( (int) ( $jobs['failed'] ?? 0 ) ); ?></div><div class="scc-stat__label"><?php esc_html_e( 'Failed', 'seo-command-center' ); ?></div></div>
		<div class="scc-stat"><div class="scc-stat__num">$<?php echo esc_html( number_format( (float) ( $usage['cost'] ?? 0 ), 2 ) ); ?></div><div class="scc-stat__label"><?php esc_html_e( 'AI spend this month', 'seo-command-center' ); ?><?php echo $budget > 0 ? esc_html( ' / $' . number_format( $budget, 0 ) ) : ''; ?></div></div>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Batch generation', 'seo-command-center' ); ?></h2>
			<div>
				<button class="button" id="scc-jobs-pause"><?php echo ! empty( $jobs['paused'] ) ? esc_html__( 'Resume queue', 'seo-command-center' ) : esc_html__( 'Pause queue', 'seo-command-center' ); ?></button>
				<button class="button" id="scc-jobs-retry"><?php esc_html_e( 'Retry failed', 'seo-command-center' ); ?></button>
				<button class="button button-primary" id="scc-jobs-batch"><?php esc_html_e( 'Generate all approved', 'seo-command-center' ); ?></button>
			</div>
		</div>
		<span class="scc-inline-status" id="scc-jobs-msg"></span>
		<?php if ( ! empty( $jobs['paused'] ) ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'The queue is paused (this can happen automatically when the monthly AI budget is reached).', 'seo-command-center' ); ?></p></div>
		<?php endif; ?>
		<p class="scc-note"><?php esc_html_e( 'Batch generation creates drafts for content-plan entries marked Approved. You confirm the estimated cost before it runs.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Generated drafts', 'seo-command-center' ); ?></h2>
		<?php if ( empty( $queue ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No generated content yet.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<table class="widefat striped scc-table" id="scc-publish-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Status', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Score', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'seo-command-center' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $queue as $row ) : ?>
						<tr data-id="<?php echo esc_attr( $row['post_id'] ); ?>">
							<td>
								<?php echo esc_html( $row['title'] ); ?>
								<?php if ( $row['approved'] ) : ?><span class="scc-badge scc-badge--ok"><?php esc_html_e( 'Approved', 'seo-command-center' ); ?></span><?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['type'] ); ?></td>
							<td class="scc-pub-status"><?php echo esc_html( $row['status'] ); ?></td>
							<td><?php echo esc_html( $row['score'] ); ?>/100</td>
							<td>
								<?php if ( $row['edit_url'] ) : ?><a class="button button-small" href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'seo-command-center' ); ?></a><?php endif; ?>
								<?php if ( $row['preview_url'] ) : ?><a class="button button-small" href="<?php echo esc_url( $row['preview_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'seo-command-center' ); ?></a><?php endif; ?>
								<?php if ( 'publish' !== $row['status'] ) : ?>
									<button class="button button-small scc-approve" data-on="<?php echo $row['approved'] ? '0' : '1'; ?>"><?php echo $row['approved'] ? esc_html__( 'Unapprove', 'seo-command-center' ) : esc_html__( 'Approve', 'seo-command-center' ); ?></button>
									<button class="button button-small button-primary scc-publish"><?php esc_html_e( 'Publish', 'seo-command-center' ); ?></button>
									<input type="datetime-local" class="scc-schedule-dt">
									<button class="button button-small scc-schedule"><?php esc_html_e( 'Schedule', 'seo-command-center' ); ?></button>
								<?php else : ?>
									<a class="button button-small" href="<?php echo esc_url( $row['view_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'seo-command-center' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<span class="scc-inline-status" id="scc-publish-msg"></span>
		<?php endif; ?>
	</div>
</div>
