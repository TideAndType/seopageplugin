<?php
/**
 * API Connections view. Keys are write-only; only masked hints are shown.
 *
 * @package SEO_Command_Center
 * @var array $data View data (hints, providers).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hints = isset( $data['hints'] ) ? $data['hints'] : array();

/**
 * Render one key field.
 *
 * @param string $field Field key.
 * @param string $label Label.
 * @param array  $hints Hints map.
 */
$field = function ( $field, $label, $hints ) {
	$configured = ! empty( $hints[ $field ]['configured'] );
	$hint       = isset( $hints[ $field ]['hint'] ) ? $hints[ $field ]['hint'] : '';
	?>
	<tr>
		<th scope="row"><label for="scc-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
		<td>
			<input type="password" autocomplete="off" class="regular-text" id="scc-<?php echo esc_attr( $field ); ?>" data-field="<?php echo esc_attr( $field ); ?>"
				placeholder="<?php echo $configured ? esc_attr( $hint ) : esc_attr__( 'Not set', 'seo-command-center' ); ?>">
			<?php if ( $configured ) : ?>
				<span class="scc-badge scc-badge--ok"><?php esc_html_e( 'Configured', 'seo-command-center' ); ?></span>
				<label class="scc-clear"><input type="checkbox" data-clear="<?php echo esc_attr( $field ); ?>"> <?php esc_html_e( 'Clear', 'seo-command-center' ); ?></label>
			<?php else : ?>
				<span class="scc-badge"><?php esc_html_e( 'Not set', 'seo-command-center' ); ?></span>
			<?php endif; ?>
		</td>
	</tr>
	<?php
};
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'API Connections', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Keys are stored securely and never shown again or sent to your browser. Leave a field blank to keep the existing key.', 'seo-command-center' ); ?></p>
	</div>

	<form id="scc-connections-form" class="scc-card">
		<h2><?php esc_html_e( 'AI providers', 'seo-command-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$field( 'claude_key', __( 'Anthropic Claude API key', 'seo-command-center' ), $hints );
			$field( 'openai_key', __( 'OpenAI API key', 'seo-command-center' ), $hints );
			$field( 'gemini_key', __( 'Google Gemini API key', 'seo-command-center' ), $hints );
			?>
		</table>
		<p class="scc-note"><?php esc_html_e( 'Get a Gemini key at aistudio.google.com/apikey. Get an Anthropic key at console.anthropic.com, and an OpenAI key at platform.openai.com.', 'seo-command-center' ); ?></p>
		<p>
			<button type="button" class="button" data-test-provider="claude"><?php esc_html_e( 'Test Claude', 'seo-command-center' ); ?></button>
			<button type="button" class="button" data-test-provider="openai"><?php esc_html_e( 'Test OpenAI', 'seo-command-center' ); ?></button>
			<button type="button" class="button" data-test-provider="gemini"><?php esc_html_e( 'Test Gemini', 'seo-command-center' ); ?></button>
			<button type="button" class="button" data-test-provider="lmstudio"><?php esc_html_e( 'Test LM Studio', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-test-status"></span>
		</p>

		<h2><?php esc_html_e( 'LM Studio (local, optional)', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'Run models on your own computer with LM Studio — free and private. Set the server URL and model under Settings → AI (default http://localhost:1234/v1). An API key is optional; only add one if you configured LM Studio to require it. Note: WordPress must be able to reach the LM Studio server, so “localhost” only works when WordPress runs on the same machine.', 'seo-command-center' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			$field( 'lmstudio_key', __( 'LM Studio API key (optional)', 'seo-command-center' ), $hints );
			?>
		</table>

		<h2><?php esc_html_e( 'DataForSEO (optional)', 'seo-command-center' ); ?></h2>
		<p class="scc-note"><?php esc_html_e( 'Adds real keyword volume, competition, and related keywords. The plugin works without it.', 'seo-command-center' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			$field( 'dataforseo_login', __( 'DataForSEO login', 'seo-command-center' ), $hints );
			$field( 'dataforseo_key', __( 'DataForSEO password/key', 'seo-command-center' ), $hints );
			?>
		</table>

		<h2><?php esc_html_e( 'Google Search Console (optional)', 'seo-command-center' ); ?></h2>

		<?php if ( ! empty( $data['gsc_notice'] ) ) : ?>
			<div class="notice notice-<?php echo ! empty( $data['gsc_notice']['ok'] ) ? 'success' : 'error'; ?> inline">
				<p><?php echo esc_html( $data['gsc_notice']['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $data['gsc_connected'] ) ) : ?>
			<p class="scc-ok"><strong><?php esc_html_e( '✓ Connected.', 'seo-command-center' ); ?></strong> <?php esc_html_e( 'Click Verify below to confirm data access and pick your property.', 'seo-command-center' ); ?></p>
		<?php endif; ?>

		<p class="scc-note">
			<?php esc_html_e( 'Imports real query, impression, click, CTR and position data. Google Search Console has no simple API key — it requires a one-time OAuth setup. Follow these steps once:', 'seo-command-center' ); ?>
		</p>
		<ol class="scc-options">
			<li><?php echo wp_kses_post( sprintf( /* translators: %s: URL */ __( 'In <a href="%s" target="_blank" rel="noopener">Google Cloud Console</a>, create (or pick) a project and enable the “Google Search Console API”.', 'seo-command-center' ), 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com' ) ); ?></li>
			<li><?php esc_html_e( 'APIs & Services → Credentials → Create credentials → OAuth client ID → Application type: Web application.', 'seo-command-center' ); ?></li>
			<li>
				<?php esc_html_e( 'Under “Authorized redirect URIs”, add this exact URL:', 'seo-command-center' ); ?><br>
				<code id="scc-gsc-redirect"><?php echo esc_html( isset( $data['gsc_redirect'] ) ? $data['gsc_redirect'] : '' ); ?></code>
				<button type="button" class="button button-small" id="scc-gsc-copy-redirect"><?php esc_html_e( 'Copy', 'seo-command-center' ); ?></button>
			</li>
			<li><?php esc_html_e( 'Paste the Client ID and Client secret below, click “Save connections”, then click “Connect Google Search Console”.', 'seo-command-center' ); ?></li>
		</ol>

		<table class="form-table" role="presentation">
			<?php
			$field( 'gsc_client_id', __( 'OAuth client ID', 'seo-command-center' ), $hints );
			$field( 'gsc_client_secret', __( 'OAuth client secret', 'seo-command-center' ), $hints );
			?>
			<tr>
				<th scope="row"><label for="scc-gsc-site"><?php esc_html_e( 'Property (site URL)', 'seo-command-center' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="scc-gsc-site" value="<?php echo esc_attr( isset( $data['gsc_site_url'] ) ? $data['gsc_site_url'] : '' ); ?>" placeholder="sc-domain:example.com  or  https://example.com/">
					<p class="description"><?php esc_html_e( 'Set automatically after connecting when your account has one property. Otherwise click Verify and choose. Domain properties look like “sc-domain:example.com”; URL-prefix like “https://example.com/”.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
		</table>

		<p>
			<button type="button" class="button button-primary" id="scc-gsc-connect" <?php echo empty( $data['gsc_has_client'] ) ? 'disabled' : ''; ?>><?php echo ! empty( $data['gsc_connected'] ) ? esc_html__( 'Reconnect Google Search Console', 'seo-command-center' ) : esc_html__( 'Connect Google Search Console', 'seo-command-center' ); ?></button>
			<button type="button" class="button" id="scc-gsc-verify"><?php esc_html_e( 'Verify connection', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-gsc-verify-status"></span>
		</p>
		<?php if ( empty( $data['gsc_has_client'] ) ) : ?>
			<p class="scc-note"><?php esc_html_e( 'Enter the Client ID + secret and Save first — then the Connect button activates.', 'seo-command-center' ); ?></p>
		<?php endif; ?>
		<div id="scc-gsc-verify-out"></div>

		<details style="margin-top:10px;">
			<summary class="scc-note"><?php esc_html_e( 'Advanced: paste a refresh token manually instead', 'seo-command-center' ); ?></summary>
			<table class="form-table" role="presentation">
				<?php $field( 'gsc_refresh_token', __( 'OAuth refresh token', 'seo-command-center' ), $hints ); ?>
			</table>
		</details>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save connections', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-connections-status"></span>
		</p>
	</form>
</div>
