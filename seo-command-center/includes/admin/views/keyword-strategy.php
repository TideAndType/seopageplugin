<?php
/**
 * Keyword Strategy view.
 *
 * @package SEO_Command_Center
 * @var array $data View data (strategy).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$strategy = isset( $data['strategy'] ) ? $data['strategy'] : null;
$inputs   = ( $strategy && ! empty( $strategy['inputs_data'] ) ) ? $strategy['inputs_data'] : array();
$map      = ( $strategy && ! empty( $strategy['map_data'] ) ) ? $strategy['map_data'] : null;

$val = function ( $inputs, $key ) {
	$v = isset( $inputs[ $key ] ) ? $inputs[ $key ] : '';
	return is_array( $v ) ? implode( "\n", $v ) : $v;
};
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<span class="scc-phase-badge">✦ <?php esc_html_e( 'AI-driven topical maps', 'seo-command-center' ); ?></span>
		<h1><?php esc_html_e( 'Keyword Strategy', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Describe the business and let the AI build a structured topical map — clusters, primary and supporting terms, intent, and recommended URLs. These are strategic recommendations, not measured search volume.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card">
		<div class="scc-card__head">
			<h2><?php esc_html_e( 'Build from my site', 'seo-command-center' ); ?></h2>
			<button class="button button-primary" id="scc-keyword-auto"><?php esc_html_e( 'Analyze my site &amp; build the plan', 'seo-command-center' ); ?></button>
		</div>
		<div class="scc-gen-settings">
			<label><?php esc_html_e( 'Map type', 'seo-command-center' ); ?>
				<select id="scc-map-type">
					<option value="seo"><?php esc_html_e( 'SEO topical map', 'seo-command-center' ); ?></option>
					<option value="question"><?php esc_html_e( 'Question / FAQ map', 'seo-command-center' ); ?></option>
					<option value="keyword"><?php esc_html_e( 'Keyword-cluster map', 'seo-command-center' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Depth', 'seo-command-center' ); ?>
				<select id="scc-map-depth">
					<option value="compact"><?php esc_html_e( 'Compact', 'seo-command-center' ); ?></option>
					<option value="standard" selected><?php esc_html_e( 'Standard', 'seo-command-center' ); ?></option>
					<option value="deep"><?php esc_html_e( 'Deep', 'seo-command-center' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Language', 'seo-command-center' ); ?>
				<input type="text" id="scc-map-language" style="max-width:160px;" placeholder="<?php esc_attr_e( 'English (default)', 'seo-command-center' ); ?>">
			</label>
		</div>
		<span class="scc-inline-status" id="scc-keyword-auto-status"></span>
		<p class="scc-note"><?php esc_html_e( 'Reads your site name, tagline, and existing page/post titles, infers your services and locations, and builds the topical map automatically — no manual input needed. You can still refine it with the form below. Then open Site Architecture to see the structure and send pages to your Content Plan.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-columns">
		<form id="scc-keyword-form" class="scc-card">
			<h2><?php esc_html_e( 'Business inputs', 'seo-command-center' ); ?></h2>
			<p><label><?php esc_html_e( 'Business name', 'seo-command-center' ); ?><br><input type="text" class="regular-text" name="business_name" value="<?php echo esc_attr( $val( $inputs, 'business_name' ) ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Description', 'seo-command-center' ); ?><br><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea( $val( $inputs, 'description' ) ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Services (one per line)', 'seo-command-center' ); ?><br><textarea name="services" rows="4" class="large-text"><?php echo esc_textarea( $val( $inputs, 'services' ) ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Products (one per line)', 'seo-command-center' ); ?><br><textarea name="products" rows="2" class="large-text"><?php echo esc_textarea( $val( $inputs, 'products' ) ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Locations (one per line)', 'seo-command-center' ); ?><br><textarea name="locations" rows="3" class="large-text"><?php echo esc_textarea( $val( $inputs, 'locations' ) ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Target audience', 'seo-command-center' ); ?><br><textarea name="audience" rows="2" class="large-text"><?php echo esc_textarea( $val( $inputs, 'audience' ) ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Competitor URLs (one per line)', 'seo-command-center' ); ?><br><textarea name="competitors" rows="2" class="large-text"><?php echo esc_textarea( $val( $inputs, 'competitors' ) ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Seed keywords (optional, one per line)', 'seo-command-center' ); ?><br><textarea name="seed_keywords" rows="2" class="large-text"><?php echo esc_textarea( $val( $inputs, 'seed_keywords' ) ); ?></textarea></label></p>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Build topical map', 'seo-command-center' ); ?></button>
				<span class="scc-inline-status" id="scc-keyword-status"></span>
			</p>
			<p class="scc-note"><?php esc_html_e( 'Uses your configured AI provider and counts toward AI usage.', 'seo-command-center' ); ?></p>
		</form>

		<div class="scc-card" id="scc-keyword-result">
			<h2><?php esc_html_e( 'Topical map', 'seo-command-center' ); ?></h2>
			<?php if ( ! $map ) : ?>
				<p class="scc-note"><?php esc_html_e( 'No strategy yet. Fill in the form and build your topical map.', 'seo-command-center' ); ?></p>
			<?php else : ?>
				<?php require __DIR__ . '/partials/topical-map.php'; ?>
			<?php endif; ?>
		</div>
	</div>
</div>
