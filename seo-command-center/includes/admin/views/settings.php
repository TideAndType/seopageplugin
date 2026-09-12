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
		<p class="scc-note"><a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-schema' ) ); ?>"><?php esc_html_e( 'View schema (structured data) reference →', 'seo-command-center' ); ?></a></p>
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
			<tr>
				<th scope="row"><label for="scc-lmstudio-timeout"><?php esc_html_e( 'LM Studio timeout (seconds)', 'seo-command-center' ); ?></label></th>
				<td>
					<input type="number" class="small-text" id="scc-lmstudio-timeout" name="lmstudio_timeout" min="60" max="1800" step="30" value="<?php echo esc_attr( isset( $s['lmstudio_timeout'] ) && (int) $s['lmstudio_timeout'] > 0 ? (int) $s['lmstudio_timeout'] : 600 ); ?>">
					<p class="description"><?php esc_html_e( 'How long to wait for the local model to finish one generation. Large models (for example a 27B) writing a long article can take several minutes — raise this if you see a timeout (cURL error 28). Max 1800 (30 min). Generation keeps running on the server even if your browser disconnects; the draft appears when it finishes.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-competitor-crawl-budget"><?php esc_html_e( 'Competitor crawl budget (seconds)', 'seo-command-center' ); ?></label></th>
				<td>
					<input type="number" class="small-text" id="scc-competitor-crawl-budget" name="competitor_crawl_budget" min="10" max="300" step="5" value="<?php echo esc_attr( isset( $s['competitor_crawl_budget'] ) && (int) $s['competitor_crawl_budget'] > 0 ? (int) $s['competitor_crawl_budget'] : 45 ); ?>">
					<p class="description"><?php esc_html_e( 'Total time the Competitor Gaps tool spends reading competitor pages before it hands off to the AI. It keeps whatever it has read when this budget is spent, so a slow or unresponsive competitor site can no longer stall the whole analysis into a timeout. The AI step that follows uses the LM Studio timeout above. Lower this if gap analysis still times out; raise it to sample more competitor pages.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Generation length', 'seo-command-center' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="generation_unlimited_tokens" value="1" <?php checked( ! empty( $s['generation_unlimited_tokens'] ) ); ?>>
						<?php esc_html_e( 'Unlimited output tokens (let the model write until it finishes)', 'seo-command-center' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Removes the token budget for content generation. On LM Studio this sends max_tokens = -1, so the local model runs until the article is complete (no early truncation) — combined with a high timeout above. Hosted providers (Claude/OpenAI/Gemini) still apply their own high ceiling. Leave off to size the budget automatically from the target word count.', 'seo-command-center' ); ?></p>
					<p class="description">
						<label for="scc-gen-max-tokens"><?php esc_html_e( 'Or a fixed max tokens (0 = auto, ignored when Unlimited is on):', 'seo-command-center' ); ?></label>
						<input type="number" class="small-text" id="scc-gen-max-tokens" name="generation_max_tokens" min="0" max="200000" step="256" value="<?php echo esc_attr( isset( $s['generation_max_tokens'] ) ? (int) $s['generation_max_tokens'] : 0 ); ?>">
					</p>
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

		<h2><?php esc_html_e( 'Content style', 'seo-command-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="scc-content-target-words"><?php esc_html_e( 'Target word count', 'seo-command-center' ); ?></label></th>
				<td>
					<input type="number" class="small-text" id="scc-content-target-words" name="content_target_words" min="0" max="20000" step="50" value="<?php echo esc_attr( isset( $s['content_target_words'] ) ? (int) $s['content_target_words'] : 0 ); ?>">
					<p class="description"><?php esc_html_e( 'The length every generated article should aim for. Set a number (for example 1500) and generation targets it; leave 0 to size automatically from each plan entry. The model is told to write at least this many words — a capable model will hit it; very small local models may still fall short.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Visual presentation', 'seo-command-center' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="visual_presentation" value="1" <?php checked( ! isset( $s['visual_presentation'] ) || ! empty( $s['visual_presentation'] ) ); ?>>
						<?php esc_html_e( 'Transform generated articles into a scannable, component layout (callouts, takeaway cards, process steps, timelines, stat cards, responsive tables)', 'seo-command-center' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Semantic, CSS-only enhancement applied to newly generated drafts. Keeps all headings, links, lists, tables and SEO copy intact — it only improves the layout. Inherits your theme’s fonts and colours. Turn off to output plain article HTML.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Elementor layout', 'seo-command-center' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="layout_auto_build" value="1" <?php checked( ! isset( $s['layout_auto_build'] ) || ! empty( $s['layout_auto_build'] ) ); ?>>
						<?php esc_html_e( 'Automatically build an Elementor layout when content is generated', 'seo-command-center' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When Elementor is active, newly generated drafts are turned into an editable Elementor page from the block library (deterministic — no AI required). Pages that already use a mapped Elementor template are left as-is. Turn off to keep plain drafts and build layouts manually from Create ▸ Recently generated ▸ Build layout.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-content-persona"><?php esc_html_e( 'Writing persona', 'seo-command-center' ); ?></label></th>
				<td>
					<select id="scc-content-persona" name="content_persona">
						<option value="" <?php selected( ( $s['content_persona'] ?? '' ), '' ); ?>><?php esc_html_e( 'Default (senior SEO copywriter)', 'seo-command-center' ); ?></option>
						<?php foreach ( SCC_Generator::personas() as $pkey => $p ) : ?>
							<option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( ( $s['content_persona'] ?? '' ), $pkey ); ?>><?php echo esc_html( $p['label'] ); ?></option>
						<?php endforeach; ?>
						<option value="custom" <?php selected( ( $s['content_persona'] ?? '' ), 'custom' ); ?>><?php esc_html_e( 'Custom (use only the instructions below)', 'seo-command-center' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'The voice/expertise the AI writes with. Applies to every generated draft.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="scc-content-persona-custom"><?php esc_html_e( 'Custom style instructions', 'seo-command-center' ); ?></label></th>
				<td>
					<textarea id="scc-content-persona-custom" name="content_persona_custom" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'e.g. Write in a confident, no-nonsense tone. Use short sentences. Always include a comparison table where relevant.', 'seo-command-center' ); ?>"><?php echo esc_textarea( isset( $s['content_persona_custom'] ) ? (string) $s['content_persona_custom'] : '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Extra instructions added on top of the persona (or used on their own if the persona is set to “Custom”). These are appended to the AI system prompt for content generation.', 'seo-command-center' ); ?></p>
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

		<?php
		$automation_mode = isset( $s['automation_mode'] ) ? (string) $s['automation_mode'] : 'assisted';
		$modes = array(
			'conservative' => __( 'Conservative — analysis & recommendations only; nothing runs automatically', 'seo-command-center' ),
			'assisted'     => __( 'Assisted — safe, reversible actions run only when you trigger them (recommended)', 'seo-command-center' ),
			'autopilot'    => __( 'Autopilot — safe, reversible actions may also run unattended (capped + audited)', 'seo-command-center' ),
		);
		?>
		<h2><?php esc_html_e( 'Intelligence & automation', 'seo-command-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Automation mode', 'seo-command-center' ); ?></th>
				<td>
					<select name="automation_mode" id="scc-automation-mode">
						<?php foreach ( $modes as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $automation_mode, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Autopilot still never edits content, publishes, deletes, or redirects — only deterministic, reversible actions (internal links) run unattended.', 'seo-command-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Revenue-aware prioritization', 'seo-command-center' ); ?></th>
				<td>
					<label><input type="checkbox" name="revenue_weighting" <?php checked( ! empty( $s['revenue_weighting'] ) ); ?>> <?php esc_html_e( 'Prioritize by business value, not search volume alone.', 'seo-command-center' ); ?></label>
					<p class="description"><?php esc_html_e( 'Extra opportunity-score points for opportunities by intent (0–30 each):', 'seo-command-center' ); ?></p>
					<label><?php esc_html_e( 'Commercial', 'seo-command-center' ); ?> <input type="number" min="0" max="30" name="value_commercial" value="<?php echo esc_attr( isset( $s['value_commercial'] ) ? (int) $s['value_commercial'] : 15 ); ?>" class="small-text"></label>
					<label><?php esc_html_e( 'Local', 'seo-command-center' ); ?> <input type="number" min="0" max="30" name="value_local" value="<?php echo esc_attr( isset( $s['value_local'] ) ? (int) $s['value_local'] : 12 ); ?>" class="small-text"></label>
					<label><?php esc_html_e( 'Informational', 'seo-command-center' ); ?> <input type="number" min="0" max="30" name="value_informational" value="<?php echo esc_attr( isset( $s['value_informational'] ) ? (int) $s['value_informational'] : 0 ); ?>" class="small-text"></label>
				</td>
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
