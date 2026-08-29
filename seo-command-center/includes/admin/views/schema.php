<?php
/**
 * Schema info view.
 *
 * @package SEO_Command_Center
 * @var array $data View data (seo_plugin, allowed).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$allowed = isset( $data['allowed'] ) ? $data['allowed'] : array();
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Schema', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Structured data is generated and validated automatically when you generate a page, and output on the front end — but only when your active SEO plugin does not already provide it, to avoid duplicates.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card">
		<h2><?php esc_html_e( 'How schema is handled', 'seo-command-center' ); ?></h2>
		<ul class="scc-options">
			<li><?php esc_html_e( 'A schema type is chosen per page type — Article/BlogPosting for articles, Service for service pages, LocalBusiness for location pages, WebPage otherwise.', 'seo-command-center' ); ?></li>
			<li><?php esc_html_e( 'FAQPage schema is added when the page includes an FAQ section.', 'seo-command-center' ); ?></li>
			<li><?php esc_html_e( 'Every node is validated for its required fields before it is stored or output.', 'seo-command-center' ); ?></li>
			<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: active SEO plugin name */
						__( 'Detected SEO plugin: %s. Types it already emits site-wide are skipped to prevent duplicate schema.', 'seo-command-center' ),
						$data['seo_plugin']
					)
				);
				?>
			</li>
		</ul>

		<h2><?php esc_html_e( 'Supported schema types', 'seo-command-center' ); ?></h2>
		<p>
			<?php foreach ( $allowed as $type ) : ?>
				<span class="scc-flag"><?php echo esc_html( $type ); ?></span>
			<?php endforeach; ?>
		</p>
	</div>
</div>
