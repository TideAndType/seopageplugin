<?php
/**
 * Generate Content view.
 *
 * Two paths, one screen:
 *   - Simple: pick a Content Type (Blog Post by default), enter a topic, Generate.
 *     A Blog Post becomes a NORMAL native WordPress post — no template required.
 *   - From your plan: generate any approved Content Plan entry (unchanged).
 *
 * @package SEO_Command_Center
 * @var array $data View data (entries, auto_publish, categories).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$entries      = isset( $data['entries'] ) ? $data['entries'] : array();
$auto_publish = ! empty( $data['auto_publish'] );
$categories   = isset( $data['categories'] ) ? $data['categories'] : array();
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Generate Content', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Enter a topic and generate a draft. A Blog Post becomes a normal WordPress post, no template needed. Everything is saved as a draft for your review.', 'seo-command-center' ); ?></p>
	</div>

	<?php if ( $auto_publish ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Automatic publishing is ON. Generated content will be published immediately. Turn it off in Settings to keep drafts for review.', 'seo-command-center' ); ?></p></div>
	<?php else : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Generated content is saved as a draft. You review and publish it yourself.', 'seo-command-center' ); ?></p></div>
	<?php endif; ?>

	<div class="scc-card scc-quickgen">
		<h2><?php esc_html_e( 'Create a draft', 'seo-command-center' ); ?></h2>

		<div class="scc-field">
			<label class="scc-field__label"><?php esc_html_e( 'Content type', 'seo-command-center' ); ?></label>
			<div class="scc-segmented" id="scc-qg-type" role="radiogroup">
				<label class="scc-seg is-active"><input type="radio" name="scc_qg_type" value="article" checked> <?php esc_html_e( 'Blog Post', 'seo-command-center' ); ?></label>
				<label class="scc-seg"><input type="radio" name="scc_qg_type" value="service"> <?php esc_html_e( 'Service Page', 'seo-command-center' ); ?></label>
				<label class="scc-seg"><input type="radio" name="scc_qg_type" value="location"> <?php esc_html_e( 'Location Page', 'seo-command-center' ); ?></label>
				<label class="scc-seg"><input type="radio" name="scc_qg_type" value="landing"> <?php esc_html_e( 'Landing Page', 'seo-command-center' ); ?></label>
				<label class="scc-seg"><input type="radio" name="scc_qg_type" value="custom"> <?php esc_html_e( 'Custom', 'seo-command-center' ); ?></label>
			</div>
			<p class="scc-note" id="scc-qg-mode-note"><?php esc_html_e( 'Blog Post generates as a normal WordPress post (title, intro, H2 sections, FAQ, conclusion).', 'seo-command-center' ); ?></p>
		</div>

		<div class="scc-field">
			<label class="scc-field__label" for="scc-qg-topic"><?php esc_html_e( 'Topic', 'seo-command-center' ); ?></label>
			<input type="text" id="scc-qg-topic" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. How to choose a local SEO agency', 'seo-command-center' ); ?>">
		</div>

		<div class="scc-field-row">
			<div class="scc-field">
				<label class="scc-field__label" for="scc-qg-keyword"><?php esc_html_e( 'Primary keyword', 'seo-command-center' ); ?></label>
				<input type="text" id="scc-qg-keyword" class="regular-text" placeholder="<?php esc_attr_e( 'optional — defaults to the topic', 'seo-command-center' ); ?>">
			</div>
			<div class="scc-field">
				<label class="scc-field__label" for="scc-qg-location"><?php esc_html_e( 'Location', 'seo-command-center' ); ?> <span class="scc-opt"><?php esc_html_e( '(optional)', 'seo-command-center' ); ?></span></label>
				<input type="text" id="scc-qg-location" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Daytona Beach, FL', 'seo-command-center' ); ?>">
			</div>
		</div>

		<div class="scc-field-row">
			<div class="scc-field">
				<label class="scc-field__label" for="scc-qg-category"><?php esc_html_e( 'Category', 'seo-command-center' ); ?> <span class="scc-opt"><?php esc_html_e( '(posts)', 'seo-command-center' ); ?></span></label>
				<select id="scc-qg-category">
					<option value=""><?php esc_html_e( 'Default category', 'seo-command-center' ); ?></option>
					<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo esc_attr( $cat['name'] ); ?>"><?php echo esc_html( $cat['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="scc-field">
				<label class="scc-field__label" for="scc-qg-tone"><?php esc_html_e( 'Tone / style', 'seo-command-center' ); ?> <span class="scc-opt"><?php esc_html_e( '(optional)', 'seo-command-center' ); ?></span></label>
				<select id="scc-qg-tone">
					<option value=""><?php esc_html_e( 'Default', 'seo-command-center' ); ?></option>
					<option value="professional"><?php esc_html_e( 'Professional', 'seo-command-center' ); ?></option>
					<option value="friendly"><?php esc_html_e( 'Friendly', 'seo-command-center' ); ?></option>
					<option value="authoritative"><?php esc_html_e( 'Authoritative', 'seo-command-center' ); ?></option>
					<option value="conversational"><?php esc_html_e( 'Conversational', 'seo-command-center' ); ?></option>
				</select>
			</div>
		</div>

		<p><button type="button" class="button-link scc-disclose" id="scc-qg-advanced-toggle" aria-expanded="false"><?php esc_html_e( 'Advanced ▾', 'seo-command-center' ); ?></button></p>
		<div class="scc-advanced" id="scc-qg-advanced" hidden>
			<div class="scc-field-row">
				<div class="scc-field">
					<label class="scc-field__label" for="scc-qg-secondary"><?php esc_html_e( 'Secondary keywords', 'seo-command-center' ); ?></label>
					<input type="text" id="scc-qg-secondary" class="regular-text" placeholder="<?php esc_attr_e( 'comma separated', 'seo-command-center' ); ?>">
				</div>
				<div class="scc-field">
					<label class="scc-field__label" for="scc-qg-words"><?php esc_html_e( 'Target word count', 'seo-command-center' ); ?></label>
					<input type="number" id="scc-qg-words" class="small-text" min="300" max="8000" step="100" placeholder="1200">
				</div>
			</div>
			<div class="scc-field scc-tpl-only" hidden>
				<label class="scc-field__label" for="scc-qg-template"><?php esc_html_e( 'Template family', 'seo-command-center' ); ?> <span class="scc-opt"><?php esc_html_e( '(structured pages)', 'seo-command-center' ); ?></span></label>
				<input type="text" id="scc-qg-template" class="regular-text" placeholder="<?php esc_attr_e( 'leave blank to use the mapped/default template', 'seo-command-center' ); ?>">
				<p class="scc-note"><?php esc_html_e( 'Only used for Service / Location / Landing / Custom pages. Manage templates under Templates.', 'seo-command-center' ); ?></p>
			</div>
		</div>

		<p>
			<button type="button" class="button button-primary button-hero" id="scc-qg-generate"><?php esc_html_e( 'Generate draft', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-qg-status"></span>
		</p>
		<div class="scc-qg-result" id="scc-qg-result" hidden></div>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'From your Content Plan', 'seo-command-center' ); ?></h2>
		<?php if ( empty( $entries ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No approved plan entries waiting. Add ideas in the Content Plan, or use the quick form above.', 'seo-command-center' ); ?></p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-content-plan' ) ); ?>"><?php esc_html_e( 'Open Content Plan', 'seo-command-center' ); ?></a>
		<?php else : ?>
			<table class="widefat striped scc-table" id="scc-generate-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Type', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Primary keyword', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Status', 'seo-command-center' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $e ) : ?>
						<tr data-id="<?php echo esc_attr( $e['id'] ); ?>">
							<td><?php echo esc_html( $e['title'] ); ?></td>
							<td><?php echo esc_html( $e['page_type'] ); ?></td>
							<td><?php echo esc_html( $e['primary_keyword'] ); ?></td>
							<td class="scc-gen-status"><?php echo esc_html( str_replace( '_', ' ', $e['status'] ) ); ?></td>
							<td>
								<button class="button scc-brief-btn"><?php esc_html_e( 'Preview brief', 'seo-command-center' ); ?></button>
								<button class="button button-primary scc-generate-btn"><?php esc_html_e( 'Generate draft', 'seo-command-center' ); ?></button>
							</td>
						</tr>
						<tr class="scc-brief-row" hidden>
							<td colspan="5"><div class="scc-brief-panel"></div></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<span class="scc-inline-status" id="scc-generate-msg"></span>
		<?php endif; ?>
	</div>
</div>
