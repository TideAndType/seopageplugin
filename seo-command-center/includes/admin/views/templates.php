<?php
/**
 * Templates view — native template engine, mapping, renderers, preview.
 *
 * @package SEO_Command_Center
 * @var array $data View data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$templates = isset( $data['templates'] ) ? $data['templates'] : array();
$types     = isset( $data['types'] ) ? $data['types'] : array();
$renderers = isset( $data['renderers'] ) ? $data['renderers'] : array();
$map       = isset( $data['map'] ) ? $data['map'] : array();
$default_r = isset( $data['default_renderer'] ) ? $data['default_renderer'] : 'gutenberg';

// family => name for select lists.
$families = array();
foreach ( $templates as $t ) {
	$families[ $t['family'] ] = $t['name'];
}
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Templates', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Native, page-builder-independent templates. The SEO engine produces structured content; a renderer turns it into a WordPress page. Elementor is optional — the default is Gutenberg.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Default renderer', 'seo-command-center' ); ?></h2>
		</div>
		<p>
			<select id="scc-default-renderer">
				<?php foreach ( $renderers as $rid => $r ) : ?>
					<option value="<?php echo esc_attr( $rid ); ?>" <?php selected( $default_r, $rid ); ?>>
						<?php echo esc_html( $r['label'] ); ?><?php echo $r['available'] ? '' : esc_html__( ' (unavailable — will fall back)', 'seo-command-center' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="scc-inline-status" id="scc-renderer-msg"></span>
		</p>
		<p class="scc-note"><?php esc_html_e( 'If the chosen renderer is unavailable at generation time, the plugin automatically falls back (Elementor → Gutenberg → native WordPress). Generation never fails because a page builder is missing.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Your templates', 'seo-command-center' ); ?></h2>
			<div>
				<select id="scc-new-template-type">
					<?php foreach ( $types as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button button-primary" id="scc-new-template"><?php esc_html_e( 'Create from default structure', 'seo-command-center' ); ?></button>
			</div>
		</div>
		<span class="scc-inline-status" id="scc-template-msg"></span>
		<?php if ( empty( $templates ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'No templates yet. Create one from a default structure above, or import an Elementor page below. A built-in fallback is always used if none is configured.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<table class="widefat striped scc-table" id="scc-templates-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Content type', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Renderer', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Sections', 'seo-command-center' ); ?></th>
						<th><?php esc_html_e( 'Version', 'seo-command-center' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $templates as $t ) : ?>
						<tr data-id="<?php echo esc_attr( $t['id'] ); ?>" data-family="<?php echo esc_attr( $t['family'] ); ?>" data-type="<?php echo esc_attr( $t['content_type'] ); ?>">
							<td><?php echo esc_html( $t['name'] ); ?></td>
							<td><?php echo esc_html( $t['content_type'] ); ?></td>
							<td><?php echo esc_html( $t['renderer'] ? $t['renderer'] : esc_html__( 'default', 'seo-command-center' ) ); ?><?php echo (int) $t['elementor_source_id'] > 0 ? ' (Elementor)' : ''; ?></td>
							<td><?php echo esc_html( count( (array) ( $t['structure']['sections'] ?? array() ) ) ); ?></td>
							<td>v<?php echo esc_html( (int) $t['version'] ); ?></td>
							<td>
								<button class="button button-small scc-tpl-preview"><?php esc_html_e( 'Preview', 'seo-command-center' ); ?></button>
								<button class="button button-small scc-tpl-clone"><?php esc_html_e( 'Duplicate', 'seo-command-center' ); ?></button>
								<button class="button button-small scc-tpl-delete"><?php esc_html_e( 'Delete', 'seo-command-center' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div id="scc-tpl-preview-out"></div>
		<?php endif; ?>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Template mapping', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'Deterministically assign a template and renderer to each content type. The AI never chooses the template.', 'seo-command-center' ); ?></p>
		<table class="widefat striped scc-table" id="scc-tpl-map-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Content type', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Template', 'seo-command-center' ); ?></th>
					<th><?php esc_html_e( 'Renderer', 'seo-command-center' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $types as $type ) :
					$entry = isset( $map[ $type ] ) && is_array( $map[ $type ] ) ? $map[ $type ] : array(); ?>
					<tr data-content-type="<?php echo esc_attr( $type ); ?>">
						<td><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?></strong></td>
						<td>
							<select class="scc-map-family">
								<option value=""><?php esc_html_e( '— Default —', 'seo-command-center' ); ?></option>
								<?php foreach ( $families as $family => $fname ) : ?>
									<option value="<?php echo esc_attr( $family ); ?>" <?php selected( $entry['family'] ?? '', $family ); ?>><?php echo esc_html( $fname ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<select class="scc-map-renderer">
								<option value=""><?php esc_html_e( 'Default renderer', 'seo-command-center' ); ?></option>
								<?php foreach ( $renderers as $rid => $r ) : ?>
									<option value="<?php echo esc_attr( $rid ); ?>" <?php selected( $entry['renderer'] ?? '', $rid ); ?>><?php echo esc_html( $r['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<span class="scc-inline-status" id="scc-map-msg"></span>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'Import an Elementor template (optional)', 'seo-command-center' ); ?></h2>
		<?php if ( empty( $data['elementor_active'] ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'Elementor is not active. The plugin works fully without it using the Gutenberg and native WordPress renderers.', 'seo-command-center' ); ?></p>
		<?php else : ?>
			<p class="scc-note"><?php esc_html_e( 'Register an existing Elementor page (built with Elementor Free) as a template. Generation duplicates its design into a new page and fills placeholders — the original is never modified. No Elementor Pro or Theme Builder required.', 'seo-command-center' ); ?></p>
			<p>
				<select id="scc-el-source">
					<?php foreach ( (array) $data['elementor_sources'] as $src ) : ?>
						<option value="<?php echo esc_attr( $src['id'] ); ?>"><?php echo esc_html( $src['name'] . ' (#' . $src['id'] . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="scc-el-type">
					<?php foreach ( $types as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button button-primary" id="scc-el-import"><?php esc_html_e( 'Import as template', 'seo-command-center' ); ?></button>
				<span class="scc-inline-status" id="scc-el-msg"></span>
			</p>
		<?php endif; ?>
	</div>
</div>
