<?php
/**
 * SEO Architecture Brain view.
 *
 * @package SEO_Command_Center
 * @var array $data View data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tree  = isset( $data['tree'] ) && is_array( $data['tree'] ) ? $data['tree'] : null;
$brain = isset( $data['brain'] ) && is_array( $data['brain'] ) ? $data['brain'] : null;
$health = $brain ? (array) ( $brain['health'] ?? array() ) : array();
$merges = $brain ? (array) ( $brain['consolidation'] ?? array() ) : array();
$growth = isset( $data['growth'] ) && is_array( $data['growth'] ) ? $data['growth'] : array();
$roadmap = (array) ( $growth['roadmap'] ?? array( 'now' => array(), 'next' => array(), 'later' => array() ) );

$node_line = function ( $node, $draggable = true ) {
	$exists         = ! empty( $node['exists'] );
	$status         = isset( $node['status'] ) ? (string) $node['status'] : ( $exists ? 'existing' : 'new' );
	$url            = isset( $node['url'] ) ? (string) $node['url'] : '';
	$decision       = (array) ( $node['decision'] ?? array() );
	$action         = (string) ( $decision['action'] ?? '' );
	$page_candidate = array_key_exists( 'page_candidate', $node ) ? ! empty( $node['page_candidate'] ) : ! $exists;
	$coverage       = (array) ( $node['coverage'] ?? array() );
	$gsc            = (array) ( $node['gsc'] ?? array() );
	$node_id        = (string) ( $node['node_id'] ?? '' );
	$has_override   = ! empty( $node['has_override'] );
	$is_ignored     = ! empty( $node['ignored'] );
	$can_parent     = ! in_array( (string) ( $node['page_type'] ?? '' ), array( 'article', 'section' ), true );
	$growth_meta    = (array) ( $node['growth'] ?? array() );
	?>
	<div
		class="scc-arch-node<?php echo $exists ? ' is-existing' : ''; ?><?php echo 'section' === $status ? ' is-section' : ''; ?><?php echo $is_ignored ? ' is-ignored' : ''; ?>"
		data-node-id="<?php echo esc_attr( $node_id ); ?>"
		data-node-url="<?php echo esc_attr( $url ); ?>"
		data-decision="<?php echo esc_attr( $action ); ?>"
		data-existing="<?php echo $exists ? '1' : '0'; ?>"
		data-growth-phase="<?php echo esc_attr( $growth_meta['phase'] ?? '' ); ?>"
		data-can-parent="<?php echo $can_parent ? '1' : '0'; ?>"
		<?php echo $draggable && empty( $node['is_pillar'] ) ? 'draggable="true"' : ''; ?>
	>
		<div class="scc-arch-node__main">
			<?php if ( $page_candidate && in_array( $action, array( 'create_page', 'create_article', 'create_location' ), true ) ) : ?>
				<label class="scc-arch-pick" title="<?php esc_attr_e( 'Include this page when sending selected gaps to Content Plan', 'seo-command-center' ); ?>">
					<input type="checkbox" class="scc-seed-pick" value="<?php echo esc_attr( $url ); ?>" checked>
				</label>
			<?php else : ?>
				<span class="scc-arch-pick scc-arch-pick--spacer" aria-hidden="true"></span>
			<?php endif; ?>

			<div class="scc-arch-node__copy">
				<div class="scc-arch-node__titleline">
					<strong class="scc-arch-title"><?php echo esc_html( $node['title'] ); ?></strong>
					<span class="scc-flag"><?php echo esc_html( $node['intent'] ?? '' ); ?></span>
					<?php if ( ! empty( $decision['label'] ) ) : ?>
						<span class="scc-badge<?php echo in_array( $action, array( 'keep', 'expand_existing' ), true ) ? ' scc-badge--ok' : ''; ?>"><?php echo esc_html( $decision['label'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $growth_meta['label'] ) && 'Keep' !== (string) $growth_meta['label'] ) : ?>
						<span class="scc-flag scc-arch-growth-phase"><?php echo esc_html( strtoupper( (string) ( $growth_meta['phase'] ?? '' ) ) . ' · ' . (string) $growth_meta['label'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $decision['confidence'] ) ) : ?>
						<span class="scc-note"><?php echo esc_html( (int) $decision['confidence'] ); ?>% <?php esc_html_e( 'confidence', 'seo-command-center' ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( '' !== $url ) : ?><code><?php echo esc_html( $url ); ?></code><?php endif; ?>

				<?php if ( ! empty( $decision['reason'] ) ) : ?>
					<p class="scc-note scc-arch-reason"><?php echo esc_html( $decision['reason'] ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $coverage['available'] ) ) : ?>
					<div class="scc-arch-evidence">
						<span><strong><?php esc_html_e( 'Coverage', 'seo-command-center' ); ?>:</strong> <?php echo esc_html( (int) $coverage['score'] ); ?>% · <?php echo esc_html( ucfirst( (string) $coverage['level'] ) ); ?></span>
						<?php if ( ! empty( $coverage['missing'] ) ) : ?>
							<span><strong><?php esc_html_e( 'Missing', 'seo-command-center' ); ?>:</strong> <?php echo esc_html( implode( ', ', (array) $coverage['missing'] ) ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $gsc['available'] ) && ! empty( $gsc['impressions'] ) ) : ?>
					<div class="scc-arch-evidence">
						<span><strong><?php esc_html_e( 'GSC evidence', 'seo-command-center' ); ?>:</strong> <?php echo esc_html( (int) $gsc['impressions'] ); ?> <?php esc_html_e( 'relevant impressions', 'seo-command-center' ); ?><?php if ( ! empty( $gsc['best_existing_url'] ) ) : ?> · <?php echo esc_html( $gsc['best_existing_url'] ); ?><?php endif; ?></span>
						<?php if ( ! empty( $gsc['queries'] ) ) : ?>
							<span><?php echo esc_html( implode( ', ', array_slice( array_map( function ( $q ) { return (string) ( $q['query'] ?? '' ); }, $gsc['queries'] ), 0, 3 ) ) ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="scc-arch-node__actions">
				<?php if ( in_array( $action, array( 'create_page', 'create_article', 'create_location' ), true ) && $page_candidate ) : ?>
					<button type="button" class="button button-small scc-arch-action" data-action="add_to_plan"><?php esc_html_e( 'Add to Content Plan', 'seo-command-center' ); ?></button>
				<?php endif; ?>
				<?php if ( 'expand_existing' === $action ) : ?>
					<button type="button" class="button button-primary button-small scc-arch-draft-generate"><?php esc_html_e( 'Draft missing section', 'seo-command-center' ); ?></button>
					<button type="button" class="button button-small scc-arch-action" data-action="queue_expand"><?php esc_html_e( 'Queue review', 'seo-command-center' ); ?></button>
				<?php endif; ?>
				<?php if ( ! empty( $node['edit_url'] ) ) : ?>
					<a class="button button-small" href="<?php echo esc_url( $node['edit_url'] ); ?>"><?php esc_html_e( 'Edit page', 'seo-command-center' ); ?></a>
				<?php endif; ?>
				<?php if ( ! $has_override && ! $exists && 'section' !== $status ) : ?>
					<button type="button" class="button button-small scc-arch-action" data-action="mark_covered"><?php esc_html_e( 'Mark covered', 'seo-command-center' ); ?></button>
				<?php endif; ?>
				<?php if ( ! $has_override ) : ?>
					<button type="button" class="button button-small scc-arch-action" data-action="ignore"><?php esc_html_e( 'Ignore', 'seo-command-center' ); ?></button>
				<?php else : ?>
					<button type="button" class="button button-small scc-arch-action" data-action="restore"><?php esc_html_e( 'Restore recommendation', 'seo-command-center' ); ?></button>
				<?php endif; ?>
				<span class="scc-inline-status scc-arch-action-status"></span>
			</div>
		</div>
		<?php if ( 'expand_existing' === $action ) : ?>
			<div class="scc-arch-draft" hidden></div>
		<?php endif; ?>
	</div>
	<?php
};

$render_branch = function ( $node, $depth = 1 ) use ( &$render_branch, $node_line ) {
	?>
	<div class="scc-arch-branch" style="--scc-arch-depth:<?php echo esc_attr( (int) $depth ); ?>">
		<?php $node_line( $node ); ?>

		<?php if ( ! empty( $node['children'] ) ) : ?>
			<div class="scc-arch-children">
				<div class="scc-label"><?php esc_html_e( 'Child services / locations', 'seo-command-center' ); ?></div>
				<?php foreach ( (array) $node['children'] as $child ) : ?>
					<?php $render_branch( $child, $depth + 1 ); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $node['sections'] ) ) : ?>
			<div class="scc-arch-children scc-arch-sections">
				<div class="scc-label"><?php esc_html_e( 'Service-page sections · no new URL', 'seo-command-center' ); ?></div>
				<?php foreach ( (array) $node['sections'] as $section ) : ?>
					<?php $node_line( $section ); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $node['articles'] ) ) : ?>
			<div class="scc-arch-children scc-arch-articles">
				<div class="scc-label"><?php esc_html_e( 'Supporting articles', 'seo-command-center' ); ?></div>
				<?php foreach ( (array) $node['articles'] as $article ) : ?>
					<?php $node_line( $article ); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
};
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'SEO Architecture Brain', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Decides where a topic belongs using the pages you actually have, their indexed content coverage, search intent, Search Console evidence, and overlap with other URLs.', 'seo-command-center' ); ?></p>
	</div>

	<?php if ( ! $tree || ! $brain ) : ?>
		<div class="scc-card scc-empty">
			<h2><?php esc_html_e( 'No strategy yet', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'Build a keyword strategy first. TideOrbit will then reconcile it against your real site and turn it into an evidence-backed architecture.', 'seo-command-center' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-keyword-strategy' ) ); ?>"><?php esc_html_e( 'Go to Keyword Strategy', 'seo-command-center' ); ?></a>
		</div>
	<?php else : ?>
		<div class="scc-card scc-arch-mode-card">
			<div class="scc-card__head">
				<div>
					<h2><?php esc_html_e( 'What should the site become?', 'seo-command-center' ); ?></h2>
					<p class="scc-note"><?php esc_html_e( 'Recommended SEO Architecture is the future-state blueprint TideOrbit believes will best cover your important search intents without unnecessary pages. Current Site shows only URLs that exist today.', 'seo-command-center' ); ?></p>
				</div>
				<div class="scc-segmented" role="group" aria-label="<?php esc_attr_e( 'Architecture view', 'seo-command-center' ); ?>">
					<button type="button" class="button button-primary scc-arch-view-btn is-active" data-view="recommended"><?php esc_html_e( 'Recommended SEO Architecture', 'seo-command-center' ); ?></button>
					<button type="button" class="button scc-arch-view-btn" data-view="current"><?php esc_html_e( 'Current Site', 'seo-command-center' ); ?></button>
				</div>
			</div>
			<p class="scc-arch-principle"><?php echo esc_html( $growth['principle'] ?? __( 'Strengthen an existing URL before creating another one.', 'seo-command-center' ) ); ?></p>
		</div>

		<?php if ( ! empty( $growth ) ) : ?>
			<div class="scc-card scc-growth-roadmap">
				<div class="scc-card__head">
					<div>
						<h2><?php esc_html_e( 'SEO Growth Roadmap', 'seo-command-center' ); ?></h2>
						<p class="scc-note"><?php esc_html_e( 'This is the action plan for improving the architecture—not a mirror of the site you already have.', 'seo-command-center' ); ?></p>
					</div>
				</div>
				<div class="scc-growth-roadmap__cols">
					<?php
					$phase_labels = array(
						'now'   => __( 'Do now', 'seo-command-center' ),
						'next'  => __( 'Do next', 'seo-command-center' ),
						'later' => __( 'Later / monitor', 'seo-command-center' ),
					);
					foreach ( $phase_labels as $phase => $label ) :
						$items = (array) ( $roadmap[ $phase ] ?? array() );
					?>
						<section class="scc-growth-phase scc-growth-phase--<?php echo esc_attr( $phase ); ?>">
							<h3><?php echo esc_html( $label ); ?> <span class="scc-badge"><?php echo esc_html( count( $items ) ); ?></span></h3>
							<?php if ( empty( $items ) ) : ?>
								<p class="scc-note"><?php esc_html_e( 'No priority work in this phase.', 'seo-command-center' ); ?></p>
							<?php else : ?>
								<?php foreach ( array_slice( $items, 0, 10 ) as $item ) : ?>
									<article class="scc-growth-item">
										<div class="scc-growth-item__top">
											<strong><?php echo esc_html( $item['title'] ?? '' ); ?></strong>
											<span class="scc-flag"><?php echo esc_html( (int) ( $item['priority'] ?? 0 ) ); ?> <?php esc_html_e( 'priority', 'seo-command-center' ); ?></span>
										</div>
										<?php if ( ! empty( $item['url'] ) ) : ?><code><?php echo esc_html( $item['url'] ); ?></code><?php endif; ?>
										<?php if ( ! empty( $item['reason'] ) ) : ?><p class="scc-note"><?php echo esc_html( $item['reason'] ); ?></p><?php endif; ?>
										<?php if ( ! empty( $item['outcome'] ) ) : ?><p class="scc-growth-outcome"><strong><?php esc_html_e( 'SEO goal:', 'seo-command-center' ); ?></strong> <?php echo esc_html( $item['outcome'] ); ?></p><?php endif; ?>
									</article>
								<?php endforeach; ?>
							<?php endif; ?>
						</section>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<div class="scc-card scc-arch-health">
			<div class="scc-card__head">
				<div>
					<h2><?php esc_html_e( 'Architecture Health', 'seo-command-center' ); ?></h2>
					<p class="scc-note"><?php esc_html_e( 'A structural diagnostic—not a Google ranking score.', 'seo-command-center' ); ?></p>
				</div>
				<div class="scc-arch-health__score">
					<strong><?php echo esc_html( (int) ( $health['score'] ?? 0 ) ); ?></strong><span>/100</span>
					<small><?php echo esc_html( $health['label'] ?? '' ); ?></small>
				</div>
			</div>
			<?php $stats = (array) ( $health['stats'] ?? array() ); ?>
			<div class="scc-arch-health__grid">
				<div><strong><?php echo esc_html( (int) ( $stats['new_pages'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'real page gaps', 'seo-command-center' ); ?></span></div>
				<div><strong><?php echo esc_html( (int) ( $stats['expand_existing'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'pages to strengthen', 'seo-command-center' ); ?></span></div>
				<div><strong><?php echo esc_html( (int) ( $stats['weak_coverage'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'weak coverage', 'seo-command-center' ); ?></span></div>
				<div><strong><?php echo esc_html( (int) ( $stats['merge_candidates'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'merge reviews', 'seo-command-center' ); ?></span></div>
				<div><strong><?php echo esc_html( (int) ( $stats['orphans'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'orphan/unreachable', 'seo-command-center' ); ?></span></div>
				<div><strong><?php echo esc_html( (int) ( $stats['deep_pages'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'deep pages', 'seo-command-center' ); ?></span></div>
				<div><strong><?php echo esc_html( (int) ( $stats['empty_hubs'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'unsupported hubs', 'seo-command-center' ); ?></span></div>
				<div><strong><?php echo esc_html( (int) ( $stats['service_hubs'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'service hubs', 'seo-command-center' ); ?></span></div>
			</div>
		</div>

		<div class="scc-card">
			<div class="scc-card__head">
				<div>
					<h2 id="scc-arch-tree-title"><?php esc_html_e( 'Recommended SEO site tree', 'seo-command-center' ); ?></h2>
					<p class="scc-note" id="scc-arch-tree-help"><?php esc_html_e( 'Future-state view: new pages, pages to strengthen, supporting content and the service hierarchy TideOrbit recommends. Drag a child page, article, or section onto another service hub to change the planning hierarchy without changing live WordPress permalinks.', 'seo-command-center' ); ?></p>
				</div>
				<div>
					<label class="scc-toggle" title="<?php esc_attr_e( 'Hide pages that need no structural work.', 'seo-command-center' ); ?>">
						<input type="checkbox" id="scc-arch-gaps-only"> <span><?php esc_html_e( 'Only show work', 'seo-command-center' ); ?></span>
					</label>
					<button class="button button-primary" id="scc-seed-plan"><?php esc_html_e( 'Send selected new pages to Content Plan', 'seo-command-center' ); ?></button>
				</div>
			</div>
			<p class="scc-note">
				<?php esc_html_e( 'Only true new-page decisions can enter Content Plan. Existing-page expansions and merge reviews go to Action Queue instead.', 'seo-command-center' ); ?>
				<label class="scc-arch-selectall"><input type="checkbox" id="scc-seed-selectall" checked> <?php esc_html_e( 'Select all new pages', 'seo-command-center' ); ?></label>
				<span id="scc-seed-count"></span>
			</p>
			<span class="scc-inline-status" id="scc-seed-status"></span>
			<span class="scc-inline-status" id="scc-arch-global-status"></span>

			<div class="scc-arch-tree">
				<?php foreach ( (array) $tree['pillars'] as $pillar ) : ?>
					<div class="scc-arch-pillar" data-parent-url="<?php echo esc_attr( $pillar['url'] ?? '' ); ?>">
						<div class="scc-label scc-arch-drop-label"><?php esc_html_e( 'Service hub · drop related items here', 'seo-command-center' ); ?></div>
						<?php $node_line( $pillar, false ); ?>

						<?php if ( ! empty( $pillar['children'] ) ) : ?>
							<div class="scc-arch-children">
								<div class="scc-label"><?php esc_html_e( 'Child services / locations', 'seo-command-center' ); ?></div>
								<?php foreach ( $pillar['children'] as $child ) : ?>
									<?php $render_branch( $child, 1 ); ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $pillar['sections'] ) ) : ?>
							<div class="scc-arch-children scc-arch-sections">
								<div class="scc-label"><?php esc_html_e( 'Service-page sections · no new URL', 'seo-command-center' ); ?></div>
								<?php foreach ( $pillar['sections'] as $section ) : ?>
									<?php $node_line( $section ); ?>
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
		</div>

		<div class="scc-card">
			<div class="scc-card__head">
				<div>
					<h2><?php esc_html_e( 'Consolidation planner', 'seo-command-center' ); ?></h2>
					<p class="scc-note"><?php esc_html_e( 'Possible overlapping URLs. These are review plans only—TideOrbit never merges content or creates a 301 automatically.', 'seo-command-center' ); ?></p>
				</div>
			</div>
			<?php if ( empty( $merges ) ) : ?>
				<p class="scc-ok"><?php esc_html_e( 'No high-confidence consolidation candidates found in the indexed site.', 'seo-command-center' ); ?></p>
			<?php else : ?>
				<?php foreach ( $merges as $merge ) : ?>
					<div class="scc-arch-merge" data-merge-id="<?php echo esc_attr( $merge['id'] ); ?>">
						<div>
							<strong><?php echo esc_html( $merge['merge_title'] ); ?></strong>
							<code><?php echo esc_html( $merge['merge_url'] ); ?></code>
							<span aria-hidden="true">→</span>
							<strong><?php echo esc_html( $merge['keep_title'] ); ?></strong>
							<code><?php echo esc_html( $merge['keep_url'] ); ?></code>
							<span class="scc-badge"><?php echo esc_html( (int) $merge['similarity'] ); ?>% <?php esc_html_e( 'overlap', 'seo-command-center' ); ?></span>
							<p class="scc-note"><?php echo esc_html( $merge['reason'] ); ?></p>
							<ol>
								<?php foreach ( (array) $merge['recommended_steps'] as $step ) : ?><li><?php echo esc_html( $step ); ?></li><?php endforeach; ?>
							</ol>
						</div>
						<div>
							<button type="button" class="button button-primary button-small scc-arch-merge-action"><?php esc_html_e( 'Queue consolidation review', 'seo-command-center' ); ?></button>
							<span class="scc-inline-status"></span>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<p class="scc-note"><?php echo esc_html( $brain['disclaimer'] ?? '' ); ?></p>
	<?php endif; ?>
</div>
