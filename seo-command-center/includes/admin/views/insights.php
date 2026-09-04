<?php
/**
 * Insights view — SEO health timeline, entity graph, experiments, AI visibility.
 *
 * @package SEO_Command_Center
 * @var array $data View data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$timeline = isset( $data['timeline'] ) ? (array) $data['timeline'] : array();
$entities = isset( $data['entities'] ) ? (array) $data['entities'] : array();
$experiments = isset( $data['experiments'] ) ? (array) $data['experiments'] : array();
$change_types = isset( $data['change_types'] ) ? (array) $data['change_types'] : array();
$ai = isset( $data['ai'] ) ? (array) $data['ai'] : array();

// Build a tiny sparkline for the health score.
$spark = '';
if ( count( $timeline ) > 1 ) {
	$scores = array_map( function ( $t ) { return (int) $t['health_score']; }, $timeline );
	$n = count( $scores );
	$w = 320; $h = 60; $max = 100;
	$pts = array();
	foreach ( $scores as $i => $s ) {
		$x = round( $i * ( $w / max( 1, $n - 1 ) ), 1 );
		$y = round( $h - ( $s / $max ) * $h, 1 );
		$pts[] = $x . ',' . $y;
	}
	$spark = '<svg width="100%" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="Health score trend"><polyline fill="none" stroke="#4f46e5" stroke-width="2" points="' . esc_attr( implode( ' ', $pts ) ) . '"/></svg>';
}
$latest_score = ! empty( $timeline ) ? (int) end( $timeline )['health_score'] : 0;
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<span class="scc-phase-badge">✦ <?php esc_html_e( 'SEO Intelligence', 'seo-command-center' ); ?></span>
		<h1><?php esc_html_e( 'Insights', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'How your SEO health is trending, the entities you should own, and the measured results of the changes you make — all from your real data.', 'seo-command-center' ); ?></p>
	</div>

	<!-- Health timeline -->
	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'SEO health timeline', 'seo-command-center' ); ?></h2>
			<button class="button" id="scc-snapshot"><?php esc_html_e( 'Capture snapshot', 'seo-command-center' ); ?></button>
		</div>
		<span class="scc-inline-status" id="scc-insights-msg"></span>
		<?php if ( empty( $timeline ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No snapshots yet. One is captured automatically each day; use “Capture snapshot” to record one now.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<div class="scc-health"><div class="scc-health__num"><?php echo esc_html( $latest_score ); ?><span>/100</span></div><div class="scc-health__spark"><?php echo $spark; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from ints */ ?></div></div>
			<table class="widefat striped scc-table">
				<thead><tr><th><?php esc_html_e( 'Date', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Health', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Clicks (28d)', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Impressions (28d)', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Open opportunities', 'seo-command-center' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_reverse( $timeline ) as $t ) : ?>
					<tr>
						<td><?php echo esc_html( $t['captured_on'] ); ?></td>
						<td><strong><?php echo esc_html( (int) $t['health_score'] ); ?></strong></td>
						<td><?php echo esc_html( $t['clicks'] > 0 ? number_format_i18n( (int) $t['clicks'] ) : '—' ); ?></td>
						<td><?php echo esc_html( $t['impressions'] > 0 ? number_format_i18n( (int) $t['impressions'] ) : '—' ); ?></td>
						<td><?php echo esc_html( (int) $t['opportunities_open'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="scc-note"><?php esc_html_e( 'Clicks/impressions are shown only when Google Search Console is connected; otherwise “—”. No numbers are estimated.', 'seo-command-center' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Entity graph -->
	<div class="scc-card">
		<h2><?php esc_html_e( 'Entity authority graph', 'seo-command-center' ); ?></h2>
		<?php if ( empty( $entities['available'] ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'Add your business details (Schema settings) and build a topical map to map your entities.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<p><?php echo esc_html( sprintf( /* translators: %d%% */ __( 'Entity coverage: %d%% of your key entities have a supporting page.', 'seo-command-center' ), (int) ( $entities['coverage'] ?? 0 ) ) ); ?></p>
			<div class="scc-entities">
				<?php foreach ( (array) ( $entities['nodes'] ?? array() ) as $node ) : ?>
					<span class="scc-entity scc-entity--<?php echo esc_attr( ! empty( $node['supported'] ) ? 'ok' : 'gap' ); ?>" title="<?php echo esc_attr( $node['type'] ); ?>"><?php echo esc_html( $node['label'] ); ?></span>
				<?php endforeach; ?>
			</div>
			<?php if ( ! empty( $entities['gaps'] ) ) : ?>
				<h3><?php esc_html_e( 'Weakly-supported entities', 'seo-command-center' ); ?></h3>
				<table class="widefat striped scc-table">
					<thead><tr><th><?php esc_html_e( 'Entity', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Recommendation', 'seo-command-center' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( (array) $entities['gaps'] as $g ) : ?>
						<tr><td><strong><?php echo esc_html( $g['entity'] ); ?></strong></td><td><span class="scc-flag"><?php echo esc_html( $g['type'] ); ?></span></td><td class="scc-note"><?php echo esc_html( $g['recommendation'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<!-- Experiments -->
	<div class="scc-card" id="scc-experiments">
		<h2><?php esc_html_e( 'SEO experiments', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'Record a change to a page and measure it against its Search Console baseline. Results are correlations over the measurement window — never proof of causation.', 'seo-command-center' ); ?></p>
		<div class="scc-exp-form">
			<input type="number" id="scc-exp-post" class="small-text" placeholder="<?php esc_attr_e( 'Post ID', 'seo-command-center' ); ?>">
			<select id="scc-exp-type">
				<?php foreach ( $change_types as $ct ) : ?>
					<option value="<?php echo esc_attr( $ct ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $ct ) ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" id="scc-exp-note" class="regular-text" placeholder="<?php esc_attr_e( 'What did you change?', 'seo-command-center' ); ?>">
			<button class="button button-primary" id="scc-exp-start"><?php esc_html_e( 'Start experiment', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-exp-msg"></span>
		</div>
		<?php if ( ! empty( $experiments ) ) : ?>
			<table class="widefat striped scc-table" id="scc-exp-table">
				<thead><tr><th><?php esc_html_e( 'Page', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Change', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Started', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Result', 'seo-command-center' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $experiments as $x ) : $res = $x['result'] ?? null; ?>
					<tr data-id="<?php echo esc_attr( $x['id'] ); ?>">
						<td><strong><?php echo esc_html( $x['title'] ); ?></strong></td>
						<td><span class="scc-flag"><?php echo esc_html( str_replace( '_', ' ', (string) $x['change_type'] ) ); ?></span></td>
						<td><?php echo esc_html( $x['start_date'] ); ?></td>
						<td><?php echo $res ? esc_html( $res['label'] ) . ' <span class="scc-note">(' . esc_html( (int) $res['confidence'] ) . '%)</span>' : '<span class="scc-note">' . esc_html__( 'measuring…', 'seo-command-center' ) . '</span>'; ?></td>
						<td><button class="button button-small scc-exp-eval"><?php esc_html_e( 'Evaluate', 'seo-command-center' ); ?></button> <button class="button button-small button-link-delete scc-exp-del"><?php esc_html_e( 'Delete', 'seo-command-center' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- AI visibility -->
	<div class="scc-card">
		<h2><?php esc_html_e( 'AI search visibility', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php echo esc_html( (string) ( $ai['disclaimer'] ?? '' ) ); ?></p>
		<div class="scc-entities">
			<?php foreach ( (array) ( $ai['providers'] ?? array() ) as $p ) : ?>
				<span class="scc-entity scc-entity--gap"><?php echo esc_html( $p['label'] ); ?> — <?php echo esc_html( $p['connected'] ? __( 'connected', 'seo-command-center' ) : __( 'not connected', 'seo-command-center' ) ); ?></span>
			<?php endforeach; ?>
		</div>
		<?php if ( ! empty( $ai['readiness'] ) ) : ?>
			<h3><?php esc_html_e( 'Citation readiness (what you can improve today)', 'seo-command-center' ); ?></h3>
			<ul class="scc-options">
				<?php foreach ( (array) $ai['readiness'] as $f ) : ?>
					<li><strong><?php echo esc_html( $f['label'] ); ?>:</strong> <?php echo esc_html( $f['known'] ? ( (int) $f['pct'] . '%' ) : __( 'not measured', 'seo-command-center' ) ); ?> <span class="scc-note"><?php echo esc_html( $f['hint'] ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</div>
