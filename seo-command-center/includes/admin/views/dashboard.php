<?php
/**
 * Dashboard view.
 *
 * @package SEO_Command_Center
 * @var array $data View data (latest, seo_plugin, elementor, usage).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$latest  = isset( $data['latest'] ) ? $data['latest'] : null;
$summary = ( $latest && isset( $latest['summary_data'] ) ) ? $latest['summary_data'] : null;
$totals  = ( $summary && isset( $summary['totals'] ) ) ? $summary['totals'] : array();
$usage   = isset( $data['usage'] ) ? $data['usage'] : array();

/**
 * Small helper to read a total safely.
 *
 * @param array  $totals Totals.
 * @param string $key    Key.
 * @return int
 */
$t = function ( $totals, $key ) {
	return isset( $totals[ $key ] ) ? (int) $totals[ $key ] : 0;
};
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'SEO Command Center', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Understand what this site should rank for, plan the pages it needs, and generate them — as drafts you approve.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-context-bar">
		<span class="scc-chip"><?php esc_html_e( 'SEO plugin:', 'seo-command-center' ); ?> <strong><?php echo esc_html( $data['seo_plugin'] ); ?></strong></span>
		<span class="scc-chip"><?php esc_html_e( 'Elementor:', 'seo-command-center' ); ?> <strong><?php echo $data['elementor'] ? esc_html__( 'Detected', 'seo-command-center' ) : esc_html__( 'Not installed', 'seo-command-center' ); ?></strong></span>
		<span class="scc-chip"><?php esc_html_e( 'AI spend this month (est.):', 'seo-command-center' ); ?> <strong>$<?php echo esc_html( number_format( (float) ( $usage['cost'] ?? 0 ), 2 ) ); ?></strong></span>
	</div>

	<?php
	// "What should I do next?" — the top opportunities from the intelligence layer.
	$opportunities = isset( $data['opportunities'] ) ? (array) $data['opportunities'] : array();
	$dc_label = array(
		'verified'    => __( 'Verified data', 'seo-command-center' ),
		'partial'     => __( 'Partial data', 'seo-command-center' ),
		'estimated'   => __( 'Estimated', 'seo-command-center' ),
		'unavailable' => __( 'Data unavailable', 'seo-command-center' ),
	);
	?>
	<div class="scc-card scc-next" id="scc-next-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'What should I do next?', 'seo-command-center' ); ?></h2>
			<button class="button" id="scc-opps-refresh"><?php esc_html_e( 'Refresh', 'seo-command-center' ); ?></button>
		</div>
		<span class="scc-inline-status" id="scc-opps-msg"></span>
		<?php if ( empty( $opportunities ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No opportunities computed yet. Run a site analysis and connect Google Search Console for the sharpest recommendations, then hit Refresh.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<div class="scc-opps" id="scc-opps-list">
				<?php foreach ( $opportunities as $op ) : ?>
					<div class="scc-opp" data-opp-id="<?php echo esc_attr( $op['id'] ); ?>">
						<div class="scc-opp__score" title="<?php esc_attr_e( 'Opportunity score', 'seo-command-center' ); ?>">
							<span class="scc-opp__num"><?php echo esc_html( (int) $op['score'] ); ?></span><span class="scc-opp__den">/100</span>
						</div>
						<div class="scc-opp__body">
							<div class="scc-opp__title"><strong><?php echo esc_html( $op['title'] ); ?></strong>
								<span class="scc-flag scc-flag--prio-<?php echo esc_attr( $op['priority'] ); ?>"><?php echo esc_html( ucfirst( $op['priority'] ) ); ?></span>
								<span class="scc-flag" title="<?php esc_attr_e( 'Data confidence', 'seo-command-center' ); ?>"><?php echo esc_html( $dc_label[ $op['data_confidence'] ] ?? $op['data_confidence'] ); ?></span>
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
							<div class="scc-opp__do"><?php echo esc_html( (string) ( $op['recommended_action'] ?? '' ) ); ?></div>
						</div>
						<div class="scc-opp__actions">
							<button class="button button-primary button-small scc-opp-approve"><?php esc_html_e( 'Add to queue', 'seo-command-center' ); ?></button>
							<button class="button button-small scc-opp-dismiss"><?php esc_html_e( 'Dismiss', 'seo-command-center' ); ?></button>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="scc-note"><a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-action-queue' ) ); ?>"><?php esc_html_e( 'Open the full Action Queue →', 'seo-command-center' ); ?></a></p>
		<?php endif; ?>
	</div>

	<?php if ( ! $latest ) : ?>
		<div class="scc-card scc-empty">
			<h2><?php esc_html_e( 'Start with a site analysis', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'Run your first analysis to discover pages, metadata gaps, thin content, and internal-link opportunities.', 'seo-command-center' ); ?></p>
			<button class="button button-primary button-hero" id="scc-run-analysis"><?php esc_html_e( 'Analyze my site', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-analysis-status"></span>
		</div>
	<?php else : ?>
		<div class="scc-grid scc-stats">
			<?php
			$stats = array(
				array( __( 'Content pages found', 'seo-command-center' ), $t( $totals, 'analyzed' ) ),
				array( __( 'Pages', 'seo-command-center' ), $t( $totals, 'pages' ) ),
				array( __( 'Blog posts', 'seo-command-center' ), $t( $totals, 'posts' ) ),
				array( __( 'Internal links', 'seo-command-center' ), $t( $totals, 'internal_links' ) ),
				array( __( 'Missing meta descriptions', 'seo-command-center' ), $t( $totals, 'missing_meta' ), 'warn' ),
				array( __( 'Thin-content opportunities', 'seo-command-center' ), $t( $totals, 'thin_content' ), 'warn' ),
				array( __( 'Pages without an H1', 'seo-command-center' ), $t( $totals, 'no_h1' ), 'warn' ),
				array( __( 'Images missing alt text', 'seo-command-center' ), $t( $totals, 'images_missing_alt' ), 'warn' ),
				array( __( 'Elementor pages', 'seo-command-center' ), $t( $totals, 'elementor_pages' ) ),
				array( __( 'Pages with schema', 'seo-command-center' ), $t( $totals, 'has_schema' ) ),
			);
			foreach ( $stats as $s ) :
				$cls = isset( $s[2] ) && $s[1] > 0 ? 'scc-stat scc-stat--' . esc_attr( $s[2] ) : 'scc-stat';
				?>
				<div class="<?php echo esc_attr( $cls ); ?>">
					<div class="scc-stat__num"><?php echo esc_html( number_format_i18n( $s[1] ) ); ?></div>
					<div class="scc-stat__label"><?php echo esc_html( $s[0] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="scc-columns">
			<div class="scc-card">
				<h2><?php esc_html_e( 'Recommended next actions', 'seo-command-center' ); ?></h2>
				<p class="scc-note"><?php esc_html_e( 'Based on your analysis. Strategy-driven recommendations (new pages, keyword targets) arrive in Phase 2.', 'seo-command-center' ); ?></p>
				<ol class="scc-actions">
					<?php
					$actions = array();
					if ( $t( $totals, 'missing_meta' ) > 0 ) {
						$actions[] = sprintf(
							/* translators: %d: number of pages */
							__( 'Add meta descriptions to %d page(s) that are missing them.', 'seo-command-center' ),
							$t( $totals, 'missing_meta' )
						);
					}
					if ( $t( $totals, 'thin_content' ) > 0 ) {
						$actions[] = sprintf(
							/* translators: %d: number of pages */
							__( 'Expand %d thin page(s) below 300 words with genuinely useful detail.', 'seo-command-center' ),
							$t( $totals, 'thin_content' )
						);
					}
					if ( $t( $totals, 'no_h1' ) > 0 ) {
						$actions[] = sprintf(
							/* translators: %d: number of pages */
							__( 'Add a clear H1 heading to %d page(s).', 'seo-command-center' ),
							$t( $totals, 'no_h1' )
						);
					}
					if ( ! empty( $summary['orphans'] ) ) {
						$actions[] = sprintf(
							/* translators: %d: number of pages */
							__( 'Add internal links to %d orphan page(s) with no inbound internal links.', 'seo-command-center' ),
							count( $summary['orphans'] )
						);
					}
					if ( empty( $actions ) ) {
						$actions[] = __( 'No critical issues found in this pass. Run a keyword strategy (Phase 2) to find growth opportunities.', 'seo-command-center' );
					}
					foreach ( $actions as $a ) :
						?>
						<li><?php echo esc_html( $a ); ?></li>
					<?php endforeach; ?>
				</ol>
			</div>

			<div class="scc-card">
				<h2><?php esc_html_e( 'Keyword cannibalization warnings', 'seo-command-center' ); ?></h2>
				<?php if ( empty( $summary['cannibalization'] ) ) : ?>
					<p class="scc-ok"><?php esc_html_e( 'No obvious title overlaps detected.', 'seo-command-center' ); ?></p>
				<?php else : ?>
					<p class="scc-note"><?php esc_html_e( 'These pages may compete for the same intent. Review before merging or redirecting — the plugin never changes them automatically.', 'seo-command-center' ); ?></p>
					<ul class="scc-cannibal">
						<?php foreach ( array_slice( $summary['cannibalization'], 0, 8 ) as $group ) : ?>
							<li>
								<strong><?php echo esc_html( $group['topic'] ); ?></strong>
								<ul>
									<?php foreach ( $group['pages'] as $p ) : ?>
										<li><a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['title'] ); ?></a></li>
									<?php endforeach; ?>
								</ul>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>

		<div class="scc-card">
			<div class="scc-card__head">
				<h2><?php esc_html_e( 'Re-run analysis', 'seo-command-center' ); ?></h2>
				<span>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-site-analysis' ) ); ?>"><?php esc_html_e( 'View full page-by-page analysis', 'seo-command-center' ); ?></a>
					<button class="button button-primary" id="scc-run-analysis"><?php esc_html_e( 'Analyze again', 'seo-command-center' ); ?></button>
				</span>
			</div>
			<span class="scc-inline-status" id="scc-analysis-status"></span>
			<p class="scc-note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: date */
						__( 'Last analyzed: %s', 'seo-command-center' ),
						$latest['created_at']
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>
</div>
