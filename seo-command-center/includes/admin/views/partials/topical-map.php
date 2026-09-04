<?php
/**
 * Partial: render a topical map ($map in scope).
 *
 * @package SEO_Command_Center
 * @var array $map Topical map (clusters, entities, notes).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clusters = isset( $map['clusters'] ) ? (array) $map['clusters'] : array();
$existing_count = isset( $map['existing_count'] ) ? (int) $map['existing_count'] : count( array_filter( $clusters, function ( $c ) { return isset( $c['status'] ) && 'existing' === $c['status']; } ) );
$new_count      = isset( $map['new_count'] ) ? (int) $map['new_count'] : ( count( $clusters ) - $existing_count );
?>
<?php
$provider_labels = array( 'lmstudio' => 'LM Studio', 'gemini' => 'Google Gemini', 'claude' => 'Anthropic Claude', 'openai' => 'OpenAI' );
$gen_by    = isset( $map['generated_by'] ) ? (string) $map['generated_by'] : '';
$gen_model = isset( $map['generated_model'] ) ? (string) $map['generated_model'] : '';
if ( '' !== $gen_by ) :
	$gen_label = isset( $provider_labels[ $gen_by ] ) ? $provider_labels[ $gen_by ] : $gen_by;
	?>
	<p class="scc-note"><strong><?php esc_html_e( 'Generated with:', 'seo-command-center' ); ?></strong>
		<?php echo esc_html( $gen_label ); ?><?php echo '' !== $gen_model ? ' · ' . esc_html( $gen_model ) : ''; ?></p>
<?php endif; ?>
<?php if ( $existing_count || $new_count ) : ?>
	<p class="scc-note">
		<?php
		echo esc_html( sprintf(
			/* translators: 1: new count, 2: existing count */
			__( 'Showing %1$d new opportunity(ies). Your %2$d existing page(s) were used to avoid duplicates and aren’t listed here.', 'seo-command-center' ),
			$new_count,
			$existing_count
		) );
		?>
	</p>
<?php endif; ?>
<?php if ( ! empty( $map['notes'] ) ) : ?>
	<p class="scc-note"><?php echo esc_html( $map['notes'] ); ?></p>
<?php endif; ?>

