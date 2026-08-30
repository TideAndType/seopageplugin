<?php
/**
 * Site Architecture view.
 *
 * @package SEO_Command_Center
 * @var array $data View data (strategy, tree).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tree = isset( $data['tree'] ) ? $data['tree'] : null;

/**
 * Render one node line.
 *
 * @param array $node Node.
 */
$node_line = function ( $node ) {
	$exists = ! empty( $node['exists'] );
	$url    = isset( $node['url'] ) ? $node['url'] : '';
	?>
	<div class="scc-arch-node<?php echo $exists ? ' is-existing' : ''; ?>">
		<?php if ( ! $exists ) : ?>
			<label class="scc-arch-pick" title="<?php esc_attr_e( 'Include this page when sending to the Content Plan', 'seo-command-center' ); ?>">
				<input type="checkbox" class="scc-seed-pick" value="<?php echo esc_attr( $url ); ?>" checked>
			</label>
		<?php else : ?>
			<span class="scc-arch-pick scc-arch-pick--spacer" aria-hidden="true"></span>
		<?php endif; ?>
		<span class="scc-arch-title"><?php echo esc_html( $node['title'] ); ?></span>
		<code><?php echo esc_html( $url ); ?></code>
		<span class="scc-flag"><?php echo esc_html( $node['intent'] ); ?></span>
		<?php if ( $exists ) : ?>
			<span class="scc-badge scc-badge--ok"><?php esc_html_e( 'Exists', 'seo-command-center' ); ?></span>
		<?php else : ?>
			<span class="scc-badge"><?php esc_html_e( 'Gap · new', 'seo-command-center' ); ?></span>
		<?php endif; ?>
	</div>
	<?php
};
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Site Architecture', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Your topical map organized into a Pillar → Service → Location → Supporting structure. Pages you already have are marked “Exists”; the rest are candidates for your content plan.', 'seo-command-center' ); ?></p>
	</div>

	<?php if ( ! $tree ) : ?>
		<div class="scc-card scc-empty">
			<h2><?php esc_html_e( 'No strategy yet', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'Build a keyword strategy first — the architecture is derived from your topical map.', 'seo-command-center' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-keyword-strategy' ) ); ?>"><?php esc_html_e( 'Go to Keyword Strategy', 'seo-command-center' ); ?></a>
		</div>
	<?php else : ?>
		<div class="scc-card">
			<div class="scc-card__head">
				<h2><?php esc_html_e( 'Recommended architecture', 'seo-command-center' ); ?></h2>
				<button class="button button-primary" id="scc-seed-plan"><?php esc_html_e( 'Send selected pages to Content Plan', 'seo-command-center' ); ?></button>
			</div>
			<p class="scc-note">
				<?php esc_html_e( 'Tick the “Gap · new” pages you want to add to your Content Plan (existing pages are never added). ', 'seo-command-center' ); ?>
				<label class="scc-arch-selectall"><input type="checkbox" id="scc-seed-selectall" checked> <?php esc_html_e( 'Select all', 'seo-command-center' ); ?></label>
				<span id="scc-seed-count"></span>
			</p>
			<span class="scc-inline-status" id="scc-seed-status"></span>

			<?php foreach ( (array) $tree['pillars'] as $pillar ) : ?>
				<div class="scc-arch-pillar">
					<?php $node_line( $pillar ); ?>
					<?php if ( ! empty( $pillar['children'] ) ) : ?>
						<div class="scc-arch-children">
							<?php foreach ( $pillar['children'] as $child ) : ?>
								<?php $node_line( $child ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $pillar['articles'] ) ) : ?>
						<div class="scc-arch-children scc-arch-articles">
							<div class="scc-label"><?php esc_html_e( 'Supporting articles', 'seo-command-center' ); ?></div>
							<?php foreach ( $pillar['articles'] as $article ) : ?>
								<?php $node_line( $article ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
