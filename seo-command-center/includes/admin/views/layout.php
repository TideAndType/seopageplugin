<?php
/**
 * Elementor Layout Engine view.
 *
 * Draft-first and live-safe: published pages are separated from drafts, live
 * applies require explicit confirmation, and TideOrbit exposes working-copy and
 * restore controls around the existing snapshot/rollback renderer.
 *
 * @package SEO_Command_Center
 * @var array $data View data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id         = (int) ( $data['post_id'] ?? 0 );
$title           = (string) ( $data['post_title'] ?? '' );
$status          = (string) ( $data['post_status'] ?? '' );
$is_live         = ! empty( $data['is_live'] );
$is_working_copy = ! empty( $data['is_working_copy'] );
$clone_of        = (int) ( $data['clone_of'] ?? 0 );
$has_backup      = ! empty( $data['has_backup'] );
$backup_at       = (string) ( $data['backup_created_at'] ?? '' );
$el_ok           = ! empty( $data['elementor_active'] );
$drafts          = (array) ( $data['drafts'] ?? array() );
$live_pages      = (array) ( $data['live_pages'] ?? array() );
?>
<div
	class="wrap scc-wrap scc-layout"
	id="scc-layout"
	data-post="<?php echo esc_attr( $post_id ); ?>"
	data-live="<?php echo $is_live ? '1' : '0'; ?>"
	data-has-backup="<?php echo $has_backup ? '1' : '0'; ?>"
>
	<div class="scc-header">
		<span class="scc-phase-badge"><?php esc_html_e( 'Layout Engine', 'seo-command-center' ); ?></span>
		<h1><?php esc_html_e( 'Build Elementor layout', 'seo-command-center' ); ?></h1>
		<p class="scc-sub">
			<?php
			echo $title
				? esc_html( sprintf( __( 'Design “%s” with TideOrbit’s controlled Elementor component system.', 'seo-command-center' ), $title ) )
				: esc_html__( 'Design a draft safely, or deliberately open a live page when you need to change one.', 'seo-command-center' );
			?>
		</p>
		<p class="scc-note"><?php echo esc_html( sprintf( __( 'Engine %s · Drafts are shown first. Published pages are never used as an automatic fallback.', 'seo-command-center' ), defined( 'SCC_VERSION' ) ? SCC_VERSION : '?' ) ); ?></p>
	</div>

	<div class="scc-card" id="scc-design-discovery">
		<div class="scc-card__head">
			<div>
				<h2><?php esc_html_e( '21st.dev Design Discovery', 'seo-command-center' ); ?></h2>
				<p class="scc-note"><?php esc_html_e( 'Scan component categories into your private design-reference library. LM Studio can then use discovered layouts and visual patterns as inspiration for editable Elementor pages.', 'seo-command-center' ); ?></p>
			</div>
			<span id="scc-dd-count" class="scc-badge"><?php esc_html_e( 'Loading library…', 'seo-command-center' ); ?></span>
		</div>
		<div class="scc-field" style="display:flex;align-items:end;gap:12px;flex-wrap:wrap">
			<label for="scc-dd-category">
				<strong><?php esc_html_e( 'Category', 'seo-command-center' ); ?></strong>
				<select id="scc-dd-category" style="display:block;min-width:180px">
					<?php foreach ( SCC_Design_Discovery::categories() as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<button type="button" class="button button-primary" id="scc-dd-scan"><?php esc_html_e( 'Scan next components', 'seo-command-center' ); ?></button>
				<button type="button" class="button" id="scc-dd-scan-all"><?php esc_html_e( 'Scan category in batches', 'seo-command-center' ); ?></button>
				<button type="button" class="button" id="scc-dd-stop" hidden><?php esc_html_e( 'Stop scanning', 'seo-command-center' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button" id="scc-dd-refresh"><?php esc_html_e( 'Refresh library', 'seo-command-center' ); ?></button>
		</div>
		<span id="scc-dd-message" class="scc-inline-status" role="status"></span>
		<div id="scc-dd-results" style="margin-top:12px"></div>
		<p class="scc-note"><?php esc_html_e( 'This scanner catalogs titles, descriptions, visual patterns, dependencies and any stated license. It does not run or install React components. Each scan fetches a small batch; repeat to discover more. Reusing source code is separate from using a design as inspiration.', 'seo-command-center' ); ?></p>
	</div>

	<?php if ( $post_id <= 0 ) : ?>
		<div class="scc-card">
			<div class="scc-card__head">
				<div>
					<h2><?php esc_html_e( 'Choose a page to design', 'seo-command-center' ); ?></h2>
					<p class="scc-note"><?php esc_html_e( 'Drafts are the safe default. Live pages are separated so you cannot accidentally choose one because no draft existed.', 'seo-command-center' ); ?></p>
				</div>
				<div class="scc-segmented" id="scc-layout-picker-toggle">
					<button type="button" class="button button-primary is-active" data-layout-list="drafts"><?php esc_html_e( 'Drafts', 'seo-command-center' ); ?> (<?php echo esc_html( count( $drafts ) ); ?>)</button>
					<button type="button" class="button" data-layout-list="live"><?php esc_html_e( 'Live', 'seo-command-center' ); ?> (<?php echo esc_html( count( $live_pages ) ); ?>)</button>
				</div>
			</div>
			<span class="scc-inline-status" id="scc-layout-picker-msg"></span>

			<div class="scc-layout-list" data-layout-panel="drafts">
				<?php if ( empty( $drafts ) ) : ?>
					<div class="scc-empty">
						<div class="scc-empty__icon" aria-hidden="true">🧩</div>
						<h2><?php esc_html_e( 'No drafts yet', 'seo-command-center' ); ?></h2>
						<p><?php esc_html_e( 'Create content or make a safe working copy of a live page. TideOrbit will not silently fall back to editing a published page.', 'seo-command-center' ); ?></p>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-generate' ) ); ?>"><?php esc_html_e( 'Create content', 'seo-command-center' ); ?></a>
					</div>
				<?php else : ?>
					<table class="widefat striped scc-table">
						<thead><tr><th><?php esc_html_e( 'Title', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Status', 'seo-command-center' ); ?></th><th></th></tr></thead>
						<tbody>
							<?php foreach ( $drafts as $r ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $r['title'] ); ?></strong>
										<?php if ( ! empty( $r['working_copy'] ) ) : ?> <span class="scc-badge scc-badge--ok"><?php esc_html_e( 'Working copy', 'seo-command-center' ); ?></span><?php endif; ?>
										<?php if ( ! empty( $r['generated'] ) ) : ?> <span class="scc-flag"><?php esc_html_e( 'TideOrbit', 'seo-command-center' ); ?></span><?php endif; ?>
									</td>
									<td><?php echo esc_html( ucfirst( $r['type'] ) ); ?></td>
									<td><?php echo esc_html( $r['status'] ); ?></td>
									<td><a class="button button-small button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-layout&post=' . (int) $r['id'] ) ); ?>"><?php esc_html_e( 'Build layout', 'seo-command-center' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="scc-layout-list" data-layout-panel="live" hidden>
				<div class="notice notice-warning inline scc-live-warning">
					<p><strong><?php esc_html_e( 'Published pages are live.', 'seo-command-center' ); ?></strong> <?php esc_html_e( 'The safest path is Make draft copy. Editing live is available only as an explicit secondary action.', 'seo-command-center' ); ?></p>
				</div>
				<?php if ( empty( $live_pages ) ) : ?>
					<p class="scc-note"><?php esc_html_e( 'No editable published pages were found.', 'seo-command-center' ); ?></p>
				<?php else : ?>
					<table class="widefat striped scc-table">
						<thead><tr><th><?php esc_html_e( 'Published page', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Safer action', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Live action', 'seo-command-center' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $live_pages as $r ) : ?>
								<tr data-live-post="<?php echo esc_attr( (int) $r['id'] ); ?>">
									<td><strong><?php echo esc_html( $r['title'] ); ?></strong> <span class="scc-badge scc-badge--warn"><?php esc_html_e( 'LIVE', 'seo-command-center' ); ?></span></td>
									<td><?php echo esc_html( ucfirst( $r['type'] ) ); ?></td>
									<td><button type="button" class="button button-primary button-small scc-layout-clone-picker" data-post="<?php echo esc_attr( (int) $r['id'] ); ?>"><?php esc_html_e( 'Make draft copy', 'seo-command-center' ); ?></button></td>
									<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-layout&post=' . (int) $r['id'] ) ); ?>"><?php esc_html_e( 'Open live page', 'seo-command-center' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

	<?php elseif ( ! $el_ok ) : ?>
		<div class="scc-card scc-empty">
			<div class="scc-empty__icon" aria-hidden="true">🧩</div>
			<h2><?php esc_html_e( 'Elementor isn’t active', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'The Layout Engine builds Elementor pages. Activate Elementor (free) to use it. Your content and SEO features are unaffected.', 'seo-command-center' ); ?></p>
		</div>
	<?php else : ?>

		<?php if ( $is_live ) : ?>
			<div class="scc-card scc-layout-live-guard">
				<div class="notice notice-error inline scc-live-warning">
					<p><strong><?php esc_html_e( 'You opened a LIVE published page.', 'seo-command-center' ); ?></strong> <?php esc_html_e( 'Applying a layout changes what visitors see. TideOrbit will create a restore point first, but a draft working copy is safer.', 'seo-command-center' ); ?></p>
				</div>
				<div class="scc-layout-live-actions">
					<button type="button" class="button button-primary" id="scc-layout-clone-draft"><?php esc_html_e( 'Make draft copy instead', 'seo-command-center' ); ?></button>
					<?php if ( $has_backup ) : ?>
						<button type="button" class="button" id="scc-layout-restore"><?php esc_html_e( 'Restore last TideOrbit layout backup', 'seo-command-center' ); ?></button>
						<span class="scc-note"><?php echo esc_html( $backup_at ? sprintf( __( 'Backup: %s', 'seo-command-center' ), $backup_at ) : '' ); ?></span>
					<?php endif; ?>
				</div>
				<label class="scc-layout-live-confirm">
					<input type="checkbox" id="scc-layout-confirm-live">
					<strong><?php esc_html_e( 'I understand Apply changes this published page immediately.', 'seo-command-center' ); ?></strong>
				</label>
				<span class="scc-inline-status" id="scc-layout-safety-msg"></span>
			</div>
		<?php elseif ( $is_working_copy ) : ?>
			<div class="scc-card scc-layout-working-copy">
				<p><span class="scc-badge scc-badge--ok"><?php esc_html_e( 'SAFE WORKING COPY', 'seo-command-center' ); ?></span> <?php esc_html_e( 'This is a draft clone; changes here do not alter the published source page.', 'seo-command-center' ); ?><?php if ( $clone_of ) : ?> <?php echo esc_html( sprintf( __( 'Source post #%d.', 'seo-command-center' ), $clone_of ) ); ?><?php endif; ?></p>
				<?php if ( $has_backup ) : ?><button type="button" class="button" id="scc-layout-restore"><?php esc_html_e( 'Restore previous TideOrbit layout', 'seo-command-center' ); ?></button><?php endif; ?>
				<span class="scc-inline-status" id="scc-layout-safety-msg"></span>
			</div>
		<?php elseif ( $has_backup ) : ?>
			<div class="scc-card scc-layout-restore-card">
				<button type="button" class="button" id="scc-layout-restore"><?php esc_html_e( 'Restore previous TideOrbit layout', 'seo-command-center' ); ?></button>
				<span class="scc-note"><?php echo esc_html( $backup_at ? sprintf( __( 'Restore point: %s', 'seo-command-center' ), $backup_at ) : '' ); ?></span>
				<span class="scc-inline-status" id="scc-layout-safety-msg"></span>
			</div>
		<?php endif; ?>

		<div class="scc-card">
			<div class="scc-card__head">
				<div>
					<h2><?php esc_html_e( 'Your page design', 'seo-command-center' ); ?></h2>
					<p class="scc-note"><?php esc_html_e( 'Describe the look you want. The AI designer can compose real Elementor containers, responsive layouts, and compatible widgets installed on this site.', 'seo-command-center' ); ?></p>
				</div>
				<span>
					<label class="scc-toggle" title="<?php esc_attr_e( 'Use the schema-aware Elementor design agent when an AI provider is configured', 'seo-command-center' ); ?>">
						<input type="checkbox" id="scc-layout-ai" checked> <?php esc_html_e( 'AI Design Agent', 'seo-command-center' ); ?>
					</label>
					<button class="button" id="scc-layout-regen"><?php esc_html_e( 'Regenerate design', 'seo-command-center' ); ?></button>
				</span>
			</div>
			<label for="scc-layout-design-prompt"><strong><?php esc_html_e( 'Design direction', 'seo-command-center' ); ?></strong></label>
			<textarea
				id="scc-layout-design-prompt"
				class="large-text"
				rows="3"
				maxlength="2400"
				placeholder="<?php esc_attr_e( 'Example: Premium editorial service page with an asymmetric image-led hero, bold typography, strong whitespace, varied section widths, subtle dark/light rhythm, and a polished mobile stack. Use installed Elementor widgets when they genuinely improve the design.', 'seo-command-center' ); ?>"
			></textarea>
			<p class="scc-note"><?php esc_html_e( 'TideOrbit keeps your finished page copy locked. The designer may change presentation and widget composition, but it cannot rewrite the SEO content.', 'seo-command-center' ); ?></p>
			<span class="scc-inline-status" id="scc-layout-msg"></span>
			<p class="scc-note" id="scc-layout-meta" hidden></p>
			<div id="scc-layout-critic" class="scc-note" hidden></div>
			<div id="scc-layout-preview" class="scc-layout-preview"></div>
			<div class="scc-layout-actions">
				<button class="button button-primary button-hero" id="scc-layout-apply" disabled>
					<?php echo esc_html( $is_live ? __( 'Apply to LIVE Page', 'seo-command-center' ) : __( 'Generate Elementor Layout', 'seo-command-center' ) ); ?>
				</button>
				<span class="scc-inline-status" id="scc-layout-apply-msg"></span>
			</div>
		</div>

		<details class="scc-card">
			<summary class="scc-adv-summary"><?php esc_html_e( 'How this works', 'seo-command-center' ); ?></summary>
			<p class="scc-note"><?php esc_html_e( 'With AI Design Agent enabled, TideOrbit discovers the Elementor and add-on widgets actually registered on this site, reads their usable controls, combines them with the site design system, and asks the model for a nested composition plan. The plan is validated against a controlled DSL and exact content references before TideOrbit compiles it into editable Elementor data. The model never writes raw Elementor JSON or replacement page copy. If the richer agent is unavailable or its plan fails validation, the existing deterministic Page Architect remains the fallback. Every apply still creates an Elementor/page restore point and WordPress revision first.', 'seo-command-center' ); ?></p>
		</details>
	<?php endif; ?>
</div>
