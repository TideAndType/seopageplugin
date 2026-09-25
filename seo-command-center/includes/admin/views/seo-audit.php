<?php
/**
 * Site Audit view.
 *
 * @package SEO_Command_Center
 * @var array $data View data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$technical       = isset( $data['technical'] ) && is_array( $data['technical'] ) ? $data['technical'] : null;
$groups          = isset( $data['cannibalization'] ) ? $data['cannibalization'] : array();
$has_analysis    = ! empty( $data['has_analysis'] );
$gsc_connected   = ! empty( $data['gsc_connected'] );
$connections_url = admin_url( 'admin.php?page=seo-command-center-connections' );
$severity_labels = array(
	'critical' => __( 'Critical', 'seo-command-center' ),
	'high'     => __( 'High', 'seo-command-center' ),
	'medium'   => __( 'Medium', 'seo-command-center' ),
	'low'      => __( 'Low', 'seo-command-center' ),
);
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Site Audit', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Technical SEO, crawlability, architecture, indexability and search-performance diagnostics. TideOrbit shows evidence before recommending a change.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card" id="scc-technical-seo">
		<div class="scc-card__head">
			<div>
				<h2><?php esc_html_e( 'Technical SEO Brain', 'seo-command-center' ); ?></h2>
				<p class="scc-note"><?php esc_html_e( 'Crawls the live site and checks the technical signals search engines actually encounter. Nothing is auto-fixed by this audit.', 'seo-command-center' ); ?></p>
			</div>
			<div>
				<label for="scc-technical-limit" class="screen-reader-text"><?php esc_html_e( 'Audit size', 'seo-command-center' ); ?></label>
				<select id="scc-technical-limit">
					<option value="50"><?php esc_html_e( 'Quick · 50 URLs', 'seo-command-center' ); ?></option>
					<option value="150" selected><?php esc_html_e( 'Full · 150 URLs', 'seo-command-center' ); ?></option>
					<option value="300"><?php esc_html_e( 'Deep · 300 URLs', 'seo-command-center' ); ?></option>
				</select>
				<button type="button" class="button button-primary" id="scc-run-technical-audit"><?php echo $technical ? esc_html__( 'Run fresh technical audit', 'seo-command-center' ) : esc_html__( 'Run technical audit', 'seo-command-center' ); ?></button>
			</div>
		</div>
		<p><span class="scc-inline-status" id="scc-technical-status"></span></p>

		<?php if ( ! $technical ) : ?>
			<div class="scc-empty">
				<div class="scc-empty__icon" aria-hidden="true">🛠️</div>
				<h2><?php esc_html_e( 'No technical audit yet', 'seo-command-center' ); ?></h2>
				<p><?php esc_html_e( 'Run the audit to inspect live rendered URLs, robots/indexability, XML sitemaps, canonicals, metadata, heading structure, internal-link architecture, redirect/error targets, structured data, mobile basics, mixed content, images and response-time outliers.', 'seo-command-center' ); ?></p>
			</div>
		<?php else : ?>
			<div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;margin:12px 0 18px;">
				<div style="min-width:145px;">
					<div class="scc-label"><?php esc_html_e( 'Technical SEO Health', 'seo-command-center' ); ?></div>
					<div style="font-size:42px;line-height:1;font-weight:700;"><?php echo esc_html( (int) $technical['score'] ); ?><span style="font-size:18px;font-weight:400;">/100</span></div>
				</div>
				<div>
					<span class="scc-flag scc-flag--prio-critical"><?php echo esc_html( (int) ( $technical['counts']['critical'] ?? 0 ) ); ?> <?php esc_html_e( 'critical', 'seo-command-center' ); ?></span>
					<span class="scc-flag scc-flag--prio-high"><?php echo esc_html( (int) ( $technical['counts']['high'] ?? 0 ) ); ?> <?php esc_html_e( 'high', 'seo-command-center' ); ?></span>
					<span class="scc-flag scc-flag--prio-medium"><?php echo esc_html( (int) ( $technical['counts']['medium'] ?? 0 ) ); ?> <?php esc_html_e( 'medium', 'seo-command-center' ); ?></span>
					<span class="scc-flag"><?php echo esc_html( (int) ( $technical['counts']['low'] ?? 0 ) ); ?> <?php esc_html_e( 'low', 'seo-command-center' ); ?></span>
					<p class="scc-note" style="margin-bottom:0;">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: pages crawled, 2: generated timestamp */
								__( '%1$d live URLs audited · Last run %2$s', 'seo-command-center' ),
								(int) ( $technical['pages'] ?? 0 ),
								(string) ( $technical['generated_at'] ?? '' )
							)
						);
						?>
					</p>
				</div>
			</div>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:16px 0 22px;">
				<?php foreach ( (array) ( $technical['categories'] ?? array() ) as $category ) : ?>
					<div style="padding:12px;border:1px solid #dcdcde;border-radius:6px;background:#fff;">
						<div class="scc-label"><?php echo esc_html( $category['label'] ); ?></div>
						<strong style="font-size:22px;"><?php echo esc_html( (int) $category['score'] ); ?>%</strong>
						<div class="scc-note"><?php echo esc_html( (int) $category['issues'] ); ?> <?php esc_html_e( 'issue types', 'seo-command-center' ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( empty( $technical['issues'] ) ) : ?>
				<p class="scc-ok"><strong><?php esc_html_e( 'No technical issues were found in the audited scope.', 'seo-command-center' ); ?></strong></p>
			<?php else : ?>
				<h3><?php esc_html_e( 'Prioritized technical findings', 'seo-command-center' ); ?></h3>
				<?php foreach ( (array) $technical['issues'] as $issue ) : ?>
					<details class="scc-cluster" style="margin:8px 0;" <?php echo in_array( $issue['severity'], array( 'critical', 'high' ), true ) ? 'open' : ''; ?>>
						<summary class="scc-cluster__head" style="cursor:pointer;">
							<span class="scc-flag scc-flag--prio-<?php echo esc_attr( $issue['severity'] ); ?>"><?php echo esc_html( $severity_labels[ $issue['severity'] ] ?? ucfirst( $issue['severity'] ) ); ?></span>
							<strong><?php echo esc_html( $issue['title'] ); ?></strong>
							<span class="scc-note"> · <?php echo esc_html( (int) $issue['affected_count'] ); ?> <?php esc_html_e( 'affected', 'seo-command-center' ); ?> · <?php echo esc_html( $issue['category'] ); ?></span>
						</summary>
						<div class="scc-cluster__body">
							<p><strong><?php esc_html_e( 'Why it matters:', 'seo-command-center' ); ?></strong> <?php echo esc_html( $issue['why_it_matters'] ); ?></p>
							<p><strong><?php esc_html_e( 'Recommended fix:', 'seo-command-center' ); ?></strong> <?php echo esc_html( $issue['fix'] ); ?></p>
							<?php if ( ! empty( $issue['examples'] ) ) : ?>
								<div class="scc-label"><?php esc_html_e( 'Evidence', 'seo-command-center' ); ?></div>
								<ul class="scc-options">
									<?php foreach ( (array) $issue['examples'] as $example ) : ?>
										<li>
											<?php if ( ! empty( $example['url'] ) ) : ?>
												<a href="<?php echo esc_url( $example['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $example['url'] ); ?></a>
											<?php endif; ?>
											<?php if ( ! empty( $example['evidence'] ) ) : ?>
												— <?php echo esc_html( $example['evidence'] ); ?>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					</details>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( ! empty( $technical['disclaimer'] ) ) : ?>
				<p class="scc-note"><?php echo esc_html( $technical['disclaimer'] ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
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
			<div class="scc-empty">
				<div class="scc-empty__icon" aria-hidden="true">🔌</div>
				<h2><?php esc_html_e( 'Connect Search Console', 'seo-command-center' ); ?></h2>
				<p><?php esc_html_e( 'See real queries ranking in positions 4–20 — your quickest wins.', 'seo-command-center' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( $connections_url ); ?>"><?php esc_html_e( 'Connect Search Console', 'seo-command-center' ); ?></a>
			</div>
		<?php else : ?>
			<span class="scc-inline-status" id="scc-gsc-status"></span>
			<div id="scc-gsc-results"></div>
		<?php endif; ?>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Competitor analysis', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'Compare a competitor’s public page structure. Respects robots.txt.', 'seo-command-center' ); ?></p>
		<p>
			<input type="url" class="regular-text" id="scc-competitor-url" placeholder="https://competitor.com/services/">
			<button class="button button-primary" id="scc-competitor-go"><?php esc_html_e( 'Analyze', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-competitor-status"></span>
		</p>
		<div id="scc-competitor-results"></div>
	</div>
</div>
