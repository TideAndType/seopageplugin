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
			/* translators: 1: existing count, 2: new count */
			__( 'Mirrors your site: %1$d existing topic(s) and %2$d recommended new topic(s), across pillars and subtopics.', 'seo-command-center' ),
			$existing_count,
			$new_count
		) );
		?>
	</p>
<?php endif; ?>
<?php if ( ! empty( $map['notes'] ) ) : ?>
	<p class="scc-note"><?php echo esc_html( $map['notes'] ); ?></p>
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

<?php foreach ( $clusters as $c ) : ?>
	<?php
	$is_existing = isset( $c['status'] ) && 'existing' === $c['status'];
	$priority    = isset( $c['priority'] ) ? $c['priority'] : 'medium';
	$score       = isset( $c['authority_score'] ) ? (int) $c['authority_score'] : 0;
	?>
	<div class="scc-cluster scc-pillar<?php echo $is_existing ? ' is-existing' : ''; ?>">
		<div class="scc-cluster__head">
			<strong><?php echo esc_html( $c['service'] ); ?><?php echo ! empty( $c['location'] ) ? ' — ' . esc_html( $c['location'] ) : ''; ?></strong>
			<?php if ( $is_existing ) : ?>
				<span class="scc-badge scc-badge--ok"><?php esc_html_e( 'Existing', 'seo-command-center' ); ?></span>
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

			<?php if ( ! empty( $c['subtopics'] ) ) : ?>
				<div class="scc-subtopics">
					<div class="scc-label"><?php esc_html_e( 'Subtopics', 'seo-command-center' ); ?></div>
					<?php foreach ( (array) $c['subtopics'] as $s ) : ?>
						<?php $s_exists = isset( $s['status'] ) && 'existing' === $s['status']; ?>
						<div class="scc-subtopic<?php echo $s_exists ? ' is-existing' : ''; ?>">
							<span class="scc-subtopic__title"><?php echo esc_html( $s['title'] ); ?></span>
							<?php if ( $s_exists ) : ?>
								<span class="scc-badge scc-badge--ok"><?php esc_html_e( 'Existing', 'seo-command-center' ); ?></span>
							<?php else : ?>
								<span class="scc-badge"><?php esc_html_e( 'Gap · new', 'seo-command-center' ); ?></span>
							<?php endif; ?>
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
								'secondary'       => array(),
							) );
							?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
<?php endforeach; ?>

<?php if ( ! empty( $map['entities'] ) ) : ?>
	<div class="scc-entities">
		<span class="scc-label"><?php esc_html_e( 'Key entities', 'seo-command-center' ); ?>:</span>
		<?php echo esc_html( implode( ', ', $map['entities'] ) ); ?>
	</div>
<?php endif; ?>

<p class="scc-note" style="margin-top:16px;">
	<?php esc_html_e( 'Next: open Site Architecture to see this organized into a page tree, then send it to your Content Plan.', 'seo-command-center' ); ?>
</p>
