<?php
/**
 * Elementor Templates view.
 *
 * @package SEO_Command_Center
 * @var array $data View data (active, templates, mappings, content_types, placeholders).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active        = ! empty( $data['active'] );
$templates     = isset( $data['templates'] ) ? $data['templates'] : array();
$mappings      = isset( $data['mappings'] ) ? $data['mappings'] : array();
$content_types = isset( $data['content_types'] ) ? $data['content_types'] : array();
$placeholders  = isset( $data['placeholders'] ) ? $data['placeholders'] : array();

$mapped = array();
foreach ( $mappings as $m ) {
	$mapped[ $m['content_type'] ] = $m;
}
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Elementor Templates', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Map an Elementor template to each content type. When you generate that type of page, the plugin fills the template’s placeholders with your content while keeping the design intact.', 'seo-command-center' ); ?></p>
	</div>

	<?php if ( ! $active ) : ?>
		<div class="scc-card scc-empty">
			<h2><?php esc_html_e( 'Elementor is not active', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'Install and activate Elementor to use template-based page building. Without it, generated content is saved as standard WordPress content, which still works.', 'seo-command-center' ); ?></p>
		</div>
	<?php else : ?>
		<div class="scc-card">
			<h2><?php esc_html_e( 'Template mappings', 'seo-command-center' ); ?></h2>
			<?php if ( empty( $templates ) ) : ?>
				<p class="scc-note"><?php esc_html_e( 'No Elementor templates or designated SEO template pages found. Create a template in Elementor, or open an Elementor page and mark it as an SEO template (via the SEO Command meta box).', 'seo-command-center' ); ?></p>
			<?php else : ?>
				<table class="widefat striped scc-table" id="scc-template-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Content type', 'seo-command-center' ); ?></th>
							<th><?php esc_html_e( 'Assigned template', 'seo-command-center' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $content_types as $ct ) : ?>
							<tr data-content-type="<?php echo esc_attr( $ct ); ?>">
								<td><strong><?php echo esc_html( ucfirst( $ct ) ); ?></strong></td>
								<td>
									<select class="scc-template-select">
										<option value=""><?php esc_html_e( '— None —', 'seo-command-center' ); ?></option>
										<?php foreach ( $templates as $tpl ) : ?>
											<option value="<?php echo esc_attr( $tpl['id'] ); ?>" data-name="<?php echo esc_attr( $tpl['name'] ); ?>"
												<?php echo ( isset( $mapped[ $ct ] ) && (int) $mapped[ $ct ]['template_id'] === (int) $tpl['id'] ) ? 'selected' : ''; ?>>
												<?php echo esc_html( $tpl['name'] . ' (#' . $tpl['id'] . ', ' . $tpl['source'] . ')' ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td class="scc-map-status">
									<?php if ( isset( $mapped[ $ct ] ) ) : ?>
										<span class="scc-badge scc-badge--ok"><?php esc_html_e( 'Mapped', 'seo-command-center' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<span class="scc-inline-status" id="scc-template-msg"></span>
			<?php endif; ?>
		</div>

		<div class="scc-card">
			<h2><?php esc_html_e( 'Placeholders', 'seo-command-center' ); ?></h2>
			<p class="scc-note"><?php esc_html_e( 'Add these tokens to any text widget in your Elementor template. When a page is generated, they are replaced with the matching content. Custom tokens ({{ANYTHING}}) are also supported and cleared if no value is available.', 'seo-command-center' ); ?></p>
			<p>
				<?php foreach ( $placeholders as $ph ) : ?>
					<code>{{<?php echo esc_html( $ph ); ?>}}</code>&nbsp;
				<?php endforeach; ?>
			</p>
			<?php if ( ! empty( $mappings ) ) : ?>
				<h3><?php esc_html_e( 'Detected in your mapped templates', 'seo-command-center' ); ?></h3>
				<ul class="scc-options">
					<?php foreach ( $mappings as $m ) : ?>
						<li>
							<strong><?php echo esc_html( $m['template_name'] ); ?></strong> (<?php echo esc_html( $m['content_type'] ); ?>):
							<?php echo $m['placeholders'] ? esc_html( implode( ', ', $m['placeholders'] ) ) : esc_html__( 'no placeholders found — add some to the template', 'seo-command-center' ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