<?php if ( ! empty( $map['gsc_connected'] ) ) : ?>
	<p class="scc-note"><strong><?php esc_html_e( 'Grounded in Google Search Console:', 'seo-command-center' ); ?></strong>
		<?php esc_html_e( 'suggestions below use your real search queries and impressions.', 'seo-command-center' ); ?></p>
	<?php if ( ! empty( $map['gsc_quick_wins'] ) ) : ?>
		<details class="scc-gsc-wins" open>
			<summary><strong><?php esc_html_e( 'Search Console quick wins', 'seo-command-center' ); ?></strong> — <?php esc_html_e( 'queries you already rank on page 1-2 for (real data). Great candidates for a dedicated page.', 'seo-command-center' ); ?></summary>
			<table class="widefat striped scc-gsc-table" id="scc-gsc-wins-table">
				<thead><tr>
					<th><?php esc_html_e( 'Query', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Avg. position', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Action', 'seo-command-center' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( (array) $map['gsc_quick_wins'] as $w ) : ?>
					<?php $q = (string) ( $w['query'] ?? '' ); ?>
					<tr>
						<td><?php echo esc_html( $q ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $w['impressions'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $w['clicks'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( $w['position'] ?? '' ); ?></td>
						<td>
							<button type="button" class="button button-small button-primary scc-gsc-plan-btn" data-query="<?php echo esc_attr( $q ); ?>"><?php esc_html_e( 'Create page', 'seo-command-center' ); ?></button>
							<span class="scc-inline-status scc-gsc-plan-status"></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="scc-note"><?php esc_html_e( '“Create page” adds this query to your Content Plan as a new article; open Content Plan to generate the draft.', 'seo-command-center' ); ?></p>
		</details>
	<?php endif; ?>
<?php endif; ?>

<?php
/**
 * Render a "Generate brief" button + output container for a topic node.
 *
 * @param array  $node Node data (title, url, primary_keyword, intent, page_type, secondary).
 */
$brief_button = function ( $node ) {
	$payload = wp_json_encode( array(
		'title'           => $node['title'],
		'url'             => $node['url'],
		'primary_keyword' => $node['primary_keyword'],
		'intent'          => $node['intent'],
		'page_type'       => $node['page_type'],
		'secondary'       => $node['secondary'],
	) );
	?>
	<div class="scc-brief-wrap">
		<button type="button" class="button button-small scc-brief-btn" data-topic="<?php echo esc_attr( $payload ); ?>"><?php esc_html_e( 'Generate content brief', 'seo-command-center' ); ?></button>
		<span class="scc-inline-status scc-brief-status"></span>
		<div class="scc-brief-out" hidden></div>
	</div>
	<?php
};
?>

<?php
$any_shown = false;
foreach ( $clusters as $c ) :
	$is_existing = isset( $c['status'] ) && 'existing' === $c['status'];
	$priority    = isset( $c['priority'] ) ? $c['priority'] : 'medium';
	$score       = isset( $c['authority_score'] ) ? (int) $c['authority_score'] : 0;

	// Only surface NEW opportunities. Existing pages are used for context but
	// not displayed. Keep an existing pillar only if it has new subtopics.
	$new_subs = array();
	foreach ( (array) ( $c['subtopics'] ?? array() ) as $s ) {
		if ( ! ( isset( $s['status'] ) && 'existing' === $s['status'] ) ) {
			$new_subs[] = $s;
		}
	}
	if ( $is_existing && empty( $new_subs ) ) {
		continue;
	}
	$any_shown = true;
	?>
	<div class="scc-cluster scc-pillar<?php echo $is_existing ? ' is-existing' : ''; ?>">
		<div class="scc-cluster__head">
			<strong><?php echo esc_html( $c['service'] ); ?><?php echo ! empty( $c['location'] ) ? ' — ' . esc_html( $c['location'] ) : ''; ?></strong>
			<?php if ( $is_existing ) : ?>
				<span class="scc-badge"><?php esc_html_e( 'Your page', 'seo-command-center' ); ?></span>
			<?php else : ?>
				<span class="scc-badge"><?php esc_html_e( 'Gap · new', 'seo-command-center' ); ?></span>
			<?php endif; ?>
			<span class="scc-flag scc-flag--<?php echo esc_attr( $c['page_type'] ); ?>"><?php echo esc_html( $c['page_type'] ); ?></span>
			<span class="scc-flag"><?php echo esc_html( $c['intent'] ); ?></span>
			<span class="scc-flag scc-flag--prio-<?php echo esc_attr( $priority ); ?>"><?php echo esc_html( sprintf( /* translators: %s: priority */ __( '%s priority', 'seo-command-center' ), ucfirst( $priority ) ) ); ?></span>
			<?php if ( $score ) : ?>
				<span class="scc-flag" title="<?php esc_attr_e( 'Strategic topical-authority score (opinion, not measured search data)', 'seo-command-center' ); ?>"><?php echo esc_html( sprintf( /* translators: %d: score */ __( 'Authority %d', 'seo-command-center' ), $score ) ); ?></span>
			<?php endif; ?>
		</div>
		<div class="scc-cluster__body">
			<?php if ( ! $is_existing ) : ?>
				<div><span class="scc-label"><?php esc_html_e( 'Primary keyword', 'seo-command-center' ); ?>:</span> <?php echo esc_html( $c['primary_keyword'] ); ?></div>
				<?php if ( ! empty( $c['meta_title'] ) ) : ?>
					<div><span class="scc-label"><?php esc_html_e( 'Meta title', 'seo-command-center' ); ?>:</span> <?php echo esc_html( $c['meta_title'] ); ?></div>
				<?php endif; ?>
				<?php if ( ! empty( $c['supporting_terms'] ) ) : ?>
					<div><span class="scc-label"><?php esc_html_e( 'Supporting', 'seo-command-center' ); ?>:</span> <?php echo esc_html( implode( ', ', $c['supporting_terms'] ) ); ?></div>
				<?php endif; ?>
				<div><span class="scc-label"><?php esc_html_e( 'Recommended URL', 'seo-command-center' ); ?>:</span> <code><?php echo esc_html( $c['recommended_url'] ); ?></code></div>
				<?php if ( ! empty( $c['rationale'] ) ) : ?>
					<div class="scc-note"><?php echo esc_html( $c['rationale'] ); ?></div>
				<?php endif; ?>

				<?php
				$brief_button( array(
					'title'           => ( '' !== (string) $c['service'] ? $c['service'] : $c['primary_keyword'] ) . ( ! empty( $c['location'] ) ? ' — ' . $c['location'] : '' ),
					'url'             => $c['recommended_url'],
					'primary_keyword' => $c['primary_keyword'],
					'intent'          => $c['intent'],
					'page_type'       => $c['page_type'],
					'secondary'       => isset( $c['supporting_terms'] ) ? $c['supporting_terms'] : array(),
				) );
				?>
			<?php else : ?>
				<div class="scc-note"><?php esc_html_e( 'New supporting content for a page you already have:', 'seo-command-center' ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $new_subs ) ) : ?>
				<div class="scc-subtopics">
					<div class="scc-label"><?php esc_html_e( 'New subtopics', 'seo-command-center' ); ?></div>
					<?php foreach ( $new_subs as $s ) : ?>
						<div class="scc-subtopic">
							<span class="scc-subtopic__title"><?php echo esc_html( $s['title'] ); ?></span>
							<span class="scc-badge"><?php esc_html_e( 'Gap · new', 'seo-command-center' ); ?></span>
							<span class="scc-flag"><?php echo esc_html( $s['intent'] ); ?></span>
							<?php if ( ! empty( $s['recommended_url'] ) ) : ?>
								<code><?php echo esc_html( $s['recommended_url'] ); ?></code>
							<?php endif; ?>
							<?php if ( ! empty( $s['primary_keyword'] ) ) : ?>
								<span class="scc-subtopic__kw"><?php echo esc_html( $s['primary_keyword'] ); ?></span>
							<?php endif; ?>
							<?php
							$brief_button( array(
								'title'           => $s['title'],
								'url'             => isset( $s['recommended_url'] ) ? $s['recommended_url'] : '',
								'primary_keyword' => isset( $s['primary_keyword'] ) ? $s['primary_keyword'] : $s['title'],
								'intent'          => isset( $s['intent'] ) ? $s['intent'] : 'informational',
								'page_type'       => 'article',
								'secondary'       => isset( $s['content_nodes'] ) ? $s['content_nodes'] : array(),
							) );
							?>
							<?php if ( ! empty( $s['content_nodes'] ) ) : ?>
								<ul class="scc-nodes">
									<?php foreach ( (array) $s['content_nodes'] as $node ) : ?>
										<li><?php echo esc_html( $node ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
<?php endforeach; ?>

<?php if ( ! $any_shown ) : ?>
	<div class="scc-cluster"><p class="scc-note" style="margin:4px 0;"><?php esc_html_e( 'No new opportunities were suggested this time — your existing pages already cover the map. Try a larger “Depth”, a different Map type, or a bigger AI model for more ideas.', 'seo-command-center' ); ?></p></div>
<?php endif; ?>

<?php if ( ! empty( $map['entities'] ) ) : ?>
	<div class="scc-entities">
		<span class="scc-label"><?php esc_html_e( 'Key entities', 'seo-command-center' ); ?>:</span>
		<?php echo esc_html( implode( ', ', $map['entities'] ) ); ?>
	</div>
<?php endif; ?>

<p class="scc-note" style="margin-top:16px;">
	<?php esc_html_e( 'Next: open Site Architecture to see this organized into a page tree, then send it to your Content Plan.', 'seo-command-center' ); ?>
</p>
