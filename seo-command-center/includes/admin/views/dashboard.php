<?php
/**
 * Dashboard view.
 *
 * The SEO Doctor is the single place that diagnoses the site — technical
 * audit, content analysis, speed, links, AI search and Search Console all feed
 * it — so the dashboard no longer repeats those findings in separate cards.
 *
 * @package SEO_Command_Center
 * @var array $data View data (seo_plugin, elementor, usage, doctor).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$usage  = isset( $data['usage'] ) ? $data['usage'] : array();
$doctor = isset( $data['doctor'] ) && is_array( $data['doctor'] ) ? $data['doctor'] : null;
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

	<?php
	// SEO Doctor — rendered by admin.js from the stored diagnosis (embedded
	// below) so fixes and re-runs update it in place without a reload.
	?>
	<div class="scc-card scc-doctor" id="scc-doctor">
		<div class="scc-card__head">
			<div>
				<h2><?php esc_html_e( 'SEO Doctor', 'seo-command-center' ); ?></h2>
				<p class="scc-note" style="margin:2px 0 0;"><?php esc_html_e( 'Everything wrong with your site, worst first — with what to do about it.', 'seo-command-center' ); ?></p>
			</div>
			<span class="scc-doctor__actions">
				<label for="scc-doctor-limit" class="screen-reader-text"><?php esc_html_e( 'How many pages to crawl', 'seo-command-center' ); ?></label>
				<select id="scc-doctor-limit" title="<?php esc_attr_e( 'How many pages the check-up crawls', 'seo-command-center' ); ?>">
					<option value="50"><?php esc_html_e( 'Quick · 50 pages', 'seo-command-center' ); ?></option>
					<option value="150" selected><?php esc_html_e( 'Full · 150 pages', 'seo-command-center' ); ?></option>
					<option value="300"><?php esc_html_e( 'Deep · 300 pages', 'seo-command-center' ); ?></option>
				</select>
				<button class="button" id="scc-doctor-refresh" <?php disabled( ! $doctor ); ?>><?php esc_html_e( 'Quick refresh', 'seo-command-center' ); ?></button>
				<button class="button button-primary" id="scc-doctor-run"><?php echo $doctor ? esc_html__( 'Run full check-up', 'seo-command-center' ) : esc_html__( 'Run my first check-up', 'seo-command-center' ); ?></button>
			</span>
		</div>
		<span class="scc-inline-status" id="scc-doctor-status" aria-live="polite"></span>
		<div id="scc-doctor-body">
			<?php if ( ! $doctor ) : ?>
				<div class="scc-empty">
					<div class="scc-empty__icon" aria-hidden="true">🩺</div>
					<h2><?php esc_html_e( 'Get a full diagnosis of your site', 'seo-command-center' ); ?></h2>
					<p><?php esc_html_e( 'The check-up reads your content, crawls your pages, measures real page speed with Google, and combines it with your links, site structure, AI-search readiness and Search Console data into one ranked list of problems — each with how to fix it, and a one-click fix where it is safe. It takes a few minutes on a larger site.', 'seo-command-center' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<script type="application/json" id="scc-doctor-data"><?php echo wp_json_encode( array( 'report' => $doctor, 'admin' => admin_url( 'admin.php?page=' ) ) ); ?></script>
	</div>

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
</div>
