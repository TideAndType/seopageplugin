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
		<h1><?php esc_html_e( 'TideOrbit', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Your SEO, prioritized.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-context-bar">
		<span class="scc-chip"><?php esc_html_e( 'SEO plugin:', 'seo-command-center' ); ?> <strong><?php echo esc_html( $data['seo_plugin'] ); ?></strong></span>
		<span class="scc-chip"><?php esc_html_e( 'Elementor:', 'seo-command-center' ); ?> <strong><?php echo $data['elementor'] ? esc_html__( 'Detected', 'seo-command-center' ) : esc_html__( 'Not installed', 'seo-command-center' ); ?></strong></span>
		<span class="scc-chip"><?php esc_html_e( 'AI spend (mo.):', 'seo-command-center' ); ?> <strong>$<?php echo esc_html( number_format( (float) ( $usage['cost'] ?? 0 ), 2 ) ); ?></strong></span>
	</div>

	<?php if ( $latest ) : ?>
		<?php
		// Compact overview — the numbers that answer "what's happening?" at a glance.
		// Problem counts carry colour; neutral counts stay quiet so the eye lands
		// on what needs attention. Full page-by-page data is one click away.
		$overview = array(
			array( __( 'Content pages', 'seo-command-center' ), $t( $totals, 'analyzed' ) ),
			array( __( 'Internal links', 'seo-command-center' ), $t( $totals, 'internal_links' ) ),
			array( __( 'Missing meta', 'seo-command-center' ), $t( $totals, 'missing_meta' ), 'warn' ),
			array( __( 'Thin content', 'seo-command-center' ), $t( $totals, 'thin_content' ), 'warn' ),
			array( __( 'Pages without H1', 'seo-command-center' ), $t( $totals, 'no_h1' ), 'warn' ),
		);
		?>
		<p class="scc-section-label"><?php esc_html_e( 'Site overview', 'seo-command-center' ); ?></p>
		<div class="scc-grid scc-stats">
			<?php
			foreach ( $overview as $s ) :
				$cls = isset( $s[2] ) && $s[1] > 0 ? 'scc-stat scc-stat--' . esc_attr( $s[2] ) : 'scc-stat';
				?>
				<div class="<?php echo esc_attr( $cls ); ?>">
					<div class="scc-stat__num"><?php echo esc_html( number_format_i18n( $s[1] ) ); ?></div>
					<div class="scc-stat__label"><?php echo esc_html( $s[0] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! $latest ) : ?>
		<div class="scc-card scc-empty">
			<div class="scc-empty__icon" aria-hidden="true">🛰️</div>
			<h2><?php esc_html_e( 'Start with a site analysis', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'Discover your pages, metadata gaps, thin content and internal-link opportunities.', 'seo-command-center' ); ?></p>
			<button class="button button-primary button-hero" id="scc-run-analysis"><?php esc_html_e( 'Analyze my site', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-analysis-status"></span>
		</div>
	<?php endif; ?>

	<div class="scc-card scc-copilot" id="scc-copilot">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'What should I work on?', 'seo-command-center' ); ?></h2>
		</div>
		<div class="scc-copilot__ask">
			<input type="text" id="scc-copilot-q" class="regular-text" placeholder="<?php esc_attr_e( 'Ask TideOrbit… e.g. What should I work on this week?', 'seo-command-center' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Ask TideOrbit', 'seo-command-center' ); ?>">
			<button class="button button-primary" id="scc-copilot-go"><?php esc_html_e( 'Ask', 'seo-command-center' ); ?></button>
		</div>
		<div class="scc-copilot__chips">
			<?php
			$suggestions = array(
				__( 'Biggest opportunities', 'seo-command-center' ),
				__( 'Pages losing traffic', 'seo-command-center' ),
				__( 'Close to ranking', 'seo-command-center' ),
				__( 'Need internal links', 'seo-command-center' ),
				__( 'Cannibalization', 'seo-command-center' ),
				__( 'Articles to create', 'seo-command-center' ),
			);
			foreach ( $suggestions as $s ) :
				?>
				<button type="button" class="scc-chip scc-chip--btn scc-copilot-suggest"><?php echo esc_html( $s ); ?></button>
			<?php endforeach; ?>
		</div>
		<span class="scc-inline-status" id="scc-copilot-msg"></span>
		<div class="scc-copilot__result" id="scc-copilot-result" hidden></div>
	</div>

	<?php
	// Top opportunities from the intelligence layer — ranked, with a clear CTA.
	$opportunities = isset( $data['opportunities'] ) ? (array) $data['opportunities'] : array();
	$dc_label = array(
		'verified'    => __( 'Verified', 'seo-command-center' ),
		'partial'     => __( 'Partial data', 'seo-command-center' ),
		'estimated'   => __( 'Estimated', 'seo-command-center' ),
		'unavailable' => __( 'No data', 'seo-command-center' ),
	);
	?>
	<div class="scc-card scc-next" id="scc-next-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Top opportunities', 'seo-command-center' ); ?></h2>
			<button class="button" id="scc-opps-refresh"><?php esc_html_e( 'Refresh', 'seo-command-center' ); ?></button>
		</div>
		<span class="scc-inline-status" id="scc-opps-msg"></span>
		<?php if ( empty( $opportunities ) ) : ?>
			<div class="scc-empty">
				<div class="scc-empty__icon" aria-hidden="true">✨</div>
				<h2><?php esc_html_e( 'No opportunities yet', 'seo-command-center' ); ?></h2>
				<p><?php esc_html_e( 'Run a site analysis and connect Search Console, then hit Refresh to see your highest-value actions.', 'seo-command-center' ); ?></p>
			</div>
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
							<div class="scc-opp__meta">
								<span><?php esc_html_e( 'Impact:', 'seo-command-center' ); ?> <strong><?php echo esc_html( ucfirst( (string) $op['expected_impact'] ) ); ?></strong></span>
								<span><?php esc_html_e( 'Effort:', 'seo-command-center' ); ?> <strong><?php echo esc_html( (string) $op['effort'] ); ?></strong></span>
								<span><?php esc_html_e( 'Confidence:', 'seo-command-center' ); ?> <strong><?php echo esc_html( (int) $op['confidence'] ); ?>%</strong></span>
							</div>
							<?php if ( ! empty( $op['recommended_action'] ) ) : ?>
								<details class="scc-opp__more">
									<summary><?php esc_html_e( 'Details', 'seo-command-center' ); ?></summary>
									<div class="scc-opp__do"><?php echo esc_html( (string) $op['recommended_action'] ); ?></div>
									<div class="scc-opp__factors">
										<?php foreach ( (array) $op['factors'] as $f ) : ?>
											<span class="scc-opp__factor">+<?php echo esc_html( (int) $f['points'] ); ?> <?php echo esc_html( $f['label'] ); ?></span>
										<?php endforeach; ?>
									</div>
								</details>
							<?php endif; ?>
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

	<?php if ( $latest && ! empty( $summary['cannibalization'] ) ) : ?>
		<div class="scc-card">
			<h2><?php esc_html_e( 'Keyword cannibalization', 'seo-command-center' ); ?></h2>
			<p class="scc-note"><?php esc_html_e( 'These pages may compete for the same intent. Review before merging or redirecting.', 'seo-command-center' ); ?></p>
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
		</div>
	<?php endif; ?>

	<?php if ( $latest ) : ?>
		<div class="scc-card__head" style="margin-top:4px;">
			<p class="scc-note" style="margin:0;">
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
			<span>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-site-analysis' ) ); ?>"><?php esc_html_e( 'Full analysis', 'seo-command-center' ); ?></a>
				<button class="button button-primary" id="scc-run-analysis"><?php esc_html_e( 'Analyze again', 'seo-command-center' ); ?></button>
				<span class="scc-inline-status" id="scc-analysis-status"></span>
			</span>
		</div>
	<?php endif; ?>
</div>
