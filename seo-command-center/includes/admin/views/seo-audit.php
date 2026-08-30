<?php
/**
 * SEO Audit view — keyword cannibalization (Phase 2). Expands in Phase 3.
 *
 * @package SEO_Command_Center
 * @var array $data View data (cannibalization, has_analysis).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$groups        = isset( $data['cannibalization'] ) ? $data['cannibalization'] : array();
$has_analysis  = ! empty( $data['has_analysis'] );
$gsc_connected = ! empty( $data['gsc_connected'] );
$connections_url = admin_url( 'admin.php?page=seo-command-center-connections' );
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'SEO Audit', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Keyword cannibalization: pages that appear to compete for the same intent. Review each group and choose an action — the plugin never merges, redirects, or deletes anything automatically.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Keyword cannibalization', 'seo-command-center' ); ?></h2>
		<?php if ( ! $has_analysis ) : ?>
			<p class="scc-note"><?php esc_html_e( 'Run a site analysis first (Site Analysis page) so there is content to compare.', 'seo-command-center' ); ?></p>
		<?php elseif ( empty( $groups ) ) : ?>
			<p class="scc-ok"><?php esc_html_e( 'No likely cannibalization detected. Nice — your pages target distinct topics.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<?php foreach ( $groups as $group ) : ?>
				<div class="scc-cluster">
					<div class="scc-cluster__head">
						<strong><?php esc_html_e( 'Overlapping topic:', 'seo-command-center' ); ?> <?php echo esc_html( $group['topic'] ); ?></strong>
					</div>
					<div class="scc-cluster__body">
						<ul class="scc-cannibal">
							<?php foreach ( $group['pages'] as $p ) : ?>
								<li><a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['title'] ? $p['title'] : $p['url'] ); ?></a></li>
							<?php endforeach; ?>
						</ul>
						<div class="scc-label"><?php esc_html_e( 'Recommended options', 'seo-command-center' ); ?>:</div>
						<ul class="scc-options">
							<?php foreach ( $group['options'] as $opt ) : ?>
								<li><?php echo esc_html( $opt ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Search Console quick wins', 'seo-command-center' ); ?></h2>
			<?php if ( $gsc_connected ) : ?>
				<button class="button button-primary" id="scc-gsc-load"><?php esc_html_e( 'Load quick wins', 'seo-command-center' ); ?></button>
			<?php endif; ?>
		</div>
		<?php if ( ! $gsc_connected ) : ?>
			<p class="scc-note">
				<?php esc_html_e( 'Google Search Console is not connected, so no query data is shown. When connected, this surfaces real queries with impressions that rank in positions 4–20 — your best optimization opportunities.', 'seo-command-center' ); ?>
				<a href="<?php echo esc_url( $connections_url ); ?>"><?php esc_html_e( 'Connect it', 'seo-command-center' ); ?></a>
			</p>
		<?php else : ?>
			<span class="scc-inline-status" id="scc-gsc-status"></span>
			<div id="scc-gsc-results"></div>
		<?php endif; ?>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Competitor analysis', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'Analyze a competitor’s public page structure and topic coverage for strategic comparison. Respects robots.txt; no private data or copying.', 'seo-command-center' ); ?></p>
		<p>
			<input type="url" class="regular-text" id="scc-competitor-url" placeholder="https://competitor.com/services/">
			<button class="button button-primary" id="scc-competitor-go"><?php esc_html_e( 'Analyze', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-competitor-status"></span>
		</p>
		<div id="scc-competitor-results"></div>
	</div>
</div>
