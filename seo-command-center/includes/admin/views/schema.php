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
$brand = class_exists( 'SCC_Brand_Brain' ) ? SCC_Brand_Brain::profile() : array();
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

		<h2><?php esc_html_e( 'Brand & evidence brain', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'These are factual inputs the Page Brain may use while planning and writing. Leave anything unknown blank. TideOrbit will not fabricate missing proof.', 'seo-command-center' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="scc-brand-name"><?php esc_html_e( 'Brand/business name', 'seo-command-center' ); ?></label></th>
				<td><input type="text" class="regular-text" id="scc-brand-name" name="brand_business_name" value="<?php echo esc_attr( (string) ( $brand['business_name'] ?? '' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-brand-voice"><?php esc_html_e( 'Brand voice', 'seo-command-center' ); ?></label></th>
				<td><textarea id="scc-brand-voice" name="brand_voice" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $brand['voice'] ?? '' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Example: direct, practical, local-expert tone; avoid hype and jargon.', 'seo-command-center' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-brand-services"><?php esc_html_e( 'Verified services (one per line)', 'seo-command-center' ); ?></label></th>
				<td><textarea id="scc-brand-services" name="brand_services" rows="4" class="large-text"><?php echo esc_textarea( implode( "\n", (array) ( $brand['services'] ?? array() ) ) ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-brand-locations"><?php esc_html_e( 'Verified locations (one per line)', 'seo-command-center' ); ?></label></th>
				<td><textarea id="scc-brand-locations" name="brand_locations" rows="4" class="large-text"><?php echo esc_textarea( implode( "\n", (array) ( $brand['locations'] ?? array() ) ) ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-brand-cta"><?php esc_html_e( 'Primary CTA', 'seo-command-center' ); ?></label></th>
				<td><input type="text" class="regular-text" id="scc-brand-cta" name="brand_primary_cta" value="<?php echo esc_attr( (string) ( $brand['primary_cta'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Request a quote, Book a consultation, Start enrollment…', 'seo-command-center' ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-brand-secondary-cta"><?php esc_html_e( 'Secondary CTA', 'seo-command-center' ); ?></label></th>
				<td><input type="text" class="regular-text" id="scc-brand-secondary-cta" name="brand_secondary_cta" value="<?php echo esc_attr( (string) ( $brand['secondary_cta'] ?? '' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-brand-usps"><?php esc_html_e( 'Verified differentiators (one per line)', 'seo-command-center' ); ?></label></th>
				<td><textarea id="scc-brand-usps" name="brand_usps" rows="4" class="large-text"><?php echo esc_textarea( implode( "\n", (array) ( $brand['unique_selling_points'] ?? array() ) ) ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-brand-proof"><?php esc_html_e( 'Proof points / real results (one per line)', 'seo-command-center' ); ?></label></th>
				<td><textarea id="scc-brand-proof" name="brand_proof_points" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", (array) ( $brand['proof_points'] ?? array() ) ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Only add numbers or outcomes you can substantiate.', 'seo-command-center' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-brand-credentials"><?php esc_html_e( 'Credentials / awards (one per line)', 'seo-command-center' ); ?></label></th>
				<td><textarea id="scc-brand-credentials" name="brand_credentials" rows="4" class="large-text"><?php echo esc_textarea( implode( "\n", (array) ( $brand['credentials'] ?? array() ) ) ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-brand-testimonials"><?php esc_html_e( 'Verified testimonials', 'seo-command-center' ); ?></label></th>
				<td><textarea id="scc-brand-testimonials" name="brand_testimonials" rows="6" class="large-text"><?php
					$testimonial_lines = array();
					foreach ( (array) ( $brand['testimonials'] ?? array() ) as $testimonial ) {
						if ( ! is_array( $testimonial ) || empty( $testimonial['quote'] ) ) { continue; }
						$line = (string) $testimonial['quote'];
						if ( ! empty( $testimonial['attribution'] ) ) { $line .= ' | ' . (string) $testimonial['attribution']; }
						$testimonial_lines[] = $line;
					}
					echo esc_textarea( implode( "\n", $testimonial_lines ) );
				?></textarea>
				<p class="description"><?php esc_html_e( 'One per line. Optional attribution format: Quote | Customer Name.', 'seo-command-center' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-brand-forbidden"><?php esc_html_e( 'Claims TideOrbit must never make (one per line)', 'seo-command-center' ); ?></label></th>
				<td><textarea id="scc-brand-forbidden" name="brand_forbidden_claims" rows="4" class="large-text"><?php echo esc_textarea( implode( "\n", (array) ( $brand['forbidden_claims'] ?? array() ) ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Useful for guarantees, unsupported superlatives, restricted claims, or promises your business does not make.', 'seo-command-center' ); ?></p></td>
			</tr>
		</table>
		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save business & brand brain', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-schema-settings-status"></span>
		</p>
	</form>
</div>
