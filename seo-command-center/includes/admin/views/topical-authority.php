<?php
/**
 * Topical Authority dashboard view.
 *
 * @package SEO_Command_Center
 * @var array $data View data (card).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$card = isset( $data['card'] ) ? $data['card'] : array();
$has  = ! empty( $card['has_map'] );
$t    = isset( $card['totals'] ) ? $card['totals'] : array();

$status_label = array(
	'strong'    => __( 'Strong', 'seo-command-center' ),
	'attention' => __( 'Needs attention', 'seo-command-center' ),
	'missing'   => __( 'Missing', 'seo-command-center' ),
);
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<span class="scc-phase-badge">✦ <?php esc_html_e( 'Topical Authority Engine', 'seo-command-center' ); ?></span>
		<h1><?php esc_html_e( 'Topical Authority', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'How well your real WordPress content covers the topics you should own. Scored from your topical map, existing pages, internal links and cannibalization — all measured, nothing fabricated.', 'seo-command-center' ); ?></p>
	</div>

	<?php if ( ! $has ) : ?>
		<div class="scc-card scc-empty">
			<h2><?php esc_html_e( 'No topical map yet', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'Build a topical map first — this dashboard scores your coverage against it.', 'seo-command-center' ); ?></p>
			<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-keyword-strategy' ) ); ?>"><?php esc_html_e( 'Build from my site', 'seo-command-center' ); ?></a>
		</div>
	<?php else : ?>

		<div class="scc-columns">
			<!-- Score ring + component bars -->
			<div class="scc-card">
				<div class="scc-ta-score">
					<?php $score = (int) ( $card['score'] ?? 0 ); ?>
					<div class="scc-ta-ring" style="--pct:<?php echo esc_attr( $score ); ?>">
						<div class="scc-ta-ring__num"><?php echo esc_html( $score ); ?><span>/100</span></div>
					</div>
					<div>
						<h2 style="margin:0 0 4px;"><?php esc_html_e( 'Topical authority', 'seo-command-center' ); ?></h2>
						<p class="scc-note" style="margin:0;"><?php esc_html_e( 'A weighted, explainable blend of the components below. Unknown components (e.g. no analysis yet) are excluded, not guessed.', 'seo-command-center' ); ?></p>
					</div>
				</div>

				<div class="scc-ta-bars">
					<?php foreach ( (array) ( $card['components'] ?? array() ) as $comp ) : ?>
						<div class="scc-ta-bar">
							<div class="scc-ta-bar__head">
								<span><?php echo esc_html( $comp['label'] ); ?> <span class="scc-note">(<?php echo esc_html( (int) $comp['weight'] ); ?>%)</span></span>
								<strong><?php echo $comp['known'] ? esc_html( (int) $comp['pct'] . '%' ) : esc_html__( 'n/a', 'seo-command-center' ); ?></strong>
							</div>
							<div class="scc-ta-track">
								<div class="scc-ta-fill<?php echo $comp['known'] ? '' : ' is-unknown'; ?>" style="width:<?php echo esc_attr( $comp['known'] ? (int) $comp['pct'] : 0 ); ?>%"></div>
							</div>
							<?php if ( ! $comp['known'] ) : ?>
								<span class="scc-note"><?php esc_html_e( 'Not measured yet — run a site analysis to include this.', 'seo-command-center' ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Opportunities + totals -->
			<div class="scc-card">
				<h2><?php esc_html_e( 'Content opportunities', 'seo-command-center' ); ?></h2>
				<?php $o = isset( $card['opportunities'] ) ? $card['opportunities'] : array(); ?>
				<div class="scc-grid scc-stats" style="margin-bottom:14px;">
					<div class="scc-stat scc-stat--warn"><div class="scc-stat__num"><?php echo esc_html( number_format_i18n( (int) ( $o['high'] ?? 0 ) ) ); ?></div><div class="scc-stat__label"><?php esc_html_e( 'High priority', 'seo-command-center' ); ?></div></div>
					<div class="scc-stat"><div class="scc-stat__num"><?php echo esc_html( number_format_i18n( (int) ( $o['medium'] ?? 0 ) ) ); ?></div><div class="scc-stat__label"><?php esc_html_e( 'Medium', 'seo-command-center' ); ?></div></div>
					<div class="scc-stat"><div class="scc-stat__num"><?php echo esc_html( number_format_i18n( (int) ( $o['low'] ?? 0 ) ) ); ?></div><div class="scc-stat__label"><?php esc_html_e( 'Low', 'seo-command-center' ); ?></div></div>
				</div>

				<table class="scc-ta-totals">
					<tbody>
						<tr><td><?php esc_html_e( 'Topics mapped', 'seo-command-center' ); ?></td><td><strong><?php echo esc_html( number_format_i18n( (int) ( $t['topics'] ?? 0 ) ) ); ?></strong></td></tr>
						<tr><td><?php esc_html_e( 'Keywords', 'seo-command-center' ); ?></td><td><strong><?php echo esc_html( number_format_i18n( (int) ( $t['keywords'] ?? 0 ) ) ); ?></strong> <span class="scc-note">(<?php echo esc_html( number_format_i18n( (int) ( $t['covered_keywords'] ?? 0 ) ) ); ?> <?php esc_html_e( 'covered', 'seo-command-center' ); ?>, <?php echo esc_html( number_format_i18n( (int) ( $t['missing_keywords'] ?? 0 ) ) ); ?> <?php esc_html_e( 'missing', 'seo-command-center' ); ?>)</span></td></tr>
						<tr><td><?php esc_html_e( 'Existing pages mapped', 'seo-command-center' ); ?></td><td><strong><?php echo esc_html( number_format_i18n( (int) ( $t['existing_topics'] ?? 0 ) ) ); ?></strong></td></tr>
						<tr><td><?php esc_html_e( 'Missing / gap topics', 'seo-command-center' ); ?></td><td><strong><?php echo esc_html( number_format_i18n( (int) ( $t['missing_topics'] ?? 0 ) ) ); ?></strong></td></tr>
						<tr><td><?php esc_html_e( 'Possible cannibalization', 'seo-command-center' ); ?></td><td><strong><?php echo esc_html( number_format_i18n( (int) ( $t['cannibalization'] ?? 0 ) ) ); ?></strong> <a class="scc-note" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-seo-audit' ) ); ?>"><?php esc_html_e( 'review', 'seo-command-center' ); ?></a></td></tr>
						<tr><td><?php esc_html_e( 'Internal-link opportunities', 'seo-command-center' ); ?></td><td><strong><?php echo esc_html( number_format_i18n( (int) ( $t['link_opportunities'] ?? 0 ) ) ); ?></strong> <a class="scc-note" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-internal-links' ) ); ?>"><?php esc_html_e( 'review', 'seo-command-center' ); ?></a></td></tr>
					</tbody>
				</table>
			</div>
		</div>

		<!-- Clusters -->
		<div class="scc-card">
			<div class="scc-card__head">
				<h2><?php esc_html_e( 'Topic clusters', 'seo-command-center' ); ?></h2>
				<span class="scc-note">
					<?php echo esc_html( sprintf( /* translators: 1,2,3 counts */ __( '%1$d strong · %2$d need attention · %3$d missing', 'seo-command-center' ), (int) ( $t['clusters_strong'] ?? 0 ), (int) ( $t['clusters_attention'] ?? 0 ), (int) ( $t['clusters_missing'] ?? 0 ) ) ); ?>
				</span>
			</div>
			<table class="widefat striped scc-table">
				<thead><tr>
					<th><?php esc_html_e( 'Cluster', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Coverage', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Status', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Subtopics (have / gap)', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Priority', 'seo-command-center' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( (array) ( $card['clusters'] ?? array() ) as $cl ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $cl['name'] ); ?></strong><?php if ( ! empty( $cl['url'] ) ) : ?> <code><?php echo esc_html( $cl['url'] ); ?></code><?php endif; ?></td>
						<td>
							<div class="scc-ta-track scc-ta-track--sm"><div class="scc-ta-fill scc-ta-fill--<?php echo esc_attr( $cl['status'] ); ?>" style="width:<?php echo esc_attr( (int) $cl['score'] ); ?>%"></div></div>
							<span class="scc-note"><?php echo esc_html( (int) $cl['score'] ); ?>%</span>
						</td>
						<td><span class="scc-badge scc-ta-badge--<?php echo esc_attr( $cl['status'] ); ?>"><?php echo esc_html( $status_label[ $cl['status'] ] ?? $cl['status'] ); ?></span></td>
						<td><?php echo esc_html( (int) $cl['existing_subs'] ); ?> / <?php echo esc_html( (int) $cl['new_subs'] ); ?></td>
						<td><span class="scc-flag scc-flag--prio-<?php echo esc_attr( $cl['priority'] ); ?>"><?php echo esc_html( ucfirst( $cl['priority'] ) ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- Top opportunities -->
		<?php if ( ! empty( $o['items'] ) ) : ?>
			<div class="scc-card">
				<div class="scc-card__head">
					<h2><?php esc_html_e( 'Highest-value gaps to create next', 'seo-command-center' ); ?></h2>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-architecture' ) ); ?>"><?php esc_html_e( 'Send to Content Plan', 'seo-command-center' ); ?></a>
				</div>
				<table class="widefat striped scc-table">
					<thead><tr>
						<th><?php esc_html_e( 'Topic', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Under pillar', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Intent', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Recommended URL', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Priority', 'seo-command-center' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_slice( (array) $o['items'], 0, 40 ) as $it ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $it['title'] ); ?></strong></td>
							<td class="scc-note"><?php echo esc_html( $it['pillar'] ); ?></td>
							<td><span class="scc-flag"><?php echo esc_html( $it['intent'] ); ?></span></td>
							<td><?php if ( ! empty( $it['url'] ) ) : ?><code><?php echo esc_html( $it['url'] ); ?></code><?php endif; ?></td>
							<td><span class="scc-flag scc-flag--prio-<?php echo esc_attr( $it['priority'] ); ?>"><?php echo esc_html( ucfirst( $it['priority'] ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

	<?php endif; ?>
</div>
