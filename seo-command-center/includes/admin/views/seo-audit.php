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

$groups       = isset( $data['cannibalization'] ) ? $data['cannibalization'] : array();
$has_analysis = ! empty( $data['has_analysis'] );
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
</div>
