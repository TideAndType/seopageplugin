<?php
/**
 * Growth Lab: measured SEO growth systems that build on TideOrbit's core.
 *
 * @package SEO_Command_Center
 * @var array $data
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$pages = (array) ( $data['pages'] ?? array() );
$business = class_exists( 'SCC_Schema_Engine' ) ? SCC_Schema_Engine::business() : array();
$action = '';
$result = null;
$error = '';

if ( 'POST' === (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['scc_growth_action'] ) ) {
	$nonce = isset( $_POST['scc_growth_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['scc_growth_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'scc_growth_lab' ) ) {
		$error = __( 'Security check failed. Refresh the page and try again.', 'seo-command-center' );
	} else {
		$action = sanitize_key( wp_unslash( $_POST['scc_growth_action'] ) );
		switch ( $action ) {
			case 'preflight':
				$result = SCC_Preflight::evaluate( (int) ( $_POST['post_id'] ?? 0 ) );
				break;
			case 'link_boost':
				$result = SCC_Link_Boost::recommendations( (int) ( $_POST['post_id'] ?? 0 ), 15 );
				break;
			case 'link_apply':
				$result = SCC_Link_Boost::strengthen( (int) ( $_POST['post_id'] ?? 0 ), 5 );
				break;
			case 'gsc_cannibal':
				$result = SCC_GSC_Cannibalization::detect( true, 90 );
				break;
			case 'runtime_settings':
				SCC_Settings::update(
					array(
						'browser_runtime_mode' => sanitize_key( wp_unslash( $_POST['browser_runtime_mode'] ?? 'auto' ) ),
						'browser_runtime_url'  => esc_url_raw( trim( wp_unslash( $_POST['browser_runtime_url'] ?? '' ) ) ),
					)
				);
				SCC_Settings::update_credentials(
					array(
						'browser_runtime_key' => sanitize_text_field( wp_unslash( $_POST['browser_runtime_key'] ?? '' ) ),
					)
				);
				$result = array( 'saved' => true, 'message' => __( 'Browser Runtime settings saved.', 'seo-command-center' ) );
				break;
			case 'runtime_test':
				$result = SCC_Browser_Runtime::health();
				break;
			case 'local_grid':
				$result = SCC_Local_Grid::scan(
					array(
						'keyword'       => sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) ),
						'business_name' => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ),
						'domain'        => sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ),
						'place_id'      => sanitize_text_field( wp_unslash( $_POST['place_id'] ?? '' ) ),
						'lat'           => (float) ( $_POST['lat'] ?? 0 ),
						'lng'           => (float) ( $_POST['lng'] ?? 0 ),
						'size'          => (int) ( $_POST['size'] ?? 3 ),
						'spacing_km'    => (float) ( $_POST['spacing_km'] ?? 1 ),
					),
					true
				);
				break;
			case 'backlink_gap':
				$raw = sanitize_textarea_field( wp_unslash( $_POST['competitors'] ?? '' ) );
				$competitors = preg_split( '/[\r\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
				$result = SCC_Backlink_Gap::scan( $competitors, 150 );
				break;
		}
		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_message();
			$result = null;
		}
	}
}

if ( class_exists( 'SCC_Browser_Runtime' ) ) {
	SCC_Browser_Runtime::poll_due_jobs();
}
$clusters = class_exists( 'SCC_PageSpeed' ) ? SCC_PageSpeed::cluster_report() : array();
$last_grid = class_exists( 'SCC_Local_Grid' ) ? SCC_Local_Grid::last() : null;
$runtime_status = class_exists( 'SCC_Browser_Runtime' ) ? SCC_Browser_Runtime::last_status() : array();
$runtime_jobs = class_exists( 'SCC_Browser_Runtime' ) ? SCC_Browser_Runtime::active_jobs() : array();
$runtime_mode = class_exists( 'SCC_Browser_Runtime' ) ? SCC_Browser_Runtime::mode() : 'dataforseo';
$runtime_url = (string) SCC_Settings::get( 'browser_runtime_url', '' );
$credential_hints = SCC_Settings::credential_hints();
$runtime_key_hint = (string) ( $credential_hints['browser_runtime_key']['hint'] ?? '' );
$runtime_key_configured = ! empty( $credential_hints['browser_runtime_key']['configured'] );
$last_backlinks = class_exists( 'SCC_Backlink_Gap' ) ? SCC_Backlink_Gap::last() : null;

$page_options = function ( $published_only = false ) use ( $pages ) {
	foreach ( $pages as $p ) {
		if ( $published_only && 'publish' !== (string) $p->post_status ) { continue; }
		printf(
			'<option value="%1$d">%2$s — %3$s</option>',
			(int) $p->ID,
			esc_html( get_the_title( $p ) ? get_the_title( $p ) : '#' . $p->ID ),
			esc_html( $p->post_status )
		);
	}
};
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<span class="scc-phase-badge">✦ <?php esc_html_e( 'Measured Growth', 'seo-command-center' ); ?></span>
		<h1><?php esc_html_e( 'SEO Growth Lab', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Turn Search Console, internal authority, Maps visibility, backlinks and Core Web Vitals into concrete actions. No ranking data is invented and destructive changes stay review-first.', 'seo-command-center' ); ?></p>
	</div>

	<?php if ( $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<div class="scc-columns">
		<div class="scc-card">
			<h2><?php esc_html_e( 'SEO Preflight', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'Run the same site-aware QA that now gates TideOrbit publishing: repeated copy, duplicate FAQs, wrong-city template bleed, title/intent mismatch and measured cannibalization.', 'seo-command-center' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'scc_growth_lab', 'scc_growth_nonce' ); ?>
				<input type="hidden" name="scc_growth_action" value="preflight">
				<select name="post_id" required><?php $page_options( false ); ?></select>
				<button class="button button-primary"><?php esc_html_e( 'Run preflight', 'seo-command-center' ); ?></button>
			</form>
		</div>

		<div class="scc-card">
			<h2><?php esc_html_e( 'GSC Link Boost', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'Find the strongest pages that can pass contextual internal authority into a ranking page. Search Console position and demand influence priority.', 'seo-command-center' ); ?></p>
			<form method="post" style="margin-bottom:8px">
				<?php wp_nonce_field( 'scc_growth_lab', 'scc_growth_nonce' ); ?>
				<input type="hidden" name="scc_growth_action" value="link_boost">
				<select name="post_id" required><?php $page_options( true ); ?></select>
				<button class="button button-primary"><?php esc_html_e( 'Find boost links', 'seo-command-center' ); ?></button>
			</form>
			<form method="post">
				<?php wp_nonce_field( 'scc_growth_lab', 'scc_growth_nonce' ); ?>
				<input type="hidden" name="scc_growth_action" value="link_apply">
				<select name="post_id" required><?php $page_options( true ); ?></select>
				<button class="button"><?php esc_html_e( 'Strengthen page · top 5 safe links', 'seo-command-center' ); ?></button>
			</form>
		</div>
	</div>

	<?php if ( 'preflight' === $action && is_array( $result ) ) : ?>
		<div class="scc-card">
			<div class="scc-card__head"><h2><?php esc_html_e( 'Preflight result', 'seo-command-center' ); ?></h2><strong><?php echo esc_html( strtoupper( (string) $result['status'] ) . ' · ' . (int) $result['score'] . '/100' ); ?></strong></div>
			<?php if ( empty( $result['issues'] ) ) : ?><p class="scc-ok"><?php esc_html_e( 'No preflight issues found.', 'seo-command-center' ); ?></p><?php endif; ?>
			<?php foreach ( (array) $result['issues'] as $issue ) : ?>
				<p><strong><?php echo esc_html( strtoupper( $issue['severity'] ) . ' · ' . $issue['title'] ); ?></strong><br><?php echo esc_html( $issue['evidence'] ); ?><?php if ( ! empty( $issue['blocking'] ) ) : ?> <span class="scc-badge scc-badge--warn"><?php esc_html_e( 'Blocks TideOrbit publish', 'seo-command-center' ); ?></span><?php endif; ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( 'link_boost' === $action && is_array( $result ) ) : ?>
		<div class="scc-card">
			<h2><?php esc_html_e( 'Best authority routes into this page', 'seo-command-center' ); ?></h2>
			<?php $tm = (array) ( $result['target_metrics'] ?? array() ); ?>
			<p class="scc-note"><?php echo $tm ? esc_html( sprintf( 'Target: position %.1f · %d impressions · %d clicks', (float) $tm['position'], (int) $tm['impressions'], (int) $tm['clicks'] ) ) : esc_html__( 'Search Console has no page-level metrics for this URL yet; relevance still works.', 'seo-command-center' ); ?></p>
			<table class="widefat striped scc-table"><thead><tr><th><?php esc_html_e( 'Source page', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Anchor', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Relevance', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Boost priority', 'seo-command-center' ); ?></th></tr></thead><tbody>
			<?php foreach ( (array) ( $result['recommendations'] ?? array() ) as $rec ) : ?>
				<tr><td><?php echo esc_html( get_the_title( (int) $rec['source_post_id'] ) ); ?></td><td><?php echo esc_html( $rec['anchor'] ); ?></td><td><?php echo esc_html( (int) $rec['confidence'] . '%' ); ?></td><td><strong><?php echo esc_html( (int) $rec['boost_score'] . '/100' ); ?></strong></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
	<?php elseif ( 'link_apply' === $action && is_array( $result ) ) : ?>
		<div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Applied %d high-confidence internal link(s). Existing TideOrbit change history can undo them.', 'seo-command-center' ), (int) ( $result['applied_count'] ?? 0 ) ) ); ?></p></div>
	<?php endif; ?>

	<div class="scc-card">
		<div class="scc-card__head">
			<div><h2><?php esc_html_e( 'Search Console Cannibalization', 'seo-command-center' ); ?></h2><p class="scc-note"><?php esc_html_e( 'Find queries where Google is splitting impressions across multiple URLs, then identify the strongest keeper.', 'seo-command-center' ); ?></p></div>
			<form method="post"><?php wp_nonce_field( 'scc_growth_lab', 'scc_growth_nonce' ); ?><input type="hidden" name="scc_growth_action" value="gsc_cannibal"><button class="button button-primary"><?php esc_html_e( 'Scan 90 days', 'seo-command-center' ); ?></button></form>
		</div>
		<?php if ( 'gsc_cannibal' === $action && is_array( $result ) ) : ?>
			<?php if ( empty( $result['available'] ) ) : ?><p><?php echo esc_html( $result['reason'] ?? 'Search Console unavailable.' ); ?></p>
			<?php elseif ( empty( $result['groups'] ) ) : ?><p class="scc-ok"><?php esc_html_e( 'No meaningful GSC cannibalization groups found.', 'seo-command-center' ); ?></p>
			<?php else : ?>
				<table class="widefat striped scc-table"><thead><tr><th><?php esc_html_e( 'Query', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Risk', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Impressions', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Keeper', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Competing URLs', 'seo-command-center' ); ?></th></tr></thead><tbody>
				<?php foreach ( array_slice( (array) $result['groups'], 0, 50 ) as $group ) : ?>
					<tr><td><strong><?php echo esc_html( $group['query'] ); ?></strong></td><td><?php echo esc_html( strtoupper( $group['risk'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $group['total_impressions'] ) ); ?></td><td><code><?php echo esc_html( wp_parse_url( $group['keeper']['url'], PHP_URL_PATH ) ?: '/' ); ?></code></td><td><?php echo esc_html( count( $group['pages'] ) - 1 ); ?></td></tr>
				<?php endforeach; ?></tbody></table>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<div>
				<h2><?php esc_html_e( 'TideOrbit Browser Runtime', 'seo-command-center' ); ?></h2>
				<p class="scc-note"><?php esc_html_e( 'Automatic mode uses your Cloudflare-tunneled local Chromium scanner first and falls back to DataForSEO if the tunnel/browser is unavailable.', 'seo-command-center' ); ?></p>
			</div>
			<?php if ( ! empty( $runtime_status['state'] ) ) : ?>
				<strong><?php echo esc_html( strtoupper( (string) $runtime_status['state'] ) ); ?></strong>
			<?php endif; ?>
		</div>
		<form method="post">
			<?php wp_nonce_field( 'scc_growth_lab', 'scc_growth_nonce' ); ?>
			<input type="hidden" name="scc_growth_action" value="runtime_settings">
			<div class="scc-columns">
				<p>
					<label><strong><?php esc_html_e( 'Execution mode', 'seo-command-center' ); ?></strong><br>
					<select name="browser_runtime_mode">
						<option value="auto" <?php selected( $runtime_mode, 'auto' ); ?>><?php esc_html_e( 'Automatic: Browser → DataForSEO', 'seo-command-center' ); ?></option>
						<option value="browser" <?php selected( $runtime_mode, 'browser' ); ?>><?php esc_html_e( 'Browser only', 'seo-command-center' ); ?></option>
						<option value="dataforseo" <?php selected( $runtime_mode, 'dataforseo' ); ?>><?php esc_html_e( 'DataForSEO only', 'seo-command-center' ); ?></option>
					</select></label>
				</p>
				<p>
					<label><strong><?php esc_html_e( 'Cloudflare Tunnel URL', 'seo-command-center' ); ?></strong><br>
					<input type="url" name="browser_runtime_url" class="regular-text" value="<?php echo esc_attr( $runtime_url ); ?>" placeholder="https://your-tunnel.trycloudflare.com"></label>
				</p>
				<p>
					<label><strong><?php esc_html_e( 'Pairing key', 'seo-command-center' ); ?></strong><br>
					<input type="password" name="browser_runtime_key" class="regular-text" value="" placeholder="<?php echo esc_attr( $runtime_key_configured ? $runtime_key_hint : 'Paste key from Google Maps SERP → Settings → TideOrbit' ); ?>"></label>
				</p>
			</div>
			<p>
				<button class="button button-primary"><?php esc_html_e( 'Save Browser Runtime', 'seo-command-center' ); ?></button>
				<?php if ( $runtime_key_configured ) : ?><span class="scc-note"><?php echo esc_html( sprintf( __( 'Pairing key saved: %s', 'seo-command-center' ), $runtime_key_hint ) ); ?></span><?php endif; ?>
			</p>
		</form>
		<form method="post" style="margin-top:8px">
			<?php wp_nonce_field( 'scc_growth_lab', 'scc_growth_nonce' ); ?>
			<input type="hidden" name="scc_growth_action" value="runtime_test">
			<button class="button"><?php esc_html_e( 'Test Browser Bridge', 'seo-command-center' ); ?></button>
			<?php if ( $runtime_jobs ) : ?>
				<span class="scc-note"><?php echo esc_html( sprintf( _n( '%d browser scan active', '%d browser scans active', count( $runtime_jobs ), 'seo-command-center' ), count( $runtime_jobs ) ) ); ?></span>
			<?php endif; ?>
		</form>
		<?php if ( 'runtime_test' === $action && is_array( $result ) && ! empty( $result['ok'] ) ) : ?>
			<p class="scc-ok"><?php esc_html_e( 'Connected. The tunneled Playwright scanner is online and accepting jobs.', 'seo-command-center' ); ?></p>
		<?php elseif ( 'runtime_settings' === $action && is_array( $result ) && ! empty( $result['saved'] ) ) : ?>
			<p class="scc-ok"><?php echo esc_html( $result['message'] ); ?></p>
		<?php elseif ( ! empty( $runtime_status['message'] ) ) : ?>
			<p class="scc-note"><?php echo esc_html( $runtime_status['message'] ); ?></p>
		<?php endif; ?>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Local Map Grid', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'In Automatic mode TideOrbit sends the grid to your local Playwright/Chromium scanner through Cloudflare Tunnel. If it cannot connect, DataForSEO is used as the fallback. Browser scans run asynchronously so WordPress never waits for Chrome.', 'seo-command-center' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'scc_growth_lab', 'scc_growth_nonce' ); ?>
			<input type="hidden" name="scc_growth_action" value="local_grid">
			<div class="scc-columns">
				<p><label><strong><?php esc_html_e( 'Keyword', 'seo-command-center' ); ?></strong><br><input type="text" name="keyword" class="regular-text" placeholder="marketing agency" required></label></p>
				<p><label><strong><?php esc_html_e( 'Business name', 'seo-command-center' ); ?></strong><br><input type="text" name="business_name" class="regular-text" value="<?php echo esc_attr( $business['organization_name'] ?? '' ); ?>"></label></p>
				<p><label><strong><?php esc_html_e( 'Domain', 'seo-command-center' ); ?></strong><br><input type="text" name="domain" class="regular-text" value="<?php echo esc_attr( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?>"></label></p>
				<p><label><strong><?php esc_html_e( 'Google Place ID / CID', 'seo-command-center' ); ?></strong><br><input type="text" name="place_id" class="regular-text" placeholder="Optional but improves exact matching"></label></p>
				<p><label><strong><?php esc_html_e( 'Center latitude', 'seo-command-center' ); ?></strong><br><input type="number" step="0.0000001" name="lat" required></label> &nbsp; <label><strong><?php esc_html_e( 'Longitude', 'seo-command-center' ); ?></strong><br><input type="number" step="0.0000001" name="lng" required></label></p>
				<p><label><strong><?php esc_html_e( 'Grid', 'seo-command-center' ); ?></strong><br><select name="size"><option value="3">3×3</option><option value="5">5×5</option></select></label> &nbsp; <label><strong><?php esc_html_e( 'Spacing km', 'seo-command-center' ); ?></strong><br><input type="number" min="0.2" max="10" step="0.1" name="spacing_km" value="1"></label></p>
			</div>
			<button class="button button-primary"><?php esc_html_e( 'Run Maps grid', 'seo-command-center' ); ?></button>
		</form>
		<?php
		$queued_grid = 'local_grid' === $action && is_array( $result ) && ! empty( $result['queued'] );
		$grid = ( 'local_grid' === $action && is_array( $result ) && ! empty( $result['points'] ) ) ? $result : $last_grid;
		?>
		<?php if ( $queued_grid ) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( $result['message'] ?? __( 'Browser grid started. TideOrbit will import it automatically.', 'seo-command-center' ) ); ?> <strong><?php echo esc_html( '#' . (string) ( $result['scan_id'] ?? '' ) ); ?></strong></p></div>
		<?php endif; ?>
		<?php if ( is_array( $grid ) && ! empty( $grid['points'] ) ) : ?>
			<h3><?php echo esc_html( sprintf( '%s · %.1f%% visible · average found rank %s', $grid['keyword'], (float) $grid['visibility_pct'], null === $grid['average_rank'] ? '—' : $grid['average_rank'] ) ); ?></h3>
			<div style="display:grid;grid-template-columns:repeat(<?php echo (int) $grid['size']; ?>,minmax(72px,1fr));gap:8px;max-width:620px">
				<?php foreach ( $grid['points'] as $point ) : $rank = $point['rank']; ?>
					<div class="scc-card" style="margin:0;padding:14px;text-align:center"><strong style="font-size:20px"><?php echo null === $rank ? '—' : esc_html( (int) $rank ); ?></strong><br><span class="scc-note"><?php echo esc_html( round( $point['lat'], 4 ) . ', ' . round( $point['lng'], 4 ) ); ?></span></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Backlink Gap', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'Find domains that link to competitors but not to you. TideOrbit prioritizes authority, associations, news/media, editorial resources and relevant agency directories; it never auto-submits.', 'seo-command-center' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'scc_growth_lab', 'scc_growth_nonce' ); ?>
			<input type="hidden" name="scc_growth_action" value="backlink_gap">
			<textarea name="competitors" class="large-text code" rows="4" placeholder="competitor-one.com&#10;competitor-two.com" required></textarea>
			<p><button class="button button-primary"><?php esc_html_e( 'Find backlink gaps', 'seo-command-center' ); ?></button></p>
		</form>
		<?php $backlinks = ( 'backlink_gap' === $action && is_array( $result ) ) ? $result : $last_backlinks; ?>
		<?php if ( is_array( $backlinks ) && ! empty( $backlinks['opportunities'] ) ) : ?>
			<p><strong><?php echo esc_html( sprintf( __( '%d high-priority opportunities', 'seo-command-center' ), (int) $backlinks['high_priority'] ) ); ?></strong></p>
			<table class="widefat striped scc-table"><thead><tr><th><?php esc_html_e( 'Referring domain', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Competitors hit', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Priority', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Next move', 'seo-command-center' ); ?></th></tr></thead><tbody>
			<?php foreach ( array_slice( $backlinks['opportunities'], 0, 75 ) as $item ) : ?>
				<tr><td><strong><?php echo esc_html( $item['domain'] ); ?></strong></td><td><?php echo esc_html( str_replace( '_', ' ', $item['type'] ) ); ?></td><td><?php echo esc_html( (int) $item['competitor_hits'] ); ?></td><td><?php echo esc_html( strtoupper( $item['priority'] ) . ' · ' . (int) $item['priority_score'] ); ?></td><td><?php echo esc_html( $item['recommended_action'] ); ?></td></tr>
			<?php endforeach; ?></tbody></table>
		<?php endif; ?>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Core Web Vitals Root Causes', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'Uses the latest SEO Doctor PageSpeed run. Repeated failures are grouped by shared WordPress/Elementor template signature.', 'seo-command-center' ); ?></p>
		<?php if ( empty( $clusters['available'] ) ) : ?>
			<p><?php esc_html_e( 'Run an SEO Doctor check-up first to collect PageSpeed/CrUX measurements.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<table class="widefat striped scc-table"><thead><tr><th><?php esc_html_e( 'Template signature', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Pages', 'seo-command-center' ); ?></th><th>LCP</th><th>CLS</th><th>INP/TBT</th><th><?php esc_html_e( 'Root-cause likelihood', 'seo-command-center' ); ?></th></tr></thead><tbody>
			<?php foreach ( (array) $clusters['clusters'] as $cluster ) : ?>
				<tr><td><code><?php echo esc_html( $cluster['signature'] ); ?></code></td><td><?php echo esc_html( (int) $cluster['page_count'] ); ?></td><td><?php echo esc_html( (int) $cluster['slow_lcp'] ); ?></td><td><?php echo esc_html( (int) $cluster['bad_cls'] ); ?></td><td><?php echo esc_html( (int) $cluster['slow_interaction'] ); ?></td><td><strong><?php echo esc_html( strtoupper( $cluster['root_cause_likelihood'] ) ); ?></strong></td></tr>
			<?php endforeach; ?></tbody></table>
		<?php endif; ?>
	</div>
</div>
