<?php
/**
 * AEO / AI Citations view.
 *
 * @package SEO_Command_Center
 * @var array $data View data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$report     = isset( $data['report'] ) && is_array( $data['report'] ) ? $data['report'] : array();
$visibility = isset( $data['visibility'] ) && is_array( $data['visibility'] ) ? $data['visibility'] : array();
$crawl      = (array) ( $report['crawlability'] ?? array() );
$factors    = (array) ( $report['factors'] ?? array() );
$recs       = (array) ( $report['recommendations'] ?? array() );
$pages      = (array) ( $report['weakest_pages'] ?? array() );

$state_badge = function ( $value, $true_text, $false_text, $unknown_text = 'Not measured' ) {
	if ( true === $value ) {
		return '<span class="scc-badge scc-badge--ok">' . esc_html( $true_text ) . '</span>';
	}
	if ( false === $value ) {
		return '<span class="scc-badge scc-badge--warn">' . esc_html( $false_text ) . '</span>';
	}
	return '<span class="scc-flag">' . esc_html( $unknown_text ) . '</span>';
};
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<span class="scc-phase-badge">✦ <?php esc_html_e( 'Answer Engine Optimization', 'seo-command-center' ); ?></span>
		<h1><?php esc_html_e( 'AEO / AI Citation Expert', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Improve how easily search and answer engines can discover, understand, verify and cite your site. TideOrbit separates measurable readiness from actual citation tracking so it never fabricates AI visibility.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card scc-aeo-scorecard">
		<div class="scc-card__head">
			<div>
				<h2><?php esc_html_e( 'AI citation readiness', 'seo-command-center' ); ?></h2>
				<p class="scc-note"><?php echo esc_html( (string) ( $report['disclaimer'] ?? '' ) ); ?></p>
			</div>
			<div class="scc-arch-health__score">
				<strong><?php echo esc_html( (int) ( $report['score'] ?? 0 ) ); ?></strong><span>/100</span>
				<small><?php echo esc_html( (string) ( $report['label'] ?? '' ) ); ?></small>
			</div>
		</div>
		<div class="scc-arch-health__grid">
			<?php foreach ( $factors as $factor ) : ?>
				<div>
					<strong><?php echo esc_html( (int) ( $factor['score'] ?? 0 ) ); ?>%</strong>
					<span><?php echo esc_html( (string) ( $factor['label'] ?? '' ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<div>
				<h2><?php esc_html_e( 'Crawler & search eligibility', 'seo-command-center' ); ?></h2>
				<p class="scc-note"><?php esc_html_e( 'AEO starts with ordinary crawl/index access. Blocking a search crawler can prevent public pages from being retrieved for search-powered answers.', 'seo-command-center' ); ?></p>
			</div>
		</div>
		<table class="widefat striped scc-table">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'WordPress public indexing', 'seo-command-center' ); ?></strong></td>
					<td><?php echo $state_badge( ! empty( $crawl['wordpress_indexable'] ), __( 'Enabled', 'seo-command-center' ), __( 'Blocked', 'seo-command-center' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Googlebot', 'seo-command-center' ); ?></strong></td>
					<td><?php echo $state_badge( $crawl['googlebot_allowed'] ?? null, __( 'Allowed', 'seo-command-center' ), __( 'Blocked', 'seo-command-center' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Bingbot', 'seo-command-center' ); ?></strong></td>
					<td><?php echo $state_badge( $crawl['bingbot_allowed'] ?? null, __( 'Allowed', 'seo-command-center' ), __( 'Blocked', 'seo-command-center' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'OAI-SearchBot (ChatGPT Search)', 'seo-command-center' ); ?></strong></td>
					<td><?php echo $state_badge( $crawl['oai_searchbot_allowed'] ?? null, __( 'Allowed', 'seo-command-center' ), __( 'Blocked', 'seo-command-center' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
			</tbody>
		</table>
		<?php if ( ! empty( $crawl['message'] ) ) : ?><p class="scc-note"><?php echo esc_html( $crawl['message'] ); ?></p><?php endif; ?>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<div>
				<h2><?php esc_html_e( 'AEO action plan', 'seo-command-center' ); ?></h2>
				<p class="scc-note"><?php esc_html_e( 'Prioritized changes TideOrbit can justify from your real site signals. These are optimization recommendations, not citation guarantees.', 'seo-command-center' ); ?></p>
			</div>
		</div>
		<?php if ( empty( $recs ) ) : ?>
			<p class="scc-ok"><?php esc_html_e( 'No major AEO readiness gaps were detected in the sampled pages.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<div class="scc-aeo-actions">
				<?php foreach ( $recs as $rec ) : ?>
					<article class="scc-aeo-action">
						<div class="scc-growth-item__top">
							<strong><?php echo esc_html( (string) ( $rec['title'] ?? '' ) ); ?></strong>
							<span class="scc-flag"><?php echo esc_html( (int) ( $rec['priority'] ?? 0 ) ); ?> <?php esc_html_e( 'priority', 'seo-command-center' ); ?></span>
						</div>
						<?php if ( ! empty( $rec['url'] ) ) : ?><code><?php echo esc_html( $rec['url'] ); ?></code><?php endif; ?>
						<p class="scc-note"><?php echo esc_html( (string) ( $rec['reason'] ?? '' ) ); ?></p>
						<?php if ( ! empty( $rec['outcome'] ) ) : ?><p><strong><?php esc_html_e( 'Goal:', 'seo-command-center' ); ?></strong> <?php echo esc_html( $rec['outcome'] ); ?></p><?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<div>
				<h2><?php esc_html_e( 'Pages needing AEO work', 'seo-command-center' ); ?></h2>
				<p class="scc-note"><?php echo esc_html( sprintf( __( '%d published page(s) sampled. Lowest-readiness pages are shown first.', 'seo-command-center' ), (int) ( $report['pages_analyzed'] ?? 0 ) ) ); ?></p>
			</div>
		</div>
		<?php if ( empty( $pages ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No published indexed pages were available to score yet. Reindex the site after content is available.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<table class="widefat striped scc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'AEO readiness', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Main gaps', 'seo-command-center' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pages as $page ) : ?>
						<tr>
							<td><strong><?php echo esc_html( (string) ( $page['title'] ?? '' ) ); ?></strong><br><code><?php echo esc_html( (string) ( $page['url'] ?? '' ) ); ?></code></td>
							<td><strong><?php echo esc_html( (int) ( $page['score'] ?? 0 ) ); ?>/100</strong><br><span class="scc-note"><?php echo esc_html( (string) ( $page['label'] ?? '' ) ); ?></span></td>
							<td class="scc-note"><?php echo esc_html( implode( ' ', array_slice( (array) ( $page['recommendations'] ?? array() ), 0, 2 ) ) ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( (int) $page['post_id'], 'raw' ) ); ?>"><?php esc_html_e( 'Edit page', 'seo-command-center' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-layout&post=' . (int) $page['post_id'] ) ); ?>"><?php esc_html_e( 'Elementor layout', 'seo-command-center' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Measured AI visibility', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php echo esc_html( (string) ( $visibility['disclaimer'] ?? '' ) ); ?></p>
		<div class="scc-entities">
			<?php foreach ( (array) ( $visibility['providers'] ?? array() ) as $provider ) : ?>
				<span class="scc-entity <?php echo ! empty( $provider['connected'] ) ? 'scc-entity--ok' : 'scc-entity--gap'; ?>">
					<?php echo esc_html( (string) ( $provider['label'] ?? '' ) ); ?> — <?php echo esc_html( ! empty( $provider['connected'] ) ? __( 'connected', 'seo-command-center' ) : __( 'not connected', 'seo-command-center' ) ); ?>
				</span>
			<?php endforeach; ?>
		</div>
		<p class="scc-note"><?php esc_html_e( 'When a real provider integration supplies citation data, TideOrbit can show it here. Until then, it will not estimate or fabricate citation counts.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'AEO rules TideOrbit follows', 'seo-command-center' ); ?></h2>
		<div class="scc-aeo-guidance">
			<?php foreach ( (array) ( $report['guidance'] ?? array() ) as $guide ) : ?>
				<article>
					<h3><?php echo esc_html( (string) ( $guide['title'] ?? '' ) ); ?></h3>
					<p><?php echo esc_html( (string) ( $guide['text'] ?? '' ) ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</div>
