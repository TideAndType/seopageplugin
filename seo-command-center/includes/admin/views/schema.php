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

$allowed  = isset( $data['allowed'] ) ? $data['allowed'] : array();
$business = isset( $data['business'] ) ? $data['business'] : array();
$b = function ( $business, $key ) {
	$v = isset( $business[ $key ] ) ? $business[ $key ] : '';
	return is_array( $v ) ? implode( "\n", $v ) : $v;
};
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

	<form class="scc-card" id="scc-schema-settings-form">
		<h2><?php esc_html_e( 'Organization &amp; business information', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'Used to build Organization and LocalBusiness schema. Only fields you provide are included — nothing is invented.', 'seo-command-center' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="scc-org-name"><?php esc_html_e( 'Organization name', 'seo-command-center' ); ?></label></th>
				<td><input type="text" class="regular-text" id="scc-org-name" name="organization_name" value="<?php echo esc_attr( $b( $business, 'organization_name' ) ); ?>"></td></tr>
			<tr><th scope="row"><label for="scc-logo"><?php esc_html_e( 'Logo URL', 'seo-command-center' ); ?></label></th>
				<td><input type="url" class="regular-text" id="scc-logo" name="logo" value="<?php echo esc_attr( $b( $business, 'logo' ) ); ?>"></td></tr>
			<tr><th scope="row"><label for="scc-phone"><?php esc_html_e( 'Phone', 'seo-command-center' ); ?></label></th>
				<td><input type="text" id="scc-phone" name="phone" value="<?php echo esc_attr( $b( $business, 'phone' ) ); ?>"></td></tr>
			<tr><th scope="row"><label for="scc-street"><?php esc_html_e( 'Street address', 'seo-command-center' ); ?></label></th>
				<td><input type="text" class="regular-text" id="scc-street" name="street" value="<?php echo esc_attr( $b( $business, 'street' ) ); ?>"></td></tr>
			<tr><th scope="row"><label for="scc-city"><?php esc_html_e( 'City', 'seo-command-center' ); ?></label></th>
				<td><input type="text" id="scc-city" name="city" value="<?php echo esc_attr( $b( $business, 'city' ) ); ?>"></td></tr>
			<tr><th scope="row"><label for="scc-region"><?php esc_html_e( 'Region/State', 'seo-command-center' ); ?></label></th>
				<td><input type="text" id="scc-region" name="region" value="<?php echo esc_attr( $b( $business, 'region' ) ); ?>"></td></tr>
			<tr><th scope="row"><label for="scc-postal"><?php esc_html_e( 'Postal code', 'seo-command-center' ); ?></label></th>
				<td><input type="text" id="scc-postal" name="postal_code" value="<?php echo esc_attr( $b( $business, 'postal_code' ) ); ?>"></td></tr>
			<tr><th scope="row"><label for="scc-country"><?php esc_html_e( 'Country', 'seo-command-center' ); ?></label></th>
				<td><input type="text" id="scc-country" name="country" value="<?php echo esc_attr( $b( $business, 'country' ) ); ?>"></td></tr>
			<tr><th scope="row"><label for="scc-author"><?php esc_html_e( 'Default author', 'seo-command-center' ); ?></label></th>
				<td><input type="text" id="scc-author" name="default_author" value="<?php echo esc_attr( $b( $business, 'default_author' ) ); ?>"></td></tr>
			<tr><th scope="row"><label for="scc-social"><?php esc_html_e( 'Social profile URLs (one per line)', 'seo-command-center' ); ?></label></th>
				<td><textarea id="scc-social" name="social_profiles" rows="3" class="large-text"><?php echo esc_textarea( $b( $business, 'social_profiles' ) ); ?></textarea></td></tr>
			<tr><th scope="row"><label for="scc-areas"><?php esc_html_e( 'Service areas (one per line)', 'seo-command-center' ); ?></label></th>
				<td><textarea id="scc-areas" name="service_areas" rows="3" class="large-text"><?php echo esc_textarea( $b( $business, 'service_areas' ) ); ?></textarea></td></tr>
		</table>
		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save business information', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-schema-settings-status"></span>
		</p>
	</form>
</div>
