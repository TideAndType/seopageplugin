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
<?php if ( $existing_count || $new_count ) : ?>
	<p class="scc-note">
		<?php
		echo esc_html( sprintf(
			/* translators: 1: existing count, 2: new count */
			__( 'Mirrors your site: %1$d existing page(s) and %2$d recommended new page(s).', 'seo-command-center' ),
			$existing_count,
			$new_count
		) );
		?>
	</p>
<?php endif; ?>
<?php if ( ! empty( $map['notes'] ) ) : ?>
	<p class="scc-note"><?php echo esc_html( $map['notes'] ); ?></p>
<?php endif; ?>

<?php foreach ( $clusters as $c ) : ?>
	<?php $is_existing = isset( $c['status'] ) && 'existing' === $c['status']; ?>
	<div class="scc-cluster<?php echo $is_existing ? ' is-existing' : ''; ?>">
		<div class="scc-cluster__head">
			<strong><?php echo esc_html( $c['service'] ); ?><?php echo ! empty( $c['location'] ) ? ' — ' . esc_html( $c['location'] ) : ''; ?></strong>
			<?php if ( $is_existing ) : ?>
				<span class="scc-badge scc-badge--ok"><?php esc_html_e( 'Existing', 'seo-command-center' ); ?></span>
			<?php else : ?>
				<span class="scc-badge"><?php esc_html_e( 'Gap · new', 'seo-command-center' ); ?></span>
			<?php endif; ?>
			<span class="scc-flag scc-flag--<?php echo esc_attr( $c['page_type'] ); ?>"><?php echo esc_html( $c['page_type'] ); ?></span>
			<span class="scc-flag"><?php echo esc_html( $c['intent'] ); ?></span>
		</div>
		<div class="scc-cluster__body">
			<div><span class="scc-label"><?php esc_html_e( 'Primary keyword', 'seo-command-center' ); ?>:</span> <?php echo esc_html( $c['primary_keyword'] ); ?></div>
			<?php if ( ! empty( $c['supporting_terms'] ) ) : ?>
				<div><span class="scc-label"><?php esc_html_e( 'Supporting', 'seo-command-center' ); ?>:</span> <?php echo esc_html( implode( ', ', $c['supporting_terms'] ) ); ?></div>
			<?php endif; ?>
			<div><span class="scc-label"><?php esc_html_e( 'Recommended URL', 'seo-command-center' ); ?>:</span> <code><?php echo esc_html( $c['recommended_url'] ); ?></code></div>
			<?php if ( ! empty( $c['related'] ) ) : ?>
				<div><span class="scc-label"><?php esc_html_e( 'Related', 'seo-command-center' ); ?>:</span> <?php echo esc_html( implode( ', ', $c['related'] ) ); ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $c['rationale'] ) ) : ?>
				<div class="scc-note"><?php echo esc_html( $c['rationale'] ); ?></div>
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
