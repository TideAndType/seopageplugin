<?php
/**
 * AI provider contract. Nothing outside includes/ai/ should reference a
 * concrete vendor — only this interface and SCC_AI_Manager.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface every AI provider implements.
 */
interface SCC_AI_Provider_Interface {

	/**
	 * Stable provider id, e.g. 'claude', 'openai'.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether an API key is configured.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Available models as id => label.
	 *
	 * @return array
	 */
	public function list_models();

	/**
	 * Perform a completion.
	 *
	 * @param array $request Normalized request:
	 *   - system   (string)
	 *   - messages (array of ['role'=>'user|assistant','content'=>string])
	 *   - model    (string)
	 *   - max_tokens (int)
	 *   - temperature (float)
	 *   - json     (bool) request structured JSON output
	 *   - operation (string) label for usage tracking.
	 * @return SCC_AI_Response
	 */
	public function complete( array $request );

	/**
	 * Estimate USD cost for a token count on a model.
	 *
	 * @param int    $input_tokens  Input tokens.
	 * @param int    $output_tokens Output tokens.
	 * @param string $model         Model id.
	 * @return float
	 */
	public function estimate_cost( $input_tokens, $output_tokens, $model );
}
