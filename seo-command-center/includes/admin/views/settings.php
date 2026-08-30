<?php
/**
 * Settings view. Saved via REST (JS) — no secrets rendered here.
 *
 * @package SEO_Command_Center
 * @var array $data View data (settings, providers).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s = isset( $data['settings'] ) ? $data['settings'] : array();
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Settings', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Defaults for AI, SEO, publishing, and spending limits. API keys live under API Connections.', 'seo-command-center' ); ?></p>
	</div>

	<form id="scc-settings-form" class="scc-card">
		<h2><?php esc_html_e( 'AI', 'seo-command-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="scc-default-provider"><?php esc_html_e( 'Primary provider', 'seo-command-center' ); ?></label></th>
				<td>
					<select id="scc-default-provider" name="default_provider">
						<option value="claude" <?php selected( $s['default_provider'], 'claude' ); ?>>Anthropic Claude</option>
						<option value="openai" <?php selected( $s['default_provider'], 'openai' ); ?>>OpenAI</option>
						<option value="gemini" <?php selected( $s['default_provider'], 'gemini' ); ?>>Google Gemini</option>
						<option value="lmstudio" <?php selected( $s['default_provider'], 'lmstudio' ); ?>>LM Studio (local)</option>
					</select>
					<p class="description"><?php esc_html_e( 'This provider runs every AI task (site plan, topical map, content) unless overridden per task below. Connecting a provider under API Connections is not enough — choose it here to actually use it. Pick “LM Studio (local)” to run everything on your own server.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-fallback-provider"><?php esc_html_e( 'Fallback provider', 'seo-command-center' ); ?></label></th>
				<td>
					<select id="scc-fallback-provider" name="fallback_provider">
						<option value="" <?php selected( $s['fallback_provider'], '' ); ?>><?php esc_html_e( 'None', 'seo-command-center' ); ?></option>
						<option value="claude" <?php selected( $s['fallback_provider'], 'claude' ); ?>>Anthropic Claude</option>
						<option value="openai" <?php selected( $s['fallback_provider'], 'openai' ); ?>>OpenAI</option>
						<option value="gemini" <?php selected( $s['fallback_provider'], 'gemini' ); ?>>Google Gemini</option>
						<option value="lmstudio" <?php selected( $s['fallback_provider'], 'lmstudio' ); ?>>LM Studio (local)</option>
					</select>
					<p class="description"><?php esc_html_e( 'Used automatically if the primary provider fails.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-claude-model"><?php esc_html_e( 'Claude model', 'seo-command-center' ); ?></label></th>
				<td>
					<select id="scc-claude-model" name="claude_model">
						<?php foreach ( $data['providers']['claude']->list_models() as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['claude_model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-openai-model"><?php esc_html_e( 'OpenAI model', 'seo-command-center' ); ?></label></th>
				<td>
					<select id="scc-openai-model" name="openai_model">
						<?php foreach ( $data['providers']['openai']->list_models() as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['openai_model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-gemini-model"><?php esc_html_e( 'Gemini model', 'seo-command-center' ); ?></label></th>
				<td>
					<select id="scc-gemini-model" name="gemini_model">
						<?php foreach ( $data['providers']['gemini']->list_models() as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['gemini_model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-lmstudio-base"><?php esc_html_e( 'LM Studio server URL', 'seo-command-center' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="scc-lmstudio-base" name="lmstudio_base_url" value="<?php echo esc_attr( $s['lmstudio_base_url'] ); ?>" placeholder="http://localhost:1234/v1">
					<p class="description"><?php esc_html_e( 'The OpenAI-compatible endpoint LM Studio exposes (Developer → Start Server). If WordPress runs on a different machine than LM Studio, use your computer’s LAN IP or a tunnel instead of localhost.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-lmstudio-model"><?php esc_html_e( 'LM Studio model', 'seo-command-center' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="scc-lmstudio-model" name="lmstudio_model" value="<?php echo esc_attr( $s['lmstudio_model'] ); ?>" placeholder="local-model" list="scc-lmstudio-model-list">
					<datalist id="scc-lmstudio-model-list"></datalist>
					<button type="button" class="button" id="scc-lmstudio-detect"><?php esc_html_e( 'Detect models', 'seo-command-center' ); ?></button>
					<span class="scc-inline-status" id="scc-lmstudio-detect-status"></span>
					<p class="description"><?php esc_html_e( 'Click “Detect models” to pull the loaded model IDs from your LM Studio server (this also confirms the URL is reachable). “local-model” uses whatever model is currently loaded.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'AI model per task', 'seo-command-center' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Optionally use a different provider/model for each AI task. Leave as “Use primary provider” to follow the setting above. (Site Analysis and the SEO Audit are rule-based and don’t use AI, so they aren’t listed here.)', 'seo-command-center' ); ?></p>
		<table class="form-table" role="presentation" id="scc-route-table">
			<?php foreach ( SCC_AI_Manager::routable_operations() as $key => $label ) :
				$sel_provider = isset( $s[ "route_{$key}_provider" ] ) ? $s[ "route_{$key}_provider" ] : '';
				$sel_model    = isset( $s[ "route_{$key}_model" ] ) ? $s[ "route_{$key}_model" ] : '';
				?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td>
						<select class="scc-route-provider" name="route_<?php echo esc_attr( $key ); ?>_provider" data-key="<?php echo esc_attr( $key ); ?>">
							<option value="" <?php selected( $sel_provider, '' ); ?>><?php esc_html_e( 'Use primary provider', 'seo-command-center' ); ?></option>
							<option value="claude" <?php selected( $sel_provider, 'claude' ); ?>>Anthropic Claude</option>
							<option value="openai" <?php selected( $sel_provider, 'openai' ); ?>>OpenAI</option>
							<option value="gemini" <?php selected( $sel_provider, 'gemini' ); ?>>Google Gemini</option>
							<option value="lmstudio" <?php selected( $sel_provider, 'lmstudio' ); ?>>LM Studio (local)</option>
						</select>
						<select class="scc-route-model" name="route_<?php echo esc_attr( $key ); ?>_model" data-key="<?php echo esc_attr( $key ); ?>" data-selected="<?php echo esc_attr( $sel_model ); ?>">
							<option value=""><?php esc_html_e( 'Default model', 'seo-command-center' ); ?></option>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h2><?php esc_html_e( 'SEO defaults', 'seo-command-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="scc-word-count"><?php esc_html_e( 'Default word count', 'seo-command-center' ); ?></label></th>
				<td><input type="number" min="100" max="10000" id="scc-word-count" name="default_word_count" value="<?php echo esc_attr( $s['default_word_count'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-max-links"><?php esc_html_e( 'Max internal links per page', 'seo-command-center' ); ?></label></th>
				<td><input type="number" min="0" max="50" id="scc-max-links" name="max_internal_links" value="<?php echo esc_attr( $s['max_internal_links'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-meta-storage"><?php esc_html_e( 'Metadata storage', 'seo-command-center' ); ?></label></th>
				<td>
					<select id="scc-meta-storage" name="meta_storage">
						<option value="auto" <?php selected( $s['meta_storage'], 'auto' ); ?>><?php esc_html_e( 'Auto (active SEO plugin, else this plugin)', 'seo-command-center' ); ?></option>
						<option value="seo_plugin" <?php selected( $s['meta_storage'], 'seo_plugin' ); ?>><?php esc_html_e( 'Active SEO plugin keys', 'seo-command-center' ); ?></option>
						<option value="plugin" <?php selected( $s['meta_storage'], 'plugin' ); ?>><?php esc_html_e( 'This plugin only', 'seo-command-center' ); ?></option>
					</select>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Internal Link Autopilot', 'seo-command-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Autopilot', 'seo-command-center' ); ?></th>
				<td>
					<label><input type="checkbox" name="autopilot_enabled" <?php checked( $s['autopilot_enabled'] ); ?>> <?php esc_html_e( 'Automatically analyze new/updated content for internal-link opportunities (runs in the background).', 'seo-command-center' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-insert', 'seo-command-center' ); ?></th>
				<td>
					<label><input type="checkbox" name="autopilot_auto_insert" <?php checked( $s['autopilot_auto_insert'] ); ?>> <?php esc_html_e( 'Automatically insert high-confidence links. Medium confidence becomes a recommendation; low confidence is ignored.', 'seo-command-center' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-high-conf"><?php esc_html_e( 'High-confidence threshold (%)', 'seo-command-center' ); ?></label></th>
				<td><input type="number" min="50" max="100" id="scc-high-conf" name="link_high_confidence" value="<?php echo esc_attr( $s['link_high_confidence'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-med-conf"><?php esc_html_e( 'Medium-confidence threshold (%)', 'seo-command-center' ); ?></label></th>
				<td><input type="number" min="1" max="99" id="scc-med-conf" name="link_medium_confidence" value="<?php echo esc_attr( $s['link_medium_confidence'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-max-dest"><?php esc_html_e( 'Max links to the same destination', 'seo-command-center' ); ?></label></th>
				<td><input type="number" min="1" max="10" id="scc-max-dest" name="link_max_per_destination" value="<?php echo esc_attr( $s['link_max_per_destination'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Safety', 'seo-command-center' ); ?></th>
				<td>
					<label><input type="checkbox" name="link_avoid_headings" <?php checked( $s['link_avoid_headings'] ); ?>> <?php esc_html_e( 'Never place links inside headings', 'seo-command-center' ); ?></label><br>
					<label><input type="checkbox" name="link_vary_anchor" <?php checked( $s['link_vary_anchor'] ); ?>> <?php esc_html_e( 'Vary anchor text (avoid exact-match repetition)', 'seo-command-center' ); ?></label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Publishing', 'seo-command-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Save as draft by default', 'seo-command-center' ); ?></th>
				<td>
					<label><input type="checkbox" name="draft_by_default" <?php checked( $s['draft_by_default'] ); ?>> <?php esc_html_e( 'Generated content is always saved as a draft (recommended).', 'seo-command-center' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatic publishing', 'seo-command-center' ); ?></th>
				<td>
					<label><input type="checkbox" name="auto_publish" <?php checked( $s['auto_publish'] ); ?>> <?php esc_html_e( 'Allow the plugin to publish AI content without review. Off by default — you stay in control.', 'seo-command-center' ); ?></label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Limits', 'seo-command-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="scc-budget"><?php esc_html_e( 'Monthly AI budget (USD)', 'seo-command-center' ); ?></label></th>
				<td>
					<input type="number" min="0" step="1" id="scc-budget" name="monthly_budget" value="<?php echo esc_attr( $s['monthly_budget'] ); ?>">
					<p class="description"><?php esc_html_e( '0 means no limit. When reached, generation is paused.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-max-pages"><?php esc_html_e( 'Max pages per batch', 'seo-command-center' ); ?></label></th>
				<td><input type="number" min="1" max="500" id="scc-max-pages" name="max_pages_per_batch" value="<?php echo esc_attr( $s['max_pages_per_batch'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-max-articles"><?php esc_html_e( 'Max articles per batch', 'seo-command-center' ); ?></label></th>
				<td><input type="number" min="1" max="500" id="scc-max-articles" name="max_articles_per_batch" value="<?php echo esc_attr( $s['max_articles_per_batch'] ); ?>"></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Housekeeping', 'seo-command-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Remove data on uninstall', 'seo-command-center' ); ?></th>
				<td>
					<label><input type="checkbox" name="remove_data_on_uninstall" <?php checked( $s['remove_data_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all plugin tables and settings when the plugin is uninstalled.', 'seo-command-center' ); ?></label>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-settings-status"></span>
		</p>
	</form>
</div>
