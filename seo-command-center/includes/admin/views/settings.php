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
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-fallback-provider"><?php esc_html_e( 'Fallback provider', 'seo-command-center' ); ?></label></th>
				<td>
					<select id="scc-fallback-provider" name="fallback_provider">
						<option value="" <?php selected( $s['fallback_provider'], '' ); ?>><?php esc_html_e( 'None', 'seo-command-center' ); ?></option>
						<option value="claude" <?php selected( $s['fallback_provider'], 'claude' ); ?>>Anthropic Claude</option>
						<option value="openai" <?php selected( $s['fallback_provider'], 'openai' ); ?>>OpenAI</option>
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
